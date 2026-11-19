<?php

use App\Models\LedgerPostingGroup;
use App\Models\ReversalRequest;
use App\Services\CollectionReadService;
use App\Services\CollectionService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\ReversalService;
use App\Services\WalletBalanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../WalletFixtures.php';
require_once __DIR__.'/../ReversalFixtures.php';
require_once __DIR__.'/../OfflinePayoutFixtures.php';

beforeEach(function (): void {
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    Queue::fake();
});

test('reversing a transfer gives the wallet credit and fee back to the plan', function (): void {
    [$agent, $customer, $assignment, $plan] = walletFixture();
    $transfer = transferToWallet($agent, $customer, $plan, 'partial', '300.00');
    $original = LedgerPostingGroup::query()->findOrFail($transfer->wallet_posting_group_id);

    $request = approveReceiptCorrection($this, $agent, $customer->fresh(), $assignment->fresh(), $original);

    $request = ReversalRequest::query()->findOrFail($request->id);
    expect($request->state)->toBe('approved_posted')
        ->and(walletGroupLines($request->compensation_posting_group_id))->toBe([
            'customer_savings_liability_ngn:credit:30000', 'customer_wallet_liability_ngn:debit:29400', 'fee_income_ngn:debit:600',
        ])
        ->and(app(WalletBalanceService::class)->position($customer)['liability_kobo'])->toBe(0)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000);
});

test('a transfer cannot be reversed once the wallet money has been paid out', function (): void {
    [$agent, $customer, , $plan] = walletFixture();
    $transfer = transferToWallet($agent, $customer, $plan, 'partial', '300.00');
    $payout = requestWalletPayout($agent, $customer, '294.00');
    $admin = walletReviewer();
    $this->actingAs($admin)->withSession(walletSession())->post(route('withdrawals.approve', $payout), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $payout->version, 'confirmed' => true, 'decision_note' => 'Approved.',
    ])->assertRedirect();
    $this->actingAs($admin)->withSession(walletSession())
        ->post(route('withdrawals.offline-payout.store', $payout), offlinePayload($payout->fresh()))->assertRedirect();

    expect(fn () => app(ReversalService::class)->preview($agent, LedgerPostingGroup::query()->findOrFail($transfer->wallet_posting_group_id)))
        ->toThrow(ConflictHttpException::class);
    expect(app(WalletBalanceService::class)->position($customer)['liability_kobo'])->toBe(0);
});

test('reversing a wallet contribution returns the money to the wallet and frees the plan slot', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(2);
    enableWallet(collections: true);
    $cash = [...collectionPayload($customer, $assignment, $plan, $today, '2000.00'), 'method' => 'cash'];
    $cash['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $cash)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $cash);
    transferToWallet($agent, $customer->fresh(), $plan->fresh(), 'partial', '1500.00');
    $walletPayload = [...collectionPayload($customer->fresh(), $assignment->fresh(), $plan->fresh(), $today, '1500.00'), 'method' => 'wallet'];
    $walletPayload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer->fresh(), $walletPayload)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer->fresh(), $walletPayload);

    $request = approveReceiptCorrection($this, $agent, $customer->fresh(), $assignment->fresh(),
        LedgerPostingGroup::query()->findOrFail($receipt->savings_posting_group_id));

    $request = ReversalRequest::query()->findOrFail($request->id);
    expect($request->state)->toBe('approved_posted')
        ->and(walletGroupLines($request->compensation_posting_group_id))->toBe([
            'customer_savings_liability_ngn:debit:150000', 'customer_wallet_liability_ngn:credit:150000',
        ])
        ->and(app(WalletBalanceService::class)->position($customer)['liability_kobo'])->toBe(150000)
        ->and(DB::table('collection_allocation_releases')->whereIn('collection_allocation_id', $receipt->allocations()->pluck('id'))->count())
        ->toBe($receipt->allocations()->count());
});

test('a full projection rebuild covers every wallet posting without freezing the Customer', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(2);
    enableWallet(collections: true);
    $cash = [...collectionPayload($customer, $assignment, $plan, $today, '2000.00'), 'method' => 'cash'];
    $cash['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $cash)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $cash);
    transferToWallet($agent, $customer->fresh(), $plan->fresh(), 'partial', '1500.00');
    $walletPayload = [...collectionPayload($customer->fresh(), $assignment->fresh(), $plan->fresh(), $today, '500.00'), 'method' => 'wallet'];
    $walletPayload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer->fresh(), $walletPayload)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer->fresh(), $walletPayload);
    approveReceiptCorrection($this, $agent, $customer->fresh(), $assignment->fresh(), LedgerPostingGroup::query()->findOrFail($receipt->savings_posting_group_id));
    $payout = requestWalletPayout($agent, $customer, '1000.00');
    $admin = walletReviewer();
    $this->actingAs($admin)->withSession(walletSession())->post(route('withdrawals.approve', $payout), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $payout->version, 'confirmed' => true, 'decision_note' => 'Approved.',
    ])->assertRedirect();
    $this->actingAs($admin)->withSession(walletSession())
        ->post(route('withdrawals.offline-payout.store', $payout), offlinePayload($payout->fresh()))->assertRedirect();

    $result = app(LedgerTransactionProjectionService::class)->rebuild();

    expect($result['frozen_customers'])->toBe(0)
        ->and($result['groups'])->toBe(LedgerPostingGroup::query()->count())
        ->and(app(WalletBalanceService::class)->position($customer)['liability_kobo'])->toBe(50000);
});
