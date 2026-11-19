<?php

use App\Models\LedgerPostingGroup;
use App\Models\WithdrawalRequest;
use App\Services\CollectionReadService;
use App\Services\WalletBalanceService;
use App\Services\WithdrawalMethodRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require_once __DIR__.'/../WalletFixtures.php';
require_once __DIR__.'/../OfflinePayoutFixtures.php';

test('a wallet payout reserves the wallet, is approved by an Admin and posts a wallet debit on the offline rail', function (): void {
    [$agent, $customer, , $plan] = walletFixture();
    transferToWallet($agent, $customer, $plan, 'partial', '300.00');
    $admin = walletReviewer();

    $payout = requestWalletPayout($agent, $customer, '200.00');

    expect($payout->state)->toBe('pending_review')
        ->and($payout->thrift_plan_id)->toBeNull()
        ->and($payout->live_wallet_customer_profile_id)->toBe($customer->id)
        ->and(app(WalletBalanceService::class)->position($customer))->toMatchArray(['liability_kobo' => 29400, 'reservations_kobo' => 20000, 'available_kobo' => 9400])
        ->and(app(CollectionReadService::class)->position($customer)['available_kobo'])->toBe(70000);

    $this->actingAs($admin)->withSession(walletSession())->post(route('withdrawals.approve', $payout), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $payout->version, 'confirmed' => true, 'decision_note' => 'Approved.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($admin)->withSession(walletSession())
        ->post(route('withdrawals.offline-payout.store', $payout), offlinePayload($payout->fresh()))->assertRedirect()->assertSessionHasNoErrors();

    $payout->refresh();
    $group = LedgerPostingGroup::query()->where('source_type', 'withdrawal')->where('source_id', (string) $payout->id)->sole();
    expect($payout->state)->toBe('posted')
        ->and($payout->live_wallet_customer_profile_id)->toBeNull()
        ->and(walletGroupLines($group->id))->toBe(['customer_wallet_liability_ngn:debit:20000', 'business_cash_ngn:credit:20000'])
        ->and(app(WalletBalanceService::class)->position($customer))->toMatchArray(['liability_kobo' => 9400, 'available_kobo' => 9400])
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(70000);
});

test('a wallet payout above the available wallet is refused without a reservation', function (): void {
    [$agent, $customer, , $plan] = walletFixture();
    transferToWallet($agent, $customer, $plan, 'partial', '300.00');

    expect(fn () => requestWalletPayout($agent, $customer, '294.01'))->toThrow(ValidationException::class)
        ->and(DB::table('withdrawal_reservations')->where('source', 'wallet')->count())->toBe(0);
});

test('only one wallet payout can be live for a Customer and rejecting it releases the wallet', function (): void {
    [$agent, $customer, , $plan] = walletFixture();
    transferToWallet($agent, $customer, $plan, 'partial', '300.00');
    $payout = requestWalletPayout($agent, $customer, '100.00');

    expect(fn () => requestWalletPayout($agent, $customer, '50.00'))->toThrow(ConflictHttpException::class);

    $this->actingAs(walletReviewer())->withSession(walletSession())->post(route('withdrawals.reject', $payout), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $payout->version, 'confirmed' => true,
        'internal_reason' => 'Customer changed their mind.', 'customer_explanation' => 'Rejected at your request.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($payout->fresh()->state)->toBe('rejected')
        ->and($payout->fresh()->live_wallet_customer_profile_id)->toBeNull()
        ->and(app(WalletBalanceService::class)->position($customer)['available_kobo'])->toBe(29400)
        ->and(requestWalletPayout($agent, $customer, '50.00')->state)->toBe('pending_review');
});

test('external rails are offered only for wallet payouts while the wallet is active', function (): void {
    walletFixture();
    $registry = app(WithdrawalMethodRegistry::class);

    expect($registry->availableMethods('plan'))->toBe(['wallet'])
        ->and($registry->availableMethods('wallet'))->toContain('offline')
        ->and($registry->availableMethods('wallet'))->not->toContain('wallet');
});

test('a live plan payout started before the wallet was enabled still posts on its rail', function (): void {
    [$admin, , $customer, , $withdrawal] = offlinePayoutFixture($this);
    enableWallet();

    $this->actingAs($admin)->withSession(offlineSession())
        ->post(route('withdrawals.offline-payout.store', $withdrawal), offlinePayload($withdrawal))->assertRedirect()->assertSessionHasNoErrors();

    expect($withdrawal->fresh()->state)->toBe('posted')
        ->and(app(WalletBalanceService::class)->position($customer)['liability_kobo'])->toBe(0)
        ->and(WithdrawalRequest::query()->where('source', 'wallet')->count())->toBe(0);
});
