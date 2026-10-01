<?php

use App\Enums\AdminPermission;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Enums\LedgerAccountCode;
use App\Enums\ThriftPlanStatus;
use App\Models\CashRecovery;
use App\Models\CustomerProfile;
use App\Models\FeeRefund;
use App\Models\FinancialWorkflowSupplement;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\PlanLifecycleEvent;
use App\Models\User;
use App\Services\CashRecoveryService;
use App\Services\CollectionReadService;
use App\Services\CollectionReplacementService;
use App\Services\CollectionService;
use App\Services\FinancialCashPosition;
use App\Services\FinancialReleaseEvidenceService;
use App\Services\FinancialWorkflowReadService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\PlanSettlementService;
use App\Services\WithdrawalReversalOwner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../ReversalFixtures.php';
require_once __DIR__.'/../CashExecutionFixtures.php';

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

test('early termination with net principal assesses the agreed fixed completion fee once without a payment', function (): void {
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(2, feeAmountKobo: 10000, feeTiming: FeeRuleTiming::CycleCompletion);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $data = collectionPayload($customer, $assignment, $plan, $date, '1000.00');
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $data);
    $count = LedgerPostingGroup::query()->count();
    $quote = app(PlanSettlementService::class)->preview($agent, $plan->fresh(), 'prepare_termination');
    $prepare = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'], 'reason' => 'Agreed early termination.', 'customer_explanation' => 'The full agreed completion fee is due before final settlement.'];
    app(PlanSettlementService::class)->confirm($agent, $plan, 'prepare_termination', $prepare);
    app(PlanSettlementService::class)->confirm($agent, $plan, 'prepare_termination', $prepare);
    $fee = $plan->currentTermsRevision()->feeSnapshot->obligation;
    expect($fee->assessedAmountKobo())->toBe(10000)->and($fee->settledAmountKobo())->toBe(0)
        ->and(LedgerPostingGroup::query()->count())->toBe($count)
        ->and($plan->fresh()->status)->toBe(ThriftPlanStatus::Paused);
    expect(app(PlanSettlementService::class)->preview($agent, $plan->fresh())['can_close'])->toBeFalse();
});

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
    app(LedgerTransactionProjectionService::class)->rebuild();
});

test('settlement remains unavailable with its live flag off before preparing any financial effect', function (): void {
    [$agent, , , $plan] = collectionFixture();
    config()->set('collections.settlement_enabled', false);
    $this->actingAs($agent)->get(route('plans.settlement', $plan))->assertServiceUnavailable();
    $this->assertDatabaseCount('financial_workflow_supplements', 0);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
});
