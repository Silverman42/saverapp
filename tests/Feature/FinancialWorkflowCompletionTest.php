<?php

use App\Enums\AdminPermission;
use App\Enums\FeeObligationEntryType;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Enums\LedgerAccountCode;
use App\Enums\ThriftPlanStatus;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CashDisbursement;
use App\Models\CashExecution;
use App\Models\CashRecovery;
use App\Models\ChargeCategoryVersion;
use App\Models\CollectionBatch;
use App\Models\CollectionException;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeRefund;
use App\Models\FeeRule;
use App\Models\FinancialPeriod;
use App\Models\FinancialWorkflowSupplement;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\ManualCharge;
use App\Models\PlanLifecycleEvent;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\CashRecoveryService;
use App\Services\CollectionBatchPosition;
use App\Services\CollectionReadService;
use App\Services\CollectionReplacementService;
use App\Services\CollectionReversalOwner;
use App\Services\CollectionService;
use App\Services\FeeConcessionPosition;
use App\Services\FeeObligationService;
use App\Services\FeeRefundService;
use App\Services\FinancialCashPosition;
use App\Services\FinancialReleaseEvidenceService;
use App\Services\FinancialWorkflowReadService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\NotificationPipeline;
use App\Services\PlanFeeCorrectionService;
use App\Services\PlanFinancialActivityReadService;
use App\Services\PlanSettlementService;
use App\Services\ReversalService;
use App\Services\ThriftPlanService;
use App\Services\WithdrawalReversalOwner;
use App\Services\WithdrawalService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\CreatesLifecycleCustomers;

require_once __DIR__.'/../ManualChargeFixtures.php';
require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../ReversalFixtures.php';
require_once __DIR__.'/../CashExecutionFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';
require_once __DIR__.'/../NoncashCollectionFixtures.php';

uses(CreatesLifecycleCustomers::class);

function percentageReconcileCash(object $test, User $admin, CollectionBatch $batch, string $amount, string $date): void
{
    $test->travel(1)->days();
    $test->artisan('collections:freeze-batches')->assertSuccessful();
    $test->travelBack();
    $test->actingAs($admin)->withSession(cashSession())->post(route('collection-batches.remittances.store', $batch), [
        'batch_version' => $batch->fresh()->version, 'handoff_reference' => 'PERCENTAGE-CYCLE-'.$batch->id, 'amount_ngn' => $amount,
        'handoff_date' => $date, 'receiving_location' => 'Business till', 'source_attestation' => 'Counted original Customer tender.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $test->post(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'Independent review matches posted original cash custody.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($batch->fresh()->status)->toBe('reconciled');
}

function percentageCashPayout(object $test, User $admin, User $agent, CustomerProfile $customer, ThriftPlan $plan, string $amount, string $type): CashExecution
{
    app(LedgerTransactionProjectionService::class)->rebuild();
    $owner = app(WithdrawalService::class);
    $instruction = [...withdrawalPayload($customer, $customer->currentAssignment, $plan), 'gross_ngn' => $amount, 'type' => $type];
    $quote = $owner->preview($agent, $customer->fresh(), $instruction);
    expect($quote['fee_kobo'])->toBe(0);
    $withdrawal = $owner->submit($agent, $customer, [...$instruction, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'], 'instruction_attested' => true, 'confirmed' => true]);
    $test->actingAs($admin)->withSession(cashSession())->post(route('withdrawals.approve', $withdrawal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->version, 'confirmed' => true, 'decision_note' => 'Reviewed original cycle funds.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $test->post(route('withdrawals.cash.start', $withdrawal), ['execution_reference' => (string) Str::uuid(),
        'version' => $withdrawal->fresh()->version, 'evidence' => 'Verified Customer at business till.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    $execution = CashExecution::query()->where('withdrawal_request_id', $withdrawal->id)->sole();
    $test->post(route('cash-executions.handoff', $execution), ['evidence' => 'Counted the exact net payout to Customer.', 'confirmed' => true])->assertRedirect();
    $test->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    expect($execution->fresh()->status)->toBe('posted');

    return $execution->fresh();
}

beforeEach(function (): void {
    config()->set('collections.settlement_enabled', true);
});

function controlledReplacementFixture(object $test): array
{
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(2);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $payload);
    LedgerAccount::query()->where('code', LedgerAccountCode::UnappliedFunds)->update(['mapping_status' => 'mapped']);
    $reversal = approveReceiptCorrection($test, $agent, $customer, $assignment, LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id))->fresh();

    return [$agent, $customer, $assignment, $plan->fresh(), $date, $receipt, $reversal];
}

test('controlled replacement consumes tender once without increasing original custody or batch receipts', function (): void {
    [$agent, $customer, $assignment, $plan, $date, $original, $reversal] = controlledReplacementFixture($this);
    $data = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $service = app(CollectionReplacementService::class);
    $quote = $service->preview($agent, $reversal, $data);
    $data['preview_fingerprint'] = $quote['preview_fingerprint'];
    $data['replacement_fingerprint'] = $quote['replacement_fingerprint'];
    $replacement = $service->record($agent, $reversal, $data);
    expect($service->record($agent, $reversal, $data)->id)->toBe($replacement->id)
        ->and($replacement->recording_agent_profile_id)->toBe($original->recording_agent_profile_id)
        ->and((int) $original->batch->receipts()->sum('tender_amount_kobo'))->toBe(200000)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
    app(LedgerTransactionProjectionService::class)->rebuild();
    expect(DB::table('ledger_transaction_projections')->where('type', 'replacement')->exists())->toBeTrue();
    $this->assertDatabaseCount('collection_receipts', 2);
    $this->assertDatabaseCount('financial_workflow_supplements', 2);
    expect(fn () => $service->record($agent, $reversal, [...$data, 'notes' => 'changed']))->toThrow(ConflictHttpException::class);
});

test('replacement cannot consume a changed tender or another Customer funds', function (): void {
    [$agent, $customer, $assignment, $plan, $date, , $reversal] = controlledReplacementFixture($this);
    $data = collectionPayload($customer, $assignment, $plan, $date, '1000.00');
    expect(fn () => app(CollectionReplacementService::class)->preview($agent, $reversal, $data))->toThrow(ConflictHttpException::class, 'exact original');
    $this->assertDatabaseCount('collection_receipts', 1);
});

test('correcting and replacing a replacement retains one physical tender and original custody', function (): void {
    [$agent, $customer, $assignment, $plan, $date, $original, $reversal] = controlledReplacementFixture($this);
    $service = app(CollectionReplacementService::class);
    $data = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $quote = $service->preview($agent, $reversal, $data);
    $replacement = $service->record($agent, $reversal, [...$data,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'replacement_fingerprint' => $quote['replacement_fingerprint']]);
    $correction = approveReceiptCorrection($this, $agent, $customer, $assignment,
        LedgerPostingGroup::findOrFail($replacement->savings_posting_group_id))->fresh();
    expect($correction->state)->toBe('approved_posted');
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::UnappliedFunds))->toBe(200000);
    expect((int) DB::table('ledger_entries')->whereIn('ledger_account_id', LedgerAccount::query()
        ->where('code', LedgerAccountCode::AgentReceivable)->select('id'))->where('side', 'debit')->sum('amount_kobo'))->toBe(200000);
    $data = collectionPayload($customer, $assignment, $plan->fresh(), $date, '2000.00');
    $quote = $service->preview($agent, $correction, $data);
    $data = [...$data, 'preview_fingerprint' => $quote['preview_fingerprint'], 'replacement_fingerprint' => $quote['replacement_fingerprint']];
    $next = $service->record($agent, $correction, $data);
    expect($service->record($agent, $correction, $data)->id)->toBe($next->id);
    expect($next->recording_agent_profile_id)->toBe($original->recording_agent_profile_id);
    expect((int) $original->batch->receipts()->sum('tender_amount_kobo'))->toBe(200000);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::UnappliedFunds))->toBe(0);
    $this->assertDatabaseCount('collection_receipts', 3);
    $this->assertDatabaseCount('collection_allocation_releases', 2);
    app(LedgerTransactionProjectionService::class)->rebuild();
});

test('correcting replacement fee tender restores its obligation without duplicate physical receipts', function (string $savings): void {
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(2);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 500);
    $data = collectionPayload($customer, $assignment, $plan, $date, $savings);
    if ($savings === '0.00') {
        $data['plan_id'] = null;
        $data['plan_version'] = null;
    }
    $data['fees'] = [['obligation_id' => $fee->id, 'amount_ngn' => '5.00']];
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    $original = app(CollectionService::class)->record($agent, $customer, $data);
    $groupId = $original->savings_posting_group_id ?? DB::table('collection_fee_components')
        ->where('collection_receipt_id', $original->id)->value('ledger_posting_group_id');
    $correction = approveReceiptCorrection($this, $agent, $customer, $assignment, LedgerPostingGroup::findOrFail($groupId))->fresh();
    $data = [...$data, 'attempt_reference' => (string) Str::uuid(), 'plan_version' => $savings === '0.00' ? null : $plan->fresh()->version];
    $service = app(CollectionReplacementService::class);
    $quote = $service->preview($agent, $correction, $data);
    $replacement = $service->record($agent, $correction, [...$data,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'replacement_fingerprint' => $quote['replacement_fingerprint']]);
    $groupId = $replacement->savings_posting_group_id ?? DB::table('collection_fee_components')
        ->where('collection_receipt_id', $replacement->id)->value('ledger_posting_group_id');
    $next = approveReceiptCorrection($this, $agent, $customer, $assignment, LedgerPostingGroup::findOrFail($groupId))->fresh();
    expect($next->state)->toBe('approved_posted');
    expect($fee->fresh()->outstandingAmountKobo())->toBe(500);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::UnappliedFunds))->toBe($original->tender_amount_kobo);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome))->toBe(0);
    expect((int) $original->batch->receipts()->sum('tender_amount_kobo'))->toBe($original->tender_amount_kobo);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $this->assertDatabaseCount('collection_receipts', 2);
})->with(['mixed savings and fees' => '2000.00', 'fee-only' => '0.00']);

test('settlement cannot close a funded cycle with unresolved liability or custody', function (): void {
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(1);
    $data = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $data);
    $plan->refresh();
    $service = app(PlanSettlementService::class);
    $preview = $service->preview($agent, $plan);
    expect($preview['can_close'])->toBeFalse();
    expect(fn () => $service->confirm($agent, $plan, 'close', ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $preview['preview_fingerprint'],
        'reason' => 'Attempt closure', 'customer_explanation' => 'Close cycle.']))->toThrow(ConflictHttpException::class, 'settlement gates');
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Completed);
});

test('partial posted cash returns credit cash once while savings remain unchanged until full reviewed compensation', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Exact cash handed over.', 'confirmed' => true])->assertRedirect();
    $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    $request = Request::create('/');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put(cashSession());
    $service = app(CashRecoveryService::class);
    $return = $service->recordReturn($admin, $execution->fresh(), (string) Str::uuid(), 'First counted partial return.', $request, 10000);
    $service->acknowledgeReturn($customer->user, $return);
    $service->acknowledgeReturn($customer->user, $return);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::BusinessCash))->toBe(80600)
        ->and(app(FinancialCashPosition::class)->balance(LedgerAccountCode::CashRecoveryClearing))->toBe(10000)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(70000);
    expect(fn () => app(WithdrawalReversalOwner::class)->preview(LedgerPostingGroup::findOrFail($execution->fresh()->ledger_posting_group_id), $customer, false))
        ->toThrow(ConflictHttpException::class, 'full return');
    app(LedgerTransactionProjectionService::class)->rebuild();
    $metrics = collect(app(FinancialWorkflowReadService::class)->activity($admin, CustomerProfile::query()->whereKey($customer->id), [], now()->addSecond()->toDateTimeString())['metrics'])->keyBy('code');
    expect($metrics['gross_withdrawals']['value'])->toBe(30000)
        ->and($metrics['withdrawal_cash_returns']['value'])->toBe(10000)
        ->and($metrics['effective_withdrawal_cash_paid']['value'])->toBe(19400)
        ->and($metrics['effective_withdrawal_debits']['value'])->toBe(30000);
    $beforeRead = DB::table('ledger_entries')->orderBy('id')->get()->all();
    $cycleActivity = app(PlanFinancialActivityReadService::class)->read($customer->user, $plan->fresh());
    expect($cycleActivity['status'])->toBe('available');
    $cycleMetrics = collect($cycleActivity['metrics'])->keyBy('code');
    expect($cycleMetrics['gross_withdrawals']['value'])->toBe(30000)
        ->and($cycleMetrics['net_cash_payouts']['value'])->toBe(29400)
        ->and($cycleMetrics['withdrawal_fees']['value'])->toBe(600)
        ->and($cycleMetrics['withdrawal_cash_returns']['value'])->toBe(10000)
        ->and($cycleMetrics['effective_withdrawal_cash_paid']['value'])->toBe(19400)
        ->and($cycleMetrics['withdrawal_compensation']['value'])->toBe(0)
        ->and(DB::table('ledger_entries')->orderBy('id')->get()->all())->toEqual($beforeRead);
    $this->assertDatabaseCount('cash_recoveries', 1);
});

test('first contribution fee application is compensated and reactivated once by a controlled replacement', function (): void {
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(2, feeAmountKobo: 10000);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $data = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $data);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(190000);
    $reversal = approveReceiptCorrection($this, $agent, $customer, $assignment, LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id))->fresh();
    $fee = $plan->currentTermsRevision()->feeSnapshot->obligation;
    expect($fee->fresh()->assessedAmountKobo())->toBe(0)->and($fee->fresh()->settledAmountKobo())->toBe(0)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
    $data = collectionPayload($customer, $assignment, $plan->fresh(), $date, '2000.00');
    $quote = app(CollectionReplacementService::class)->preview($agent, $reversal, $data);
    $data['preview_fingerprint'] = $quote['preview_fingerprint'];
    $data['replacement_fingerprint'] = $quote['replacement_fingerprint'];
    app(CollectionReplacementService::class)->record($agent, $reversal, $data);
    expect($fee->fresh()->assessedAmountKobo())->toBe(10000)->and($fee->fresh()->settledAmountKobo())->toBe(10000)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(190000);
    app(LedgerTransactionProjectionService::class)->rebuild();
});

test('separate early termination preparation and settled closure post no money and resolve original attempt', function (): void {
    [$agent, $customer, , $plan] = collectionFixture();
    $service = app(PlanSettlementService::class);
    $quote = $service->preview($agent, $plan, 'prepare_termination');
    $data = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'], 'reason' => 'Customer ends the unused agreement.', 'customer_explanation' => 'No funds or obligations remain.'];
    $service->confirm($agent, $plan, 'prepare_termination', $data);
    $quote = $service->preview($agent, $plan->fresh());
    $data = [...$data, 'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint']];
    $service->confirm($agent, $plan, 'close', $data);
    $service->confirm($agent, $plan, 'close', $data);
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Closed)->and($plan->fresh()->open_customer_profile_id)->toBeNull();
    $this->assertDatabaseCount('ledger_posting_groups', 0);
    $this->assertDatabaseCount('financial_workflow_supplements', 2);
});

test('settlement HTTP actions require current scope and reject stale previews without changing the cycle', function (): void {
    [$agent, $customer, , $plan] = collectionFixture();
    $payload = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => str_repeat('0', 64), 'reason' => 'Early termination agreed.', 'customer_explanation' => 'Separate settlement follows.', 'confirmed' => true];
    $this->actingAs($agent)->postJson(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'prepare_termination']), $payload)->assertConflict();
    $quote = app(PlanSettlementService::class)->preview($agent, $plan, 'prepare_termination');
    $this->post(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'prepare_termination']), [...$payload, 'preview_fingerprint' => $quote['preview_fingerprint']])->assertRedirect();
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Paused);
    $this->actingAs(User::factory()->agent()->create())->get(route('plans.settlement', $plan))->assertForbidden();
});

test('owner release evidence is versioned encrypted and invalidated by mapping changes without enabling flags', function (): void {
    [$admin] = cashPaymentFixture();
    $admin->givePermissionTo(AdminPermission::BusinessSettingsManage);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    config()->set('app.financial_release_revision', 'test-release-1');
    $service = app(FinancialReleaseEvidenceService::class);
    expect($service->check('withdrawal_cash')['state'])->toBe('Unavailable');
    foreach ($service::ROLES as $role) {
        $data = ['capability' => 'withdrawal_cash', 'owner_role' => $role, 'version' => 1, 'state' => 'accepted', 'dependency_hash' => $service->dependencyHash(), 'valid_until' => now()->addDay()->toIso8601String(), 'evidence' => 'TEST FIXTURE owner attestation with retained acceptance reference.'];
        $id = $service->record($admin, $data);
        expect($service->record($admin, $data))->toBe($id);
        expect(fn () => $service->record($admin, [...$data, 'evidence' => 'changed']))->toThrow(ConflictHttpException::class);
    }
    expect($service->check('withdrawal_cash')['state'])->toBe('Ready to enable');
    expect(DB::table('financial_release_evidence')->value('evidence'))->not->toContain('TEST FIXTURE');
    $evidence = DB::table('financial_release_evidence')->first();
    DB::table('financial_release_evidence')->where('id', $evidence->id)->update(['evidence' => 'corrupted ciphertext']);
    expect($service->check('withdrawal_cash')['state'])->toBe('Unavailable');
    DB::table('financial_release_evidence')->where('id', $evidence->id)->update(['evidence' => $evidence->evidence]);
    expect($service->check('withdrawal_cash')['state'])->toBe('Ready to enable');
    LedgerAccount::query()->where('code', LedgerAccountCode::BusinessCash)->increment('version');
    expect($service->check('withdrawal_cash')['state'])->toBe('Unavailable');
});

test('partial return evidence owns an uncertain attempt and blocks payout acknowledgement and another start', function (): void {
    [$admin, $customer, , $withdrawal] = cashPaymentFixture();
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Original handoff uncertain.', 'confirmed' => true])->assertRedirect();
    $quote = app(CashRecoveryService::class)->preview($admin, $execution->fresh());
    $data = ['preview_fingerprint' => $quote['preview_fingerprint'], 'recovery_reference' => (string) Str::uuid(), 'amount_ngn' => '10.00', 'evidence' => 'Counted partial return only.', 'confirmed' => true];
    $this->withSession(cashSession())->post(route('cash-executions.return', $execution), $data)->assertRedirect();
    $this->post(route('cash-executions.return', $execution), $data)->assertRedirect();
    $this->postJson(route('cash-executions.return', $execution), [...$data, 'recovery_reference' => (string) Str::uuid()])->assertConflict();
    $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertConflict();
    $returned = CashRecovery::query()->sole();
    $this->post(route('cash-recoveries.acknowledge', $returned), ['confirmed' => true])->assertRedirect();
    expect($execution->fresh()->status)->toBe('outcome_unknown');
    $this->assertDatabaseHas('withdrawal_reservations', ['status' => 'live']);
    $this->assertDatabaseMissing('ledger_posting_groups', ['source_type' => 'cash_recovery']);
});

test('replacement owner event failure rolls back funds consumption and recovers the same operation', function (): void {
    [$agent, $customer, $assignment, $plan, $date, , $reversal] = controlledReplacementFixture($this);
    $data = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $service = app(CollectionReplacementService::class);
    $quote = $service->preview($agent, $reversal, $data);
    $data['preview_fingerprint'] = $quote['preview_fingerprint'];
    $data['replacement_fingerprint'] = $quote['replacement_fingerprint'];
    $count = LedgerPostingGroup::query()->count();
    $eventName = 'eloquent.creating: '.FinancialWorkflowSupplement::class;
    Event::listen($eventName, function (): never {
        throw new RuntimeException('Injected owner event failure.');
    });
    try {
        expect(fn () => $service->record($agent, $reversal, $data))->toThrow(RuntimeException::class, 'Injected owner');
    } finally {
        Event::forget($eventName);
    }
    expect(LedgerPostingGroup::query()->count())->toBe($count);
    $this->assertDatabaseCount('collection_receipts', 1);
    $service->record($agent, $reversal, $data);
    $this->assertDatabaseCount('collection_receipts', 2);
});

test('TPC-AC-040: monetary early termination returns 503 for legacy agreements without the disclosed approved policy', function (bool $paused): void {
    $this->freezeTime();
    Queue::fake();
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(2, feeAmountKobo: 10000, feeTiming: FeeRuleTiming::CycleCompletion);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $data = collectionPayload($customer, $assignment, $plan, $date, '1000.00');
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $data);
    if ($paused) {
        app(ThriftPlanService::class)->transition($agent, $plan->fresh(), 'pause', (string) Str::uuid(), [
            'customer_version' => $customer->fresh()->version, 'assignment_version' => $assignment->version,
            'plan_version' => $plan->fresh()->version, 'reason' => 'Customer requested a collection pause.',
            'customer_explanation' => 'Original dated expectations remain while collection is paused.',
        ]);
    }
    expect($plan->fresh()->status)->toBe($paused ? ThriftPlanStatus::Paused : ThriftPlanStatus::Active)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000)
        ->and($plan->currentTermsRevision()->feeSnapshot->obligation)->toBeNull();
    $before = array_diff_key(cycleClosureFeeRows(), array_flip(['audit_events', 'canonical_audit_events']));
    foreach (['collection_batches', 'collection_fee_components', 'collection_notification_intents', 'withdrawal_requests',
        'withdrawal_reservations', 'fee_application_notification_intents'] as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $preview = $this->actingAs($agent)->get(route('plans.settlement', ['plan' => $plan, 'action' => 'prepare_termination']));
    $fingerprint = str_repeat('0', 64);
    if ($preview->status() === 200) {
        $fingerprint = app(PlanSettlementService::class)->preview($agent, $plan->fresh(), 'prepare_termination')['preview_fingerprint'];
    }
    $prepare = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $fingerprint,
        'reason' => 'Customer requests an early settlement review.',
        'customer_explanation' => 'No early fee is agreed without its approved authoritative policy.', 'confirmed' => true];
    $old = $this->postJson(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'prepare_termination']), $prepare);
    $fresh = $this->postJson(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'prepare_termination']),
        [...$prepare, 'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => str_repeat('1', 64)]);
    expect($plan->currentTermsRevision()->feeSnapshot->obligation()->exists())->toBeFalse();
    $preview->assertServiceUnavailable();
    $old->assertServiceUnavailable();
    $fresh->assertServiceUnavailable();
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
})->with(['Active funded cycle' => [false], 'Paused funded cycle' => [true]]);

test('completed receipt correction preserves completion history and corrects only the fee whose trigger is lost before reviewed resume', function (FeeRuleTiming $timing, int $remainingFee, bool $percentage = false): void {
    $this->freezeTime();
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    if ($percentage) {
        [$admin, $customer, $profile] = $this->createLifecycleFixture();
        $agent = $profile->user;
        $assignment = $customer->currentAssignment;
        $date = now('Africa/Lagos')->toDateString();
        FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
        LedgerAccount::query()->update(['mapping_status' => 'mapped']);
        $admin->givePermissionTo([AdminPermission::WithdrawalsReview, AdminPermission::CashExecute, AdminPermission::ReconciliationManage, AdminPermission::DeductionsManage]);
        enableFixtureMethod();
        $owner = app(ThriftPlanService::class);
        $priorRule = FeeRule::create(['version' => 1, 'name' => 'Prior no-fee cycle', 'kind' => 'plan', 'rule_key' => 'prior-cycle',
            'model' => 'no_fee', 'timing' => 'first_contribution', 'basis' => 'none', 'settlement_source' => 'external_receipt',
            'currency' => 'NGN', 'amount_kobo' => 0, 'customer_description' => 'No fee for this prior cycle.',
            'effective_at' => now()->subDay(), 'published_by_user_id' => $admin->id, 'publication_reason' => 'Isolated prior agreement.']);
        $priorTerms = ['name' => 'Prior funded cycle', 'amount_ngn' => '1000.00', 'start_date' => $date, 'contribution_days' => 5,
            'customer_visible_notes' => '', 'fee_rule_id' => $priorRule->id, 'fee_rule_version' => $priorRule->version,
            'customer_version' => $customer->version, 'assignment_version' => $assignment->version,
            'business_version' => BusinessProfile::current()->version, 'customer_agreement_attested' => true];
        $priorTerms['preview_fingerprint'] = $owner->preview($agent, $customer, $priorTerms)['preview_fingerprint'];
        $predecessor = $owner->create($agent, $customer, (string) Str::uuid(), $priorTerms)['plan'];
        $priorReceipt = collectionPayload($customer, $assignment, $predecessor, $date, '5000.00');
        $priorReceipt['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $priorReceipt)['preview_fingerprint'];
        $priorPosted = app(CollectionService::class)->record($agent, $customer, $priorReceipt);
        percentageReconcileCash($this, $admin, $priorPosted->batch, '5000.00', $date);
        percentageCashPayout($this, $admin, $agent, $customer, $predecessor->fresh(), '5000.00', 'end_of_cycle');
        $settlement = app(PlanSettlementService::class);
        $closure = $settlement->preview($agent, $predecessor->fresh());
        expect($closure['can_close'])->toBeTrue();
        $settlement->confirm($agent, $predecessor->fresh(), 'close', ['attempt_reference' => (string) Str::uuid(),
            'preview_fingerprint' => $closure['preview_fingerprint'], 'reason' => 'All original funds and custody are settled.',
            'customer_explanation' => 'Your prior cycle is fully settled and closed.']);
        $priorHistory = [$predecessor->fresh()->getAttributes(), $predecessor->currentTermsRevision()->getAttributes(),
            $predecessor->currentTermsRevision()->feeSnapshot->getAttributes(), $predecessor->slots()->orderBy('id')->get()->map->getAttributes()->all(),
            $priorPosted->fresh()->getAttributes(), DB::table('ledger_entries')->where('thrift_plan_id', $predecessor->id)->orderBy('id')->get()->all()];
        $rule = FeeRule::create(['version' => 2, 'name' => 'Two percent completion agreement', 'kind' => 'plan', 'rule_key' => 'net-completion',
            'model' => 'percentage', 'timing' => 'cycle_completion', 'basis' => 'net_cycle_contributions', 'basis_points' => 200,
            'settlement_source' => 'savings_application', 'currency' => 'NGN', 'amount_kobo' => 0,
            'customer_description' => 'Two percent of net posted cycle contributions at completion.',
            'effective_at' => now()->subDay(), 'published_by_user_id' => $admin->id, 'publication_reason' => 'Isolated agreed completion terms.']);
        $data = ['name' => 'Actual percentage completion cycle', 'amount_ngn' => '2000.00', 'start_date' => $date, 'contribution_days' => 3,
            'customer_visible_notes' => '', 'fee_rule_id' => $rule->id, 'fee_rule_version' => $rule->version,
            'customer_version' => $customer->version, 'assignment_version' => $assignment->version,
            'business_version' => BusinessProfile::current()->version, 'customer_agreement_attested' => true];
        $data['preview_fingerprint'] = $owner->preview($agent, $customer, $data)['preview_fingerprint'];
        $plan = $owner->create($agent, $customer, (string) Str::uuid(), $data)['plan'];
    } else {
        [$agent, $customer, $assignment, $plan, $date] = collectionFixture(3, feeAmountKobo: 10000, feeTiming: $timing);
    }
    $originalFee = $percentage ? 12000 : 10000;
    $otherDebits = $percentage ? 40000 : 0;
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $slots = $plan->slots()->orderBy('id')->get(['id', 'due_date', 'expected_amount_kobo'])->toArray();
    $receipts = [];
    $collection = app(CollectionService::class);
    foreach (['2000.00', '4000.00'] as $amount) {
        $data = collectionPayload($customer->fresh(), $assignment->fresh(), $plan->fresh(), $date, $amount);
        $data['preview_fingerprint'] = $collection->preview($agent, $customer->fresh(), $data)['preview_fingerprint'];
        $receipts[] = $collection->record($agent, $customer->fresh(), $data);
    }
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Completed);
    $originalSnapshot = $plan->fresh()->currentTermsRevision()->feeSnapshot;
    $originalSnapshotAttributes = $originalSnapshot->getAttributes();
    expect($originalSnapshot->obligation->assessedAmountKobo())->toBe($originalFee);
    if ($percentage) {
        expect($originalSnapshot->basis_amount_kobo)->toBe(600000);
        expect($originalSnapshot->basis_points)->toBe(200);
        $receivable = DB::table('ledger_entries')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->join('ledger_posting_groups', 'ledger_posting_groups.id', '=', 'ledger_entries.ledger_posting_group_id')
            ->where('ledger_posting_groups.thrift_plan_id', $plan->id)->where('ledger_accounts.code', LedgerAccountCode::AgentReceivable->value)
            ->selectRaw("SUM(CASE WHEN side = 'debit' THEN amount_kobo ELSE -amount_kobo END) AS outstanding")->sole();
        expect((int) $receivable->outstanding)->toBe(600000);
        $beforeHandoff = app(PlanFeeCorrectionService::class)->preview($plan->fresh());
        expect($beforeHandoff['principal_kobo'])->toBe(600000);
        expect($beforeHandoff['target_kobo'])->toBe(12000);
        percentageReconcileCash($this, $admin, $receipts[0]->batch, '6000.00', $date);
        $payout = percentageCashPayout($this, $admin, $agent, $customer, $plan->fresh(), '300.00', 'partial');
        config()->set('fees.manual_charges_enabled', true);
        $this->actingAs($admin)->withSession(cashSession())->post(route('admin.charges.publish'), [
            'publication_reference' => (string) Str::uuid(), 'category_key' => 'percentage-excluded-deduction', 'kind' => 'deduction',
            'purpose' => 'Approved independent service deduction', 'customer_description' => 'Agreed separate service charge.',
            'amount_ngn' => '100.00', 'confirmed' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $category = ChargeCategoryVersion::query()->where('category_key', 'percentage-excluded-deduction')->sole();
        $this->post(route('admin.charges.assess'), reviewManualCharge($this, ['operation_reference' => (string) Str::uuid(), 'customer_id' => $customer->customer_id,
            'plan_id' => $plan->plan_id, 'category_id' => $category->id, 'customer_version' => $customer->fresh()->version,
            'plan_version' => $plan->fresh()->version, 'reason' => 'Reviewed distinct service deduction.', 'confirmed' => true]))->assertRedirect()->assertSessionHasNoErrors();
        $position = app(PlanFeeCorrectionService::class)->preview($plan->fresh());
        expect($position['principal_kobo'])->toBe(600000);
        expect($position['target_kobo'])->toBe(12000);
        expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(548000);
        $payoutAttributes = $payout->getAttributes();
        $deductionRows = DB::table('manual_charges')->orderBy('id')->get()->all();
    }
    $completion = PlanLifecycleEvent::query()->where('thrift_plan_id', $plan->id)->where('event_type', 'completed')->sole()->toArray();
    $completedVersion = $plan->fresh()->version;
    $originalReceiptAttributes = $receipts[0]->getAttributes();
    $request = approveReceiptCorrection($this, $agent, $customer->fresh(), $assignment->fresh(),
        LedgerPostingGroup::findOrFail($receipts[0]->savings_posting_group_id))->fresh();
    $plan->refresh();
    $fee = $plan->currentTermsRevision()->feeSnapshot->obligation;
    if ($percentage) {
        $correction = app(PlanFeeCorrectionService::class)->preview($plan);
        expect($correction['principal_kobo'])->toBe(400000);
        expect($correction['target_kobo'])->toBe(0);
        $compensation = LedgerPostingGroup::query()->findOrFail($request->compensation_posting_group_id);
        foreach (['assessment_correction', 'settlement_reversal'] as $type) {
            $this->assertDatabaseHas('fee_obligation_entries', ['fee_obligation_id' => $fee->id, 'entry_type' => $type,
                'amount_kobo' => 12000, 'source_type' => 'plan_fee_correction', 'source_id' => (string) $compensation->id,
                'actor_user_id' => $request->reviewed_by_user_id, 'ledger_posting_reference' => $compensation->posting_reference]);
        }
        foreach ([[LedgerAccountCode::FeeIncome, 'debit'], [LedgerAccountCode::CustomerSavingsLiability, 'credit']] as [$code, $side]) {
            $amount = DB::table('ledger_entries')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
                ->where('ledger_entries.ledger_posting_group_id', $compensation->id)->where('fee_obligation_id', $fee->id)
                ->where('ledger_accounts.code', $code->value)->where('side', $side)->sum('amount_kobo');
            expect((int) $amount)->toBe(12000);
        }
    }
    expect($plan->status)->toBe(ThriftPlanStatus::Paused)
        ->and($fee->assessedAmountKobo())->toBe($remainingFee)->and($fee->settledAmountKobo())->toBe($remainingFee)
        ->and(PlanLifecycleEvent::findOrFail($completion['id'])->toArray())->toBe($completion)
        ->and($plan->slots()->orderBy('id')->get(['id', 'due_date', 'expected_amount_kobo'])->toArray())->toBe($slots);
    $pause = PlanLifecycleEvent::query()->where('thrift_plan_id', $plan->id)->where('event_type', 'receipt_compensated')->sole();
    expect($pause->from_status)->toBe(ThriftPlanStatus::Completed)->and($pause->to_status)->toBe(ThriftPlanStatus::Paused)
        ->and($pause->payload['reversal_request_id'])->toBe($request->id)
        ->and($pause->actor_user_id)->toBe($request->reviewed_by_user_id)
        ->and($request->reviewed_by_user_id)->not->toBe($agent->id);
    $card = app(CollectionReadService::class)->card($plan);
    expect($card['funded_kobo'])->toBe(400000)->and($card['paid_slots'])->toBe(2)
        ->and($card['slots'][0]['status'])->toBe('blocked')->and($card['slots'][0]['remaining_kobo'])->toBe(200000)
        ->and($card['position']['liability_kobo'])->toBe(400000 - $remainingFee - $otherDebits);
    $replacement = app(CollectionReplacementService::class);
    $data = collectionPayload($customer->fresh(), $assignment->fresh(), $plan, $date, '2000.00');
    expect(fn () => $replacement->preview($agent, $request, $data))->toThrow(ValidationException::class, 'Choose an active plan for this Customer.');
    $resume = ['customer_version' => $customer->fresh()->version, 'assignment_version' => $assignment->fresh()->version,
        'plan_version' => $completedVersion, 'reason' => 'Reviewed approved correction, fee effects and original outstanding day.',
        'customer_explanation' => 'Your original outstanding contribution day can be funded after reviewed resume.'];
    $lifecycle = app(ThriftPlanService::class);
    expect(fn () => $lifecycle->transition($agent, $plan, 'resume', (string) Str::uuid(), $resume))
        ->toThrow(ConflictHttpException::class);
    $resume['plan_version'] = $plan->version;
    $groups = LedgerPostingGroup::query()->count();
    $lifecycle->transition($agent, $plan, 'resume', (string) Str::uuid(), $resume);
    expect(LedgerPostingGroup::query()->count())->toBe($groups);
    $resumedCard = app(CollectionReadService::class)->card($plan->fresh());
    expect($resumedCard['slots'][0]['status'])->toBe('pending')->and($resumedCard['funded_kobo'])->toBe(400000);
    $data = collectionPayload($customer->fresh(), $assignment->fresh(), $plan->fresh(), $date, '2000.00');
    $quote = $replacement->preview($agent, $request, $data);
    $replacementPayload = [...$data, 'preview_fingerprint' => $quote['preview_fingerprint'],
        'replacement_fingerprint' => $quote['replacement_fingerprint']];
    $replacement->record($agent, $request, $replacementPayload);
    $replacementGroups = LedgerPostingGroup::query()->pluck('id')->all();
    $replacement->record($agent, $request, $replacementPayload);
    $fee->refresh();
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Completed)
        ->and($fee->assessedAmountKobo())->toBe($originalFee)->and($fee->settledAmountKobo())->toBe($originalFee)
        ->and(app(CollectionReadService::class)->card($plan->fresh())['paid_slots'])->toBe(3)
        ->and(PlanLifecycleEvent::findOrFail($completion['id'])->toArray())->toBe($completion)
        ->and((int) $receipts[0]->batch->receipts()->sum('tender_amount_kobo'))->toBe(600000);
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($replacementGroups)
        ->and(PlanLifecycleEvent::query()->where('thrift_plan_id', $plan->id)->where('event_type', 'completed')->count())->toBe(2);
    $incomeAccount = LedgerAccount::query()->where('code', LedgerAccountCode::FeeIncome->value)->sole();
    $income = DB::table('ledger_entries')->where('ledger_account_id', $incomeAccount->id)
        ->selectRaw("SUM(CASE WHEN side = 'credit' THEN amount_kobo ELSE -amount_kobo END) AS earned")->sole();
    expect((int) $income->earned)->toBe($originalFee)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(600000 - $originalFee - $otherDebits);
    expect($originalSnapshot->fresh()->getAttributes())->toBe($originalSnapshotAttributes);
    expect($receipts[0]->fresh()->getAttributes())->toBe($originalReceiptAttributes);
    if ($percentage) {
        expect(app(PlanFeeCorrectionService::class)->preview($plan->fresh())['principal_kobo'])->toBe(600000);
        expect(app(PlanFeeCorrectionService::class)->preview($plan->fresh())['target_kobo'])->toBe(12000);
        expect($payout->fresh()->getAttributes())->toBe($payoutAttributes);
        expect(DB::table('manual_charges')->orderBy('id')->get()->all())->toEqual($deductionRows);
        expect([$predecessor->fresh()->getAttributes(), $predecessor->currentTermsRevision()->getAttributes(),
            $predecessor->currentTermsRevision()->feeSnapshot->getAttributes(), $predecessor->slots()->orderBy('id')->get()->map->getAttributes()->all(),
            $priorPosted->fresh()->getAttributes(), DB::table('ledger_entries')->where('thrift_plan_id', $predecessor->id)->orderBy('id')->get()->all()])->toEqual($priorHistory);
    }
    $this->assertDatabaseCount('withdrawal_reservations', $percentage ? 2 : 0);
    $this->assertDatabaseCount('cash_executions', $percentage ? 2 : 0);
})->with([
    'completion fee is restored and reassessed when completion returns' => [FeeRuleTiming::CycleCompletion, 0],
    'first-contribution fee remains due while other original receipts survive' => [FeeRuleTiming::FirstContribution, 10000],
    'percentage completion retains net basis after original receipt reversal and controlled replacement' => [FeeRuleTiming::CycleCompletion, 0, true],
]);

test('closed plan resolution settles every explicit exception and keeps predecessor status and archival history', function (): void {
    [$agent, $customer, , $plan] = collectionFixture();
    $plan->update(['status' => ThriftPlanStatus::Closed, 'open_customer_profile_id' => null]);
    foreach ([1, 2] as $number) {
        PlanLifecycleEvent::create(['thrift_plan_id' => $plan->id, 'event_type' => 'receipt_compensated', 'from_status' => 'closed', 'to_status' => 'closed', 'actor_user_id' => $agent->id, 'plan_version' => $plan->version, 'payload' => ['closed_plan_exception' => true], 'effective_at' => now()]);
    }
    $service = app(PlanSettlementService::class);
    expect($service->preview($agent, $plan)['can_close'])->toBeFalse();
    $quote = $service->preview($agent, $plan, 'resolve_exception');
    $service->confirm($agent, $plan, 'resolve_exception', ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'], 'reason' => 'All correction effects verified fully settled.', 'customer_explanation' => 'The closed cycle stays closed.']);
    expect($service->preview($agent, $plan->fresh())['can_close'])->toBeTrue()->and($plan->fresh()->status)->toBe(ThriftPlanStatus::Closed);
    expect(DB::table('financial_workflow_supplements')->where('kind', 'closed_exception_resolved')->count())->toBe(2);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
});

test('external first-contribution fee correction creates a linked unpaid refund entitlement without handing out cash', function (): void {
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(2, feeAmountKobo: 10000, feeSource: FeeSettlementSource::ExternalReceipt);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $data = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $data);
    $fee = $plan->currentTermsRevision()->feeSnapshot->obligation;
    $feeData = collectionPayload($customer, $assignment, $plan->fresh(), $date, '0');
    $feeData['plan_id'] = null;
    $feeData['plan_version'] = null;
    $feeData['fees'] = [['obligation_id' => $fee->id, 'amount_ngn' => '100.00']];
    $feeData['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $feeData)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $feeData);
    approveReceiptCorrection($this, $agent, $customer, $assignment, LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id));
    $refund = FeeRefund::query()->sole();
    expect($refund->amount_kobo)->toBe(10000)->and($refund->compensation_posting_group_id)->not->toBeNull()
        ->and($fee->fresh()->settledAmountKobo())->toBe(0)->and($fee->fresh()->assessedAmountKobo())->toBe(0)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe(10000);
    $this->assertDatabaseCount('cash_disbursements', 0);
    $audit = AuditEvent::query()->where('event_type', 'fee.refund_authorized')->sole();
    expect($audit->target_id)->toBe($refund->id)->and($audit->target_reference)->toBe($refund->refund_reference)
        ->and($audit->payload['amount_kobo'])->toBe(10000)
        ->and(DB::table('canonical_audit_events')->where('event_type', 'fee.refund_authorized')->sole()->required_permission)->toBe('reversals.review');
    $reviewer = User::query()->findOrFail($refund->actor_user_id);
    expect($reviewer->hasPermissionTo(AdminPermission::FeesManage->value))->toBeFalse();
    $operator = DB::table('financial_cash_notification_intents')->where('audience_type', 'refund_correction_operator')->sole();
    $pipeline = app(NotificationPipeline::class);
    $pipeline->deliverOwner('financial_cash', $operator->id);
    $intentId = DB::table('notification_inbox_aliases')->where('family', 'financial_cash')->where('owner_intent_id', $operator->id)->sole()->intent_id;
    expect($pipeline->isRecipientEligible($intentId))->toBeTrue();
    $reviewer->revokePermissionTo(AdminPermission::ReversalsReview);
    expect($pipeline->isRecipientEligible($intentId))->toBeFalse()
        ->and(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe(10000);
    app(LedgerTransactionProjectionService::class)->rebuild();
});

test('correcting a cycle fee preserves an independent concession without refunding it twice', function (FeeSettlementSource $source, string $kind, string $handoff, int $liability, int $cash, int $payable, int $refundCount): void {
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true, 'fees.refunds_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(2, feeAmountKobo: 10000, feeSource: $source);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $data = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $data);
    $fee = $plan->currentTermsRevision()->feeSnapshot->obligation;
    if ($kind === 'external') {
        $feeData = collectionPayload($customer, $assignment, $plan->fresh(), $date, '0');
        $feeData = [...$feeData, 'plan_id' => null, 'plan_version' => null,
            'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '100.00']]];
        $feeData['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $feeData)['preview_fingerprint'];
        app(CollectionService::class)->record($agent, $customer, $feeData);
    }
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::ReconciliationManage, AdminPermission::FeesManage]);
    $batch = $receipt->batch;
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();
    $this->actingAs($admin)->post(route('collection-batches.remittances.store', $batch), [
        'handoff_reference' => 'CONCESSION-CASH', 'amount_ngn' => $handoff, 'handoff_date' => $date,
        'receiving_location' => 'Lagos office', 'source_attestation' => 'Full counted tender received.',
        'batch_version' => $batch->fresh()->version, 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->withSession(cashSession())->post(route('admin.fees.refunds.store', $fee), [
        'refund_reference' => (string) Str::uuid(), 'kind' => $kind, 'amount_ngn' => '40.00',
        'reason' => 'Approved independent concession.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe($liability);
    $correction = approveReceiptCorrection($this, $agent, $customer, $assignment,
        LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id))->fresh();
    expect($correction->state)->toBe('approved_posted');
    expect($fee->fresh()->assessedAmountKobo())->toBe(0);
    expect($fee->fresh()->settledAmountKobo())->toBe(0);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome))->toBe(0);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::BusinessCash))->toBe($cash);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::UnappliedFunds))->toBe(200000);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe($payable);
    $this->assertDatabaseCount('fee_refunds', $refundCount);
    $this->assertDatabaseCount('cash_disbursements', 0);
    $data = collectionPayload($customer, $assignment, $plan->fresh(), $date, '2000.00');
    $service = app(CollectionReplacementService::class);
    $quote = $service->preview($agent, $correction, $data);
    $replacement = $service->record($agent, $correction, [...$data,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'replacement_fingerprint' => $quote['replacement_fingerprint']]);
    if ($kind === 'savings') {
        $newConcession = ['refund_reference' => (string) Str::uuid(), 'kind' => 'savings', 'amount_ngn' => '100.01',
            'reason' => 'A new concession must use only the newly retained fee.', 'confirmed' => true];
        $this->actingAs($admin)->withSession(cashSession())->post(route('admin.fees.refunds.store', $fee), $newConcession)->assertConflict();
        $this->post(route('admin.fees.refunds.store', $fee), [...$newConcession, 'amount_ngn' => '25.00'])
            ->assertRedirect()->assertSessionHasNoErrors();
    }
    $nextCorrection = approveReceiptCorrection($this, $agent, $customer, $assignment,
        LedgerPostingGroup::findOrFail($replacement->savings_posting_group_id))->fresh();
    expect($nextCorrection->state)->toBe('approved_posted');
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome))->toBe(0);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe($payable);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::UnappliedFunds))->toBe(200000);
    $this->assertDatabaseCount('fee_refunds', 2);
    app(LedgerTransactionProjectionService::class)->rebuild();
})->with([
    'savings relief' => [FeeSettlementSource::SavingsApplication, 'savings', '2000.00', 194000, 200000, 0, 1],
    'external entitlement' => [FeeSettlementSource::ExternalReceipt, 'external', '2100.00', 200000, 210000, 10000, 2],
]);

test('settlement remains unavailable with its live flag off before preparing any financial effect', function (): void {
    [$agent, , , $plan] = collectionFixture();
    config()->set('collections.settlement_enabled', false);
    $this->actingAs($agent)->get(route('plans.settlement', $plan))->assertServiceUnavailable();
    $this->assertDatabaseCount('financial_workflow_supplements', 0);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
});

test('external fee receipt corrections consume only remaining tender and preserve independent concessions', function (string $firstAmount, string $secondAmount, string $concessionAmount, int $controlled, int $retained, int $consumed, bool $paid): void {
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true, 'fees.refunds_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 50000);
    $receipts = [];
    foreach ([$firstAmount, $secondAmount] as $amount) {
        $data = [...collectionPayload($customer, $assignment, $plan, $date, '0'), 'plan_id' => null, 'plan_version' => null,
            'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => $amount]]];
        $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
        $receipts[] = app(CollectionService::class)->record($agent, $customer, $data);
    }
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::FeesManage, AdminPermission::ReconciliationManage]);
    $batch = $receipts[0]->batch;
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();
    $this->actingAs($admin)->withSession(cashSession())->post(route('collection-batches.remittances.store', $batch), [
        'handoff_reference' => 'RETAINED-FEE-TENDER', 'amount_ngn' => '500.00', 'handoff_date' => $date,
        'receiving_location' => 'Lagos office', 'source_attestation' => 'Counted physical external fee tender.',
        'batch_version' => $batch->fresh()->version, 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $refundPayload = ['refund_reference' => (string) Str::uuid(), 'kind' => 'external', 'amount_ngn' => $concessionAmount,
        'reason' => 'Independently approved fee concession remains owed.', 'confirmed' => true];
    $this->post(route('admin.fees.refunds.store', $fee), $refundPayload)->assertRedirect()->assertSessionHasNoErrors();
    $refund = FeeRefund::query()->sole();
    if ($paid) {
        config()->set('fees.cash_disbursements_enabled', true);
        $admin->givePermissionTo(AdminPermission::CashExecute);
        $this->post(route('fee-refunds.cash', $refund), ['execution_reference' => (string) Str::uuid(),
            'evidence' => 'Independently owed concession cash confirmed.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
        $execution = CashDisbursement::query()->sole();
        $this->post(route('cash-disbursements.handoff', $execution), ['delivered' => true, 'evidence' => 'Exact concession cash delivered to Customer.', 'confirmed' => true])->assertRedirect();
        $this->actingAs($customer->user)->post(route('cash-disbursements.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    }
    $original = LedgerPostingGroup::query()->where('source_type', 'collection_receipt')->where('source_id', $receipts[0]->id.'-'.$fee->id)->sole();
    $cash = app(FinancialCashPosition::class);
    $correction = approveReceiptCorrection($this, $agent, $customer, $assignment, $original)->fresh();
    expect($correction->state)->toBe('approved_posted');
    expect($fee->fresh()->settledAmountKobo())->toBe(app(CollectionService::class)->amountToKobo($secondAmount));
    expect($cash->balance(LedgerAccountCode::UnappliedFunds))->toBe($controlled);
    expect(app(FeeConcessionPosition::class)->retainedSources($fee))->toBe(['savings_kobo' => 0, 'external_kobo' => $retained]);
    expect(LedgerPostingGroup::findOrFail($correction->compensation_posting_group_id)->metadata['consumed_external_concession_kobo'])->toBe($consumed);
    $this->actingAs($admin)->withSession(cashSession())->post(route('admin.fees.refunds.store', $fee), [
        ...$refundPayload, 'refund_reference' => (string) Str::uuid(), 'amount_ngn' => number_format(($retained + 1) / 100, 2, '.', ''),
    ])->assertConflict();
    $replacementData = [...collectionPayload($customer, $assignment, $plan->fresh(), $date, '0'),
        'plan_id' => null, 'plan_version' => null, 'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => number_format(($controlled) / 100, 2, '.', '')]]];
    $replacements = app(CollectionReplacementService::class);
    expect(fn () => $replacements->preview($agent, $correction, [...$replacementData,
        'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => number_format(($controlled - 1) / 100, 2, '.', '')]]]))->toThrow(ConflictHttpException::class);
    $quote = $replacements->preview($agent, $correction, $replacementData);
    $replacement = $replacements->record($agent, $correction, [...$replacementData,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'replacement_fingerprint' => $quote['replacement_fingerprint']]);
    expect($replacement->tender_amount_kobo)->toBe($controlled);
    $nextOriginal = LedgerPostingGroup::query()->where('source_type', 'collection_receipt')->where('source_id', $replacement->id.'-'.$fee->id)->sole();
    $next = approveReceiptCorrection($this, $agent, $customer, $assignment, $nextOriginal)->fresh();
    expect($next->state)->toBe('approved_posted');
    expect($cash->balance(LedgerAccountCode::UnappliedFunds))->toBe($controlled);
    expect($cash->balance(LedgerAccountCode::BusinessCash))->toBe(50000 - ($paid ? app(CollectionService::class)->amountToKobo($concessionAmount) : 0));
    expect($cash->balance(LedgerAccountCode::RefundPayable))->toBe($paid ? 0 : app(CollectionService::class)->amountToKobo($concessionAmount));
    expect($refund->fresh()->ledger_posting_group_id)->toBe($refund->ledger_posting_group_id)
        ->and($refund->fresh()->compensation_posting_group_id)->toBeNull();
    $this->assertDatabaseCount('fee_refunds', 1);
    $this->assertDatabaseCount('collection_receipts', 3);
    app(LedgerTransactionProjectionService::class)->rebuild();
})->with([
    'retained unpaid' => ['200.00', '300.00', '100.00', 20000, 20000, 0, false],
    'retained paid' => ['200.00', '300.00', '100.00', 20000, 20000, 0, true],
    'reduced unpaid' => ['400.00', '100.00', '200.00', 30000, 0, 10000, false],
    'reduced paid' => ['400.00', '100.00', '200.00', 30000, 0, 10000, true],
]);

test('noncash mixed receipt corrections preserve the physical proof while replacing reduced controlled tender', function (string $concession, int $controlled, string $replacementFee): void {
    [$agent, $customer, $assignment, $plan, $date, $admin, $proof, $payload] = noncashFixture($this, savings: '2500.00');
    config()->set('fees.refunds_enabled', true);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 50000);
    $payload = [...$payload, 'savings_ngn' => '2000.00', 'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '500.00']]];
    $receipt = postNoncashReceipt($this, $customer, $payload);
    $cashCustomer = CustomerProfile::factory()->create();
    $cashAssignment = CustomerAssignment::factory()->create(['customer_profile_id' => $cashCustomer->id,
        'agent_profile_id' => $assignment->agent_profile_id, 'assigned_by_user_id' => $agent->id]);
    $cashFee = reportFeeObligation($agent, $cashCustomer, 300000, version: 2);
    $cashData = [...collectionPayload($cashCustomer, $cashAssignment, $plan->fresh(), $date, '0'), 'plan_id' => null, 'plan_version' => null,
        'fees' => [['obligation_id' => $cashFee->id, 'amount_ngn' => '3000.00']]];
    $cashData['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $cashCustomer, $cashData)['preview_fingerprint'];
    $cashReceipt = app(CollectionService::class)->record($agent, $cashCustomer, $cashData);
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();
    $this->actingAs($admin)->withSession(cashSession())->post(route('collection-batches.remittances.store', $cashReceipt->batch), [
        'handoff_reference' => 'INDEPENDENT-REFUND-CASH', 'amount_ngn' => '3000.00', 'handoff_date' => $date,
        'receiving_location' => 'Lagos office', 'source_attestation' => 'Independent fee cash available for the concession.',
        'batch_version' => $cashReceipt->batch->fresh()->version, 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $original = LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id);
    $reversals = app(ReversalService::class);
    $initial = $reversals->preview($agent, $original);
    $correction = $reversals->submit($agent, $original, ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $initial['preview_fingerprint'], 'customer_version' => $customer->version,
        'assignment_version' => $assignment->version, 'reason_category' => 'wrong_amount_allocation',
        'internal_reason' => 'Original independently verified bank tender remains controlled.',
        'customer_explanation' => 'The original receipt allocation is being corrected.',
        'evidence_text' => 'Bank receipt and original fee tender reviewed.', 'confirmed' => true]);
    $admin->givePermissionTo([AdminPermission::FeesManage, AdminPermission::ReversalsReview]);
    $stale = $reversals->reviewPreview($admin, $correction);
    $this->actingAs($admin)->withSession(cashSession())->post(route('admin.fees.refunds.store', $fee), [
        'refund_reference' => (string) Str::uuid(), 'kind' => 'external', 'amount_ngn' => $concession,
        'reason' => 'Independent fee benefit survives the full receipt correction.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $decision = ['attempt_reference' => (string) Str::uuid(), 'version' => $correction->version,
        'preview_fingerprint' => $stale['preview_fingerprint'], 'decision_reason' => 'Reviewed remaining controlled funds and independent entitlement.', 'confirmed' => true];
    $this->post(route('reversals.approve', $correction), $decision)->assertConflict();
    expect($correction->fresh()->state)->toBe('pending_review');
    $current = $reversals->reviewPreview($admin, $correction);
    $this->post(route('reversals.approve', $correction), [...$decision, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $current['preview_fingerprint']])->assertRedirect()->assertSessionHasNoErrors();
    $correction->refresh();
    expect($correction->dependency_snapshot['summary']['controlled_kobo'])->toBe(250000);
    expect(LedgerPostingGroup::findOrFail($correction->compensation_posting_group_id)->metadata['controlled_kobo'])->toBe($controlled);
    $this->actingAs($agent)->get(route('reversals.replacement', $correction))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('collections/Create')->where('replacement_controlled_kobo', $controlled));
    $cash = app(FinancialCashPosition::class);
    expect($cash->balance(LedgerAccountCode::UnappliedFunds))->toBe($controlled);
    expect($cash->balance(LedgerAccountCode::RefundPayable))->toBe(250000 - $controlled);
    expect($fee->fresh()->settledAmountKobo())->toBe(0);
    $data = [...collectionPayload($customer, $assignment, $plan->fresh(), $date, '2000.00'),
        'fees' => $replacementFee === '0.00' ? [] : [['obligation_id' => $fee->id, 'amount_ngn' => $replacementFee]]];
    $service = app(CollectionReplacementService::class);
    $quote = $service->preview($agent, $correction, $data);
    $replacement = $service->record($agent, $correction, [...$data, 'preview_fingerprint' => $quote['preview_fingerprint'],
        'replacement_fingerprint' => $quote['replacement_fingerprint']]);
    expect($replacement->tender_amount_kobo)->toBe($controlled);
    expect($replacement->method)->toBe('transfer')->and($replacement->collection_batch_id)->toBe($receipt->collection_batch_id)
        ->and($replacement->collection_payment_evidence_id)->toBeNull()
        ->and($replacement->collection_evidence_review_id)->toBe($receipt->collection_evidence_review_id);
    $next = approveReceiptCorrection($this, $agent, $customer, $assignment,
        LedgerPostingGroup::findOrFail($replacement->savings_posting_group_id))->fresh();
    expect($next->state)->toBe('approved_posted');
    expect($cash->balance(LedgerAccountCode::BusinessBank))->toBe(250000);
    expect($cash->balance(LedgerAccountCode::BusinessCash))->toBe(300000);
    expect(app(CollectionBatchPosition::class)->read($cashReceipt->batch)['outstanding_kobo'])->toBe(0);
    expect($cash->balance(LedgerAccountCode::UnappliedFunds))->toBe($controlled);
    expect($cash->balance(LedgerAccountCode::RefundPayable))->toBe(250000 - $controlled);
    $this->assertDatabaseCount('collection_payment_evidence', 1);
    $this->assertDatabaseCount('fee_refunds', 1);
    $this->assertDatabaseCount('collection_receipts', 3);
    app(LedgerTransactionProjectionService::class)->rebuild();
})->with([
    'partial external concession' => ['200.00', 230000, '300.00'],
    'fully conceded fee with savings' => ['500.00', 200000, '0.00'],
]);

test('receipt and plan correction owners partition shared external fee settlement once', function (string $receiptFee, string $otherFee, string $concession, int $controlled, int $payable, int $refundCount, bool $remainingPrincipal, bool $drawPending): void {
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true, 'fees.refunds_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(3, feeAmountKobo: 50000, feeSource: FeeSettlementSource::ExternalReceipt);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = app(FeeObligationService::class)->assessSnapshot($plan->currentTermsRevision()->feeSnapshot, $agent);
    if ($remainingPrincipal) {
        $data = collectionPayload($customer, $assignment, $plan, $date, '1000.00');
        $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
        app(CollectionService::class)->record($agent, $customer, $data);
    }
    $data = [...collectionPayload($customer, $assignment, $plan->fresh(), $date, '2000.00'),
        'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => $receiptFee]]];
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $data);
    if ($otherFee !== '0.00') {
        $data = [...collectionPayload($customer, $assignment, $plan->fresh(), $date, '0'), 'plan_id' => null, 'plan_version' => null,
            'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => $otherFee]]];
        $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
        app(CollectionService::class)->record($agent, $customer, $data);
    }
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::FeesManage, AdminPermission::ReconciliationManage]);
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();
    $this->actingAs($admin)->withSession(cashSession())->post(route('collection-batches.remittances.store', $receipt->batch), [
        'handoff_reference' => 'SHARED-PLAN-FEE-TENDER', 'amount_ngn' => $remainingPrincipal ? '3500.00' : '2500.00',
        'handoff_date' => $date, 'receiving_location' => 'Lagos office', 'source_attestation' => 'Full savings and external fee cash counted.',
        'batch_version' => $receipt->batch->fresh()->version, 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    if ($concession !== '0.00') {
        $this->post(route('admin.fees.refunds.store', $fee), ['refund_reference' => (string) Str::uuid(), 'kind' => 'external',
            'amount_ngn' => $concession, 'reason' => 'Independent plan fee concession.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    }
    $original = LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id);
    if ($drawPending) {
        config()->set('fees.cash_disbursements_enabled', true);
        $admin->givePermissionTo(AdminPermission::CashExecute);
        $this->post(route('earnings-draws.start'), ['execution_reference' => (string) Str::uuid(), 'amount_ngn' => '50.00',
            'evidence' => 'Independent approved distribution reserves fee earnings.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
        expect(fn () => app(CollectionReversalOwner::class)->preview($original, $customer, false))->toThrow(ConflictHttpException::class, 'encumbered fee earnings');
        expect($fee->fresh()->settledAmountKobo())->toBe(50000);
        $this->assertDatabaseCount('reversal_requests', 0);
        $this->assertDatabaseCount('collection_allocation_releases', 0);

        return;
    }

    $correction = approveReceiptCorrection($this, $agent, $customer, $assignment, $original)->fresh();
    expect($correction->state)->toBe('approved_posted');
    $group = LedgerPostingGroup::findOrFail($correction->compensation_posting_group_id);
    expect($group->metadata['plan_fee_effect']['excluded_receipt_fee_kobo'])->toBe(app(CollectionService::class)->amountToKobo($receiptFee));
    expect($fee->fresh()->settledAmountKobo())->toBe(0);
    expect($fee->fresh()->outstandingAmountKobo())->toBe($remainingPrincipal ? 50000 : 0);
    expect((int) $fee->entries()->where('entry_type', FeeObligationEntryType::SettlementReversal)->sum('amount_kobo'))->toBe(50000);
    $cash = app(FinancialCashPosition::class);
    expect($cash->balance(LedgerAccountCode::FeeIncome))->toBe(0);
    expect($cash->balance(LedgerAccountCode::UnappliedFunds))->toBe($controlled);
    expect($cash->balance(LedgerAccountCode::RefundPayable))->toBe($payable);
    $this->assertDatabaseCount('fee_refunds', $refundCount);
    $data = collectionPayload($customer, $assignment, $plan->fresh(), $date, number_format($controlled / 100, 2, '.', ''));
    $replacements = app(CollectionReplacementService::class);
    $quote = $replacements->preview($agent, $correction, $data);
    $replacement = $replacements->record($agent, $correction, [...$data,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'replacement_fingerprint' => $quote['replacement_fingerprint']]);
    $next = approveReceiptCorrection($this, $agent, $customer, $assignment, LedgerPostingGroup::findOrFail($replacement->savings_posting_group_id))->fresh();
    expect($next->state)->toBe('approved_posted');
    expect($cash->balance(LedgerAccountCode::UnappliedFunds))->toBe($controlled);
    expect($cash->balance(LedgerAccountCode::FeeIncome))->toBe(0);
    expect($cash->balance(LedgerAccountCode::RefundPayable))->toBe($payable);
    expect($cash->balance(LedgerAccountCode::BusinessCash))->toBe($remainingPrincipal ? 350000 : 250000);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe($remainingPrincipal ? 100000 : 0);
    expect($fee->fresh()->outstandingAmountKobo())->toBe($remainingPrincipal ? 50000 : 0);
    $this->assertDatabaseCount('fee_refunds', $refundCount);
    app(LedgerTransactionProjectionService::class)->rebuild();
})->with([
    'single settlement' => ['500.00', '0.00', '0.00', 250000, 0, 0, false, false],
    'single settlement with concession' => ['500.00', '0.00', '200.00', 230000, 20000, 1, false, false],
    'other receipt bears concession' => ['200.00', '300.00', '100.00', 220000, 30000, 2, false, false],
    'both owners consume concession' => ['400.00', '100.00', '200.00', 230000, 20000, 1, false, false],
    'fully conceded fee component' => ['500.00', '0.00', '500.00', 200000, 50000, 1, false, false],
    'remaining principal keeps trigger' => ['500.00', '0.00', '200.00', 230000, 20000, 1, true, false],
    'combined earnings are encumbered' => ['200.00', '300.00', '100.00', 220000, 30000, 2, false, true],
]);

test('late approval audit failure rolls back both owners of a shared fee settlement', function (): void {
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(3, feeAmountKobo: 50000, feeSource: FeeSettlementSource::ExternalReceipt);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = app(FeeObligationService::class)->assessSnapshot($plan->currentTermsRevision()->feeSnapshot, $agent);
    $data = [...collectionPayload($customer, $assignment, $plan, $date, '2000.00'), 'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '200.00']]];
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $data);
    $data = [...collectionPayload($customer, $assignment, $plan->fresh(), $date, '0'), 'plan_id' => null, 'plan_version' => null,
        'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '300.00']]];
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $data);
    $original = LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id);
    $service = app(ReversalService::class);
    $quote = $service->preview($agent, $original);
    $reversal = $service->submit($agent, $original, ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'customer_version' => $customer->version, 'assignment_version' => $assignment->version,
        'reason_category' => 'wrong_amount_allocation', 'internal_reason' => 'Complete shared fee settlement is controlled.',
        'customer_explanation' => 'The full receipt and cycle fee are being corrected.', 'evidence_text' => 'Original tender reviewed.', 'confirmed' => true]);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::ReversalsReview);
    $review = $service->reviewPreview($admin, $reversal);
    $request = Request::create('/reversals/approve', 'POST');
    $session = new Store('shared-fee-audit', new ArraySessionHandler(600));
    $session->put(cashSession());
    $request->setLaravelSession($session);
    $observedRefunds = null;
    Event::listen('eloquent.creating: '.AuditEvent::class, function (AuditEvent $event) use (&$observedRefunds): void {
        if ($event->event_type === 'reversal.approved_posted') {
            $observedRefunds = FeeRefund::query()->count();
            throw new RuntimeException('Approval audit unavailable.');
        }
    });

    expect(fn () => $service->decide($admin, $reversal, 'approve', ['attempt_reference' => (string) Str::uuid(),
        'version' => $reversal->version, 'preview_fingerprint' => $review['preview_fingerprint'], 'decision_reason' => 'Shared settlement reviewed.', 'confirmed' => true], $request))
        ->toThrow(RuntimeException::class, 'Approval audit unavailable.');
    expect($observedRefunds)->toBe(1);
    expect($reversal->fresh()->state)->toBe('pending_review')->and($reversal->fresh()->compensation_posting_group_id)->toBeNull();
    expect($fee->fresh()->settledAmountKobo())->toBe(50000);
    expect($fee->fresh()->assessedAmountKobo())->toBe(50000);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::UnappliedFunds))->toBe(0);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe(0);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
    $this->assertDatabaseCount('fee_refunds', 0);
    $this->assertDatabaseCount('collection_allocation_releases', 0);
    $this->assertDatabaseCount('reversal_attempts', 1);
    expect(LedgerPostingGroup::query()->where('event_type', 'receipt_reclassification')->count())->toBe(0);
});

function settledNoncashCycleFixture(object $test, string $method, bool $settleClearing = true): array
{
    [$agent, $customer, $assignment, $plan, $date, $admin, , $payload] = noncashFixture($test, $method,
        $method === 'transfer' ? 'business_bank_ngn' : 'payment_clearing_ngn');
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $receipt = postNoncashReceipt($test, $customer, $payload);
    $test->travel(1)->days();
    $test->artisan('collections:freeze-batches')->assertSuccessful();
    $test->travelBack();
    $test->actingAs($admin)->withSession(cashSession());
    if ($method === 'pos' && $settleClearing) {
        $bank = $test->postJson(route('collection-methods.store'), [
            'publication_reference' => (string) Str::uuid(), 'method_key' => 'transfer', 'version' => 1,
            'label' => 'Cycle settlement bank', 'custody_account_code' => 'business_bank_ngn', 'mapping_version' => 1,
            'destination_key' => 'cycle-bank', 'attachment_required' => true, 'reason' => 'Reviewed bank settlement destination.',
        ])->assertCreated()->json('method_version_id');
        $test->postJson(route('collection-batches.settlements.store', $receipt->batch), [
            'settlement_reference' => (string) Str::uuid(), 'batch_version' => $receipt->batch->fresh()->version,
            'bank_method_version_id' => $bank, 'bank_reference' => 'SETTLED-CYCLE-1', 'settled_date' => $date,
            'amount_ngn' => '2000.00', 'source_attestation' => 'Full original captured tender independently credited by bank.',
            'reason' => 'Confirmed complete bank settlement.', 'confirmed' => true,
            'files' => [UploadedFile::fake()->image('cycle-bank.png')],
        ])->assertCreated();
    }
    if ($method !== 'pos' || $settleClearing) {
        $test->post(route('collection-batches.review', $receipt->batch), [
            'batch_version' => $receipt->batch->fresh()->version, 'reason' => 'Original method custody and payment proof fully reconcile.', 'confirmed' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }
    $reversal = approveReceiptCorrection($test, $agent, $customer, $assignment, LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id))->fresh();
    $fee = reportFeeObligation($agent, $customer, 200000);
    $data = [...collectionPayload($customer, $assignment, $plan->fresh(), $date, '0'),
        'plan_id' => null, 'plan_version' => null, 'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '2000.00']]];
    $replacement = app(CollectionReplacementService::class);
    $quote = $replacement->preview($agent, $reversal, $data);
    $replacement->record($agent, $reversal, [...$data, 'preview_fingerprint' => $quote['preview_fingerprint'], 'replacement_fingerprint' => $quote['replacement_fingerprint']]);
    $service = app(PlanSettlementService::class);
    $quote = $service->preview($agent, $plan->fresh(), 'prepare_termination');
    $service->confirm($agent, $plan->fresh(), 'prepare_termination', ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'reason' => 'Settle the used cycle after complete controlled-tender allocation.',
        'customer_explanation' => 'Original financial history remains; no cycle principal or fee remains.']);

    return [$agent, $plan->fresh(), $receipt->batch->fresh(), $admin];
}

test('settled bank and clearing cycle closure uses actual custody without requiring cash remittance', function (string $method): void {
    [$agent, $plan, $batch] = settledNoncashCycleFixture($this, $method);
    $service = app(PlanSettlementService::class);
    $quote = $service->preview($agent, $plan);
    expect($quote['can_close'])->toBeTrue();
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    $slots = $plan->slots()->pluck('id')->all();
    $this->actingAs($agent)->get(route('plans.settlement', $plan))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('preview.custody_fingerprint', $quote['custody_fingerprint'])->missing('preview.custody'));
    $this->actingAs($agent)->post(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'close']), [
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'reason' => 'Every original custody and cycle settlement owner now confirms zero.', 'customer_explanation' => 'Settled cycle closed; original history retained.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Closed);
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
    expect($plan->slots()->pluck('id')->all())->toBe($slots);
    expect($batch->remittances()->count())->toBe(0);
})->with(['verified transfer' => 'transfer', 'fully settled POS' => 'pos']);

test('cycle closure rejects missing damaged or newer original custody evidence', function (string $damage): void {
    [$agent, $plan, $batch, $admin] = settledNoncashCycleFixture($this, $damage === 'file' ? 'pos' : 'transfer');
    $quote = app(PlanSettlementService::class)->preview($agent, $plan);
    expect($quote['can_close'])->toBeTrue();
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    if ($damage === 'review') {
        DB::table('collection_batch_reviews')->where('collection_batch_id', $batch->id)->delete();
    } elseif ($damage === 'amount') {
        DB::table('collection_batch_reviews')->where('collection_batch_id', $batch->id)->update(['remitted_kobo' => 199999]);
    } elseif ($damage === 'version') {
        $batch->increment('version');
        expect(app(PlanSettlementService::class)->preview($agent, $plan)['can_close'])->toBeTrue();
    } elseif ($damage === 'file') {
        Storage::disk('collection_evidence')->delete(DB::table('collection_settlement_files')->value('storage_path'));
    } else {
        CollectionException::create(['collection_batch_id' => $batch->id, 'opened_by_user_id' => $admin->id,
            'kind' => 'missing_transfer', 'amount_kobo' => 200000, 'status' => 'resolved', 'reason' => 'Unsupported resolved label without durable approval.']);
    }
    $this->actingAs($agent)->post(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'close']), [
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'reason' => 'Previously reviewed custody no longer has the required owner evidence.', 'customer_explanation' => 'Retain cycle until authoritative settlement can be verified.', 'confirmed' => true,
    ])->assertStatus($damage === 'file' ? 503 : 409);
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Paused);
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
    expect($plan->lifecycleEvents()->where('event_type', 'closed')->count())->toBe(0);
})->with(['missing review' => 'review', 'wrong reviewed custody' => 'amount', 'new custody epoch' => 'version', 'missing bank settlement file' => 'file', 'unsupported resolved exception' => 'exception']);

test('clearing capture still blocks cycle closure after principal has been completely corrected and allocated', function (): void {
    [$agent, $plan, $batch] = settledNoncashCycleFixture($this, 'pos', settleClearing: false);
    $quote = app(PlanSettlementService::class)->preview($agent, $plan);
    expect($quote['position']['cycle_liability_kobo'])->toBe(0);
    expect($quote['can_close'])->toBeFalse();
    expect($quote['blockers'])->toContain('Original custody and reconciliation remain unresolved.');
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    $this->actingAs($agent)->post(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'close']), [
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'reason' => 'Zero principal does not settle original terminal capture.', 'customer_explanation' => 'Actual bank settlement is still required.', 'confirmed' => true,
    ])->assertConflict();
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Paused);
    expect(app(CollectionBatchPosition::class)->read($batch)['outstanding_kobo'])->toBe(200000);
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
});

function cycleClosureFeeRows(): array
{
    $rows = [];
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'plan_lifecycle_events',
        'manual_charges', 'fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'fee_refunds',
        'collection_receipts', 'collection_allocations', 'ledger_posting_groups', 'ledger_entries',
        'financial_workflow_supplements', 'plan_notification_intents', 'audit_events', 'canonical_audit_events'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

test('separately assessed manual cycle debt rejects both old and newly reviewed closure instructions', function (): void {
    config()->set('fees.manual_charges_enabled', true);
    [$agent, $customer, , $plan] = collectionFixture();
    $settlement = app(PlanSettlementService::class);
    $prepare = $settlement->preview($agent, $plan, 'prepare_termination');
    $settlement->confirm($agent, $plan, 'prepare_termination', [
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $prepare['preview_fingerprint'],
        'reason' => 'Customer requested separate unused cycle settlement.', 'customer_explanation' => 'Your dated cycle is prepared for closure.',
    ]);
    $plan->refresh();
    $reviewed = $settlement->preview($agent, $plan);
    expect($reviewed['can_close'])->toBeTrue();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $this->actingAs($admin)->withSession(cashSession())->post(route('admin.charges.publish'), [
        'publication_reference' => (string) Str::uuid(), 'category_key' => 'cycle-closure-service', 'kind' => 'manual_fee',
        'purpose' => 'Approved service category', 'customer_description' => 'Agreed separate cycle service fee.',
        'amount_ngn' => '100.01', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $category = ChargeCategoryVersion::query()->where('category_key', 'cycle-closure-service')->sole();
    $reference = (string) Str::uuid();
    $this->post(route('admin.charges.assess'), reviewManualCharge($this, [
        'operation_reference' => $reference, 'customer_id' => $customer->customer_id, 'plan_id' => $plan->plan_id,
        'category_id' => $category->id, 'customer_version' => $customer->version, 'plan_version' => $plan->version,
        'reason' => 'Customer agreed separately priced cycle service.', 'confirmed' => true,
    ]))->assertRedirect()->assertSessionHasNoErrors();
    $charge = ManualCharge::query()->where('operation_reference', $reference)->sole();
    $fee = FeeObligation::query()->findOrFail($charge->fee_obligation_id);
    expect($fee->outstandingAmountKobo())->toBe(10001)
        ->and($fee->fee_snapshot_id)->not->toBe($plan->currentTermsRevision()->fee_snapshot_id)
        ->and($plan->fresh()->version)->toBe($plan->version);
    $baseline = cycleClosureFeeRows();
    $instructions = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $reviewed['preview_fingerprint'],
        'reason' => 'Close the reviewed settled cycle.', 'customer_explanation' => 'Your cycle is closed after separate settlement.'];
    expect(fn () => $settlement->confirm($agent, $plan, 'close', $instructions))->toThrow(ConflictHttpException::class);
    $current = $settlement->preview($agent, $plan->fresh());
    expect($current['can_close'])->toBeFalse()->and($current['blockers'])->toContain('Outstanding cycle fee '.$fee->id)
        ->and(collect($current['fee_history'])->pluck(0)->all())->toContain($fee->id);
    expect(fn () => $settlement->confirm($agent, $plan, 'close', [...$instructions,
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $current['preview_fingerprint']]))
        ->toThrow(ConflictHttpException::class, 'settlement gates');
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Paused)->and(cycleClosureFeeRows())->toEqual($baseline);
});

/** @return array{User, CustomerProfile, ThriftPlan, User, ManualCharge, FeeObligation} */
function closureManualFeeFixture(object $test): array
{
    config()->set('fees.manual_charges_enabled', true);
    [$agent, $customer, , $plan] = collectionFixture();
    $owner = app(PlanSettlementService::class);
    $prepare = $owner->preview($agent, $plan, 'prepare_termination');
    $owner->confirm($agent, $plan, 'prepare_termination', ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $prepare['preview_fingerprint'], 'reason' => 'Unused cycle enters separate settlement.',
        'customer_explanation' => 'Your dated cycle is prepared for separate settlement.']);
    $plan->refresh();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $test->actingAs($admin)->withSession(cashSession())->post(route('admin.charges.publish'), [
        'publication_reference' => (string) Str::uuid(), 'category_key' => 'separate-cycle-service', 'kind' => 'manual_fee',
        'purpose' => 'Approved service category', 'customer_description' => 'Agreed separate cycle fee.',
        'amount_ngn' => '100.01', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $category = ChargeCategoryVersion::query()->where('category_key', 'separate-cycle-service')->sole();
    $reference = (string) Str::uuid();
    $test->post(route('admin.charges.assess'), reviewManualCharge($test, ['operation_reference' => $reference,
        'customer_id' => $customer->customer_id, 'plan_id' => $plan->plan_id, 'category_id' => $category->id,
        'customer_version' => $customer->version, 'plan_version' => $plan->version,
        'reason' => 'Customer agreed separate cycle service.', 'confirmed' => true]))->assertRedirect()->assertSessionHasNoErrors();
    $charge = ManualCharge::query()->where('operation_reference', $reference)->sole();

    return [$agent, $customer, $plan, $admin, $charge, FeeObligation::query()->findOrFail($charge->fee_obligation_id)];
}

test('a settled manual cycle fee permits closure with unrelated registration debt but damaged attribution blocks it', function (string $damage): void {
    [$agent, $customer, $plan, $admin, $charge, $fee] = closureManualFeeFixture($this);
    $registration = reportFeeObligation($agent, $customer, 50000);
    $request = Request::create('/cycle-fee-waiver', 'POST');
    $session = new Store('cycle-fee-waiver', new ArraySessionHandler(600));
    $session->put(cashSession());
    $request->setLaravelSession($session);
    app(FeeObligationService::class)->waive($admin, $fee->id, 10001, 'Separate service fee concession approved.',
        'Your separate service fee was waived.', (string) Str::uuid(), $request);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(0)->and($registration->fresh()->outstandingAmountKobo())->toBe(50000);
    if ($damage === 'missing binding') {
        DB::table('manual_charges')->where('id', $charge->id)->update(['fee_obligation_id' => null]);
    } elseif ($damage === 'orphan reference') {
        DB::table('fee_obligations')->where('id', $fee->id)->update(['source_id' => 'UNVERIFIED-MANUAL-OWNER']);
    } elseif ($damage === 'category rule') {
        DB::table('charge_category_versions')->where('id', $charge->charge_category_version_id)->update([
            'fee_rule_id' => $plan->currentTermsRevision()->feeSnapshot->fee_rule_id,
        ]);
    }
    $baseline = cycleClosureFeeRows();
    $owner = app(PlanSettlementService::class);
    $quote = $owner->preview($agent, $plan->fresh());
    $data = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'reason' => 'Reviewed complete cycle obligations.', 'customer_explanation' => 'Your cycle closes after separate settlement.'];
    expect($quote['can_close'])->toBe($damage === 'none');
    if ($damage === 'none') {
        expect(collect($quote['fee_history'])->pluck(0)->all())->toBe([$fee->id]);
        $owner->confirm($agent, $plan, 'close', $data);
        $owner->confirm($agent, $plan, 'close', $data);
        expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Closed)
            ->and($registration->fresh()->outstandingAmountKobo())->toBe(50000)
            ->and($fee->fresh()->outstandingAmountKobo())->toBe(0);
        $this->assertDatabaseCount('ledger_posting_groups', 0);
        $plans = app(ThriftPlanService::class);
        $rule = $plan->currentTermsRevision()->feeSnapshot->feeRule;
        $terms = ['name' => 'Independent next dated cycle', 'amount_ngn' => '2000.00',
            'start_date' => now('Africa/Lagos')->toDateString(), 'contribution_days' => 2,
            'customer_visible_notes' => '', 'fee_rule_id' => $rule->id, 'fee_rule_version' => $rule->version,
            'customer_version' => $customer->fresh()->version, 'assignment_version' => $customer->currentAssignment->version,
            'business_version' => BusinessProfile::current()->version, 'customer_agreement_attested' => true];
        $terms['preview_fingerprint'] = $plans->preview($agent, $customer->fresh(), $terms)['preview_fingerprint'];
        $next = $plans->create($agent, $customer->fresh(), (string) Str::uuid(), $terms)['plan'];
        $nextQuote = $owner->preview($agent, $next);
        expect($nextQuote['can_close'])->toBeTrue()->and($nextQuote['fee_history'])->toBe([])
            ->and($fee->fresh()->outstandingAmountKobo())->toBe(0)
            ->and($registration->fresh()->outstandingAmountKobo())->toBe(50000);
    } else {
        expect($quote['blockers'])->toContain('Financial obligation attribution is unavailable.');
        expect(fn () => $owner->confirm($agent, $plan, 'close', $data))->toThrow(ConflictHttpException::class, 'settlement gates');
        expect(cycleClosureFeeRows())->toEqual($baseline)->and($plan->fresh()->status)->toBe(ThriftPlanStatus::Paused);
    }
})->with(['none', 'missing binding', 'orphan reference', 'category rule']);

test('fee-only cycle cash custody must reconcile before otherwise settled closure', function (bool $pendingCorrection, string $sourceCase): void {
    [$agent, $customer, $plan, $admin, , $fee] = closureManualFeeFixture($this);
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $owner = app(PlanSettlementService::class);
    $previous = $owner->preview($agent, $plan->fresh());
    $date = now('Africa/Lagos')->toDateString();
    $payment = [...collectionPayload($customer, $customer->currentAssignment, $plan, $date, '0.00'),
        'plan_id' => null, 'plan_version' => null, 'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '100.01']]];
    $payment['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payment)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $payment);
    $component = DB::table('collection_fee_components')->where('collection_receipt_id', $receipt->id)->sole();
    $group = LedgerPostingGroup::findOrFail($component->ledger_posting_group_id);
    expect($receipt->thrift_plan_id)->toBeNull()->and($receipt->savings_amount_kobo)->toBe(0)
        ->and((int) $component->fee_obligation_id)->toBe($fee->id)
        ->and($group->thrift_plan_id)->toBe($plan->id)
        ->and($group->customer_profile_id)->toBe($customer->id)
        ->and($group->source_type)->toBe('collection_receipt')
        ->and($fee->fresh()->outstandingAmountKobo())->toBe(0)
        ->and($fee->fresh()->settledAmountKobo())->toBe(10001);
    $custody = app(CollectionBatchPosition::class)->read($receipt->batch);
    expect($custody['expected_kobo'])->toBe(10001)->and($custody['outstanding_kobo'])->toBe(10001);
    $baseline = cycleClosureFeeRows();
    $custodyRows = [];
    foreach (['collection_batches', 'collection_fee_components', 'cash_remittances', 'collection_batch_reviews'] as $table) {
        $custodyRows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $current = $owner->preview($agent, $plan->fresh());
    expect($current['position']['cycle_liability_kobo'])->toBe(0)
        ->and($current['position']['cycle_reservations_kobo'])->toBe(0)
        ->and($current['can_close'])->toBeFalse()
        ->and($current['blockers'])->toContain('Original custody and reconciliation remain unresolved.');
    foreach ([$previous, $current] as $quote) {
        expect(fn () => $owner->confirm($agent, $plan->fresh(), 'close', [
            'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
            'reason' => 'Original fee-only cycle custody must be independently settled.',
            'customer_explanation' => 'Your fee payment remains recorded while its custody is reconciled.',
        ]))->toThrow(ConflictHttpException::class);
    }
    expect(cycleClosureFeeRows())->toEqual($baseline);
    foreach ($custodyRows as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
    $admin->givePermissionTo(AdminPermission::ReconciliationManage);
    percentageReconcileCash($this, $admin, $receipt->batch, '100.01', $date);
    $ready = $owner->preview($agent, $plan->fresh());
    expect($ready['can_close'])->toBeTrue();
    if (in_array($sourceCase, ['missing component', 'crossed journal'], true)) {
        if ($sourceCase === 'missing component') {
            DB::table('collection_fee_components')->where('id', $component->id)->delete();
        } else {
            $remittance = DB::table('cash_remittances')->where('collection_batch_id', $receipt->collection_batch_id)->sole();
            expect((int) $remittance->ledger_posting_group_id)->not->toBe($group->id);
            DB::table('collection_fee_components')->where('id', $component->id)
                ->update(['ledger_posting_group_id' => $remittance->ledger_posting_group_id]);
        }
        $damagedRows = cycleClosureFeeRows();
        $damagedCustodyRows = [];
        foreach (['collection_batches', 'collection_fee_components', 'cash_remittances', 'collection_batch_reviews'] as $table) {
            $damagedCustodyRows[$table] = DB::table($table)->orderBy('id')->get()->all();
        }
        $damaged = $owner->preview($agent, $plan->fresh());
        expect($damaged['can_close'])->toBeFalse()
            ->and($damaged['blockers'])->toContain('Cycle fee receipt custody attribution is unavailable.');
        foreach ([$ready, $damaged] as $reviewed) {
            expect(fn () => $owner->confirm($agent, $plan->fresh(), 'close', [
                'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $reviewed['preview_fingerprint'],
                'reason' => 'Original fee receipt custody needs verified retained ownership.',
                'customer_explanation' => 'Your original fee evidence must be verified before closure.',
            ]))->toThrow(ConflictHttpException::class);
        }
        expect(cycleClosureFeeRows())->toEqual($damagedRows);
        foreach ($damagedCustodyRows as $table => $rows) {
            expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
        }

        return;
    }
    if ($sourceCase === 'registration-only batch') {
        $registration = reportFeeObligation($agent, $customer, 50000);
        $this->travel(1)->days();
        $registrationPayment = [...collectionPayload($customer, $customer->currentAssignment, $plan,
            now('Africa/Lagos')->toDateString(), '0.00'), 'plan_id' => null, 'plan_version' => null,
            'fees' => [['obligation_id' => $registration->id, 'amount_ngn' => '500.00']]];
        $registrationPayment['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $registrationPayment)['preview_fingerprint'];
        $registrationReceipt = app(CollectionService::class)->record($agent, $customer, $registrationPayment);
        expect($registrationReceipt->collection_batch_id)->not->toBe($receipt->collection_batch_id);
        $registrationGroupId = DB::table('collection_fee_components')->where('collection_receipt_id', $registrationReceipt->id)
            ->value('ledger_posting_group_id');
        expect(LedgerPostingGroup::findOrFail($registrationGroupId)->thrift_plan_id)->toBeNull()
            ->and($registration->fresh()->outstandingAmountKobo())->toBe(0)
            ->and(app(CollectionBatchPosition::class)->read($registrationReceipt->batch)['outstanding_kobo'])->toBe(50000);
        $ready = $owner->preview($agent, $plan->fresh());
        expect($ready['can_close'])->toBeTrue();
    }
    if ($pendingCorrection) {
        $corrections = app(ReversalService::class);
        $quote = $corrections->preview($agent, $group);
        $correction = $corrections->submit($agent, $group, ['attempt_reference' => (string) Str::uuid(),
            'preview_fingerprint' => $quote['preview_fingerprint'], 'customer_version' => $quote['customer_version'],
            'assignment_version' => $quote['assignment_version'], 'reason_category' => 'wrong_amount_allocation',
            'internal_reason' => 'Review the complete original cycle fee tender.',
            'customer_explanation' => 'Your recorded fee payment is independently reviewed.',
            'evidence_text' => 'Original fee receipt and settled custody retained.', 'confirmed' => true]);
        expect($correction->state)->toBe('pending_review');
        $pending = $owner->preview($agent, $plan->fresh());
        expect($pending['position']['cycle_liability_kobo'])->toBe(0)
            ->and($pending['position']['cycle_reservations_kobo'])->toBe(0)
            ->and($pending['can_close'])->toBeFalse()
            ->and($pending['blockers'])->toBe(['Pending financial owner work remains.']);
        $beforePending = cycleClosureFeeRows();
        $requestsBefore = DB::table('reversal_requests')->orderBy('id')->get()->all();
        foreach ([$ready, $pending] as $reviewed) {
            expect(fn () => $owner->confirm($agent, $plan->fresh(), 'close', [
                'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $reviewed['preview_fingerprint'],
                'reason' => 'Pending fee receipt correction needs independent disposition.',
                'customer_explanation' => 'Your cycle waits for independent review of the original fee receipt.',
            ]))->toThrow(ConflictHttpException::class);
        }
        expect(cycleClosureFeeRows())->toEqual($beforePending)
            ->and(DB::table('reversal_requests')->orderBy('id')->get()->all())->toEqual($requestsBefore);
        $reviewer = User::factory()->admin()->withTwoFactor()->create();
        $reviewer->givePermissionTo(AdminPermission::ReversalsReview);
        $this->actingAs($reviewer)->withSession(cashSession())->post(route('reversals.reject', $correction), [
            'attempt_reference' => (string) Str::uuid(), 'version' => $correction->version,
            'decision_reason' => 'Independent review verified the original fee payment and custody.', 'confirmed' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        expect($correction->fresh()->state)->toBe('rejected')
            ->and($correction->fresh()->reviewed_by_user_id)->toBe($reviewer->id)
            ->and($correction->fresh()->compensation_posting_group_id)->toBeNull();
        foreach (['ledger_posting_groups', 'ledger_entries', 'collection_receipts', 'fee_obligations', 'fee_obligation_entries'] as $table) {
            expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($beforePending[$table]);
        }
        $ready = $owner->preview($agent, $plan->fresh());
        expect($ready['can_close'])->toBeTrue();
    }
    $financialRows = [];
    foreach (['manual_charges', 'fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'collection_receipts',
        'collection_fee_components', 'collection_batches', 'cash_remittances', 'collection_batch_reviews',
        'ledger_posting_groups', 'ledger_entries'] as $table) {
        $financialRows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $instructions = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $ready['preview_fingerprint'],
        'reason' => 'Independent owner review reconciled the original fee-only cash.',
        'customer_explanation' => 'Your settled cycle closes with its original fee payment retained.'];
    $owner->confirm($agent, $plan, 'close', $instructions);
    $owner->confirm($agent, $plan, 'close', $instructions);
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Closed)
        ->and($plan->lifecycleEvents()->where('event_type', 'closed')->count())->toBe(1);
    foreach ($financialRows as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
})->with([
    'Reconciled fee receipt' => [false, 'none'],
    'Pending correction then independent rejection' => [true, 'none'],
    'Missing original fee component' => [false, 'missing component'],
    'Crossed original component journal' => [false, 'crossed journal'],
    'Unrelated registration-only cash batch' => [false, 'registration-only batch'],
]);

test('a separately paid manual cycle fee retains its external refund payable gate after custody is reconciled', function (): void {
    [$agent, $customer, $plan, $admin, , $fee] = closureManualFeeFixture($this);
    config()->set(['collections.enabled' => true, 'fees.refunds_enabled' => true]);
    reportFeeObligation($agent, $customer, 50000);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $date = now('Africa/Lagos')->toDateString();
    $payment = [...collectionPayload($customer, $customer->currentAssignment, $plan, $date, '0.00'),
        'plan_id' => null, 'plan_version' => null, 'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '100.01']]];
    $payment['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payment)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $payment);
    $admin->givePermissionTo(AdminPermission::ReconciliationManage);
    percentageReconcileCash($this, $admin, $receipt->batch, '100.01', $date);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(0)->and($fee->fresh()->settledAmountKobo())->toBe(10001);
    $owner = app(PlanSettlementService::class);
    $reviewed = $owner->preview($agent, $plan->fresh());
    expect($reviewed['can_close'])->toBeTrue();
    $request = Request::create('/manual-cycle-refund', 'POST');
    $session = new Store('manual-cycle-refund', new ArraySessionHandler(600));
    $session->put(cashSession());
    $request->setLaravelSession($session);
    $refund = app(FeeRefundService::class)->authorizeRefund($admin, $fee->fresh(), (string) Str::uuid(),
        'external', 500, 'Approved retained manual service fee concession.', $request);
    expect($refund->amount_kobo)->toBe(500)
        ->and(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe(500);
    $baseline = cycleClosureFeeRows();
    $current = $owner->preview($agent, $plan->fresh());
    expect($current['can_close'])->toBeFalse()->and($current['blockers'])->toContain('Cycle refund payable remains.')
        ->and(collect($current['fee_history'])->pluck(0)->all())->toContain($fee->id);
    foreach ([$reviewed, $current] as $quote) {
        expect(fn () => $owner->confirm($agent, $plan, 'close', ['attempt_reference' => (string) Str::uuid(),
            'preview_fingerprint' => $quote['preview_fingerprint'], 'reason' => 'Close after complete settlement.',
            'customer_explanation' => 'Your dated cycle is closed after settlement.']))->toThrow(ConflictHttpException::class);
    }
    expect(cycleClosureFeeRows())->toEqual($baseline)->and($plan->fresh()->status)->toBe(ThriftPlanStatus::Paused)
        ->and($fee->fresh()->outstandingAmountKobo())->toBe(0)
        ->and(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe(500);
});

/** @return array{User, CustomerProfile, ThriftPlan} */
function closureNoFeeAgreementFixture(object $test, User $admin, CustomerProfile $customer, User $agent, bool $amend = false): array
{
    $rule = FeeRule::create(['version' => 1, 'name' => 'Explicit nofee cycle agreement', 'kind' => 'plan',
        'rule_key' => 'closure-nofee', 'model' => 'no_fee', 'timing' => 'first_contribution', 'basis' => 'none',
        'settlement_source' => 'external_receipt', 'currency' => 'NGN', 'amount_kobo' => 0,
        'customer_description' => 'No fee under these dated cycle terms.', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $admin->id, 'publication_reason' => 'Explicit isolated nofee agreement.']);
    $terms = ['name' => 'Dated nofee closure cycle', 'amount_ngn' => '2000.00', 'start_date' => now('Africa/Lagos')->toDateString(),
        'contribution_days' => 2, 'customer_visible_notes' => '', 'fee_rule_id' => $rule->id, 'fee_rule_version' => $rule->version,
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'business_version' => BusinessProfile::current()->version, 'customer_agreement_attested' => true];
    $plans = app(ThriftPlanService::class);
    $terms['preview_fingerprint'] = $plans->preview($agent, $customer, $terms)['preview_fingerprint'];
    $plan = $plans->create($agent, $customer, (string) Str::uuid(), $terms)['plan'];
    if ($amend) {
        $amendment = [...$terms, 'name' => 'Revised dated nofee cycle', 'amount_ngn' => '2500.00',
            'contribution_days' => 3, 'plan_version' => $plan->version, 'terms_revision' => $plan->current_terms_revision,
            'reason' => 'Customer reviewed a distinct financial schedule before activity.',
            'customer_explanation' => 'Your agreed revised dated schedule is retained.'];
        $amendment['preview_fingerprint'] = $plans->previewRevision($agent, $plan, $amendment)['preview_fingerprint'];
        $plan = $plans->revise($agent, $plan, (string) Str::uuid(), $amendment);
    }
    $owner = app(PlanSettlementService::class);
    $quote = $owner->preview($agent, $plan, 'prepare_termination');
    $owner->confirm($agent, $plan, 'prepare_termination', ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'reason' => 'Customer requested separate unused cycle closure.',
        'customer_explanation' => 'Your dated cycle is prepared for separate settlement.']);

    return [$agent, $customer, $plan->fresh()];
}

test('zero fee closure requires verified current and original agreement evidence without any obligation', function (string $damage): void {
    [$admin, $customer, $profile] = $this->createLifecycleFixture();
    [$agent, $customer, $plan] = closureNoFeeAgreementFixture($this, $admin, $customer, $profile->user,
        in_array($damage, ['original source', 'terms snapshot'], true));
    $owner = app(PlanSettlementService::class);
    $reviewed = $owner->preview($agent, $plan);
    expect($reviewed['can_close'])->toBeTrue();
    $current = $plan->currentTermsRevision();
    $snapshot = $current->feeSnapshot;
    $this->assertDatabaseCount('fee_obligations', 0);
    if ($damage === 'rule version') {
        DB::table('fee_snapshots')->where('id', $snapshot->id)->update(['fee_rule_version' => 999]);
    } elseif ($damage === 'crossed rule') {
        $registration = $customer->feeSnapshots()->where('kind', 'registration')->sole();
        DB::table('fee_snapshots')->where('id', $snapshot->id)->update(['fee_rule_id' => $registration->fee_rule_id]);
    } elseif ($damage === 'current source') {
        DB::table('fee_snapshots')->where('id', $snapshot->id)->update(['source_id' => 'UNVERIFIED-CYCLE-R1']);
    } elseif ($damage === 'customer') {
        $foreign = CustomerProfile::factory()->create();
        DB::table('fee_snapshots')->where('id', $snapshot->id)->update(['customer_profile_id' => $foreign->id]);
    } elseif ($damage === 'currency') {
        DB::table('fee_snapshots')->where('id', $snapshot->id)->update(['currency' => 'USD']);
    } elseif ($damage === 'zero quote amount') {
        DB::table('fee_snapshots')->where('id', $snapshot->id)->update(['amount_kobo' => 1]);
    } elseif ($damage === 'original source') {
        $original = $plan->termsRevisions()->where('revision', 1)->sole();
        expect($original->fee_snapshot_id)->not->toBe($snapshot->id);
        DB::table('fee_snapshots')->where('id', $original->fee_snapshot_id)->update(['source_id' => 'UNVERIFIED-ORIGINAL-R1']);
    } elseif ($damage === 'terms snapshot') {
        $original = $plan->termsRevisions()->where('revision', 1)->sole();
        DB::table('plan_terms_revisions')->where('id', $current->id)->update(['fee_snapshot_id' => $original->fee_snapshot_id]);
    } elseif ($damage === 'missing current terms') {
        DB::table('thrift_plans')->where('id', $plan->id)->update(['current_terms_revision' => 999]);
        $plan->refresh();
    }
    $baseline = cycleClosureFeeRows();
    $data = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $reviewed['preview_fingerprint'],
        'reason' => 'Close only after verified original nofee agreement.', 'customer_explanation' => 'Your settled cycle is closed.'];
    if ($damage === 'none') {
        $owner->confirm($agent, $plan, 'close', $data);
        $owner->confirm($agent, $plan, 'close', $data);
        expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Closed);
        $this->assertDatabaseCount('ledger_posting_groups', 0);
        $this->assertDatabaseCount('fee_obligations', 0);
    } else {
        expect(fn () => $owner->confirm($agent, $plan, 'close', $data))->toThrow(ConflictHttpException::class);
        $quote = $owner->preview($agent, $plan->fresh());
        expect($quote['can_close'])->toBeFalse()->and($quote['blockers'])->toContain('Cycle fee disposition is unavailable.');
        expect(fn () => $owner->confirm($agent, $plan, 'close', [...$data,
            'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint']]))
            ->toThrow(ConflictHttpException::class, 'settlement gates');
        expect(cycleClosureFeeRows())->toEqual($baseline)->and($plan->fresh()->status)->toBe(ThriftPlanStatus::Paused);
        $this->assertDatabaseCount('ledger_posting_groups', 0);
        $this->assertDatabaseCount('fee_obligations', 0);
    }
})->with(['none', 'rule version', 'crossed rule', 'current source', 'customer', 'currency', 'zero quote amount',
    'original source', 'terms snapshot', 'missing current terms']);

test('posted payout return blocks ready cycle closure while its owner disposition remains unsettled', function (bool $acknowledged): void {
    config()->set(['collections.enabled' => true, 'withdrawals.cash_enabled' => true, 'withdrawals.cash_certified' => true]);
    [$admin, $customer, $agentProfile] = $this->createLifecycleFixture();
    $admin->givePermissionTo([AdminPermission::WithdrawalsReview, AdminPermission::CashExecute, AdminPermission::ReconciliationManage]);
    $agent = $agentProfile->user;
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $month = now('Africa/Lagos')->startOfMonth()->toDateString();
    if (! FinancialPeriod::query()->whereDate('month', $month)->exists()) {
        FinancialPeriod::factory()->create(['month' => $month]);
    }
    enableFixtureMethod();
    $rule = FeeRule::create(['version' => 1, 'name' => 'Explicit nofee payout return agreement', 'kind' => 'plan',
        'rule_key' => 'closure-return', 'model' => 'no_fee', 'timing' => 'first_contribution', 'basis' => 'none',
        'settlement_source' => 'external_receipt', 'currency' => 'NGN', 'amount_kobo' => 0,
        'customer_description' => 'No fee under the original completed cycle.', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $admin->id, 'publication_reason' => 'Explicit isolated existing nofee agreement.']);
    $terms = ['name' => 'Completed cycle with an actual payout', 'amount_ngn' => '2000.00',
        'start_date' => now('Africa/Lagos')->toDateString(), 'contribution_days' => 1, 'customer_visible_notes' => '',
        'fee_rule_id' => $rule->id, 'fee_rule_version' => 1, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => BusinessProfile::current()->version,
        'customer_agreement_attested' => true];
    $plans = app(ThriftPlanService::class);
    $terms['preview_fingerprint'] = $plans->preview($agent, $customer, $terms)['preview_fingerprint'];
    $plan = $plans->create($agent, $customer, (string) Str::uuid(), $terms)['plan'];
    $date = $terms['start_date'];
    $payload = collectionPayload($customer, $customer->currentAssignment, $plan, $date, '2000.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $payload);
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Completed);
    percentageReconcileCash($this, $admin, $receipt->batch, '2000.00', $date);
    $execution = percentageCashPayout($this, $admin, $agent, $customer, $plan->fresh(), '2000.00', 'end_of_cycle');
    $settlement = app(PlanSettlementService::class);
    $ready = $settlement->preview($agent, $plan->fresh());
    expect($ready['can_close'])->toBeTrue();
    expect($ready['position']['cycle_liability_kobo'])->toBe(0);
    expect($ready['position']['cycle_reservations_kobo'])->toBe(0);
    $httpRequest = Request::create('/');
    $session = new Store('closure-return', new ArraySessionHandler(600));
    $session->put(cashSession());
    $httpRequest->setLaravelSession($session);
    $returns = app(CashRecoveryService::class);
    $recovery = $returns->recordReturn($admin, $execution, (string) Str::uuid(), 'Custodian counted the complete original payout return.', $httpRequest, 200000);
    if ($acknowledged) {
        $returns->acknowledgeReturn($customer->user, $recovery);
    }
    expect($recovery->fresh()->status)->toBe($acknowledged ? 'confirmed' : 'awaiting_customer');
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::CashRecoveryClearing))->toBe($acknowledged ? 200000 : 0);
    $before = [];
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'plan_lifecycle_events', 'plan_operation_attempts',
        'financial_workflow_supplements', 'fee_snapshots', 'fee_obligations', 'fee_obligation_entries',
        'collection_receipts', 'collection_allocations', 'collection_batches', 'collection_batch_reviews', 'cash_remittances',
        'ledger_posting_groups', 'ledger_entries', 'withdrawal_requests', 'withdrawal_reservations', 'withdrawal_events',
        'cash_executions', 'cash_recoveries', 'reversal_requests', 'cash_disbursements'] as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $current = $settlement->preview($agent, $plan->fresh());
    expect($current['can_close'])->toBeFalse();
    expect($current['blockers'])->not->toBeEmpty();
    expect($current['preview_fingerprint'])->not->toBe($ready['preview_fingerprint']);
    expect($current['position']['cycle_liability_kobo'])->toBe(0);
    expect($current['position']['cycle_reservations_kobo'])->toBe(0);
    foreach ([$ready, $current] as $quote) {
        expect(fn () => $settlement->confirm($agent, $plan->fresh(), 'close', [
            'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
            'reason' => 'Closure requires the independent payout return disposition.',
            'customer_explanation' => 'Your returned cash still requires its own settlement.',
        ]))->toThrow(ConflictHttpException::class);
    }
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Completed);
    expect($plan->fresh()->open_customer_profile_id)->toBe($customer->id);
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
})->with(['Return awaiting Customer acknowledgement' => false, 'Confirmed return awaiting compensation' => true]);

/** @return array{ThriftPlan, CashExecution} */
function paidOutClosureCycleFixture(object $test, User $admin, CustomerProfile $customer, User $agent, ?ThriftPlan $predecessor = null, string $payoutAmount = '2000.00'): array
{
    config()->set(['collections.enabled' => true, 'withdrawals.cash_enabled' => true, 'withdrawals.cash_certified' => true]);
    $admin->givePermissionTo([AdminPermission::WithdrawalsReview, AdminPermission::CashExecute, AdminPermission::ReconciliationManage]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $month = now('Africa/Lagos')->startOfMonth()->toDateString();
    if (! FinancialPeriod::query()->whereDate('month', $month)->exists()) {
        FinancialPeriod::factory()->create(['month' => $month]);
    }
    enableFixtureMethod();
    $version = ((int) FeeRule::query()->where('kind', 'plan')->max('version')) + 1;
    $rule = FeeRule::create(['version' => $version, 'name' => 'Explicit nofee completed payout agreement', 'kind' => 'plan',
        'rule_key' => 'closure-return-positive', 'model' => 'no_fee', 'timing' => 'first_contribution', 'basis' => 'none',
        'settlement_source' => 'external_receipt', 'currency' => 'NGN', 'amount_kobo' => 0,
        'customer_description' => 'No fee under this independently reviewed cycle.', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $admin->id, 'publication_reason' => 'Explicit isolated existing nofee agreement.']);
    $terms = ['name' => 'Independently settled completed cycle', 'amount_ngn' => '2000.00',
        'start_date' => now('Africa/Lagos')->toDateString(), 'contribution_days' => 1, 'customer_visible_notes' => '',
        'fee_rule_id' => $rule->id, 'fee_rule_version' => $rule->version, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => BusinessProfile::current()->version,
        'customer_agreement_attested' => true];
    if ($predecessor !== null) {
        $terms['predecessor_plan_id'] = $predecessor->plan_id;
    }
    $plans = app(ThriftPlanService::class);
    $terms['preview_fingerprint'] = $plans->preview($agent, $customer, $terms)['preview_fingerprint'];
    $plan = $plans->create($agent, $customer, (string) Str::uuid(), $terms)['plan'];
    $payload = collectionPayload($customer, $customer->currentAssignment, $plan, $terms['start_date'], '2000.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $payload);
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Completed);
    percentageReconcileCash($test, $admin, $receipt->batch, '2000.00', $terms['start_date']);
    $execution = percentageCashPayout($test, $admin, $agent, $customer, $plan->fresh(), $payoutAmount,
        $payoutAmount === '2000.00' ? 'end_of_cycle' : 'partial');

    return [$plan->fresh(), $execution];
}

function closureReturnRequest(): Request
{
    $request = Request::create('/');
    $session = new Store('closure-return-positive', new ArraySessionHandler(600));
    $session->put(cashSession());
    $request->setLaravelSession($session);

    return $request;
}

test('actual partial payout residual and live reservation independently retain closure gates', function (bool $reserve): void {
    [$admin, $customer, $profile] = $this->createLifecycleFixture();
    $agent = $profile->user;
    [$plan, $execution] = paidOutClosureCycleFixture($this, $admin, $customer, $agent, null,
        $reserve ? '1700.00' : '1999.99');
    $owner = app(PlanSettlementService::class);
    $previous = $owner->preview($agent, $plan->fresh());
    $remainingKobo = $reserve ? 30000 : 1;
    expect($execution->status)->toBe('posted')->and($plan->status)->toBe(ThriftPlanStatus::Completed)
        ->and($previous['position']['cycle_liability_kobo'])->toBe($remainingKobo)
        ->and($previous['position']['cycle_reservations_kobo'])->toBe(0);
    if ($reserve) {
        app(LedgerTransactionProjectionService::class)->rebuild();
        $withdrawals = app(WithdrawalService::class);
        $instruction = [...withdrawalPayload($customer, $customer->currentAssignment, $plan),
            'gross_ngn' => '300.00', 'type' => 'end_of_cycle'];
        $quote = $withdrawals->preview($agent, $customer->fresh(), $instruction);
        $withdrawal = $withdrawals->submit($agent, $customer, [...$instruction,
            'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
            'quote_expires_at' => $quote['quote_expires_at'], 'customer_version' => $quote['customer_version'],
            'assignment_version' => $quote['assignment_version'], 'plan_version' => $quote['plan_version'],
            'business_version' => $quote['business_version'], 'instruction_attested' => true, 'confirmed' => true]);
        expect($withdrawal->state)->toBe('pending_review');
        $reservation = DB::table('withdrawal_reservations')->where('id', $withdrawal->withdrawal_reservation_id)->sole();
        expect($reservation->status)->toBe('live')->and((int) $reservation->gross_amount_kobo)->toBe(30000)
            ->and($reservation->owner_reference)->toBe($withdrawal->withdrawal_id);
    }
    $current = $owner->preview($agent, $plan->fresh());
    expect($current['can_close'])->toBeFalse()
        ->and($current['position']['cycle_liability_kobo'])->toBe($remainingKobo)
        ->and($current['position']['cycle_reservations_kobo'])->toBe($reserve ? 30000 : 0)
        ->and($current['position']['cycle_available_kobo'])->toBe($reserve ? 0 : 1)
        ->and($current['blockers'])->toContain('Cycle savings or reservations remain.')
        ->and($current['blockers'])->not->toContain('Original custody and reconciliation remain unresolved.');
    $before = [];
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'plan_lifecycle_events',
        'collection_receipts', 'collection_allocations', 'collection_batches', 'collection_batch_reviews', 'cash_remittances',
        'fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'ledger_posting_groups', 'ledger_entries',
        'withdrawal_requests', 'withdrawal_reservations', 'withdrawal_events', 'cash_executions', 'cash_recoveries'] as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    foreach ([$previous, $current] as $reviewed) {
        expect(fn () => $owner->confirm($agent, $plan->fresh(), 'close', [
            'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $reviewed['preview_fingerprint'],
            'reason' => 'Exact residual savings and current reservation require owner settlement.',
            'customer_explanation' => 'Your remaining savings stay protected until their payout is settled.',
        ]))->toThrow(ConflictHttpException::class);
    }
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
})->with(['One kobo posted residual' => false, 'Actual live withdrawal reservation' => true]);

test('a retained other cycle payout return does not replace the selected cycle closure outcome', function (bool $acknowledged): void {
    [$admin, $customer, $profile] = $this->createLifecycleFixture();
    $agent = $profile->user;
    [$previous, $previousExecution] = paidOutClosureCycleFixture($this, $admin, $customer, $agent);
    $settlement = app(PlanSettlementService::class);
    $oldQuote = $settlement->preview($agent, $previous);
    expect($oldQuote['can_close'])->toBeTrue();
    $settlement->confirm($agent, $previous, 'close', ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $oldQuote['preview_fingerprint'], 'reason' => 'Original cycle was definitively paid and reconciled.',
        'customer_explanation' => 'Your original settled cycle closes before renewal.']);
    [$selected] = paidOutClosureCycleFixture($this, $admin, $customer->fresh(), $agent, $previous->fresh());
    $returns = app(CashRecoveryService::class);
    $return = $returns->recordReturn($admin, $previousExecution, (string) Str::uuid(),
        'A later counted return belongs only to the previous payout cycle.', closureReturnRequest(), 200000);
    if ($acknowledged) {
        $returns->acknowledgeReturn($customer->user, $return);
    }
    $returnAttributes = $return->fresh()->getAttributes();
    expect($return->fresh()->status)->toBe($acknowledged ? 'confirmed' : 'awaiting_customer');
    $quote = $settlement->preview($agent, $selected);
    expect($quote['can_close'])->toBeTrue();
    expect($quote['position']['cycle_liability_kobo'])->toBe(0);
    expect($quote['position']['cycle_reservations_kobo'])->toBe(0);
    $financialRows = [];
    foreach (['collection_receipts', 'collection_allocations', 'collection_batches', 'ledger_posting_groups', 'ledger_entries',
        'fee_snapshots', 'fee_obligations', 'withdrawal_requests', 'withdrawal_reservations', 'cash_executions', 'cash_recoveries'] as $table) {
        $financialRows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $data = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'reason' => 'Selected cycle has its own fully paid and reconciled source.',
        'customer_explanation' => 'Your selected settled cycle closes with its own history.'];
    $settlement->confirm($agent, $selected, 'close', $data);
    $settlement->confirm($agent, $selected, 'close', $data);
    expect($selected->fresh()->status)->toBe(ThriftPlanStatus::Closed);
    expect($selected->fresh()->open_customer_profile_id)->toBeNull();
    expect($previous->fresh()->status)->toBe(ThriftPlanStatus::Closed);
    expect($selected->lifecycleEvents()->where('event_type', 'closed')->count())->toBe(1);
    expect($return->fresh()->getAttributes())->toBe($returnAttributes);
    foreach ($financialRows as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
})->with(['Other cycle return awaiting Customer' => false, 'Other cycle confirmed return' => true]);

test('consumed payout return leaves the restored savings gate until a second definitive payout settles it', function (string $damage): void {
    config()->set('withdrawals.cash_compensation_enabled', true);
    [$admin, $customer, $profile] = $this->createLifecycleFixture();
    $agent = $profile->user;
    [$plan, $execution] = paidOutClosureCycleFixture($this, $admin, $customer, $agent);
    $returns = app(CashRecoveryService::class);
    $return = $returns->recordReturn($admin, $execution, (string) Str::uuid(),
        'Original custodian counted the complete payout return.', closureReturnRequest(), 200000);
    $returns->acknowledgeReturn($customer->user, $return);
    $reversal = approveReceiptCorrection($this, $agent, $customer, $customer->currentAssignment,
        LedgerPostingGroup::findOrFail($execution->ledger_posting_group_id))->fresh();
    expect($reversal->state)->toBe('approved_posted');
    expect($return->fresh()->status)->toBe('consumed');
    expect($return->fresh()->consumed_at)->not->toBeNull();
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::CashRecoveryClearing))->toBe(0);
    $settlement = app(PlanSettlementService::class);
    $restored = $settlement->preview($agent, $plan->fresh());
    expect($restored['can_close'])->toBeFalse();
    expect($restored['blockers'])->toContain('Cycle savings or reservations remain.');
    expect($restored['blockers'])->not->toContain('Cycle payout return disposition remains unresolved.');
    expect($restored['position']['cycle_liability_kobo'])->toBe(200000);
    percentageCashPayout($this, $admin, $agent, $customer, $plan->fresh(), '2000.00', 'end_of_cycle');
    $foreignReturn = null;
    $foreignCompensationCustomer = null;
    if ($damage === 'foreign return source') {
        [$foreignAdmin, $foreignCustomer, $foreignProfile] = $this->createLifecycleFixture();
        [, $foreignExecution] = paidOutClosureCycleFixture($this, $foreignAdmin, $foreignCustomer, $foreignProfile->user);
        $foreignReturn = $returns->recordReturn($foreignAdmin, $foreignExecution, (string) Str::uuid(),
            'Independent Customer confirmed a different original payout return.', closureReturnRequest(), 200000);
    }
    if ($damage === 'foreign compensation Customer') {
        [, $foreignCompensationCustomer] = $this->createLifecycleFixture();
    }
    $quote = $settlement->preview($agent, $plan->fresh());
    expect($quote['can_close'])->toBeTrue();
    if ($damage !== 'none') {
        if ($damage === 'missing clearing line') {
            DB::table('ledger_entries')->where('ledger_posting_group_id', $return->fresh()->return_posting_group_id)
                ->where('side', 'credit')->delete();
        } elseif ($damage === 'rejected compensation request') {
            DB::table('reversal_requests')->where('id', $reversal->id)->update(['state' => 'rejected']);
        } elseif ($damage === 'foreign compensation Customer') {
            DB::table('ledger_posting_groups')->where('id', $reversal->compensation_posting_group_id)
                ->update(['customer_profile_id' => $foreignCompensationCustomer->id]);
        } elseif ($damage === 'requester reviewed compensation') {
            expect($reversal->reviewed_by_user_id)->not->toBe($reversal->requested_by_user_id);
            DB::table('reversal_requests')->where('id', $reversal->id)
                ->update(['reviewed_by_user_id' => $reversal->requested_by_user_id]);
        } else {
            DB::table('ledger_posting_groups')->where('id', $return->fresh()->return_posting_group_id)
                ->update(['source_id' => $foreignReturn === null ? 'missing-consumed-return' : (string) $foreignReturn->id]);
        }
        $before = [];
        foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'plan_lifecycle_events', 'financial_workflow_supplements',
            'collection_receipts', 'collection_allocations', 'collection_batches', 'ledger_posting_groups', 'ledger_entries',
            'fee_snapshots', 'fee_obligations', 'withdrawal_requests', 'withdrawal_reservations', 'cash_executions', 'cash_recoveries', 'reversal_requests'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->all();
        }
        $damaged = $settlement->preview($agent, $plan->fresh());
        expect($damaged['can_close'])->toBeFalse();
        foreach ([$quote, $damaged] as $reviewed) {
            expect(fn () => $settlement->confirm($agent, $plan->fresh(), 'close', [
                'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $reviewed['preview_fingerprint'],
                'reason' => 'Consumed cash return must retain its original linked financial source.',
                'customer_explanation' => 'Your original return evidence needs verification before closure.',
            ]))->toThrow(ConflictHttpException::class);
        }
        foreach ($before as $table => $rows) {
            expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
        }

        return;
    }
    expect($quote['position']['cycle_liability_kobo'])->toBe(0);
    expect($quote['position']['cycle_reservations_kobo'])->toBe(0);
    $financialRows = [];
    foreach (['collection_receipts', 'collection_allocations', 'collection_batches', 'ledger_posting_groups', 'ledger_entries',
        'fee_snapshots', 'fee_obligations', 'withdrawal_requests', 'withdrawal_reservations', 'cash_executions', 'cash_recoveries', 'reversal_requests'] as $table) {
        $financialRows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $data = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'reason' => 'Consumed return and definitive replacement payout are independently settled.',
        'customer_explanation' => 'Your original compensation and final payout stay in history.'];
    $settlement->confirm($agent, $plan, 'close', $data);
    $settlement->confirm($agent, $plan, 'close', $data);
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Closed);
    expect($plan->lifecycleEvents()->where('event_type', 'closed')->count())->toBe(1);
    foreach ($financialRows as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
})->with(['none', 'missing return source', 'foreign return source', 'missing clearing line',
    'rejected compensation request', 'foreign compensation Customer', 'requester reviewed compensation']);
