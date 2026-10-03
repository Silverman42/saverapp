<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Enums\LedgerAccountCode;
use App\Models\AgentProfile;
use App\Models\AuditEvent;
use App\Models\ChargeCategoryVersion;
use App\Models\CollectionAnnotation;
use App\Models\CollectionException;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Services\CollectionBatchPosition;
use App\Services\CollectionReadService;
use App\Services\CollectionService;
use App\Services\CustomerReassignmentService;
use App\Services\CustomerStatusManagementService;
use App\Services\ThriftPlanService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\CreatesLifecycleCustomers;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../ReversalFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';
require_once __DIR__.'/../ManualChargeFixtures.php';
require_once __DIR__.'/../WithdrawalFixtures.php';

uses(CreatesLifecycleCustomers::class);

/** @return array<string, mixed> */
function collectionCanonical(string $type): array
{
    $event = DB::table('canonical_audit_events')->where('event_type', $type)->orderByDesc('id')->firstOrFail();

    return json_decode($event->content, true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<string, array<int, object>> */
function collectionFeeCustodyRows(): array
{
    $rows = [];
    foreach (['collection_receipts', 'collection_fee_components', 'collection_allocations', 'collection_batches',
        'cash_remittances', 'collection_batch_reviews', 'collection_exceptions', 'collection_exception_events',
        'fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'ledger_posting_groups', 'ledger_entries',
        'collection_notification_intents', 'thrift_plans', 'plan_terms_revisions', 'contribution_slots',
        'withdrawal_requests', 'withdrawal_reservations'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

test('receipt failure audit retains one masked current scope outcome without repeating accepted money', function (string $failure): void {
    Queue::fake();
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(3);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $payload['notes'] = 'SECRET original collection note';
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json('preview_fingerprint');
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)
        ->assertRedirect()->assertSessionHasNoErrors();
    $accepted = collectionFeeCustodyRows();
    $sourceAudit = DB::table('canonical_audit_events')->where('event_type', 'collection.receipt_posted')->sole();
    expect($sourceAudit->outcome)->toBe('Succeeded');
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(collectionFeeCustodyRows())->toEqual($accepted)
        ->and(DB::table('canonical_audit_events')->where('event_type', 'collection.receipt_posted')->get()->all())->toEqual([$sourceAudit])
        ->and(DB::table('canonical_audit_events')->where('event_type', 'collection.receipt_attempt')->count())->toBe(0);

    $customer->refresh();
    $assignment->refresh();
    $routeCustomer = $customer;
    $status = 409;
    $outcome = 'Conflict';
    $category = 'state_conflict';
    if ($failure === 'foreign Customer') {
        $routeCustomer = CustomerProfile::factory()->create();
        $payload['attempt_reference'] = (string) Str::uuid();
        $status = 404;
        $outcome = 'Denied';
        $category = 'authority_or_scope_denied';
    } elseif ($failure === 'required accounting outage') {
        $plan->refresh();
        $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
        $payload['preview_fingerprint'] = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
            ->assertOk()->json('preview_fingerprint');
        LedgerAccount::query()->where('code', LedgerAccountCode::AgentReceivable)->update(['mapping_status' => 'unmapped']);
        $status = 503;
        $outcome = 'Failed';
        $category = 'system_failed';
    }
    $payload['notes'] = 'SECRET changed receipt investigation';
    $payload['late_reason'] = 'SECRET late explanation';
    $payload['payment_reference'] = 'SECRET submitted payment reference';
    $payload['password'] = 'SECRET submitted authentication';
    $payload['two_factor_code'] = 'SECRET submitted two factor';
    $before = collectionFeeCustodyRows();
    foreach (['customer_profiles', 'customer_assignments', 'ledger_accounts', 'collection_method_versions',
        'collection_payment_evidence', 'collection_evidence_files', 'collection_evidence_reviews', 'manual_charges', 'collection_annotations'] as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $this->postJson(route('customers.collections.store', $routeCustomer->customer_id), $payload)->assertStatus($status);
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
    expect(DB::table('canonical_audit_events')->where('event_type', 'collection.receipt_posted')->get()->all())->toEqual([$sourceAudit]);
    expect(DB::table('canonical_audit_events')->where('event_type', 'collection.receipt_attempt')->count())->toBe(1);
    $event = AuditEvent::query()->where('event_type', 'collection.receipt_attempt')->sole();
    $scoped = $failure !== 'foreign Customer';
    expect($event->actor_id)->toBe($agent->id)
        ->and($event->target_reference)->toBe($scoped ? $customer->customer_id : null)
        ->and($event->payload['category'])->toBe($category)
        ->and($event->payload['customer_profile_id'])->toBe($scoped ? $customer->id : null)
        ->and($event->payload['customer_version'])->toBe($scoped ? $customer->version : null)
        ->and($event->payload['assignment_id'])->toBe($scoped ? $assignment->id : null)
        ->and($event->payload['assignment_version'])->toBe($scoped ? $assignment->version : null);
    $canonical = DB::table('canonical_audit_events')->where('legacy_audit_event_id', $event->id)->sole();
    expect($canonical->outcome)->toBe($outcome)
        ->and($canonical->correlation_reference)->toBe(hash('sha256', $payload['attempt_reference']))
        ->and($canonical->content)->not->toContain('SECRET')->not->toContain($payload['attempt_reference']);
    if (! $scoped) {
        expect($canonical->content)->not->toContain($routeCustomer->customer_id);
    }
})->with(['changed accepted payload' => ['changed accepted payload'],
    'foreign Customer' => ['foreign Customer'], 'required accounting outage' => ['required accounting outage']]);

test('FEE-AC-034: actual reassignment keeps historical fee cash shortage with its original Agent', function (): void {
    $this->freezeTime();
    Queue::fake();
    config()->set('collections.enabled', true);
    [$originalAgent, $customer, $assignment, $plan, $date] = collectionFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($originalAgent, $customer, 50000);
    $payload = [...collectionPayload($customer, $assignment, $plan, $date, '0.00'),
        'plan_id' => null, 'plan_version' => null, 'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '400.00']]];
    $payload['preview_fingerprint'] = $this->actingAs($originalAgent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk()->json('preview_fingerprint');
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $receipt = CollectionReceipt::query()->sole();
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();
    $batch = $receipt->batch->fresh();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::ReconciliationManage, AdminPermission::CustomersReassign]);
    $this->actingAs($admin)->post(route('collection-batches.remittances.store', $batch), [
        'batch_version' => $batch->version, 'handoff_reference' => 'HISTORICAL-FEE-CASH', 'amount_ngn' => '300.00',
        'handoff_date' => $date, 'receiving_location' => 'Business cash office',
        'source_attestation' => 'Counted three hundred from the original fee custodian.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'Original fee custody retains one hundred unremitted.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    $exception = CollectionException::query()->where('collection_batch_id', $batch->id)->sole();
    expect($batch->fresh()->status)->toBe('exception')->and($exception->status)->toBe('open')
        ->and(app(CollectionBatchPosition::class)->read($batch->fresh())['outstanding_kobo'])->toBe(10000)
        ->and($fee->fresh()->outstandingAmountKobo())->toBe(10000)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
    $before = collectionFeeCustodyRows();
    $successor = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $handover = app(CustomerReassignmentService::class);
    $quote = $handover->preview($admin, $customer, $successor->id);
    $handover->execute($admin, $customer, ['attempt_reference' => (string) Str::uuid(),
        'version' => $quote['version'], 'assignment_version' => $quote['assignment_version'],
        'preview_token' => $quote['preview_token'], 'target_agent_id' => $successor->id, 'confirmed' => true,
        'reason' => 'Reviewed replacement of the current service Agent.',
        'customer_explanation' => 'Your fee history remains recorded under its original cash custodian.']);
    expect($customer->fresh()->currentAssignment->agent_profile_id)->toBe($successor->id);
    $this->actingAs($originalAgent)->get(route('customers.collections.create', $customer->customer_id))->assertNotFound();
    $this->get(route('collection-batches.show', $batch))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can_manage', false)->where('receipts', null));
    $this->actingAs($successor->user)->get(route('customers.collections.create', $customer->customer_id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('fee_obligations', 1)
            ->where('fee_obligations.0.id', $fee->id)->where('fee_obligations.0.outstanding_kobo', 10000));
    $this->get(route('collection-batches.show', $batch))->assertForbidden();
    $agentDebt = DB::table('ledger_entries as entries')->join('ledger_accounts as accounts', 'accounts.id', '=', 'entries.ledger_account_id')
        ->where('accounts.code', LedgerAccountCode::AgentReceivable->value)
        ->selectRaw("entries.agent_profile_id, SUM(CASE WHEN entries.side = 'debit' THEN entries.amount_kobo ELSE -entries.amount_kobo END) as balance")
        ->groupBy('entries.agent_profile_id')->pluck('balance', 'entries.agent_profile_id');
    expect((int) $agentDebt[$originalAgent->agentProfile->id])->toBe(10000)->and($agentDebt->has($successor->id))->toBeFalse()
        ->and($receipt->fresh()->recording_agent_profile_id)->toBe($originalAgent->agentProfile->id);
    expect(collectionFeeCustodyRows())->toEqual($before);
});

test('FEE-AC-035: actual Inactive Customer settles only existing fees and preserves savings slots', function (): void {
    $this->freezeTime();
    Queue::fake();
    config()->set('collections.enabled', true);
    [$agent, $customer, , $plan, $date] = collectionFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 50000);
    $pricing = $fee->feeSnapshot->getAttributes();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::CustomersManage);
    $customer = app(CustomerStatusManagementService::class)->transition($admin, $customer, CustomerStatus::Inactive,
        $customer->version, 'Reviewed temporary inactivity.', 'Existing agreed fees remain payable.');
    $before = collectionFeeCustodyRows();
    $mixed = [...collectionPayload($customer, $customer->currentAssignment, $plan, $date, '1000.00'),
        'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '400.00']]];
    $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $mixed)->assertUnprocessable();
    expect(collectionFeeCustodyRows())->toEqual($before);
    foreach (['400.00', '100.00'] as $amount) {
        $payload = [...collectionPayload($customer, $customer->currentAssignment, $plan, $date, '0.00'),
            'plan_id' => null, 'plan_version' => null, 'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => $amount]]];
        $payload['preview_fingerprint'] = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
            ->assertOk()->json('preview_fingerprint');
        $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $posted = collectionFeeCustodyRows();
        $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect()->assertSessionHasNoErrors();
        expect(collectionFeeCustodyRows())->toEqual($posted);
    }
    expect($customer->fresh()->operational_status)->toBe(CustomerStatus::Inactive)
        ->and($fee->fresh()->outstandingAmountKobo())->toBe(0)->and($fee->fresh()->settledAmountKobo())->toBe(50000)
        ->and($fee->feeSnapshot->getAttributes())->toBe($pricing)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'collection_allocations', 'withdrawal_requests', 'withdrawal_reservations'] as $table) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($before[$table]);
    }
    expect(CollectionReceipt::query()->count())->toBe(2)->and(CollectionReceipt::query()->sum('savings_amount_kobo'))->toBe(0)
        ->and(DB::table('ledger_entries')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->where('ledger_accounts.code', LedgerAccountCode::FeeIncome->value)->where('side', 'credit')->sum('amount_kobo'))->toBe(50000);
});

test('FEE-AC-035: actual Restricted Customer rejects normal fee capture but retains independent corrective review', function (): void {
    $this->freezeTime();
    Queue::fake();
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 50000);
    $payload = [...collectionPayload($customer, $assignment, $plan, $date, '0.00'),
        'plan_id' => null, 'plan_version' => null, 'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '400.00']]];
    $payload['preview_fingerprint'] = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json('preview_fingerprint');
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $receipt = CollectionReceipt::query()->sole();
    $component = DB::table('collection_fee_components')->where('collection_receipt_id', $receipt->id)->sole();
    $group = LedgerPostingGroup::findOrFail($component->ledger_posting_group_id);
    $originalLines = $group->entries()->orderBy('id')->get()->map->getAttributes()->all();
    $originalReceipt = $receipt->getAttributes();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::CustomersManage);
    $customer = app(CustomerStatusManagementService::class)->transition($admin, $customer, CustomerStatus::Restricted,
        $customer->version, 'Reviewed contribution and normal financial hold.', 'Corrections remain independently reviewed.');
    $before = collectionFeeCustodyRows();
    $normal = [...$payload, 'attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '100.00']]];
    unset($normal['preview_fingerprint']);
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $normal)->assertUnprocessable();
    expect(collectionFeeCustodyRows())->toEqual($before);
    $reversal = approveReceiptCorrection($this, $agent, $customer, $customer->currentAssignment, $group)->fresh();
    expect($reversal->state)->toBe('approved_posted')->and($reversal->requested_by_user_id)->toBe($agent->id)
        ->and($reversal->reviewed_by_user_id)->not->toBe($agent->id)
        ->and($customer->fresh()->operational_status)->toBe(CustomerStatus::Restricted)
        ->and($fee->fresh()->outstandingAmountKobo())->toBe(50000)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0)
        ->and($receipt->fresh()->getAttributes())->toBe($originalReceipt)
        ->and($group->fresh()->entries()->orderBy('id')->get()->map->getAttributes()->all())->toBe($originalLines);
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'collection_allocations', 'withdrawal_requests', 'withdrawal_reservations'] as $table) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($before[$table]);
    }
});

test('FEE-AC-035: actual lifecycle holds normal charges and payout admission', function (string $status): void {
    $this->freezeTime();
    Queue::fake();
    config()->set(['collections.enabled' => true, 'fees.manual_charges_enabled' => true]);
    [$admin, $customer, $agentProfile] = $this->createLifecycleFixture();
    $agent = $agentProfile->user;
    $plan = $this->createLifecyclePlan($customer, $agent);
    FinancialPeriod::factory()->create();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $admin->givePermissionTo([AdminPermission::FeesManage, AdminPermission::DeductionsManage,
        AdminPermission::WithdrawalsReview, AdminPermission::CashExecute]);
    $session = ['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
    $charges = [];
    foreach (['manual_fee', 'deduction'] as $kind) {
        $this->actingAs($admin)->withSession($session)->post(route('admin.charges.publish'), [
            'publication_reference' => (string) Str::uuid(), 'category_key' => 'lifecycle-'.$kind,
            'kind' => $kind, 'purpose' => 'Reviewed service category',
            'customer_description' => 'Agreed service charge', 'amount_ngn' => '100.01', 'confirmed' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $charges[] = ['operation_reference' => (string) Str::uuid(), 'customer_id' => $customer->customer_id,
            'plan_id' => $plan->plan_id, 'category_id' => ChargeCategoryVersion::query()->where('category_key', 'lifecycle-'.$kind)->sole()->id,
            'customer_version' => $customer->version, 'plan_version' => $plan->version,
            'reason' => 'Reviewed Customer service instruction', 'confirmed' => true,
            'mode' => $kind === 'manual_fee' ? 'assessment_only' : 'deduction'];
    }
    $withdrawal = null;
    if ($status === 'restricted') {
        $payload = $this->lifecycleCollectionPayload($customer, $plan);
        $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
        $this->actingAs($agent)->post(route('customers.collections.store', $customer->customer_id), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        enableFixtureMethod();
        $withdrawal = submittedWithdrawal($agent, $customer, $customer->currentAssignment, $plan->fresh());
        $this->actingAs($admin)->withSession($session)->post(route('withdrawals.approve', $withdrawal), [
            'attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->version,
            'confirmed' => true, 'decision_note' => 'Reviewed original funded cycle instruction.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        expect($withdrawal->fresh()->state)->toBe('approved');
        foreach ($charges as &$charge) {
            $charge['plan_version'] = $plan->fresh()->version;
            $charge = reviewManualCharge($this, $charge);
        }
        unset($charge);
        $customer = app(CustomerStatusManagementService::class)->transition($admin, $customer->fresh(), CustomerStatus::Restricted,
            $customer->fresh()->version, 'Reviewed financial hold.', 'Normal payments and charges are paused.');
        expect($withdrawal->fresh()->held)->toBeTrue();
    } else {
        app(ThriftPlanService::class)->transition($agent, $plan, 'cancel', (string) Str::uuid(), [
            'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
            'plan_version' => $plan->version, 'reason' => 'Unused cycle', 'customer_explanation' => 'Your unused cycle was cancelled.',
        ]);
        $this->actingAs($admin)->postJson(route('customers.lifecycle.archive', $customer->customer_id),
            $this->lifecyclePayload($customer->fresh()))->assertOk();
        $customer = $customer->fresh();
        expect($customer->operational_status)->toBe(CustomerStatus::Archived)
            ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0)
            ->and(DB::table('fee_obligations')->count())->toBe(0);
    }
    $before = collectionFeeCustodyRows();
    $extra = [];
    foreach (['manual_charges', 'manual_charge_notification_intents', 'cash_executions', 'withdrawal_events', 'withdrawal_notification_intents'] as $table) {
        $extra[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    foreach ($charges as $charge) {
        $inputs = [...$charge, 'customer_version' => $customer->version, 'plan_version' => $plan->fresh()->version];
        $inputs = array_intersect_key($inputs, array_flip(['customer_id', 'plan_id', 'category_id', 'customer_version', 'plan_version', 'reason', 'mode']));
        $this->actingAs($admin)->withSession($session)->postJson(route('admin.charges.preview'), $inputs)
            ->assertUnprocessable()->assertJsonValidationErrors('customer_status');
        if ($status === 'restricted') {
            $this->post(route('admin.charges.assess'), $charge)->assertSessionHasErrors('customer_status');
        }
    }
    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id),
        withdrawalPayload($customer, $customer->currentAssignment, $plan->fresh()))->assertForbidden();
    if ($withdrawal !== null) {
        $this->actingAs($admin)->withSession($session)->post(route('withdrawals.cash.start', $withdrawal), [
            'execution_reference' => (string) Str::uuid(), 'version' => $withdrawal->fresh()->version,
            'evidence' => 'Customer presented the original approved instruction.', 'confirmed' => true,
        ])->assertConflict();
        expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000)
            ->and($withdrawal->fresh()->held)->toBeTrue()->and($withdrawal->fresh()->state)->toBe('approved');
    }
    expect(collectionFeeCustodyRows())->toEqual($before);
    foreach ($extra as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
})->with(['restricted', 'archived']);

test('FEE-AC-038: committed fee-only receipt replay retains original identity after actual assignment loss', function (): void {
    $this->freezeTime();
    Queue::fake();
    config()->set('collections.enabled', true);
    [$originalAgent, $customer, $assignment, $plan, $date] = collectionFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $registration = reportFeeObligation($originalAgent, $customer, 50000);
    $payload = [...collectionPayload($customer, $assignment, $plan, $date, '0.00'),
        'plan_id' => null, 'plan_version' => null, 'fees' => [['obligation_id' => $registration->id, 'amount_ngn' => '400.00']],
        'notes' => 'PRIVATE original fee tender evidence'];
    $payload['preview_fingerprint'] = $this->actingAs($originalAgent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json('preview_fingerprint');
    $this->postJson(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    $receipt = CollectionReceipt::query()->where('attempt_reference', $payload['attempt_reference'])->sole();
    $this->postJson(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    $statusRoute = route('collections.attempts.show', ['reference' => $payload['attempt_reference'], 'customer' => $customer->customer_id]);
    $this->getJson($statusRoute)->assertExactJson(['status' => 'posted', 'receipt_reference' => $receipt->receipt_reference]);
    expect($receipt->savings_amount_kobo)->toBe(0)->and($receipt->fee_amount_kobo)->toBe(40000)
        ->and($receipt->recorded_by_user_id)->toBe($originalAgent->id)
        ->and($receipt->recording_agent_profile_id)->toBe($originalAgent->agentProfile->id)
        ->and($registration->fresh()->settledAmountKobo())->toBe(40000)
        ->and($registration->fresh()->outstandingAmountKobo())->toBe(10000);
    $originalRows = [];
    foreach (['collection_receipts', 'collection_fee_components', 'collection_allocations', 'collection_batches',
        'fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'ledger_posting_groups', 'ledger_entries',
        'collection_notification_intents'] as $table) {
        $originalRows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::CustomersReassign);
    $successor = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $handover = app(CustomerReassignmentService::class);
    $quote = $handover->preview($admin, $customer, $successor->id);
    $handover->execute($admin, $customer, ['attempt_reference' => (string) Str::uuid(),
        'version' => $quote['version'], 'assignment_version' => $quote['assignment_version'],
        'preview_token' => $quote['preview_token'], 'target_agent_id' => $successor->id, 'confirmed' => true,
        'reason' => 'Independent reviewed change of current service Agent.',
        'customer_explanation' => 'Your original fee receipt remains with its original custodian.']);
    foreach ($originalRows as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
    $baseline = $originalRows;
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'withdrawal_requests', 'withdrawal_reservations',
        'customer_assignments', 'customer_profiles', 'manual_charge_notification_intents', 'plan_notification_intents'] as $table) {
        $baseline[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $this->actingAs($originalAgent);
    $formerReplay = $this->postJson(route('customers.collections.store', $customer->customer_id), $payload)->assertNotFound();
    $formerStatus = $this->getJson($statusRoute)->assertNotFound();
    $this->actingAs($successor->user);
    $successorReplay = $this->postJson(route('customers.collections.store', $customer->customer_id), $payload)->assertConflict();
    $successorStatus = $this->getJson($statusRoute)->assertNotFound();
    foreach ([$formerReplay, $formerStatus, $successorReplay, $successorStatus] as $denied) {
        $denied->assertJsonMissingPath('receipt_reference');
        expect($denied->getContent())->not->toContain($receipt->receipt_reference)
            ->not->toContain($payload['notes']);
    }
    foreach ($baseline as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
    $this->assertDatabaseCount('collection_receipts', 1);
    expect($receipt->fresh()->recorded_by_user_id)->toBe($originalAgent->id)
        ->and($receipt->fresh()->recording_agent_profile_id)->toBe($originalAgent->agentProfile->id);
});

test('actual receipt remittance and batch transitions retain authoritative canonical versions and safe source identity', function (): void {
    Queue::fake();
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    $data = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $data['notes'] = 'SECRET raw cash investigation';
    $service = app(CollectionService::class);
    $data['preview_fingerprint'] = $service->preview($agent, $customer, $data)['preview_fingerprint'];
    $receipt = $service->record($agent, $customer, $data);
    $receiptAudit = collectionCanonical('collection.receipt_posted');
    expect($receiptAudit['actor_id'])->toBe($agent->id)->and($receiptAudit['actor_type'])->toBe('agent')
        ->and($receiptAudit['safe_changes']['assignment_id'])->toBe($assignment->id)
        ->and($receiptAudit['safe_changes']['posting_group_id'])->toBe($receipt->savings_posting_group_id)
        ->and($receiptAudit['safe_changes']['method'])->toBe('cash')
        ->and($receiptAudit['safe_changes']['currency'])->toBe('NGN')
        ->and($receiptAudit['correlation_reference'])->toBe(hash('sha256', $data['attempt_reference']))
        ->and(json_encode($receiptAudit))->not->toContain('SECRET');
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();
    $batch = $receipt->batch->fresh();
    expect(collectionCanonical('collection.batch_frozen')['source_version'])->toBe($batch->version);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::ReconciliationManage);
    $this->actingAs($admin)->post(route('collection-batches.remittances.store', $batch), [
        'batch_version' => $batch->version, 'handoff_reference' => 'AUDIT-HANDOFF', 'amount_ngn' => '2000.00',
        'handoff_date' => $date, 'receiving_location' => 'SECRET counted location',
        'source_attestation' => 'SECRET cash attestation', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $remittance = $batch->remittances()->sole();
    $handoff = collectionCanonical('collection.remittance_confirmed');
    expect($handoff['actor_id'])->toBe($admin->id)->and($handoff['source_version'])->toBe($batch->fresh()->version)
        ->and($handoff['authority']['required_permission'])->toBe('reconciliation.manage')
        ->and($handoff['safe_changes']['posting_group_id'])->toBe($remittance->ledger_posting_group_id)
        ->and($handoff['safe_changes']['amount_kobo'])->toBe(200000)
        ->and(json_encode($handoff))->not->toContain('SECRET');
    $this->post(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'SECRET independent review reason', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    $review = collectionCanonical('collection.batch_reviewed');
    expect($review['source_version'])->toBe($batch->fresh()->version)->and($review['safe_changes']['outcome'])->toBe('reconciled')
        ->and(json_encode($review))->not->toContain('SECRET');
    foreach ([$receiptAudit, $handoff, $review] as $event) {
        expect($event['occurred_at'])->toEndWith('Z')->and($event['recorded_at'])->toEndWith('Z')
            ->and($event['event_id'])->not->toBeEmpty();
        expect(fn () => DB::table('canonical_audit_events')->where('event_id', $event['event_id'])->update(['outcome' => 'Failed']))->toThrow(QueryException::class);
        expect(fn () => DB::table('canonical_audit_events')->where('event_id', $event['event_id'])->delete())->toThrow(QueryException::class);
    }
});

test('versioned annotation canonical evidence encrypts reasons and rolls back with its owner on audit outage', function (): void {
    Queue::fake();
    config()->set('collections.enabled', true);
    [$agent, , , $plan] = collectionFixture();
    $slot = $plan->slots()->orderBy('ordinal')->firstOrFail();
    foreach ([0, 1] as $version) {
        $this->actingAs($agent)->post(route('plans.card.annotations.store', [$plan, $slot]), [
            'version' => $version, 'kind' => 'skipped', 'reason' => 'SECRET protected attendance reason',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $content = collectionCanonical('collection.slot_annotated');
        expect($content['source_version'])->toBe($version + 1)->and($content['actor_id'])->toBe($agent->id)
            ->and($content['safe_changes']['version'])->toBe($version + 1)
            ->and(json_encode($content))->not->toContain('SECRET');
    }
    $canonical = DB::table('canonical_audit_events')->where('event_type', 'collection.slot_annotated')->orderByDesc('id')->firstOrFail();
    $protected = DB::table('audit_protected_payloads')->where('canonical_event_id', $canonical->id)->sole();
    expect(Crypt::decryptString($protected->ciphertext))->toContain('SECRET protected attendance reason');
    expect(fn () => DB::table('canonical_audit_events')->where('id', $canonical->id)->update(['outcome' => 'Failed']))->toThrow(QueryException::class);
    expect(fn () => DB::table('canonical_audit_events')->where('id', $canonical->id)->delete())->toThrow(QueryException::class);
    DB::statement("CREATE TRIGGER fail_collection_annotation_audit BEFORE INSERT ON canonical_audit_events WHEN NEW.event_type = 'collection.slot_annotated' BEGIN SELECT RAISE(ABORT, 'Canonical audit outage'); END");
    try {
        $this->withoutExceptionHandling();
        expect(fn () => $this->post(route('plans.card.annotations.store', [$plan, $slot]), [
            'version' => 2, 'kind' => 'clear', 'reason' => 'SECRET later annotation',
        ]))->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER fail_collection_annotation_audit');
    }
    expect(CollectionAnnotation::query()->count())->toBe(2)->and(CollectionAnnotation::query()->max('version'))->toBe(2);
});

test('actual receipt reversal canonical history retains separate actor approval version and protected access', function (): void {
    Queue::fake();
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true, 'audit.enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    $data = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $service = app(CollectionService::class);
    $data['preview_fingerprint'] = $service->preview($agent, $customer, $data)['preview_fingerprint'];
    $receipt = $service->record($agent, $customer, $data);
    $originalReceipt = $receipt->getAttributes();
    LedgerAccount::query()->where('code', LedgerAccountCode::UnappliedFunds)->update(['mapping_status' => 'mapped']);
    $reversal = approveReceiptCorrection($this, $agent, $customer, $assignment,
        LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id))->fresh();
    $submitted = collectionCanonical('reversal.submitted');
    $approved = collectionCanonical('reversal.approved_posted');
    expect($submitted['actor_id'])->toBe($agent->id)->and($submitted['source_version'])->toBe(1)
        ->and($approved['actor_id'])->toBe($reversal->reviewed_by_user_id)
        ->and($approved['approver_id'])->toBe($reversal->reviewed_by_user_id)
        ->and($approved['source_version'])->toBe($reversal->version)
        ->and($approved['authority']['required_permission'])->toBe('reversals.review')
        ->and($approved['safe_changes']['original_posting_group_id'])->toBe($receipt->savings_posting_group_id)
        ->and($approved['safe_changes']['compensation_posting_group_id'])->toBe($reversal->compensation_posting_group_id)
        ->and($approved['correlation_reference'])->toStartWith('reversal-event:');
    $eventId = $approved['event_id'];
    expect(fn () => DB::table('canonical_audit_events')->where('event_id', $eventId)->update(['source_version' => 999]))->toThrow(QueryException::class);
    expect(fn () => DB::table('canonical_audit_events')->where('event_id', $eventId)->delete())->toThrow(QueryException::class);
    foreach ([$agent, $customer->user, User::factory()->admin()->withTwoFactor()->create()] as $viewer) {
        $this->actingAs($viewer)->get(route('admin.audit.show', $eventId))->assertForbidden();
    }
    $viewer = User::factory()->admin()->withTwoFactor()->create();
    $viewer->givePermissionTo(AdminPermission::AuditView);
    $this->actingAs($viewer)->get(route('admin.audit.show', $eventId))->assertOk();
    expect(CollectionReceipt::query()->sole()->getAttributes())->toBe($originalReceipt);
});
