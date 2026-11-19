<?php

use App\Services\LedgerTransactionProjectionService;
use App\Services\StatementPreviewService;
use Illuminate\Support\Str;

require_once __DIR__.'/../WalletFixtures.php';
require_once __DIR__.'/../OfflinePayoutFixtures.php';

test('the statement keeps plan savings and the wallet in separate reconciled sections', function (): void {
    [$agent, $customer, , $plan] = walletFixture();
    transferToWallet($agent, $customer, $plan, 'partial', '300.00');
    $payout = requestWalletPayout($agent, $customer, '200.00');
    $admin = walletReviewer();
    $this->actingAs($admin)->withSession(walletSession())->post(route('withdrawals.approve', $payout), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $payout->version, 'confirmed' => true, 'decision_note' => 'Approved.',
    ])->assertRedirect();
    $this->actingAs($admin)->withSession(walletSession())
        ->post(route('withdrawals.offline-payout.store', $payout), offlinePayload($payout->fresh()))->assertRedirect();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $today = now('Africa/Lagos')->toDateString();

    $statement = app(StatementPreviewService::class)->preview($agent, $customer->fresh(), $today, $today, 'Africa/Lagos');

    expect($statement['status'])->toBe('ready')
        ->and($statement['closing_kobo'])->toBe(70000)
        ->and(collect($statement['lines'])->pluck('type')->all())->toContain('wallet_transfer', 'wallet_payout')
        ->and(collect($statement['lines'])->firstWhere('type', 'wallet_payout')['savings_effect_kobo'])->toBe(0)
        ->and($statement['wallet'])->toMatchArray(['status' => 'ready', 'opening_kobo' => 0, 'closing_kobo' => 9400, 'current_available_kobo' => 9400])
        ->and(collect($statement['wallet']['lines'])->pluck('wallet_effect_kobo')->all())->toBe([29400, -20000]);
});
