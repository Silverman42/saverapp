<?php

use App\Enums\AdminPermission;
use App\Models\CustomerProfile;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalService;
use Illuminate\Support\Str;

require_once __DIR__.'/WithdrawalFixtures.php';

function offlineSession(): array
{
    return ['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
}

/**
 * A Customer with ₦1,000.00 of cycle savings and one approved offline withdrawal (2% withdrawal fee).
 *
 * @return array{0: User, 1: User, 2: CustomerProfile, 3: ThriftPlan, 4: WithdrawalRequest}
 */
function offlinePayoutFixture(object $test, string $type = 'partial', string $gross = '300.00', bool $approve = true): array
{
    config()->set('withdrawals.offline_enabled', true);
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    FinancialPeriod::factory()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::WithdrawalsReview);
    $instruction = [...withdrawalPayload($customer, $assignment, $plan), 'type' => $type, 'gross_ngn' => $gross,
        'method' => 'offline', 'destination_reference' => 'customer:'.$customer->id];
    $withdrawals = app(WithdrawalService::class);
    $quote = $withdrawals->preview($agent, $customer->fresh(), $instruction);
    $withdrawal = $withdrawals->submit($agent, $customer, [...$instruction, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'],
        'instruction_attested' => true, 'confirmed' => true]);
    if ($approve) {
        $test->actingAs($admin)->withSession(offlineSession())->post(route('withdrawals.approve', $withdrawal), [
            'attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->version, 'confirmed' => true,
            'decision_note' => 'Approved for payment outside the platform.',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    return [$admin, $agent, $customer, $plan, $withdrawal->fresh()];
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function offlinePayload(WithdrawalRequest $withdrawal, array $overrides = []): array
{
    return ['attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->version, 'channel' => 'cash',
        'external_reference' => '', 'paid_on' => now('Africa/Lagos')->toDateString(),
        'note' => 'Paid at the branch; Customer ID checked.', 'confirmed' => true, ...$overrides];
}
