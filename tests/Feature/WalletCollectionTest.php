<?php

use App\Enums\CustomerStatus;
use App\Enums\FeeSettlementSource;
use App\Models\CollectionBatch;
use App\Models\CollectionReceipt;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\CollectionBatchPosition;
use App\Services\CollectionReadService;
use App\Services\CollectionService;
use App\Services\WalletBalanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../WalletFixtures.php';

beforeEach(function (): void {
    config()->set('collections.enabled', true);
    Queue::fake();
});

/**
 * A two-day ₦2,000 plan with day one paid in cash and ₦1,500 then moved to the wallet.
 *
 * @return array{0: User, 1: CustomerProfile, 2: CustomerAssignment, 3: ThriftPlan, 4: string}
 */
function walletCollectionFixture(): array
{
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(2);
    enableWallet(collections: true);
    recordWalletTestCollection($agent, $customer, $assignment, $plan, $today, '2000.00', 'cash');
    transferToWallet($agent, $customer->fresh(), $plan->fresh(), 'partial', '1500.00');

    return [$agent, $customer->fresh(), $assignment->fresh(), $plan->fresh(), $today];
}

function recordWalletTestCollection($agent, $customer, $assignment, $plan, string $date, string $amount, string $method): CollectionReceipt
{
    $payload = [...collectionPayload($customer->fresh(), $assignment->fresh(), $plan->fresh(), $date, $amount), 'method' => $method];
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer->fresh(), $payload)['preview_fingerprint'];

    return app(CollectionService::class)->record($agent, $customer->fresh(), $payload);
}

test('a wallet contribution moves wallet money into the plan with no custody to remit', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = walletCollectionFixture();

    $receipt = recordWalletTestCollection($agent, $customer, $assignment, $plan, $today, '1500.00', 'wallet');

    expect($receipt->method)->toBe('wallet')
        ->and(walletGroupLines($receipt->savings_posting_group_id))->toBe([
            'customer_wallet_liability_ngn:debit:150000', 'customer_savings_liability_ngn:credit:150000',
        ])
        ->and(app(WalletBalanceService::class)->position($customer)['liability_kobo'])->toBe(0)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
    $batch = CollectionBatch::query()->findOrFail($receipt->collection_batch_id);
    expect($batch->method_identity)->toBe('wallet')
        ->and(app(CollectionBatchPosition::class)->read($batch))->toBe([
            'expected_kobo' => 150000, 'received_kobo' => 150000, 'outstanding_kobo' => 0, 'settlement_pending' => false,
        ]);
});

test('a wallet contribution above the available wallet is refused', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = walletCollectionFixture();

    expect(fn () => recordWalletTestCollection($agent, $customer, $assignment, $plan, $today, '1500.01', 'wallet'))
        ->toThrow(ValidationException::class);
    expect(CollectionReceipt::query()->where('method', 'wallet')->count())->toBe(0);
});

test('an Inactive Customer cannot fund a contribution from the wallet', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = walletCollectionFixture();
    $customer->update(['operational_status' => CustomerStatus::Inactive]);

    expect(fn () => recordWalletTestCollection($agent, $customer, $assignment, $plan, $today, '100.00', 'wallet'))
        ->toThrow(ValidationException::class);
    expect(app(WalletBalanceService::class)->position($customer)['liability_kobo'])->toBe(150000);
});

test('wallet contributions stay unavailable until the wallet collection switch is on', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = walletCollectionFixture();
    config()->set('wallet.collection_enabled', false);

    expect(fn () => recordWalletTestCollection($agent, $customer, $assignment, $plan, $today, '100.00', 'wallet'))
        ->toThrow(HttpException::class);
});

test('a wallet receipt cannot carry payment evidence', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = walletCollectionFixture();
    $payload = [...collectionPayload($customer, $assignment, $plan, $today, '100.00'), 'method' => 'wallet', 'evidence_reference' => 'EVD-1'];

    expect(fn () => app(CollectionService::class)->preview($agent, $customer, $payload))->toThrow(ConflictHttpException::class);
});

test('an agreed fee paid with a wallet contribution is debited from the wallet', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(2, feeAmountKobo: 10000, feeSource: FeeSettlementSource::ExternalReceipt);
    enableWallet(collections: true);
    recordWalletTestCollection($agent, $customer, $assignment, $plan, $today, '2000.00', 'cash');
    transferToWallet($agent, $customer->fresh(), $plan->fresh(), 'partial', '1500.00');
    $fee = $plan->currentTermsRevision()->feeSnapshot->obligation;
    $payload = [...collectionPayload($customer->fresh(), $assignment->fresh(), $plan->fresh(), $today, '1000.00'), 'method' => 'wallet',
        'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '100.00']]];
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer->fresh(), $payload)['preview_fingerprint'];

    $receipt = app(CollectionService::class)->record($agent, $customer->fresh(), $payload);

    $feeGroup = DB::table('collection_fee_components')->where('collection_receipt_id', $receipt->id)->value('ledger_posting_group_id');
    expect($receipt->tender_amount_kobo)->toBe(110000)
        ->and(walletGroupLines($feeGroup))->toBe(['customer_wallet_liability_ngn:debit:10000', 'fee_income_ngn:credit:10000'])
        ->and(app(WalletBalanceService::class)->position($customer)['liability_kobo'])->toBe(40000)
        ->and($fee->fresh()->outstandingAmountKobo())->toBe(0);
});
