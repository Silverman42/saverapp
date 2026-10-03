<?php

use App\Data\PlanFeeReadSnapshot;
use App\Enums\AdminPermission;
use App\Enums\LedgerAccountCode;
use App\Models\AgentProfile;
use App\Models\AuditEvent;
use App\Models\CashDisbursement;
use App\Models\CashExecution;
use App\Models\CashRecovery;
use App\Models\CollectionReceipt;
use App\Models\CustomerAssignment;
use App\Models\FeeObligation;
use App\Models\FeeRefund;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Services\CashRecoveryService;
use App\Services\CollectionService;
use App\Services\FeeConcessionPosition;
use App\Services\FinancialCashPosition;
use App\Services\LedgerTransactionProjectionService;
use App\Services\ReportReadService;
use App\Services\ReversalCapabilityRegistry;
use App\Services\StatementPreviewService;
use App\Services\WithdrawalBalanceService;
use App\Services\WithdrawalService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../CashExecutionFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';
require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../ReversalFixtures.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'Africa/Lagos'));
    LedgerAccount::query()->whereNotNull('effective_at')->update(['effective_at' => now()->subDay()]);
    DB::table('cash_method_versions')->update(['effective_at' => now()->subDay()]);
});

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
    $immutableSources = [];
    foreach (['customer_profiles', 'thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'collection_receipts',
        'collection_allocations', 'cash_remittances', 'fee_snapshots', 'fee_obligations', 'fee_obligation_entries'] as $table) {
        $immutableSources[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $originalFeeEntries = $immutableSources['fee_obligation_entries'];
    unset($immutableSources['fee_obligation_entries']);
    $originalGroups = DB::table('ledger_posting_groups')->orderBy('id')->get()->all();
    $originalLines = DB::table('ledger_entries')->orderBy('id')->get()->all();
    $cash = app(FinancialCashPosition::class)->balance(LedgerAccountCode::BusinessCash);
    $payload = ['refund_reference' => (string) Str::uuid(), 'kind' => 'savings', 'amount_ngn' => '1.00', 'reason' => 'SECRET approved customer concession.', 'confirmed' => true];
    $this->actingAs($admin)->withSession(cashSession())->post(route('admin.fees.refunds.store', $obligation), $payload)->assertRedirect();
    $refundAudit = DB::table('canonical_audit_events')->where('event_type', 'fee.refund_authorized')->sole();
    $this->post(route('admin.fees.refunds.store', $obligation), $payload)->assertRedirect();
    expect(DB::table('canonical_audit_events')->where('event_type', 'fee.refund_authorized')->get()->all())->toEqual([$refundAudit]);
    $event = AuditEvent::query()->where('event_type', 'fee.refund_authorized')->sole();
    expect($refundAudit->correlation_reference)->toBe($payload['refund_reference'])
        ->and($refundAudit->content)->not->toContain($payload['reason'])
        ->and($event->payload)->not->toHaveKey('reason')
        ->and($event->payload['customer_profile_id'])->toBe($customer->id)
        ->and($event->payload['amount_kobo'])->toBe(100)
        ->and($event->target_reference)->toBe($payload['refund_reference']);
    $protected = DB::table('audit_protected_payloads')->where('canonical_event_id', $refundAudit->id)->sole();
    expect($protected->ciphertext)->not->toContain($payload['reason'])
        ->and(json_decode(Crypt::decryptString($protected->ciphertext), true, flags: JSON_THROW_ON_ERROR)['reason'])->toBe($payload['reason']);
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'])->toBe(70100);
    $this->assertDatabaseCount('fee_refunds', 1);
    $this->assertDatabaseCount('cash_disbursements', 0);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $this->post(route('admin.fees.refunds.store', $obligation), [...$payload, 'amount_ngn' => '2.00'])->assertConflict();
    $beforeExcess = businessDrawProtectedSources();
    $this->post(route('admin.fees.refunds.store', $obligation), [...$payload,
        'refund_reference' => (string) Str::uuid(), 'amount_ngn' => '6.00'])->assertConflict();
    expect(businessDrawProtectedSources())->toEqual($beforeExcess);
    $remaining = [...$payload, 'refund_reference' => (string) Str::uuid(), 'amount_ngn' => '5.00',
        'reason' => 'Approved refund of the remaining retained savings-funded fee.'];
    $this->post(route('admin.fees.refunds.store', $obligation), $remaining)->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('admin.fees.refunds.store', $obligation), $remaining)->assertRedirect()->assertSessionHasNoErrors();
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'])->toBe(70600);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome))->toBe(0);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::BusinessCash))->toBe($cash);
    expect($obligation->fresh()->settledAmountKobo())->toBe(600);
    expect($obligation->fresh()->outstandingAmountKobo())->toBe(0);
    $this->assertDatabaseCount('fee_refunds', 2);
    $this->assertDatabaseCount('cash_disbursements', 0);
    $beforeRepeated = businessDrawProtectedSources();
    $this->post(route('admin.fees.refunds.store', $obligation), [...$payload,
        'refund_reference' => (string) Str::uuid(), 'amount_ngn' => '0.01'])->assertConflict();
    expect(businessDrawProtectedSources())->toEqual($beforeRepeated);
    foreach ($immutableSources as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
    foreach ($originalFeeEntries as $row) {
        expect(DB::table('fee_obligation_entries')->where('id', $row->id)->first())->toEqual($row);
    }
    expect(DB::table('fee_obligation_entries')->where('fee_obligation_id', $obligation->id)->where('entry_type', 'assessment')->count())->toBe(1);
    expect((int) DB::table('fee_obligation_entries')->where('fee_obligation_id', $obligation->id)->where('entry_type', 'savings_refund')->sum('amount_kobo'))->toBe(600);
    foreach ($originalGroups as $row) {
        expect(DB::table('ledger_posting_groups')->where('id', $row->id)->first())->toEqual($row);
    }
    foreach ($originalLines as $row) {
        expect(DB::table('ledger_entries')->where('id', $row->id)->first())->toEqual($row);
    }
    expect((int) FeeRefund::query()->sum('amount_kobo'))->toBe(600);
});

test('returned withdrawal compensation preserves an independent fee concession without restoring it twice', function (string $amount): void {
    config()->set(['fees.refunds_enabled' => true, 'withdrawals.cash_compensation_enabled' => true]);
    [$admin, $customer, $plan] = cashEarningsFixture($this);
    $fee = FeeObligation::query()->sole();
    $this->actingAs($admin)->withSession(cashSession())->post(route('admin.fees.refunds.store', $fee), [
        'refund_reference' => (string) Str::uuid(), 'kind' => 'savings', 'amount_ngn' => $amount,
        'reason' => 'Independent savings-funded fee concession.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $execution = CashExecution::query()->sole();
    $this->post(route('cash-executions.return', $execution), [
        'preview_fingerprint' => app(CashRecoveryService::class)->preview($admin, $execution)['preview_fingerprint'],
        'recovery_reference' => (string) Str::uuid(), 'amount_ngn' => '294.00',
        'evidence' => 'Full original Customer cash returned and counted.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $returned = CashRecovery::query()->sole();
    $this->actingAs($customer->user)->post(route('cash-recoveries.acknowledge', $returned), ['confirmed' => true])->assertRedirect();
    $assignment = CustomerAssignment::query()->where('customer_profile_id', $customer->id)->sole();
    $agent = AgentProfile::findOrFail($assignment->agent_profile_id)->user;
    $correction = approveReceiptCorrection($this, $agent, $customer, $assignment,
        LedgerPostingGroup::findOrFail($execution->ledger_posting_group_id))->fresh();
    expect($correction->state)->toBe('approved_posted');
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'])->toBe(100000);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome))->toBe(0);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::CashRecoveryClearing))->toBe(0);
    expect($fee->fresh()->settledAmountKobo())->toBe(0);
    expect(app(FeeConcessionPosition::class)->retainedSources($fee))->toBe(['savings_kobo' => 0, 'external_kobo' => 0]);
    $this->assertDatabaseCount('fee_refunds', 1);
    app(LedgerTransactionProjectionService::class)->rebuild();
})->with(['partial concession' => '1.00', 'full concession' => '6.00']);

test('business earnings draw requires both direct permissions and excludes customer liabilities from cash', function (): void {
    config()->set('fees.cash_disbursements_enabled', true);
    [$admin, $customer, $plan] = cashEarningsFixture($this);
    expect(app(FinancialCashPosition::class)->read()['draw_limit_kobo'])->toBe(600);
    $payload = ['execution_reference' => (string) Str::uuid(), 'amount_ngn' => '3.00', 'evidence' => 'Business owner draw from verified free till cash.', 'confirmed' => true];
    $admin->revokePermissionTo(AdminPermission::CashExecute);
    $this->actingAs($admin)->withSession(cashSession())->post(route('earnings-draws.start'), $payload)->assertForbidden();
    $admin->givePermissionTo(AdminPermission::CashExecute);
    $admin->revokePermissionTo(AdminPermission::FeesManage);
    $cashOnlySources = businessDrawProtectedSources();
    $this->post(route('earnings-draws.start'), $payload)->assertForbidden();
    expect(businessDrawProtectedSources())->toEqual($cashOnlySources);
    $this->assertDatabaseCount('cash_disbursements', 0);
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $this->post(route('earnings-draws.start'), [...$payload, 'amount_ngn' => '6.01'])->assertConflict();
    $this->post(route('earnings-draws.start'), $payload)->assertRedirect();
    $this->post(route('earnings-draws.start'), $payload)->assertRedirect();
    $heldSources = businessDrawProtectedSources();
    $this->post(route('earnings-draws.start'), [...$payload, 'amount_ngn' => '2.00'])->assertConflict();
    expect(businessDrawProtectedSources())->toEqual($heldSources);
    $execution = CashDisbursement::query()->sole();
    expect(app(FinancialCashPosition::class)->read()['draw_limit_kobo'])->toBe(300);
    $this->post(route('cash-disbursements.handoff', $execution), ['delivered' => true, 'evidence' => 'Exact business distribution delivered.', 'confirmed' => true])->assertRedirect();
    $this->post(route('cash-disbursements.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    $this->post(route('cash-disbursements.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    expect($execution->fresh()->status)->toBe('posted')->and(app(FinancialCashPosition::class)->read()['draw_limit_kobo'])->toBe(300);
    $liability = app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'];
    $originalGroups = DB::table('ledger_posting_groups')->orderBy('id')->get()->all();
    $originalLines = DB::table('ledger_entries')->orderBy('id')->get()->all();
    $originalSources = [];
    foreach (['collection_receipts', 'cash_remittances', 'fee_snapshots', 'fee_obligations', 'fee_obligation_entries',
        'withdrawal_requests', 'withdrawal_reservations', 'cash_executions', 'cash_disbursements'] as $table) {
        $originalSources[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
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
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'])->toBe($liability);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome))->toBe(600);
    foreach ($originalGroups as $row) {
        expect(DB::table('ledger_posting_groups')->where('id', $row->id)->first())->toEqual($row);
    }
    foreach ($originalLines as $row) {
        expect(DB::table('ledger_entries')->where('id', $row->id)->first())->toEqual($row);
    }
    foreach ($originalSources as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
    $compensation = LedgerPostingGroup::query()->where('event_type', 'disbursement_compensation')->sole();
    expect($compensation->metadata['original_posting_group_id'])->toBe($execution->fresh()->ledger_posting_group_id);
    expect($compensation->customer_profile_id)->toBeNull();
    expect($compensation->entries()->where('side', 'debit')->sum('amount_kobo'))->toBe(300);
    expect($compensation->entries()->where('side', 'credit')->sum('amount_kobo'))->toBe(300);
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
    expect($execution->fresh()->status)->toBe('outcome_unknown')
        ->and($execution->fresh()->live_fee_refund_id)->toBe($refund->id)
        ->and($execution->fresh()->ledger_posting_group_id)->toBeNull()
        ->and(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe(200);
    $uncertainSources = businessDrawProtectedSources();
    $this->withSession(cashSession())->post(route('fee-refunds.cash', $refund), [
        ...$payment, 'execution_reference' => (string) Str::uuid(),
    ])->assertConflict();
    expect(businessDrawProtectedSources())->toEqual($uncertainSources);
    $this->post(route('fee-refunds.cash', $refund), $payment)->assertRedirect()->assertSessionHasNoErrors();
    expect(businessDrawProtectedSources())->toEqual($uncertainSources);
    $this->assertDatabaseCount('cash_disbursements', 1);
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

test('actual external refund payable blocks Customer archival with retained fee and cash owners', function (): void {
    config()->set(['fees.refunds_enabled' => true, 'collections.enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(1);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::CustomersManage, AdminPermission::FeesManage, AdminPermission::ReconciliationManage]);
    $obligation = reportFeeObligation($agent, $customer, 500);
    $payload = [...collectionPayload($customer, $assignment, $plan, $date, '2000.00'),
        'fees' => [['obligation_id' => $obligation->id, 'amount_ngn' => '5.00']]];
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk()->json('preview_fingerprint');
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $batch = CollectionReceipt::query()->sole()->batch;
    $recordedAt = now()->toImmutable();
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelTo($recordedAt);
    $this->actingAs($admin)->withSession(cashSession())->post(route('collection-batches.remittances.store', $batch), [
        'batch_version' => $batch->fresh()->version, 'handoff_reference' => 'REFUND-ARCHIVAL-'.$batch->id,
        'amount_ngn' => '2005.00', 'handoff_date' => $date, 'receiving_location' => 'Business till',
        'source_attestation' => 'Counted the original savings and registration fee tender.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'Reviewed full original cash custody.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('admin.fees.refunds.store', $obligation), [
        'refund_reference' => (string) Str::uuid(), 'kind' => 'external', 'amount_ngn' => '2.00',
        'reason' => 'Approved retained registration fee concession.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::RefundPayable))->toBe(200)
        ->and($obligation->fresh()->outstandingAmountKobo())->toBe(0);
    $sources = businessDrawProtectedSources();
    $preview = $this->postJson(route('customers.lifecycle.preview', $customer->customer_id))->assertOk()->json();
    expect(collect($preview['checks'])->firstWhere('key', 'fees')['status'])->toBe('blocked')
        ->and($preview['eligible'])->toBeFalse();
    $this->postJson(route('customers.lifecycle.archive', $customer->customer_id), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $customer->fresh()->version,
        'assignment_version' => $assignment->fresh()->version, 'confirmed' => true,
        'reason' => 'Reviewed archival must retain the unpaid external refund.',
        'customer_explanation' => 'Your approved refund still requires verified payment.',
    ])->assertUnprocessable();
    expect(businessDrawProtectedSources())->toEqual($sources);
    $this->assertDatabaseCount('customer_lifecycle_operations', 0);
    $this->assertDatabaseCount('customer_status_histories', 0);
});

test('current cash positions preserve posted earnings cash backing and an owned draw exclusion through acknowledgement', function (): void {
    config()->set('fees.cash_disbursements_enabled', true);
    [$admin] = cashEarningsFixture($this);
    $owner = app(FinancialCashPosition::class);
    expect($owner->read(forUpdate: true))->toEqual($owner->read());
    expect($owner->read()['draw_limit_kobo'])->toBe(600);
    $this->actingAs($admin)->withSession(cashSession())->post(route('earnings-draws.start'), [
        'execution_reference' => (string) Str::uuid(), 'amount_ngn' => '3.00',
        'evidence' => 'Cash-backed business earnings draw.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $execution = CashDisbursement::query()->sole();
    expect($owner->read(forUpdate: true))->toEqual($owner->read());
    expect($owner->read(forUpdate: true)['draw_limit_kobo'])->toBe(300);
    expect($owner->read($execution->id, true))->toEqual($owner->read($execution->id));
    expect($owner->read($execution->id, true)['draw_limit_kobo'])->toBe(600);
    $this->post(route('cash-disbursements.handoff', $execution), ['delivered' => true,
        'evidence' => 'Full business draw handed over.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('cash-disbursements.acknowledge', $execution), ['confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($execution->fresh()->status)->toBe('posted');
    expect($owner->read(forUpdate: true))->toEqual($owner->read());
    expect($owner->read(forUpdate: true))->toMatchArray(['undrawn_earnings_kobo' => 300, 'free_cash_kobo' => 300, 'draw_limit_kobo' => 300]);
});

test('current cash positions reject a zero journal or cash reservation without changing its retained owners', function (string $damage): void {
    [$admin] = cashEarningsFixture($this);
    if ($damage === 'journal') {
        DB::table('ledger_entries')->where('ledger_account_id', LedgerAccount::query()->where('code', LedgerAccountCode::FeeIncome)->sole()->id)
            ->update(['amount_kobo' => 0]);
    } else {
        CashDisbursement::create(['execution_reference' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
            'executor_user_id' => $admin->id, 'recipient_user_id' => $admin->id, 'kind' => 'earnings_draw', 'amount_kobo' => 0,
            'cash_mapping_version' => 1, 'debit_mapping_version' => 1, 'method_version' => 1, 'status' => 'processing',
            'custody_evidence' => 'Damaged isolated reservation evidence.']);
    }
    $rows = DB::table('ledger_entries')->orderBy('id')->get();
    $disbursements = DB::table('cash_disbursements')->orderBy('id')->get();

    expect(fn () => app(FinancialCashPosition::class)->read(forUpdate: true))->toThrow(RuntimeException::class);
    expect(DB::table('ledger_entries')->orderBy('id')->get())->toEqual($rows);
    expect(DB::table('cash_disbursements')->orderBy('id')->get())->toEqual($disbursements);
})->with(['journal', 'reservation']);

test('current paid fee sources track an actual savings concession and reject captured report history', function (): void {
    config()->set('fees.refunds_enabled', true);
    [$admin] = cashEarningsFixture($this);
    $fee = FeeObligation::query()->sole();
    $this->actingAs($admin)->withSession(cashSession())->post(route('admin.fees.refunds.store', $fee), [
        'refund_reference' => (string) Str::uuid(), 'kind' => 'savings', 'amount_ngn' => '2.00',
        'reason' => 'Actual retained fee concession.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $owner = app(FeeConcessionPosition::class);
    expect($owner->retainedSources($fee, forUpdate: true))->toBe(['savings_kobo' => 400, 'external_kobo' => 0]);
    expect($owner->read($fee, forUpdate: true))->toBe(['savings_kobo' => 200, 'external_kobo' => 0,
        'consumed_savings_kobo' => 0, 'consumed_external_kobo' => 0]);
    $captured = new PlanFeeReadSnapshot(plans: collect(), withdrawals: collect(), obligations: collect([$fee]),
        charges: collect(), categories: collect(), groups: collect(), executions: collect(), reversals: collect(),
        refunds: collect(), receiptPlanIds: collect(), completedPlanIds: collect());
    $before = DB::table('fee_obligation_entries')->orderBy('id')->get()->all();
    expect(fn () => $owner->retainedSources($fee, $captured, true))->toThrow(RuntimeException::class, 'current history');
    expect(fn () => $owner->read($fee, $captured, true))->toThrow(RuntimeException::class, 'current history');
    expect(DB::table('fee_obligation_entries')->orderBy('id')->get()->all())->toEqual($before);
    expect($owner->retainedSources($fee))->toBe(['savings_kobo' => 400, 'external_kobo' => 0]);
});

/** @return array<string, array<int, object>> */
function businessDrawProtectedSources(): array
{
    $rows = [];
    foreach (['customer_profiles', 'thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'collection_receipts',
        'collection_allocations', 'collection_fee_components', 'collection_batches', 'cash_remittances', 'ledger_posting_groups',
        'ledger_entries', 'fee_rules', 'fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'fee_refunds', 'withdrawal_requests',
        'withdrawal_reservations', 'cash_executions', 'cash_disbursements', 'cash_recoveries', 'reversal_requests',
        'financial_cash_notification_intents'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

/** @return array{User, object, object, CashDisbursement} */
function postedBusinessDrawFixture(object $test): array
{
    config()->set('fees.cash_disbursements_enabled', true);
    [$admin, $customer, $plan] = cashEarningsFixture($test);
    $test->actingAs($admin)->withSession(cashSession())->post(route('earnings-draws.start'), [
        'execution_reference' => (string) Str::uuid(), 'amount_ngn' => '3.00',
        'evidence' => 'Original business distribution from verified remitted earnings.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $draw = CashDisbursement::query()->sole();
    $test->post(route('cash-disbursements.handoff', $draw), ['delivered' => true,
        'evidence' => 'Original business beneficiary received complete counted distribution.', 'confirmed' => true])
        ->assertRedirect()->assertSessionHasNoErrors();
    $test->post(route('cash-disbursements.acknowledge', $draw), ['confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($draw->fresh()->status)->toBe('posted');

    return [$admin, $customer, $plan, $draw->fresh()];
}

test('recognized unremitted fee tender is earnings without drawable business cash', function (): void {
    config()->set(['collections.enabled' => true, 'fees.cash_disbursements_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 10001);
    $payment = [...collectionPayload($customer, $assignment, $plan, $date, '0.00'), 'plan_id' => null, 'plan_version' => null,
        'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '100.01']]];
    $payment['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payment)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $payment);
    expect($fee->fresh()->settledAmountKobo())->toBe(10001);
    expect(app(FinancialCashPosition::class)->read())->toMatchArray(['cash_kobo' => 0, 'free_cash_kobo' => 0,
        'undrawn_earnings_kobo' => 10001, 'draw_limit_kobo' => 0]);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::FeesManage, AdminPermission::CashExecute]);
    $before = businessDrawProtectedSources();

    $this->actingAs($admin)->withSession(cashSession())->post(route('earnings-draws.start'), [
        'execution_reference' => (string) Str::uuid(), 'amount_ngn' => '1.00',
        'evidence' => 'Recognition alone cannot supply remitted business cash.', 'confirmed' => true,
    ])->assertConflict();

    expect(businessDrawProtectedSources())->toEqual($before);
    $this->assertDatabaseCount('cash_remittances', 0);
    $this->assertDatabaseCount('cash_disbursements', 0);
});

test('actual Customer payout reservation is protected within liability without reducing business free cash twice', function (): void {
    config()->set('fees.cash_disbursements_enabled', true);
    [$admin, $customer, $plan] = cashEarningsFixture($this);
    $assignment = $customer->currentAssignment;
    $agent = $assignment->agentProfile->user;
    $withdrawals = app(WithdrawalService::class);
    $instruction = withdrawalPayload($customer, $assignment, $plan->fresh());
    $review = $withdrawals->preview($agent, $customer, $instruction);
    $withdrawals->submit($agent, $customer, [...$instruction, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $review['preview_fingerprint'], 'quote_expires_at' => $review['quote_expires_at'],
        'customer_version' => $review['customer_version'], 'assignment_version' => $review['assignment_version'],
        'plan_version' => $review['plan_version'], 'business_version' => $review['business_version'],
        'instruction_attested' => true, 'confirmed' => true]);
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan))->toMatchArray(['liability_kobo' => 70000, 'reservations_kobo' => 30000]);
    expect(app(FinancialCashPosition::class)->read())->toMatchArray(['cash_kobo' => 70600, 'free_cash_kobo' => 600, 'draw_limit_kobo' => 600]);
    $before = businessDrawProtectedSources();
    unset($before['cash_disbursements'], $before['financial_cash_notification_intents']);

    $this->actingAs($admin)->withSession(cashSession())->post(route('earnings-draws.start'), [
        'execution_reference' => (string) Str::uuid(), 'amount_ngn' => '6.00',
        'evidence' => 'Only six naira of remitted earnings outside full Customer liability.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(CashDisbursement::query()->sole()->amount_kobo)->toBe(600);
    $after = businessDrawProtectedSources();
    unset($after['cash_disbursements'], $after['financial_cash_notification_intents']);
    expect($after)->toEqual($before);
    expect(app(FinancialCashPosition::class)->read()['draw_limit_kobo'])->toBe(0);
});

test('business draw requires current fresh authentication and nonblank executed payment evidence', function (bool $stale): void {
    config()->set('fees.cash_disbursements_enabled', true);
    [$admin] = cashEarningsFixture($this);
    $before = businessDrawProtectedSources();
    $session = $stale ? ['auth.fresh_until' => now()->subMinute()->timestamp,
        'auth.password_confirmed_at' => now()->subHour()->timestamp, 'auth.mfa_confirmed_at' => now()->subHour()->timestamp] : cashSession();
    $this->actingAs($admin)->withSession($session)->postJson(route('earnings-draws.start'), [
        'execution_reference' => (string) Str::uuid(), 'amount_ngn' => '3.00',
        'evidence' => $stale ? 'Fully evidenced but authentication expired.' : '   ', 'confirmed' => true,
    ])->assertStatus($stale ? 423 : 422);

    expect(businessDrawProtectedSources())->toEqual($before);
    $this->assertDatabaseCount('cash_disbursements', 0);
})->with(['Expired fresh authentication' => true, 'Blank payment evidence' => false]);

test('actual business distribution has no Customer reversal routing authority', function (): void {
    [$admin, $customer, , $draw] = postedBusinessDrawFixture($this);
    $original = LedgerPostingGroup::findOrFail($draw->ledger_posting_group_id);
    expect($original->customer_profile_id)->toBeNull();
    expect(app(ReversalCapabilityRegistry::class)->resolve($original))->toBeNull();
    $before = businessDrawProtectedSources();
    foreach ([$admin, $customer->currentAssignment->agentProfile->user, $customer->user] as $actor) {
        $this->actingAs($actor)->postJson(route('reversals.preview', $original->posting_reference))->assertNotFound();
    }
    expect(businessDrawProtectedSources())->toEqual($before);
    $this->assertDatabaseCount('reversal_requests', 0);
});

test('business return compensation refuses a lost original return journal source before restoring draw capacity', function (): void {
    [$admin, , , $draw] = postedBusinessDrawFixture($this);
    $returns = app(CashRecoveryService::class);
    $this->actingAs($admin)->withSession(cashSession())->post(route('cash-disbursements.return', $draw), [
        'preview_fingerprint' => $returns->preview($admin, $draw)['preview_fingerprint'],
        'recovery_reference' => (string) Str::uuid(), 'amount_ngn' => '1.00',
        'evidence' => 'First actual business distribution return counted.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $first = CashRecovery::query()->sole();
    $this->post(route('cash-recoveries.acknowledge', $first), ['confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('cash-disbursements.return', $draw), [
        'preview_fingerprint' => $returns->preview($admin, $draw->fresh())['preview_fingerprint'],
        'recovery_reference' => (string) Str::uuid(), 'amount_ngn' => '2.00',
        'evidence' => 'Remaining actual business distribution return counted.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $remaining = CashRecovery::query()->latest('id')->firstOrFail();
    DB::table('ledger_posting_groups')->where('id', $first->fresh()->return_posting_group_id)
        ->update(['source_id' => 'missing-original-business-return']);
    $before = businessDrawProtectedSources();

    $this->post(route('cash-recoveries.acknowledge', $remaining), ['confirmed' => true])->assertConflict();

    expect(businessDrawProtectedSources())->toEqual($before);
    expect($remaining->fresh()->status)->toBe('awaiting_customer');
    expect($first->fresh()->status)->toBe('confirmed');
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::CashRecoveryClearing))->toBe(100);
    expect(app(FinancialCashPosition::class)->read()['draw_limit_kobo'])->toBe(300);
    $this->assertDatabaseMissing('ledger_posting_groups', ['event_type' => 'disbursement_compensation']);
});

test('business draw rejects unavailable or negative actual cash without new financial owners', function (bool $negative): void {
    config()->set('fees.cash_disbursements_enabled', true);
    [$admin] = cashEarningsFixture($this);
    $cash = LedgerAccount::query()->where('code', LedgerAccountCode::BusinessCash)->sole();
    if ($negative) {
        $withdrawal = CashExecution::query()->sole();
        DB::table('ledger_entries')->where('ledger_posting_group_id', $withdrawal->ledger_posting_group_id)
            ->where('ledger_account_id', $cash->id)->where('side', 'credit')->update(['amount_kobo' => 100001]);
    } else {
        DB::table('ledger_accounts')->where('id', $cash->id)->update(['mapping_status' => 'unmapped']);
    }
    $before = businessDrawProtectedSources();
    $mapping = DB::table('ledger_accounts')->where('id', $cash->id)->first();
    $response = $this->actingAs($admin)->withSession(cashSession())->postJson(route('earnings-draws.start'), [
        'execution_reference' => (string) Str::uuid(), 'amount_ngn' => '1.00',
        'evidence' => 'An unavailable actual cash source cannot supply a new business draw.', 'confirmed' => true,
    ]);

    expect(businessDrawProtectedSources())->toEqual($before);
    expect(DB::table('ledger_accounts')->where('id', $cash->id)->first())->toEqual($mapping);
    $this->assertDatabaseCount('cash_disbursements', 0);
    expect($response->status())->toBe(503);
})->with(['Unavailable business cash mapping' => false, 'Negative signed original cash source' => true]);
