<?php

use App\Enums\ThriftPlanStatus;
use App\Models\LedgerAccount;
use App\Models\WithdrawalRequest;
use App\Services\CollectionReadService;
use App\Services\WalletBalanceService;
use App\Services\WithdrawalService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../WalletFixtures.php';

test('a partial transfer moves plan savings into the wallet net of the plan fee', function (): void {
    [$agent, $customer, , $plan] = walletFixture();

    $transfer = transferToWallet($agent, $customer, $plan, 'partial', '300.00');

    expect($transfer->state)->toBe('posted')
        ->and($transfer->method)->toBe('wallet')
        ->and([$transfer->gross_amount_kobo, $transfer->fee_amount_kobo, $transfer->net_amount_kobo])->toBe([30000, 600, 29400])
        ->and(walletGroupLines($transfer->wallet_posting_group_id))->toBe([
            'customer_savings_liability_ngn:debit:30000', 'customer_wallet_liability_ngn:credit:29400', 'fee_income_ngn:credit:600',
        ])
        ->and(app(WalletBalanceService::class)->position($customer))->toMatchArray(['liability_kobo' => 29400, 'available_kobo' => 29400])
        ->and(app(CollectionReadService::class)->position($customer))->toMatchArray(['liability_kobo' => 70000, 'available_kobo' => 70000])
        ->and($transfer->live_thrift_plan_id)->toBeNull();
});

test('a full transfer moves the whole plan into the wallet and starts the plan closure', function (): void {
    [$agent, $customer, , $plan] = walletFixture();

    $transfer = transferToWallet($agent, $customer, $plan, 'full', '1000.00');

    expect($transfer->state)->toBe('posted')
        ->and(app(WalletBalanceService::class)->position($customer)['liability_kobo'])->toBe(98000)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0)
        ->and($plan->fresh()->status)->toBe(ThriftPlanStatus::Paused)
        ->and($plan->fresh()->closure_pending_at)->not->toBeNull();
});

test('while the wallet is active plan savings cannot be paid out directly', function (): void {
    [$agent, $customer, $assignment, $plan] = walletFixture();

    expect(fn () => app(WithdrawalService::class)->preview($agent, $customer->fresh(), [...withdrawalPayload($customer, $assignment, $plan),
        'method' => 'offline', 'destination_reference' => 'customer:'.$customer->id]))
        ->toThrow(ValidationException::class);
});

test('a stale transfer quote posts nothing', function (): void {
    [$agent, $customer, , $plan] = walletFixture();

    expect(fn () => transferToWallet($agent, $customer, $plan, 'partial', '300.00', ['preview_fingerprint' => str_repeat('0', 64)]))
        ->toThrow(ConflictHttpException::class);
    expect(WithdrawalRequest::query()->count())->toBe(0)
        ->and(app(WalletBalanceService::class)->position($customer)['liability_kobo'])->toBe(0);
});

test('replaying a transfer attempt returns the original transfer without posting again', function (): void {
    [$agent, $customer, , $plan] = walletFixture();
    $instruction = ['plan_id' => $plan->plan_id, 'type' => 'partial', 'gross_ngn' => '300.00', 'method' => 'wallet',
        'destination_reference' => 'wallet:'.$customer->id, 'reason' => 'Move savings to the wallet', 'internal_notes' => ''];
    $quote = app(WithdrawalService::class)->preview($agent, $customer->fresh(), $instruction);
    $payload = [...$instruction, 'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'quote_expires_at' => $quote['quote_expires_at'], 'customer_version' => $quote['customer_version'],
        'assignment_version' => $quote['assignment_version'], 'plan_version' => $quote['plan_version'],
        'business_version' => $quote['business_version'], 'instruction_attested' => true, 'confirmed' => true];

    $first = app(WithdrawalService::class)->submit($agent, $customer->fresh(), $payload);
    $second = app(WithdrawalService::class)->submit($agent, $customer->fresh(), $payload);

    expect($second->id)->toBe($first->id)
        ->and(app(WalletBalanceService::class)->position($customer)['liability_kobo'])->toBe(29400);
});

test('an unmapped wallet account blocks transfers and leaves plan savings readable', function (): void {
    [$agent, $customer, , $plan] = walletFixture();
    LedgerAccount::query()->where('code', 'customer_wallet_liability_ngn')->update(['mapping_status' => 'unconfigured']);

    expect(fn () => transferToWallet($agent, $customer, $plan))->toThrow(HttpException::class)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000);
});
