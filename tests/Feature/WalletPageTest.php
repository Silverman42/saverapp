<?php

use App\Models\AgentProfile;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../WalletFixtures.php';

test('the assigned Agent, the Customer and an Admin see the wallet; another Agent cannot', function (): void {
    [$agent, $customer, , $plan] = walletFixture();
    transferToWallet($agent, $customer, $plan, 'partial', '300.00');
    $outsider = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id])->user;

    foreach ([$agent, $customer->user, User::factory()->admin()->withTwoFactor()->create()] as $viewer) {
        $this->actingAs($viewer)->get(route('customers.wallet.show', $customer->customer_id))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('wallets/Show')
                ->where('wallet.liability_kobo', 29400)->where('wallet.available_kobo', 29400)
                ->has('history.data', 1)->where('history.data.0.type', 'plan_to_wallet_transfer')
                ->where('history.data.0.amount_kobo', 29400));
    }
    $this->actingAs($outsider)->get(route('customers.wallet.show', $customer->customer_id))->assertForbidden();
});

test('the request page offers the wallet as the only plan destination and wallet payouts from the wallet', function (): void {
    [$agent, $customer, , $plan] = walletFixture();
    transferToWallet($agent, $customer, $plan, 'partial', '300.00');

    $this->actingAs($agent)->get(route('customers.withdrawals.create', [$customer->customer_id, 'source' => 'wallet']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('withdrawals/Create')
            ->where('methods', ['wallet'])->where('wallet.active', true)->where('wallet.available_kobo', 29400)
            ->where('wallet.methods', ['offline'])->where('initial_source', 'wallet'));
});

test('an Agent moves plan savings to the wallet through the request form', function (): void {
    [$agent, $customer, , $plan] = walletFixture();
    $instruction = ['plan_id' => $plan->plan_id, 'type' => 'partial', 'gross_ngn' => '300.00', 'method' => 'wallet',
        'destination_reference' => 'wallet:'.$customer->id, 'reason' => 'Move savings to the wallet', 'internal_notes' => ''];
    $quote = $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id), $instruction)->assertOk()->json();

    $this->actingAs($agent)->post(route('customers.withdrawals.store', $customer->customer_id), [...$instruction,
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'quote_expires_at' => $quote['quote_expires_at'], 'customer_version' => $quote['customer_version'],
        'assignment_version' => $quote['assignment_version'], 'plan_version' => $quote['plan_version'],
        'business_version' => $quote['business_version'], 'instruction_attested' => true, 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(WithdrawalRequest::query()->sole()->state)->toBe('posted');
});

test('an Agent requests a wallet payout through the request form', function (): void {
    [$agent, $customer, , $plan] = walletFixture();
    transferToWallet($agent, $customer, $plan, 'partial', '300.00');
    $instruction = ['source' => 'wallet', 'type' => 'wallet_payout', 'gross_ngn' => '100.00', 'method' => 'offline',
        'destination_reference' => 'customer:'.$customer->id, 'reason' => 'Customer asked for cash', 'internal_notes' => ''];
    $quote = $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id), $instruction)->assertOk()->json();

    $this->actingAs($agent)->post(route('customers.withdrawals.store', $customer->customer_id), [...$instruction,
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'quote_expires_at' => $quote['quote_expires_at'], 'customer_version' => $quote['customer_version'],
        'assignment_version' => $quote['assignment_version'], 'business_version' => $quote['business_version'],
        'instruction_attested' => true, 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(WithdrawalRequest::query()->where('source', 'wallet')->sole()->state)->toBe('pending_review');
});

test('the withdrawals list and detail page show a wallet payout against the wallet balance', function (): void {
    [$agent, $customer, , $plan] = walletFixture();
    transferToWallet($agent, $customer, $plan, 'partial', '300.00');
    $payout = requestWalletPayout($agent, $customer, '100.00');
    $admin = walletReviewer();

    $this->actingAs($admin)->get(route('withdrawals.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('requests.data', fn ($rows): bool => collect($rows)
            ->contains(fn (array $row): bool => $row['id'] === $payout->withdrawal_id && $row['plan_id'] === null && $row['source'] === 'wallet')));
    foreach ([$admin, $agent, $customer->user] as $viewer) {
        $this->actingAs($viewer)->get(route('withdrawals.show', $payout))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('withdrawals/Show')
                ->where('withdrawal.plan_id', null)->where('withdrawal.source', 'wallet')
                ->where('position.liability_kobo', 29400)->where('position.reservations_kobo', 10000)
                ->where('position.available_kobo', 19400)->where('position.cycle_available_kobo', null));
    }
});
