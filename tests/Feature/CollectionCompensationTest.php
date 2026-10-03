<?php

use App\Enums\AdminPermission;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Enums\ThriftPlanStatus;
use App\Jobs\DeliverCollectionNotificationIntent;
use App\Jobs\DeliverReversalNotificationIntent;
use App\Models\AgentProfile;
use App\Models\CollectionReceipt;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalNotificationIntent;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\AgentEligibilityService;
use App\Services\CollectionReadService;
use App\Services\CollectionReversalOwner;
use App\Services\CollectionService;
use App\Services\CustomerReassignmentService;
use App\Services\FinancialCashPosition;
use App\Services\LedgerTransactionProjectionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require_once __DIR__.'/../WithdrawalFixtures.php';
require_once __DIR__.'/../ReversalFixtures.php';

function receiptReversalAuthorityRows(): array
{
    $rows = [];
    foreach (['collection_receipts', 'collection_batches', 'collection_allocations', 'collection_allocation_releases',
        'thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'ledger_posting_groups', 'ledger_entries',
        'reversal_requests', 'reversal_attempts', 'reversal_events', 'reversal_notification_intents'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

test('current receipt reversal authority survives handover while any value needs an independent granted fresh Admin', function (int $amountKobo, string $amount): void {
    $this->freezeTime();
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$recorder, $customer, $assignment, $plan, $date] = collectionFixture(1, slotAmountKobo: $amountKobo);
    $collection = app(CollectionService::class);
    $payload = collectionPayload($customer, $assignment, $plan, $date, $amount);
    $payload['preview_fingerprint'] = $collection->preview($recorder, $customer, $payload)['preview_fingerprint'];
    $receipt = $collection->record($recorder, $customer, $payload);
    LedgerAccount::query()->where('code', LedgerAccountCode::UnappliedFunds->value)->update(['mapping_status' => 'mapped']);
    $original = LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id);
    $originalReceipt = $receipt->getAttributes();
    $originalEntries = $original->entries()->orderBy('id')->get()->map->getAttributes()->all();
    $successor = User::factory()->agent()->withTwoFactor()->create();
    $profile = AgentProfile::factory()->active()->create(['user_id' => $successor->id]);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::CustomersReassign, AdminPermission::ReversalsReview]);
    $this->actingAs($admin)->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    $handover = app(CustomerReassignmentService::class);
    $preview = $handover->preview($admin, $customer, $profile->id);
    $handover->execute($admin, $customer, ['attempt_reference' => (string) Str::uuid(),
        'version' => $preview['version'], 'assignment_version' => $preview['assignment_version'],
        'target_agent_id' => $profile->id, 'preview_token' => $preview['preview_token'], 'confirmed' => true,
        'reason' => 'Transfer current receipt follow-up responsibility.', 'customer_explanation' => 'Your service contact changed.']);
    $quote = $this->actingAs($successor)->postJson(route('reversals.preview', $original->posting_reference))->assertOk()->json();
    expect($quote['gross_kobo'])->toBe($amountKobo);
    $requestData = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'reason_category' => 'wrong_amount_allocation', 'internal_reason' => 'Original full tender is controlled by its original custodian.',
        'customer_explanation' => 'Reviewing the original receipt.', 'evidence_text' => 'Original receipt and full controlled cash verified.', 'confirmed' => true];
    $foreign = User::factory()->agent()->withTwoFactor()->create();
    AgentProfile::factory()->active()->create(['user_id' => $foreign->id]);
    $before = receiptReversalAuthorityRows();
    foreach ([$recorder, $foreign] as $denied) {
        $this->actingAs($denied)->postJson(route('reversals.preview', $original->posting_reference))->assertNotFound();
        $this->postJson(route('reversals.store', $original->posting_reference), $requestData)->assertNotFound();
    }
    $this->actingAs($customer->user)->postJson(route('reversals.store', $original->posting_reference), $requestData)->assertForbidden();
    expect(receiptReversalAuthorityRows())->toEqual($before);
    $this->actingAs($successor)->post(route('reversals.store', $original->posting_reference), $requestData)->assertRedirect()->assertSessionHasNoErrors();
    $pending = ReversalRequest::query()->sole();
    $submitted = receiptReversalAuthorityRows();
    $this->post(route('reversals.store', $original->posting_reference), $requestData)->assertRedirect()->assertSessionHasNoErrors();
    $this->postJson(route('reversals.store', $original->posting_reference), [...$requestData, 'attempt_reference' => (string) Str::uuid()])->assertConflict();
    expect(receiptReversalAuthorityRows())->toEqual($submitted)
        ->and($pending->requested_by_user_id)->toBe($successor->id)->and($pending->initiating_agent_profile_id)->toBe($profile->id);
    $review = $this->actingAs($admin)->getJson(route('reversals.review-preview', $pending))->assertOk()->json();
    $approval = ['attempt_reference' => (string) Str::uuid(), 'version' => $pending->version,
        'preview_fingerprint' => $review['preview_fingerprint'], 'decision_reason' => 'Independent full-value custody and correction review.', 'confirmed' => true];
    $this->actingAs($successor)->postJson(route('reversals.approve', $pending), $approval)->assertForbidden();
    $ungranted = User::factory()->admin()->withTwoFactor()->create();
    $fresh = ['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
    $this->actingAs($ungranted)->withSession($fresh)->postJson(route('reversals.approve', $pending), $approval)->assertForbidden();
    $this->actingAs($admin)->withSession(['auth.fresh_until' => now()->subMinute()->timestamp,
        'auth.password_confirmed_at' => now()->subHour()->timestamp, 'auth.mfa_confirmed_at' => now()->subHour()->timestamp])
        ->postJson(route('reversals.approve', $pending), $approval)->assertStatus(423)->assertJsonPath('message', 'Fresh authentication required.');
    expect(receiptReversalAuthorityRows())->toEqual($submitted)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe($amountKobo);
    $this->actingAs($admin)->withSession($fresh)->post(route('reversals.approve', $pending), $approval)->assertRedirect()->assertSessionHasNoErrors();
    expect($pending->fresh()->state)->toBe('approved_posted')->and($pending->fresh()->reviewed_by_user_id)->toBe($admin->id)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0)
        ->and($receipt->fresh()->getAttributes())->toBe($originalReceipt)
        ->and($original->entries()->orderBy('id')->get()->map->getAttributes()->all())->toBe($originalEntries);
    $approved = receiptReversalAuthorityRows();
    $this->post(route('reversals.approve', $pending), $approval)->assertRedirect()->assertSessionHasNoErrors();
    expect(receiptReversalAuthorityRows())->toEqual($approved);
})->with(['small original' => [200000, '2000.00'], 'maximum permitted original' => [999999999999, '9999999999.99']]);

test('a real pending receipt reversal transfers follow-up while separately confirmed replacement retains original cash custody', function (bool $deliveredBeforeHandover): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'Africa/Lagos'));
    Queue::fake();
    config()->set('notifications.enabled', true);
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$recorder, $customer, $assignment, $plan, $date] = collectionFixture(2);
    $collection = app(CollectionService::class);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $payload['preview_fingerprint'] = $collection->preview($recorder, $customer, $payload)['preview_fingerprint'];
    $receipt = $collection->record($recorder, $customer, $payload);
    LedgerAccount::query()->where('code', LedgerAccountCode::UnappliedFunds->value)->update(['mapping_status' => 'mapped']);
    $original = LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id);
    $originalReceipt = $receipt->getAttributes();
    $originalEntries = $original->entries()->orderBy('id')->get()->map->getAttributes()->all();
    $quote = $this->actingAs($recorder)->postJson(route('reversals.preview', $original->posting_reference))->assertOk()->json();
    $requestData = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'reason_category' => 'wrong_amount_allocation', 'internal_reason' => 'Original cash remains with original recording Agent.',
        'customer_explanation' => 'Reviewing original allocation.', 'evidence_text' => 'Actual controlled tender and original allocation verified.', 'confirmed' => true];
    $this->post(route('reversals.store', $original->posting_reference), $requestData)->assertRedirect()->assertSessionHasNoErrors();
    $pending = ReversalRequest::query()->sole();
    $pendingBefore = $pending->getAttributes();
    $receiptNotice = DB::table('collection_notification_intents')->where('channel', 'database')->sole();
    (new DeliverCollectionNotificationIntent($receiptNotice->id))->handle();
    (new DeliverCollectionNotificationIntent($receiptNotice->id))->handle();
    expect(DB::table('notifications')->where('id', $receiptNotice->notification_id)->count())->toBe(1);
    $agentNotice = ReversalNotificationIntent::query()->where('audience_type', 'current_agent')->sole();
    $noticeBaseline = array_diff_key(receiptReversalAuthorityRows(), ['reversal_notification_intents' => true]);
    if ($deliveredBeforeHandover) {
        (new DeliverReversalNotificationIntent($agentNotice->id))->handle(app(AgentEligibilityService::class));
        $this->actingAs($recorder)->get(route('notifications.show', $agentNotice->notification_id))->assertOk();
    } else {
        DB::statement("CREATE TRIGGER fail_collection_corrective_notice BEFORE UPDATE ON notification_inbox_intents WHEN NEW.status = 'delivered' BEGIN SELECT RAISE(ABORT, 'private local delivery outage'); END");
        try {
            (new DeliverReversalNotificationIntent($agentNotice->id))->handle(app(AgentEligibilityService::class));
        } finally {
            DB::statement('DROP TRIGGER fail_collection_corrective_notice');
        }
        expect($agentNotice->fresh()->status)->toBe('pending');
        $nextAttempt = DB::table('notification_inbox_intents')->whereIn('id', DB::table('notification_inbox_aliases')
            ->where('family', 'reversal')->where('owner_intent_id', $agentNotice->id)->select('intent_id'))->max('next_attempt_at');
        expect($nextAttempt)->not->toBeNull();
        $this->travelTo(CarbonImmutable::parse($nextAttempt)->addSecond());
    }
    expect(array_diff_key(receiptReversalAuthorityRows(), ['reversal_notification_intents' => true]))->toEqual($noticeBaseline);
    $successor = User::factory()->agent()->withTwoFactor()->create();
    $profile = AgentProfile::factory()->active()->create(['user_id' => $successor->id]);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::CustomersReassign, AdminPermission::ReversalsReview]);
    $this->actingAs($admin)->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    $handover = app(CustomerReassignmentService::class);
    $preview = $handover->preview($admin, $customer, $profile->id);
    $handover->execute($admin, $customer, ['attempt_reference' => (string) Str::uuid(),
        'version' => $preview['version'], 'assignment_version' => $preview['assignment_version'],
        'target_agent_id' => $profile->id, 'preview_token' => $preview['preview_token'], 'confirmed' => true,
        'reason' => 'Transfer current receipt follow-up responsibility.', 'customer_explanation' => 'Your service contact changed.']);
    expect($pending->fresh()->getAttributes())->toBe($pendingBefore);
    $noticeBaseline = array_diff_key(receiptReversalAuthorityRows(), ['reversal_notification_intents' => true]);
    (new DeliverReversalNotificationIntent($agentNotice->id))->handle(app(AgentEligibilityService::class));
    (new DeliverReversalNotificationIntent($agentNotice->id))->handle(app(AgentEligibilityService::class));
    expect($agentNotice->fresh()->status)->toBe($deliveredBeforeHandover ? 'delivered' : 'suppressed')
        ->and(DB::table('notifications')->where('id', $agentNotice->notification_id)->count())->toBe($deliveredBeforeHandover ? 1 : 0)
        ->and(array_diff_key(receiptReversalAuthorityRows(), ['reversal_notification_intents' => true]))->toEqual($noticeBaseline);
    $rerouted = ReversalNotificationIntent::query()->where('reversal_event_id', $agentNotice->reversal_event_id)
        ->where('recipient_user_id', $successor->id)->get();
    expect($rerouted)->toHaveCount($deliveredBeforeHandover ? 0 : 1);
    foreach ($rerouted as $notice) {
        expect($notice->notification_id)->not->toBe($agentNotice->notification_id);
        (new DeliverReversalNotificationIntent($notice->id))->handle(app(AgentEligibilityService::class));
        (new DeliverReversalNotificationIntent($notice->id))->handle(app(AgentEligibilityService::class));
        $this->actingAs($successor)->get(route('notifications.show', $notice->notification_id))->assertOk();
        expect(DB::table('notifications')->where('id', $notice->notification_id)->count())->toBe(1);
    }
    expect(array_diff_key(receiptReversalAuthorityRows(), ['reversal_notification_intents' => true]))->toEqual($noticeBaseline);
    $this->actingAs($recorder)->get(route('notifications.show', $agentNotice->notification_id))->assertNotFound();
    $this->get(route('notifications.open', $agentNotice->notification_id))->assertNotFound();
    $this->get(route('notifications.index', ['search' => $pending->reversal_id]))
        ->assertInertia(fn (Assert $page) => $page->has('inbox.items', 0));
    $this->actingAs($successor)->get(route('reversals.show', $pending))->assertOk();
    $before = receiptReversalAuthorityRows();
    $this->actingAs($recorder)->get(route('reversals.show', $pending))->assertNotFound();
    $this->postJson(route('reversals.cancel', $pending), ['attempt_reference' => (string) Str::uuid(), 'version' => 1,
        'decision_reason' => 'Former Agent cannot mutate current follow-up.', 'confirmed' => true])->assertForbidden();
    expect(receiptReversalAuthorityRows())->toEqual($before);
    $review = $this->actingAs($admin)->getJson(route('reversals.review-preview', $pending))->assertOk()->json();
    $this->post(route('reversals.approve', $pending), ['attempt_reference' => (string) Str::uuid(),
        'version' => $pending->version, 'preview_fingerprint' => $review['preview_fingerprint'],
        'decision_reason' => 'Reviewed original cash and legitimate pending request after service handover.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($pending->fresh()->state)->toBe('approved_posted')
        ->and($pending->fresh()->requested_by_user_id)->toBe($recorder->id)->and($pending->fresh()->assignment_id)->toBe($assignment->id)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
    $this->assertDatabaseCount('collection_receipts', 1);
    $noticeBaseline = array_diff_key(receiptReversalAuthorityRows(), ['reversal_notification_intents' => true]);
    $postedNotices = ReversalNotificationIntent::query()->where('channel', 'database')
        ->whereIn('reversal_event_id', DB::table('reversal_events')->where('reversal_request_id', $pending->id)
            ->where('event_type', 'approved_posted')->select('id'))->get();
    expect($postedNotices)->toHaveCount(2);
    foreach ($postedNotices as $notice) {
        (new DeliverReversalNotificationIntent($notice->id))->handle(app(AgentEligibilityService::class));
        (new DeliverReversalNotificationIntent($notice->id))->handle(app(AgentEligibilityService::class));
        expect($notice->fresh()->status)->toBe('delivered')
            ->and(DB::table('notifications')->where('id', $notice->notification_id)->count())->toBe(1);
        $viewer = $notice->audience_type === 'subject_customer' ? $customer->user : $successor;
        $response = $this->actingAs($viewer)->get(route('notifications.show', $notice->notification_id))->assertOk();
        expect($response->getContent())->not->toContain($requestData['internal_reason'])
            ->not->toContain($requestData['evidence_text'])->not->toContain('private local delivery outage');
    }
    expect(array_diff_key(receiptReversalAuthorityRows(), ['reversal_notification_intents' => true]))->toEqual($noticeBaseline);
    $customer->refresh()->load('currentAssignment');
    $replacementData = collectionPayload($customer, $customer->currentAssignment, $plan->fresh(), $date, '2000.00');
    $replacementQuote = $this->actingAs($successor)->postJson(route('reversals.replacement.preview', $pending), $replacementData)->assertOk()->json();
    $replacementData['preview_fingerprint'] = $replacementQuote['preview_fingerprint'];
    $replacementData['replacement_fingerprint'] = $replacementQuote['replacement_fingerprint'];
    $approved = receiptReversalAuthorityRows();
    $this->postJson(route('reversals.replacement.store', $pending), [...$replacementData, 'confirmed' => false])
        ->assertUnprocessable()->assertJsonValidationErrors('confirmed');
    $profile->update(['operational_status' => 'inactive']);
    $this->postJson(route('reversals.replacement.store', $pending), $replacementData)->assertForbidden();
    expect(receiptReversalAuthorityRows())->toEqual($approved);
    $profile->update(['operational_status' => 'active']);
    $this->post(route('reversals.replacement.store', $pending), $replacementData)->assertRedirect()->assertSessionHasNoErrors();
    $replacement = CollectionReceipt::query()->where('replacement_reversal_id', $pending->id)->sole();
    expect($replacement->receipt_reference)->not->toBe($receipt->receipt_reference)
        ->and($replacement->attempt_reference)->not->toBe($receipt->attempt_reference)
        ->and($replacement->recorded_by_user_id)->toBe($successor->id)
        ->and($replacement->assignment_id)->toBe($customer->currentAssignment->id)
        ->and($replacement->recording_agent_profile_id)->toBe($receipt->recording_agent_profile_id)
        ->and($replacement->collection_batch_id)->toBe($receipt->collection_batch_id)
        ->and($receipt->fresh()->getAttributes())->toBe($originalReceipt)
        ->and($original->entries()->orderBy('id')->get()->map->getAttributes()->all())->toBe($originalEntries)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
    $receivable = LedgerAccount::query()->where('code', LedgerAccountCode::AgentReceivable)->sole();
    $debtEntries = $receivable->entries()->get();
    expect((int) $debtEntries->where('side', LedgerEntrySide::Debit)->sum('amount_kobo'))->toBe(200000)
        ->and($debtEntries->where('side', LedgerEntrySide::Credit))->toHaveCount(0)
        ->and($debtEntries->pluck('agent_profile_id')->unique()->all())->toBe([$receipt->recording_agent_profile_id]);
    $posted = receiptReversalAuthorityRows();
    $this->post(route('reversals.replacement.store', $pending), $replacementData)->assertRedirect()->assertSessionHasNoErrors();
    expect(receiptReversalAuthorityRows())->toEqual($posted);
    $this->assertDatabaseCount('collection_receipts', 2);
    $this->assertDatabaseCount('collection_batches', 1);
})->with(['delivered notice loses scope' => true, 'outage retry loses scope' => false]);

test('receipt correction fails closed when the complete allocation and fee graph is unavailable', function (): void {
    [$agent, $customer] = withdrawalFixture();
    $receipt = CollectionReceipt::query()->sole();
    $mapping = LedgerAccount::query()->where('code', LedgerAccountCode::UnappliedFunds->value)->sole();
    $mapping->update(['mapping_status' => 'mapped', 'account_class' => LedgerAccountClass::UnappliedFunds, 'normal_balance' => LedgerEntrySide::Credit]);

    expect(fn () => app(CollectionReversalOwner::class)->preview(LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id), $customer, false))
        ->toThrow(ConflictHttpException::class, 'allocation graph');
    $this->assertDatabaseCount('collection_allocation_releases', 0);
    $this->assertDatabaseCount('ledger_posting_groups', 1);
});

require_once __DIR__.'/../CollectionFixtures.php';

test('full controlled receipt correction releases slots and preserves original custodian and cash', function (): void {
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(1);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $quote = app(CollectionService::class)->preview($agent, $customer, $payload);
    $payload['preview_fingerprint'] = $quote['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $payload);
    $mapping = LedgerAccount::query()->where('code', LedgerAccountCode::UnappliedFunds->value)->sole();
    $mapping->update(['mapping_status' => 'mapped']);
    $original = LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id);
    $request = approveReceiptCorrection($this, $agent, $customer, $assignment, $original);
    expect($request->fresh()->state)->toBe('approved_posted')
        ->and($plan->fresh()->status)->toBe(ThriftPlanStatus::Paused)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
    $this->assertDatabaseCount('collection_allocations', 1);
    $this->assertDatabaseCount('collection_allocation_releases', 1);
    app(LedgerTransactionProjectionService::class)->rebuild();
    expect(DB::table('ledger_transaction_projections')->where('type', 'reversal')->latest('id')->value('savings_effect_kobo'))->toBe(-200000);
    expect($original->fresh()->entries()->where('side', 'debit')->sum('amount_kobo'))->toBe(200000);
    $receiptBefore = $receipt->fresh()->getAttributes();
    $baseline = [];
    foreach (['collection_receipts', 'collection_batches', 'collection_allocations', 'collection_allocation_releases',
        'thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'ledger_posting_groups', 'ledger_entries',
        'reversal_requests', 'reversal_attempts', 'canonical_audit_events', 'notification_events', 'notification_inbox_intents'] as $table) {
        $baseline[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $collections = app(CollectionService::class);
    expect($collections->record($agent, $customer, $payload)->id)->toBe($receipt->id)
        ->and($collections->record($agent, $customer, $payload)->getAttributes())->toBe($receiptBefore);
    expect(fn () => $collections->record($agent, $customer, [...$payload, 'savings_ngn' => '1000.00']))
        ->toThrow(ConflictHttpException::class, 'different request');
    foreach ($baseline as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
});

require_once __DIR__.'/../FeeFixtures.php';

test('mixed savings and fee receipt correction reclassifies full tender and restores the unpaid fee without moving cash', function (): void {
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(1);
    $obligation = reportFeeObligation($agent, $customer, 500);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $payload['fees'] = [['obligation_id' => $obligation->id, 'amount_ngn' => '5.00']];
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $payload);
    LedgerAccount::query()->whereIn('code', [LedgerAccountCode::UnappliedFunds->value, LedgerAccountCode::BusinessDistributions->value])->update(['mapping_status' => 'mapped']);
    $original = LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id);
    $request = approveReceiptCorrection($this, $agent, $customer, $assignment, $original);
    expect($request->fresh()->state)->toBe('approved_posted')->and($obligation->fresh()->outstandingAmountKobo())->toBe(500)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0)
        ->and(app(FinancialCashPosition::class)->balance(LedgerAccountCode::UnappliedFunds))->toBe(200500)
        ->and(app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome))->toBe(0);
    $group = LedgerPostingGroup::findOrFail($request->fresh()->compensation_posting_group_id);
    expect((int) $group->entries()->where('side', 'debit')->sum('amount_kobo'))->toBe(200500)
        ->and((int) $group->entries()->where('side', 'credit')->sum('amount_kobo'))->toBe(200500);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $this->assertDatabaseCount('collection_allocations', 1);
    $this->assertDatabaseCount('collection_allocation_releases', 1);
});

test('fee-only receipt correction restores the obligation and retains the original cash custodian without a plan allocation', function (): void {
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(1);
    $obligation = reportFeeObligation($agent, $customer, 500);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '0.00');
    $payload['plan_id'] = null;
    $payload['plan_version'] = null;
    $payload['fees'] = [['obligation_id' => $obligation->id, 'amount_ngn' => '5.00']];
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $payload);
    LedgerAccount::query()->where('code', LedgerAccountCode::UnappliedFunds->value)->update(['mapping_status' => 'mapped']);
    $original = LedgerPostingGroup::findOrFail(DB::table('collection_fee_components')->where('collection_receipt_id', $receipt->id)->value('ledger_posting_group_id'));
    $before = receiptReversalAuthorityRows();
    expect(fn () => app(CollectionReversalOwner::class)->preview($original, $customer, false))
        ->toThrow(RuntimeException::class, 'mapping is unavailable');
    expect(receiptReversalAuthorityRows())->toEqual($before);
    LedgerAccount::query()->where('code', LedgerAccountCode::BusinessDistributions->value)->update(['mapping_status' => 'mapped']);
    $request = approveReceiptCorrection($this, $agent, $customer, $assignment, $original);
    expect($request->fresh()->state)->toBe('approved_posted')->and($obligation->fresh()->outstandingAmountKobo())->toBe(500)
        ->and(app(FinancialCashPosition::class)->balance(LedgerAccountCode::UnappliedFunds))->toBe(500)
        ->and(app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome))->toBe(0)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0)
        ->and($plan->fresh()->status)->toBe(ThriftPlanStatus::Active);
    $this->assertDatabaseCount('collection_allocations', 0);
    $this->assertDatabaseCount('collection_allocation_releases', 0);
    $group = LedgerPostingGroup::findOrFail($request->fresh()->compensation_posting_group_id);
    expect($group->entries()->where('side', 'credit')->sole()->agent_profile_id)->toBe($receipt->recording_agent_profile_id);
    app(LedgerTransactionProjectionService::class)->rebuild();
    expect((int) DB::table('ledger_transaction_projections')->where('type', 'reversal')->value('savings_effect_kobo'))->toBe(0);
});
