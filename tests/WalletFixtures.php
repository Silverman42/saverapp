<?php

use App\Enums\AdminPermission;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalService;
use Illuminate\Support\Str;

require_once __DIR__.'/WithdrawalFixtures.php';

function enableWallet(bool $collections = false): void
{
    config()->set(['wallet.enabled' => true, 'wallet.certified' => true, 'wallet.collection_enabled' => $collections,
        'withdrawals.offline_enabled' => true]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
}

function walletSession(): array
{
    return ['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
}

/**
 * A Customer with ₦1,000.00 of plan savings under a 2% withdrawal fee, the wallet enabled, and an open financial period.
 *
 * @return array{0: User, 1: CustomerProfile, 2: CustomerAssignment, 3: ThriftPlan}
 */
function walletFixture(bool $fixedWithdrawalFee = false): array
{
    [$agent, $customer, $assignment, $plan] = withdrawalFixture($fixedWithdrawalFee);
    enableWallet();
    FinancialPeriod::factory()->create();

    return [$agent, $customer, $assignment, $plan];
}

/** @param array<string, mixed> $overrides */
function transferToWallet(User $agent, CustomerProfile $customer, ThriftPlan $plan, string $type = 'partial', string $gross = '300.00', array $overrides = []): WithdrawalRequest
{
    $instruction = ['plan_id' => $plan->plan_id, 'type' => $type, 'gross_ngn' => $gross, 'method' => 'wallet',
        'destination_reference' => 'wallet:'.$customer->id, 'reason' => 'Move savings to the wallet', 'internal_notes' => ''];
    $withdrawals = app(WithdrawalService::class);
    $quote = $withdrawals->preview($agent, $customer->fresh(), $instruction);

    return $withdrawals->submit($agent, $customer->fresh(), [...$instruction, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'],
        'instruction_attested' => true, 'confirmed' => true, ...$overrides]);
}

function requestWalletPayout(User $agent, CustomerProfile $customer, string $gross = '200.00', string $method = 'offline'): WithdrawalRequest
{
    $instruction = ['source' => 'wallet', 'type' => 'wallet_payout', 'gross_ngn' => $gross, 'method' => $method,
        'destination_reference' => 'customer:'.$customer->id, 'reason' => 'Customer asked for cash', 'internal_notes' => ''];
    $withdrawals = app(WithdrawalService::class);
    $quote = $withdrawals->preview($agent, $customer->fresh(), $instruction);

    return $withdrawals->submit($agent, $customer->fresh(), [...$instruction, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'business_version' => $quote['business_version'], 'instruction_attested' => true, 'confirmed' => true]);
}

function walletReviewer(): User
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::WithdrawalsReview);

    return $admin;
}

/** @return list<string> */
function walletGroupLines(int $groupId): array
{
    return LedgerEntry::query()->with('account')->where('ledger_posting_group_id', $groupId)->orderBy('line_number')->get()
        ->map(fn ($entry): string => $entry->account->code->value.':'.$entry->side->value.':'.$entry->amount_kobo)->all();
}
