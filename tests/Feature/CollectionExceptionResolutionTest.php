<?php

use App\Enums\AdminPermission;
use App\Enums\FeeSettlementSource;
use App\Models\AuditEvent;
use App\Models\CollectionException;
use App\Models\CollectionReceipt;
use App\Models\CustomerAssignment;
use App\Models\FinancialWorkflowSupplement;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Services\CollectionBatchPosition;
use App\Services\CollectionReadService;
use App\Services\FeeObligationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../CollectionExceptionFixtures.php';
require_once __DIR__.'/../NoncashCollectionFixtures.php';
require_once __DIR__.'/../CollectionNoMoneyFixtures.php';

function matchedTransferExceptionFixture(object $test, string $amount = '2000.00', string $method = 'transfer', string $custody = 'business_bank_ngn'): array
{
    [$agent, $customer, , , $date, $admin, , $data] = noncashFixture($test, $method, $custody);
    $receipt = postNoncashReceipt($test, $customer, $data);
    $test->travel(1)->days();
    $test->artisan('collections:freeze-batches')->assertSuccessful();
    $test->travelBack();
    $test->actingAs($admin)->post(route('collection-batches.exceptions.store', $receipt->batch), [
        'batch_version' => $receipt->batch->fresh()->version, 'kind' => 'missing_transfer', 'amount_ngn' => $amount,
        'reason' => 'The payment reference required further independent matching.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    return [$customer, $admin, $receipt, CollectionException::query()->sole(), $date, $agent];
}

test('a missing transfer resolves against exact verified original payment without new money movement', function (string $method, string $custody): void {
    [$customer, $admin, $receipt, $exception, , $agent] = matchedTransferExceptionFixture($this, method: $method, custody: $custody);
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    $this->post(route('collection-batches.exceptions.resolve', [$receipt->batch, $exception]), [
        'batch_version' => $receipt->batch->fresh()->version, 'resolution_kind' => 'verified_match',
        'receipt_reference' => $receipt->receipt_reference, 'reason' => 'Independent bank proof matches the entire original receipt.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($exception->fresh()->status)->toBe('resolved');
    $proof = FinancialWorkflowSupplement::query()->sole();
    expect($proof->kind)->toBe('collection_exception_resolution')->and($proof->facts['exception_id'])->toBe($exception->id)
        ->and($proof->facts['receipt_id'])->toBe($receipt->id)->and($proof->facts['evidence_id'])->toBe($receipt->collection_payment_evidence_id);
    expect($proof->getRawOriginal('evidence'))->not->toContain('Independent bank proof');
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
    $this->get(route('collection-batches.show', $receipt->batch))->assertInertia(fn (Assert $page) => $page
        ->has('resolution_records.data', 1)->where('resolution_records.data.0.receipt_reference', $receipt->receipt_reference)
        ->where('resolution_records.data.0.cause', 'Independent bank proof matches the entire original receipt.'));
    $this->actingAs($agent)->get(route('collection-batches.show', $receipt->batch))->assertInertia(fn (Assert $page) => $page
        ->where('resolution_records', null)->where('exceptions.data.0.reason', null));
    $admin->revokePermissionTo(AdminPermission::ReconciliationManage);
    $this->actingAs($admin)->get(route('collection-batches.show', $receipt->batch))->assertInertia(fn (Assert $page) => $page
        ->where('resolution_records', null));
    $this->actingAs($customer->user)->get(route('collection-batches.show', $receipt->batch))->assertForbidden();
})->with(['bank transfer' => ['transfer', 'business_bank_ngn'], 'POS clearing' => ['pos', 'payment_clearing_ngn'], 'configured other custody' => ['other', 'agent_receivable_ngn']]);

test('missing transfer match rejects incomplete amounts and unavailable protected proof', function (bool $missingFile): void {
    [, , $receipt, $exception] = matchedTransferExceptionFixture($this, $missingFile ? '2000.00' : '1000.00');
    $data = ['batch_version' => $receipt->batch->fresh()->version, 'resolution_kind' => 'verified_match',
        'receipt_reference' => $receipt->receipt_reference, 'reason' => 'Match the original payment evidence.', 'confirmed' => true];
    if ($missingFile) {
        $path = DB::table('collection_evidence_files')->value('storage_path');
        Storage::disk('collection_evidence')->delete($path);
    }
    $this->post(route('collection-batches.exceptions.resolve', [$receipt->batch, $exception]), $data)->assertStatus($missingFile ? 503 : 409);
    expect($exception->fresh()->status)->toBe('open');
    $this->assertDatabaseCount('financial_workflow_supplements', 0);
})->with(['incomplete match' => false, 'missing protected file' => true]);

test('an approved receipt correction may resolve investigation while original Agent custody remains outstanding', function (): void {
    [$agent, $customer, $admin, $batch] = collectionInvestigationFixture($this, openCase: false);
    config()->set('collections.receipt_corrections_enabled', true);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $receipt = CollectionReceipt::query()->sole();
    $assignment = CustomerAssignment::query()->where('customer_profile_id', $customer->id)->sole();
    $original = LedgerPostingGroup::query()->findOrFail($receipt->savings_posting_group_id);
    $reversal = approveReceiptCorrection($this, $agent, $customer, $assignment, $original)->fresh();
    $this->actingAs($admin)->post(route('collection-batches.review', $batch), [
        'batch_version' => $batch->fresh()->version, 'reason' => 'Original physical custody remains with the recording Agent.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $exception = CollectionException::query()->sole();
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    $this->actingAs($admin)->post(route('collection-batches.exceptions.resolve', [$batch, $exception]), [
        'batch_version' => $batch->fresh()->version, 'resolution_kind' => 'approved_correction',
        'receipt_reference' => $receipt->receipt_reference, 'reversal_id' => $reversal->reversal_id,
        'reason' => 'Separately approved receipt correction resolves this recording investigation; original custody remains due.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($exception->fresh()->status)->toBe('resolved');
    $proof = FinancialWorkflowSupplement::query()->where('kind', 'collection_exception_resolution')->sole();
    expect($proof->facts['outstanding_kobo'])->toBe(200000)->and($proof->reversal_request_id)->toBe($reversal->id);
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
    $this->post(route('collection-batches.review', $batch), [
        'batch_version' => $batch->fresh()->version, 'reason' => 'Original Agent custody still requires handoff.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($batch->fresh()->status)->toBe('exception');
});

test('late audit failure rolls back matched-payment resolution and immutable supplement together', function (): void {
    [, , $receipt, $exception] = matchedTransferExceptionFixture($this);
    Event::listen('eloquent.creating: '.AuditEvent::class, function (AuditEvent $event): void {
        if ($event->event_type === 'collection.exception_resolved') {
            throw new RuntimeException('Matched resolution audit unavailable.');
        }
    });
    $this->withoutExceptionHandling();
    expect(fn () => $this->post(route('collection-batches.exceptions.resolve', [$receipt->batch, $exception]), [
        'batch_version' => $receipt->batch->fresh()->version, 'resolution_kind' => 'verified_match',
        'receipt_reference' => $receipt->receipt_reference, 'reason' => 'Verified original payment matches this receipt.', 'confirmed' => true,
    ]))->toThrow(RuntimeException::class, 'Matched resolution audit unavailable.');
    expect($exception->fresh()->status)->toBe('open');
    $this->assertDatabaseCount('financial_workflow_supplements', 0);
    $this->assertDatabaseCount('collection_exception_events', 1);
});

test('pending corrections cannot resolve an investigation or consume a concession a second time', function (): void {
    [, , , , , , $receipt, , , $admin, $reversal] = noMoneyReceiptFixture($this);
    $this->actingAs($admin)->post(route('collection-batches.exceptions.store', $receipt->batch), [
        'batch_version' => $receipt->batch->fresh()->version, 'kind' => 'missing_transfer', 'amount_ngn' => '500.00',
        'reason' => 'The original payment requires correction investigation.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $exception = CollectionException::query()->sole();
    $this->post(route('collection-batches.exceptions.resolve', [$receipt->batch, $exception]), [
        'batch_version' => $receipt->batch->fresh()->version, 'resolution_kind' => 'approved_correction',
        'receipt_reference' => $receipt->receipt_reference, 'reversal_id' => $reversal->reversal_id,
        'reason' => 'A pending correction is insufficient resolution evidence.', 'confirmed' => true,
    ])->assertConflict();
    expect($exception->fresh()->status)->toBe('open')->and($reversal->fresh()->state)->toBe('pending_review');
    $this->assertDatabaseCount('financial_workflow_supplements', 0);
    $this->assertDatabaseCount('fee_refunds', 1);
});

test('an approved no-money receipt correction resolves investigation without another refund or journal', function (): void {
    [, , , , , , $receipt, , , $admin, $reversal, $decision] = noMoneyReceiptFixture($this);
    $this->actingAs($admin)->post(route('reversals.approve', $reversal), $decision)->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('collection-batches.exceptions.store', $receipt->batch), [
        'batch_version' => $receipt->batch->fresh()->version, 'kind' => 'missing_transfer', 'amount_ngn' => '500.00',
        'reason' => 'Later receipt investigation requires the existing correction reference.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $exception = CollectionException::query()->sole();
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    $this->post(route('collection-batches.exceptions.resolve', [$receipt->batch, $exception]), [
        'batch_version' => $receipt->batch->fresh()->version, 'resolution_kind' => 'approved_correction',
        'receipt_reference' => $receipt->receipt_reference, 'reversal_id' => $reversal->reversal_id,
        'reason' => 'Existing independent no-money correction resolves the later investigation.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($exception->fresh()->status)->toBe('resolved');
    $proof = FinancialWorkflowSupplement::query()->where('kind', 'collection_exception_resolution')->sole();
    expect($proof->facts['compensation_posting_group_id'])->toBeNull()->and($proof->reversal_request_id)->toBe($reversal->id);
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
    $this->assertDatabaseCount('fee_refunds', 1);
    $this->get(route('collection-batches.show', $receipt->batch))->assertInertia(fn (Assert $page) => $page
        ->has('resolution_records.data', 1)->where('resolution_records.data.0.reversal_id', $reversal->reversal_id));
});

test('original exception identity amount and history cannot be edited or deleted once recorded', function (): void {
    [, , , , $exception] = collectionInvestigationFixture($this);
    foreach (['kind' => 'overage', 'amount_kobo' => 1, 'reason' => 'Rewrite the original facts.'] as $field => $value) {
        expect(fn () => $exception->fresh()->update([$field => $value]))->toThrow(RuntimeException::class, 'Original collection exception facts are immutable.');
    }
    expect(fn () => $exception->fresh()->delete())->toThrow(RuntimeException::class, 'Collection exception history cannot be deleted.');
    $this->assertDatabaseCount('collection_exceptions', 1);
    $this->assertDatabaseCount('collection_exception_events', 1);
});

test('cold resolution evidence is required for reconciliation history archival and offboarding', function (string $damage): void {
    [$customer, , $receipt, $exception, , $agent] = matchedTransferExceptionFixture($this);
    $this->post(route('collection-batches.exceptions.resolve', [$receipt->batch, $exception]), [
        'batch_version' => $receipt->batch->fresh()->version, 'resolution_kind' => 'verified_match',
        'receipt_reference' => $receipt->receipt_reference, 'reason' => 'Original bank evidence independently matches the complete receipt.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $proof = FinancialWorkflowSupplement::query()->sole();
    $batch = $receipt->batch->fresh();
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    $review = ['batch_version' => $batch->version, 'reason' => 'Verified original payment and resolved case permit batch closure.', 'confirmed' => true];
    if ($damage === 'file') {
        Storage::disk('collection_evidence')->delete(DB::table('collection_evidence_files')->value('storage_path'));
    } elseif ($damage === 'snapshot') {
        DB::table('financial_workflow_supplements')->where('id', $proof->id)->update(['facts' => json_encode([...$proof->facts, 'outstanding_kobo' => 1], JSON_THROW_ON_ERROR)]);
    } elseif ($damage === 'cause') {
        DB::table('financial_workflow_supplements')->where('id', $proof->id)->update(['evidence' => 'Unreadable encrypted cause']);
    } elseif ($damage === 'event') {
        DB::table('collection_exception_events')->where('collection_exception_id', $exception->id)->where('event_type', 'resolved')->delete();
    } elseif ($damage === 'audit') {
        DB::statement('DROP TRIGGER canonical_audit_events_no_update');
        DB::table('canonical_audit_events')->where('event_type', 'collection.exception_resolved')->update(['content_hash' => str_repeat('0', 64)]);
    }
    $status = $damage === 'file' ? 503 : 409;
    $this->get(route('collection-batches.show', $batch))->assertStatus($status);
    $this->post(route('collection-batches.review', $batch), $review)->assertStatus($status);
    expect($batch->fresh()->status)->toBe('exception');
    $this->assertDatabaseCount('collection_batch_reviews', 0);
    DB::table('collection_batches')->where('id', $batch->id)->update(['status' => 'reconciled']);
    DB::table('collection_batch_reviews')->insert([
        'collection_batch_id' => $batch->id, 'reviewed_by_user_id' => $proof->actor_user_id,
        'batch_version' => $batch->version, 'outcome' => 'reconciled', 'expected_kobo' => 200000,
        'remitted_kobo' => 200000, 'outstanding_kobo' => 0, 'reason' => 'Unsupported closure label.', 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(app(CollectionReadService::class)->archivalStatus($customer))->toBe('unavailable');
    expect(app(CollectionReadService::class)->agentOffboardingStatus($agent->agentProfile))->toBe('unavailable');
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
})->with(['lost protected file' => 'file', 'modified custody snapshot' => 'snapshot', 'unreadable protected cause' => 'cause', 'lost resolved event' => 'event', 'modified approval audit' => 'audit']);

test('a reopened matched exception retains readable historical resolution and requires another closure', function (): void {
    [, , $receipt, $exception] = matchedTransferExceptionFixture($this);
    $resolve = ['resolution_kind' => 'verified_match', 'receipt_reference' => $receipt->receipt_reference,
        'reason' => 'Original verified payment matches the complete receipt.', 'confirmed' => true];
    $this->post(route('collection-batches.exceptions.resolve', [$receipt->batch, $exception]), [...$resolve, 'batch_version' => $receipt->batch->fresh()->version])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('collection-batches.exceptions.reopen', [$receipt->batch, $exception]), [
        'batch_version' => $receipt->batch->fresh()->version, 'reason' => 'New contradictory reference requires another independent review.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('collection-batches.show', $receipt->batch))->assertInertia(fn (Assert $page) => $page
        ->has('resolution_records.data', 1)->where('exceptions.data.0.status', 'reopened'));
    $this->post(route('collection-batches.review', $receipt->batch), [
        'batch_version' => $receipt->batch->fresh()->version, 'reason' => 'Reopened investigation remains outstanding.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($receipt->batch->fresh()->status)->toBe('exception');
    $this->post(route('collection-batches.exceptions.resolve', [$receipt->batch, $exception]), [...$resolve, 'batch_version' => $receipt->batch->fresh()->version])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('collection-batches.review', $receipt->batch), [
        'batch_version' => $receipt->batch->fresh()->version, 'reason' => 'Both durable resolutions and current verified payment reconcile.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($receipt->batch->fresh()->status)->toBe('reconciled');
    $this->get(route('collection-batches.show', $receipt->batch))->assertInertia(fn (Assert $page) => $page->has('resolution_records.data', 2));
});

test('a resolved status without a recorded owner outcome cannot close a batch', function (): void {
    [, , $receipt, $exception] = matchedTransferExceptionFixture($this);
    DB::table('collection_exceptions')->where('id', $exception->id)->update(['status' => 'resolved']);
    $this->post(route('collection-batches.review', $receipt->batch), [
        'batch_version' => $receipt->batch->fresh()->version, 'reason' => 'A label alone does not prove resolution.', 'confirmed' => true,
    ])->assertConflict();
    expect($receipt->batch->fresh()->status)->toBe('exception');
    $this->assertDatabaseCount('collection_batch_reviews', 0);
    $this->assertDatabaseCount('financial_workflow_supplements', 0);
});

function exceptionResolutionFinancialRows(): array
{
    $rows = [];
    foreach (['collection_receipts', 'collection_allocations', 'collection_fee_components', 'ledger_posting_groups',
        'ledger_entries', 'fee_obligations', 'fee_obligation_entries', 'cash_remittances', 'collection_settlements',
        'collection_notification_intents', 'fee_refunds', 'reversal_requests'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

function exceptionResolutionMethodReceipt(object $test, string $method, string $custody, int $feeKobo = 0): array
{
    if ($method !== 'cash') {
        [$agent, $customer, $assignment, , , $admin, , $payload] = noncashFixture($test, $method, $custody, $feeKobo);
        $receipt = postNoncashReceipt($test, $customer, $payload);
    } else {
        config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
        [$agent, $customer, $assignment, $plan, $date] = collectionFixture(3, feeAmountKobo: $feeKobo, feeSource: FeeSettlementSource::ExternalReceipt);
        $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
        if ($feeKobo > 0) {
            $fee = app(FeeObligationService::class)->assessSnapshot($plan->currentTermsRevision()->feeSnapshot, $agent);
            $payload['fees'] = [['obligation_id' => $fee->id, 'amount_ngn' => '500.00']];
        }
        $test->actingAs($agent);
        $receipt = postNoncashReceipt($test, $customer, $payload);
        $admin = User::factory()->admin()->create();
        $admin->givePermissionTo(AdminPermission::ReconciliationManage);
    }
    $test->travel(1)->days();
    $test->artisan('collections:freeze-batches')->assertSuccessful();
    $test->travelBack();
    $test->actingAs($admin);

    return [$agent, $customer, $assignment, $admin, $receipt];
}

test('cross-method overage investigation cannot redirect savings fees or custody through unsupported resolution', function (string $method, string $custody): void {
    [, $customer, , , $receipt] = exceptionResolutionMethodReceipt($this, $method, $custody, 50000);
    $financial = exceptionResolutionFinancialRows();
    $position = app(CollectionBatchPosition::class)->read($receipt->batch);
    $this->post(route('collection-batches.exceptions.store', $receipt->batch), [
        'batch_version' => $receipt->batch->fresh()->version, 'kind' => 'overage', 'amount_ngn' => '500.00',
        'reason' => 'Additional custody has no approved matched receipt or allocation.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $exception = CollectionException::query()->sole();
    $batch = $receipt->batch->fresh();
    $case = $exception->getAttributes();
    $events = DB::table('collection_exception_events')->orderBy('id')->get()->all();
    $audit = DB::table('canonical_audit_events')->orderBy('id')->get()->all();
    $route = route('collection-batches.exceptions.resolve', [$batch, $exception]);
    $data = ['batch_version' => $batch->version, 'receipt_reference' => $receipt->receipt_reference,
        'reason' => 'Unsupported overage settlement cannot assign another Customer credit or fee.', 'confirmed' => true];

    foreach (['remittance', 'verified_match'] as $kind) {
        $this->post($route, [...$data, 'resolution_kind' => $kind])->assertConflict();
        expect($exception->fresh()->getAttributes())->toBe($case);
        expect($batch->fresh()->getAttributes())->toBe($batch->getAttributes());
        expect(exceptionResolutionFinancialRows())->toEqual($financial);
    }
    foreach (['write_off', 'manual_adjustment', 'suspense_to_income'] as $kind) {
        $this->post($route, [...$data, 'resolution_kind' => $kind])->assertSessionHasErrors('resolution_kind');
        expect(exceptionResolutionFinancialRows())->toEqual($financial);
    }
    expect(DB::table('collection_exception_events')->orderBy('id')->get()->all())->toEqual($events);
    expect(DB::table('canonical_audit_events')->orderBy('id')->get()->all())->toEqual($audit);
    expect(app(CollectionBatchPosition::class)->read($batch))->toBe($position);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
    $this->assertDatabaseCount('financial_workflow_supplements', 0);
    $review = ['batch_version' => $batch->version, 'reason' => 'The unallocated overage remains unresolved.', 'confirmed' => true];
    if ($method === 'pos') {
        $this->post(route('collection-batches.review', $batch), $review)->assertConflict();
        $this->assertDatabaseCount('collection_batch_reviews', 0);
    } else {
        $this->post(route('collection-batches.review', $batch), $review)->assertRedirect()->assertSessionHasNoErrors();
    }
    expect($batch->fresh()->status)->toBe('exception');
    expect($exception->fresh()->status)->toBe('open');
    expect(exceptionResolutionFinancialRows())->toEqual($financial);
})->with(['cash custody' => ['cash', 'agent_receivable_ngn'], 'bank transfer' => ['transfer', 'business_bank_ngn'], 'POS clearing' => ['pos', 'payment_clearing_ngn'], 'configured other custody' => ['other', 'agent_receivable_ngn']]);

test('cross-method overage cannot reuse an actual approved receipt correction as settlement authority', function (string $method, string $custody): void {
    [$agent, $customer, $assignment, $admin, $receipt] = exceptionResolutionMethodReceipt($this, $method, $custody);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $reversal = approveReceiptCorrection($this, $agent, $customer, $assignment, LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id))->fresh();
    expect($reversal->state)->toBe('approved_posted');
    $this->actingAs($admin)->post(route('collection-batches.exceptions.store', $receipt->batch), [
        'batch_version' => $receipt->batch->fresh()->version, 'kind' => 'overage', 'amount_ngn' => '500.00',
        'reason' => 'Independent additional custody remains unattributed after the recording correction.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $exception = CollectionException::query()->sole();
    $financial = exceptionResolutionFinancialRows();
    $supplements = DB::table('financial_workflow_supplements')->orderBy('id')->get()->all();
    $batch = $receipt->batch->fresh();
    $events = DB::table('collection_exception_events')->orderBy('id')->get()->all();

    $this->post(route('collection-batches.exceptions.resolve', [$batch, $exception]), [
        'batch_version' => $batch->version, 'resolution_kind' => 'approved_correction',
        'receipt_reference' => $receipt->receipt_reference, 'reversal_id' => $reversal->reversal_id,
        'reason' => 'An approved recording correction cannot forgive unrelated overage.', 'confirmed' => true,
    ])->assertConflict();

    expect($exception->fresh()->status)->toBe('open');
    expect($batch->fresh()->getAttributes())->toBe($batch->getAttributes());
    expect(DB::table('collection_exception_events')->orderBy('id')->get()->all())->toEqual($events);
    expect(exceptionResolutionFinancialRows())->toEqual($financial);
    expect(DB::table('financial_workflow_supplements')->orderBy('id')->get()->all())->toEqual($supplements);
    $this->assertDatabaseMissing('financial_workflow_supplements', ['kind' => 'collection_exception_resolution']);
})->with(['cash custody' => ['cash', 'agent_receivable_ngn'], 'bank transfer' => ['transfer', 'business_bank_ngn'], 'POS clearing' => ['pos', 'payment_clearing_ngn'], 'configured other custody' => ['other', 'agent_receivable_ngn']]);

test('reopened POS payment matches retain both resolutions and cannot replace bank settlement', function (): void {
    [, , $receipt, $exception] = matchedTransferExceptionFixture($this, method: 'pos', custody: 'payment_clearing_ngn');
    $financial = exceptionResolutionFinancialRows();
    $resolve = ['resolution_kind' => 'verified_match', 'receipt_reference' => $receipt->receipt_reference,
        'reason' => 'Independent original POS payment proof matches the complete capture.', 'confirmed' => true];
    $this->post(route('collection-batches.exceptions.resolve', [$receipt->batch, $exception]), [...$resolve, 'batch_version' => $receipt->batch->fresh()->version])
        ->assertRedirect()->assertSessionHasNoErrors();
    $originalProof = FinancialWorkflowSupplement::query()->sole()->getAttributes();
    $this->post(route('collection-batches.exceptions.reopen', [$receipt->batch, $exception]), [
        'batch_version' => $receipt->batch->fresh()->version, 'reason' => 'A new contradictory reference requires renewed payment investigation.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('collection-batches.show', $receipt->batch))->assertInertia(fn (Assert $page) => $page
        ->has('resolution_records.data', 1)->where('exceptions.data.0.status', 'reopened'));
    $this->post(route('collection-batches.exceptions.resolve', [$receipt->batch, $exception]), [...$resolve, 'batch_version' => $receipt->batch->fresh()->version])
        ->assertRedirect()->assertSessionHasNoErrors();

    $this->post(route('collection-batches.review', $receipt->batch), [
        'batch_version' => $receipt->batch->fresh()->version, 'reason' => 'Verified capture is not confirmed bank settlement.', 'confirmed' => true,
    ])->assertConflict();
    expect($exception->fresh()->status)->toBe('resolved');
    expect($receipt->batch->fresh()->status)->toBe('exception');
    expect(FinancialWorkflowSupplement::query()->oldest('id')->firstOrFail()->getAttributes())->toBe($originalProof);
    expect(app(CollectionBatchPosition::class)->read($receipt->batch))->toBe([
        'expected_kobo' => 200000, 'received_kobo' => 0, 'outstanding_kobo' => 200000, 'settlement_pending' => true,
    ]);
    expect(exceptionResolutionFinancialRows())->toEqual($financial);
    $this->assertDatabaseCount('collection_batch_reviews', 0);
    $this->get(route('collection-batches.show', $receipt->batch))->assertInertia(fn (Assert $page) => $page->has('resolution_records.data', 2));
});

test('exception transitions notify the original Agent and other reconciliation managers but not the actor', function (): void {
    [, $admin, $receipt, $exception, , $agent] = matchedTransferExceptionFixture($this);
    $peer = User::factory()->admin()->create();
    $peer->givePermissionTo(AdminPermission::ReconciliationManage);
    $outsider = User::factory()->admin()->create();
    $this->actingAs($admin)->post(route('collection-batches.exceptions.progress', [$receipt->batch, $exception]), [
        'batch_version' => $receipt->batch->fresh()->version, 'status' => 'investigating',
        'reason' => 'Matching the bank statement line.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($agent)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
        ->has('inbox.items', 2)->where('inbox.items', fn ($items) => collect($items)->pluck('title')->sort()->values()->all()
            === ['Reconciliation exception opened', 'Reconciliation exception updated']));
    $this->actingAs($peer)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page->has('inbox.items', 1)
        ->where('inbox.items.0.title', 'Reconciliation exception updated'));
    $this->actingAs($admin)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page->has('inbox.items', 0));
    $this->actingAs($outsider)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page->has('inbox.items', 0));

    $peer->revokePermissionTo(AdminPermission::ReconciliationManage);
    $this->actingAs($peer->fresh())->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page->has('inbox.items', 0));
    expect(DB::table('notifications')->where('notifiable_id', $agent->id)->pluck('data')->implode(' '))
        ->not->toContain('statement line', 'Matching');
});
