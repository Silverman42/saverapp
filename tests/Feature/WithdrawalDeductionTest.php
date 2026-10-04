<?php

use App\Enums\LedgerAccountCode;
use App\Models\ChargeCategoryVersion;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\LedgerTransactionProjectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../BankPayoutFixtures.php';
require_once __DIR__.'/../CashExecutionFixtures.php';

function publishDeductionCategory(int $amountKobo = 500, string $key = 'withdrawal-handling'): ChargeCategoryVersion
{
    $account = LedgerAccount::query()->where('code', LedgerAccountCode::OtherDeductionDestination->value)->firstOrFail();
    $account->update(['mapping_status' => 'mapped']);
    $version = (int) ChargeCategoryVersion::query()->where('category_key', $key)->max('version') + 1;

    return ChargeCategoryVersion::create(['publication_reference' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $key.$version),
        'category_key' => $key, 'version' => $version, 'kind' => 'deduction', 'purpose' => 'Approved withdrawal handling', 'customer_description' => 'Withdrawal handling charge',
        'destination_code' => LedgerAccountCode::OtherDeductionDestination->value, 'destination_mapping_version' => $account->version,
        'amount_kobo' => $amountKobo, 'published_by_user_id' => User::factory()->admin()->create()->id]);
}

function enableDeduction(): void
{
    config()->set(['withdrawals.deduction_enabled' => true, 'withdrawals.deduction_category_key' => 'withdrawal-handling']);
}

test('D is zero unless the contract is explicitly enabled', function (): void {
    [$admin, , , , $withdrawal] = bankPayoutFixture($this, '1');
    publishDeductionCategory();

    expect($withdrawal->deduction_amount_kobo)->toBe(0)->and($withdrawal->net_amount_kobo)->toBe(30000);
    startBankPayout($this, $admin, $withdrawal);
    expect(LedgerPostingGroup::query()->where('event_type', 'bank_withdrawal')->sole()->entries()->count())->toBe(2);
});

test('a frozen deduction is posted once to its destination and reconciles in the projection', function (): void {
    publishDeductionCategory();
    enableDeduction();
    [$admin, , , , $withdrawal] = bankPayoutFixture($this, '1');

    expect($withdrawal->gross_amount_kobo)->toBe(30000)->and($withdrawal->deduction_amount_kobo)->toBe(500)->and($withdrawal->net_amount_kobo)->toBe(29500);
    startBankPayout($this, $admin, $withdrawal);

    $group = LedgerPostingGroup::query()->where('event_type', 'bank_withdrawal')->with('entries.account')->sole();
    expect(groupLines($group))->toBe(['customer_savings_liability_ngn:debit:30000', 'other_deduction_destination_ngn:credit:500', 'payout_clearing_ngn:credit:29500']);
    app(LedgerTransactionProjectionService::class)->rebuild();
    expect(DB::table('ledger_transaction_projections')->where('type', 'withdrawal')->value('gross_amount_kobo'))->toBe(30000);
});

test('a deduction that would consume the whole payout is rejected at quote time', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    publishDeductionCategory(30000);
    enableDeduction();

    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id), withdrawalPayload($customer, $assignment, $plan))
        ->assertStatus(422)->assertJsonValidationErrors('gross_ngn');
});

test('changing the deduction contract after submission blocks approval until a new quote', function (): void {
    publishDeductionCategory();
    enableDeduction();
    [$admin, , , , $withdrawal] = bankPayoutFixture($this, '1', approve: false);
    publishDeductionCategory(700);

    $this->actingAs($admin)->withSession(bankSession())->post(route('withdrawals.approve', $withdrawal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->version, 'confirmed' => true, 'decision_note' => 'Approve.'])->assertStatus(409);

    expect($withdrawal->fresh()->state)->toBe('pending_review')->and($withdrawal->fresh()->deduction_amount_kobo)->toBe(500);
});

test('a frozen deduction is paid through the cash rail with the same balanced posting', function (): void {
    publishDeductionCategory();
    enableDeduction();
    [$admin, , , $withdrawal] = cashPaymentFixture();
    expect($withdrawal->deduction_amount_kobo)->toBe(500)->and($withdrawal->fee_amount_kobo)->toBe(600)->and($withdrawal->net_amount_kobo)->toBe(28900);
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Handed over.', 'confirmed' => true])->assertRedirect();
    $this->actingAs($withdrawal->customerProfile->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();

    $group = LedgerPostingGroup::query()->where('event_type', 'cash_withdrawal')->with('entries.account')->sole();
    expect(groupLines($group))->toBe(['business_cash_ngn:credit:28900', 'customer_savings_liability_ngn:debit:30000', 'fee_income_ngn:credit:600', 'other_deduction_destination_ngn:credit:500'])
        ->and(WithdrawalRequest::query()->sole()->state)->toBe('posted');
});
