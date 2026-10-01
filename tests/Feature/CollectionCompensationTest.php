<?php

use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Enums\ThriftPlanStatus;
use App\Models\CollectionReceipt;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Services\CollectionReadService;
use App\Services\CollectionReversalOwner;
use App\Services\CollectionService;
use App\Services\FinancialCashPosition;
use App\Services\LedgerTransactionProjectionService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require_once __DIR__.'/../WithdrawalFixtures.php';
require_once __DIR__.'/../ReversalFixtures.php';

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
    LedgerAccount::query()->where('code', LedgerAccountCode::UnappliedFunds->value)->update(['mapping_status' => 'mapped']);
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
