<?php

use App\Enums\AdminPermission;
use App\Models\BankPayoutAttempt;
use App\Models\CustomerPayoutDestination;
use App\Models\CustomerProfile;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\FakePayoutProvider;
use App\Services\WithdrawalService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/NoncashCollectionFixtures.php';

function bankSession(): array
{
    return ['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
}

function enableBankRail(): void
{
    config()->set(['withdrawals.bank_enabled' => true, 'withdrawals.bank_certified' => true, 'withdrawals.bank.provider' => 'fake',
        'withdrawals.bank.callback_secret' => 'test-callback-secret']);
    LedgerAccount::query()->whereIn('code', ['payout_clearing_ngn', 'cash_recovery_clearing_ngn', 'business_bank_ngn', 'business_cash_ngn', 'fee_income_ngn',
        'refund_payable_ngn', 'unapplied_funds_ngn', 'customer_savings_liability_ngn'])->update(['mapping_status' => 'mapped']);
}

/**
 * A funded bank, a Customer with ₦2,000.00 of cycle savings, a verified bank destination whose last account digit selects the fake
 * provider scenario, and one approved ₦300.00 partial withdrawal awaiting execution.
 *
 * @return array{0: User, 1: User, 2: CustomerProfile, 3: ThriftPlan, 4: WithdrawalRequest, 5: CustomerPayoutDestination}
 */
function bankPayoutFixture(object $test, string $lastDigit = '1', string $gross = '300.00', bool $approve = true): array
{
    [$agent, $customer, $assignment, $plan, , $admin, , $payload] = noncashFixture($test, 'transfer', 'business_bank_ngn');
    postNoncashReceipt($test, $customer, $payload);
    enableBankRail();
    $admin->givePermissionTo([AdminPermission::WithdrawalsReview, AdminPermission::CustomersManage]);
    $account = '123456789'.$lastDigit;
    FakePayoutProvider::nameAccount('058', $account, (string) $customer->user->name);
    $test->actingAs($agent)->post(route('customers.payout-destinations.store', $customer->customer_id), [
        'bank_code' => '058', 'account_number' => $account, 'attestation' => 'The Customer gave this account in person.',
        'registration_reference' => (string) Str::uuid(),
    ])->assertRedirect();
    $destination = CustomerPayoutDestination::query()->latest('id')->firstOrFail();
    $test->actingAs($admin)->withSession(bankSession())->post(route('payout-destinations.verify', $destination), [
        'note' => 'Verified against the provider name.', 'confirmed' => true,
    ])->assertRedirect();
    $destination = $destination->fresh();
    $request = ['plan_id' => $plan->plan_id, 'type' => 'partial', 'gross_ngn' => $gross, 'method' => 'bank_transfer',
        'destination_reference' => $destination->destination_reference, 'reason' => 'Customer requested a transfer', 'internal_notes' => ''];
    $quote = app(WithdrawalService::class)->preview($agent, $customer->fresh(), $request);
    $withdrawal = app(WithdrawalService::class)->submit($agent, $customer, [...$request, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'],
        'instruction_attested' => true, 'confirmed' => true]);
    if ($approve) {
        $test->actingAs($admin)->withSession(bankSession())->post(route('withdrawals.approve', $withdrawal), [
            'attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->version, 'confirmed' => true, 'decision_note' => 'Approved after review.',
        ])->assertRedirect();
    }

    return [$admin, $agent, $customer, $plan, $withdrawal->fresh(), $destination];
}

function startBankPayout(object $test, User $admin, WithdrawalRequest $withdrawal, ?string $reference = null): string
{
    $reference ??= (string) Str::uuid();
    $test->actingAs($admin)->withSession(bankSession())->post(route('withdrawals.bank.start', $withdrawal), [
        'attempt_reference' => $reference, 'version' => $withdrawal->fresh()->version, 'confirmed' => true,
    ])->assertRedirect();

    return $reference;
}

function bankAttempt(string $reference): BankPayoutAttempt
{
    return BankPayoutAttempt::query()->where('attempt_reference', $reference)->firstOrFail();
}

/** @return list<string> */
function groupLines(LedgerPostingGroup $group): array
{
    return $group->entries->map(fn ($line): string => $line->account->code->value.':'.$line->side->value.':'.$line->amount_kobo)->sort()->values()->all();
}

/** Put verified funds into business bank custody without a collection flow (tests that do not rebuild the projection). */
function fundBusinessBank(int $amountKobo): void
{
    $actor = User::factory()->admin()->create();
    LedgerAccount::query()->whereIn('code', ['business_bank_ngn', 'unapplied_funds_ngn'])->update(['mapping_status' => 'mapped']);
    $group = LedgerPostingGroup::create(['posting_reference' => 'TEST-FUND-'.Str::uuid(), 'idempotency_key' => 'test-fund-'.Str::uuid(),
        'payload_hash' => str_repeat('c', 64), 'source_type' => 'test_bank_funding', 'source_id' => '1', 'event_type' => 'test_bank_funding',
        'currency' => 'NGN', 'actor_user_id' => $actor->id, 'occurred_at' => now(), 'occurred_on' => now()->toDateString(),
        'business_timezone' => 'Africa/Lagos', 'schema_version' => 1, 'committed_at' => now()]);
    foreach ([['business_bank_ngn', 'debit'], ['unapplied_funds_ngn', 'credit']] as $index => [$code, $side]) {
        LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $index + 1,
            'ledger_account_id' => LedgerAccount::query()->where('code', $code)->value('id'), 'side' => $side, 'amount_kobo' => $amountKobo]);
    }
}

function sendPayoutCallback(object $test, array $event, ?int $timestamp = null, ?array $tamper = null): TestResponse
{
    $signed = FakePayoutProvider::signedCallback($event, $timestamp);
    $body = $tamper === null ? $signed['body'] : json_encode($tamper);

    return $test->call('POST', route('payout-callbacks.store', 'fake'), [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYOUT_SIGNATURE' => $signed['headers']['x-payout-signature'], 'HTTP_ACCEPT' => 'application/json'], $body);
}

function callbackEvent(BankPayoutAttempt $attempt, string $type, ?string $eventId = null, array $extra = []): array
{
    return ['event_id' => $eventId ?? (string) Str::uuid(), 'event_type' => 'transfer.'.$type, 'idempotency_key' => $attempt->idempotency_key,
        'provider_reference' => $attempt->provider_reference, 'amount_kobo' => $attempt->amount_kobo, 'occurred_at' => now()->toIso8601String(), ...$extra];
}
