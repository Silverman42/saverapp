<?php

use App\Enums\AdminPermission;
use App\Enums\LedgerAccountCode;
use App\Models\AgentProfile;
use App\Models\CashDisbursement;
use App\Models\CashRecovery;
use App\Models\CustomerAssignment;
use App\Models\FeeObligation;
use App\Models\FeeRefund;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Services\CashRecoveryService;
use App\Services\FinancialCashPosition;
use App\Services\LedgerTransactionProjectionService;
use App\Services\ReportReadService;
use App\Services\StatementPreviewService;
use App\Services\WithdrawalBalanceService;
use Illuminate\Support\Str;

require_once __DIR__.'/../CashExecutionFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';

function cashEarningsFixture(object $test): array
{
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    [$execution] = startCashFixture($test, $admin, $withdrawal);
    $test->post(route('cash-executions.handoff', $execution), ['evidence' => 'Verified full Customer handoff.', 'confirmed' => true])->assertRedirect();
    $test->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    $admin->givePermissionTo(AdminPermission::FeesManage);

    return [$admin, $customer, $plan];
}

test('cash-backed savings fee concession restores liability and never hands out cash under fees permission', function (): void {
    config()->set('fees.refunds_enabled', true);
    [$admin, $customer, $plan] = cashEarningsFixture($this);
    $obligation = FeeObligation::query()->sole();
    $payload = ['refund_reference' => (string) Str::uuid(), 'kind' => 'savings', 'amount_ngn' => '1.00', 'reason' => 'Approved customer concession.', 'confirmed' => true];
    $this->actingAs($admin)->withSession(cashSession())->post(route('admin.fees.refunds.store', $obligation), $payload)->assertRedirect();
    $this->post(route('admin.fees.refunds.store', $obligation), $payload)->assertRedirect();
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'])->toBe(70100);
    $this->assertDatabaseCount('fee_refunds', 1);
    $this->assertDatabaseCount('cash_disbursements', 0);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $this->post(route('admin.fees.refunds.store', $obligation), [...$payload, 'amount_ngn' => '2.00'])->assertConflict();
});

test('business earnings draw requires both direct permissions and excludes customer liabilities from cash', function (): void {
    config()->set('fees.cash_disbursements_enabled', true);
    [$admin] = cashEarningsFixture($this);
    expect(app(FinancialCashPosition::class)->read()['draw_limit_kobo'])->toBe(600);
    $payload = ['execution_reference' => (string) Str::uuid(), 'amount_ngn' => '3.00', 'evidence' => 'Business owner draw from verified free till cash.', 'confirmed' => true];
    $admin->revokePermissionTo(AdminPermission::CashExecute);
    $this->actingAs($admin)->withSession(cashSession())->post(route('earnings-draws.start'), $payload)->assertForbidden();
    $admin->givePermissionTo(AdminPermission::CashExecute);
    $this->post(route('earnings-draws.start'), [...$payload, 'amount_ngn' => '6.01'])->assertConflict();
    $this->post(route('earnings-draws.start'), $payload)->assertRedirect();
    $this->post(route('earnings-draws.start'), $payload)->assertRedirect();
    $execution = CashDisbursement::query()->sole();
    expect(app(FinancialCashPosition::class)->read()['draw_limit_kobo'])->toBe(300);
    $this->post(route('cash-disbursements.handoff', $execution), ['delivered' => true, 'evidence' => 'Exact business distribution delivered.', 'confirmed' => true])->assertRedirect();
    $this->post(route('cash-disbursements.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    $this->post(route('cash-disbursements.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    expect($execution->fresh()->status)->toBe('posted')->and(app(FinancialCashPosition::class)->read()['draw_limit_kobo'])->toBe(300);
    $this->withSession(cashSession())->post(route('cash-disbursements.return', $execution), ['preview_fingerprint' => app(CashRecoveryService::class)->preview($admin, $execution->fresh())['preview_fingerprint'], 'recovery_reference' => (string) Str::uuid(), 'amount_ngn' => '1.00', 'evidence' => 'First distribution cash returned.', 'confirmed' => true])->assertRedirect();
    $partial = CashRecovery::query()->sole();
    $this->post(route('cash-recoveries.acknowledge', $partial), ['confirmed' => true])->assertRedirect();
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::CashRecoveryClearing))->toBe(100);
    $this->post(route('cash-disbursements.return', $execution), ['preview_fingerprint' => app(CashRecoveryService::class)->preview($admin, $execution->fresh())['preview_fingerprint'], 'recovery_reference' => (string) Str::uuid(), 'amount_ngn' => '2.00', 'evidence' => 'Remaining distribution cash returned.', 'confirmed' => true])->assertRedirect();
    $remaining = CashRecovery::query()->latest('id')->firstOrFail();
    $this->post(route('cash-recoveries.acknowledge', $remaining), ['confirmed' => true])->assertRedirect();
    $this->post(route('cash-recoveries.acknowledge', $remaining), ['confirmed' => true])->assertRedirect();
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::CashRecoveryClearing))->toBe(0)
        ->and(app(FinancialCashPosition::class)->read()['draw_limit_kobo'])->toBe(600);
    $this->assertDatabaseCount('cash_recoveries', 2);
    $this->assertDatabaseCount('cash_disbursements', 1);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $report = app(ReportReadService::class)->read($admin, 'withdrawals', ['page_size' => 25, 'group' => '', 'from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]);
    $metrics = collect($report['sections']['posted_financial_movements']['metrics'])->keyBy('code');
    expect($metrics['gross_withdrawals']['value'])->toBe(30000)->and($metrics['net_cash_payouts']['value'])->toBe(29400)
        ->and($metrics['withdrawal_fees']['value'])->toBe(600);
});

test('external fee refund clears only its payable after authenticated exact Customer handoff', function (): void {
    config()->set('fees.refunds_enabled', true);
    config()->set('fees.cash_disbursements_enabled', true);
    config()->set('collections.enabled', true);
    [$admin, $customer, $plan] = cashEarningsFixture($this);
    $assignment = CustomerAssignment::query()->where('customer_profile_id', $customer->id)->sole();
    $agent = AgentProfile::findOrFail($assignment->agent_profile_id)->user;
    $obligation = reportFeeObligation($agent, $customer, 500);
    $payload = ['attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $assignment->version, 'business_version' => 1, 'plan_id' => null,
        'received_date' => now()->toDateString(), 'savings_ngn' => '0', 'fees' => [['obligation_id' => $obligation->id, 'amount_ngn' => '5.00']],
        'allocations' => [], 'late_reason' => '', 'notes' => '', 'confirmed' => true];
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk()->json();
    $this->post(route('customers.collections.store', $customer->customer_id), [...$payload, 'preview_fingerprint' => $preview['preview_fingerprint']])->assertRedirect();
    $entitlement = ['refund_reference' => (string) Str::uuid(), 'kind' => 'external', 'amount_ngn' => '2.00', 'reason' => 'Reviewed external fee concession.', 'confirmed' => true];
    $this->actingAs($admin)->withSession(cashSession())->post(route('admin.fees.refunds.store', $obligation), $entitlement)->assertRedirect();
    $refund = FeeRefund::query()->sole();
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe(200);
    $payment = ['execution_reference' => (string) Str::uuid(), 'evidence' => 'Customer and exact cash entitlement verified.', 'confirmed' => true];
    $admin->revokePermissionTo(AdminPermission::CashExecute);
    $this->post(route('fee-refunds.cash', $refund), $payment)->assertForbidden();
    $admin->givePermissionTo(AdminPermission::CashExecute);
    $admin->revokePermissionTo(AdminPermission::FeesManage);
    $this->post(route('fee-refunds.cash', $refund), $payment)->assertRedirect();
    $this->post(route('fee-refunds.cash', $refund), $payment)->assertRedirect();
    $execution = CashDisbursement::query()->sole();
    $this->post(route('cash-disbursements.acknowledge', $execution), ['confirmed' => true])->assertForbidden();
    $this->post(route('cash-disbursements.handoff', $execution), ['delivered' => true, 'evidence' => 'Exact Customer received two naira.', 'confirmed' => true])->assertRedirect();
    $this->actingAs($customer->user)->post(route('cash-disbursements.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    $this->post(route('cash-disbursements.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe(0)
        ->and(app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'])->toBe(70000);
    $this->assertDatabaseCount('cash_disbursements', 1);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $report = app(ReportReadService::class)->read($customer->user, 'fees', ['page_size' => 25, 'group' => '', 'from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]);
    $metrics = collect($report['sections']['posted_financial_movements']['metrics'])->keyBy('code');
    expect($metrics['external_refund_entitlements']['value'])->toBe(200)->and($metrics['external_refund_payments']['value'])->toBe(200);
    $statement = app(StatementPreviewService::class)->preview($customer->user, $customer, now()->startOfMonth()->toDateString(), now()->toDateString(), 'Africa/Lagos');
    expect($statement['closing_kobo'])->toBe(70000)->and(collect($statement['lines'])->where('type', 'external_refund_payment')->count())->toBe(1);
    $this->actingAs($admin)->withSession(cashSession())->post(route('cash-disbursements.return', $execution), ['preview_fingerprint' => app(CashRecoveryService::class)->preview($admin, $execution->fresh())['preview_fingerprint'], 'recovery_reference' => (string) Str::uuid(), 'amount_ngn' => '2.00', 'evidence' => 'Exact previously paid refund returned.', 'confirmed' => true])->assertRedirect();
    $returned = CashRecovery::query()->sole();
    $this->actingAs($customer->user)->post(route('cash-recoveries.acknowledge', $returned), ['confirmed' => true])->assertRedirect();
    $this->post(route('cash-recoveries.acknowledge', $returned), ['confirmed' => true])->assertRedirect();
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe(200)
        ->and(app(FinancialCashPosition::class)->balance(LedgerAccountCode::CashRecoveryClearing))->toBe(0)
        ->and(app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'])->toBe(70000);
    $this->assertDatabaseCount('cash_disbursements', 1);
    expect(LedgerPostingGroup::query()->where('source_type', 'disbursement_recovery')->count())->toBe(1);

});
