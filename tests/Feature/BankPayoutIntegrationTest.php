<?php

use App\Enums\AdminPermission;
use App\Models\BankPayoutAttempt;
use App\Models\BankPayoutReturn;
use App\Models\CustomerPayoutDestination;
use App\Models\FinancialPeriod;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Services\LedgerTransactionProjectionService;
use App\Services\PlanFeeHistoryReadService;
use App\Services\PlanFinancialActivityReadService;
use App\Services\PlanWithdrawalFeePosition;
use App\Services\ReversalService;
use App\Services\WithdrawalBalanceService;
use App\Services\WithdrawalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require_once __DIR__.'/../WithdrawalFixtures.php';
require_once __DIR__.'/../BankPayoutFixtures.php';

test('a positive fee withdrawal paid by bank transfer keeps its cycle fee verifiable', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture(true);
    enableBankRail();
    fundBusinessBank(500000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::WithdrawalsReview, AdminPermission::CustomersManage]);
    $destination = CustomerPayoutDestination::factory()->verified($admin)->create(['customer_profile_id' => $customer->id, 'registered_by_user_id' => $agent->id]);
    $request = ['plan_id' => $plan->plan_id, 'type' => 'partial', 'gross_ngn' => '300.00', 'method' => 'bank_transfer',
        'destination_reference' => $destination->destination_reference, 'reason' => 'Customer requested a transfer', 'internal_notes' => ''];
    $quote = app(WithdrawalService::class)->preview($agent, $customer->fresh(), $request);
    $withdrawal = app(WithdrawalService::class)->submit($agent, $customer, [...$request, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'], 'instruction_attested' => true, 'confirmed' => true]);
    $this->actingAs($admin)->withSession(bankSession())->post(route('withdrawals.approve', $withdrawal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->version, 'confirmed' => true, 'decision_note' => 'Approved.'])->assertRedirect();

    FinancialPeriod::factory()->create();
    $reference = startBankPayout($this, $admin, $withdrawal->fresh());

    $group = LedgerPostingGroup::query()->where('event_type', 'bank_withdrawal')->with('entries.account')->sole();
    expect(bankAttempt($reference)->status)->toBe('succeeded')->and($withdrawal->fresh()->state)->toBe('posted')
        ->and($withdrawal->fresh()->fee_amount_kobo)->toBe(600)
        ->and(groupLines($group))->toBe(['customer_savings_liability_ngn:debit:30000', 'fee_income_ngn:credit:600', 'payout_clearing_ngn:credit:29400']);
    $snapshot = $plan->fresh()->currentTermsRevision()->feeSnapshot;
    $position = app(PlanWithdrawalFeePosition::class)->read($plan->fresh(), $snapshot);
    expect($position['consumed'])->toBeTrue()->and($position['settled_kobo'])->toBe(600)->and($position['outstanding_kobo'])->toBe(0);
    $history = app(PlanFeeHistoryReadService::class)->read($agent, $plan->fresh());
    expect($history['status'] ?? null)->not->toBe('unavailable');
});

test('a bank payout reconciles in the rebuilt projection and the cycle activity totals', function (): void {
    [$admin, $agent, $customer, $plan, $withdrawal] = bankPayoutFixture($this, '1');
    $attempt = bankAttempt(startBankPayout($this, $admin, $withdrawal));
    $this->artisan('withdrawals:reconcile-bank-payouts')->assertSuccessful();
    app(LedgerTransactionProjectionService::class)->rebuild();

    $activity = app(PlanFinancialActivityReadService::class)->read($admin, $plan->fresh());
    $metrics = collect($activity['metrics'])->keyBy('code');
    expect($metrics['gross_withdrawals']['value'])->toBe(30000)->and($metrics['net_bank_payouts']['value'])->toBe(30000)
        ->and($metrics['net_cash_payouts']['value'])->toBe(0)->and($metrics['effective_withdrawal_debits']['value'])->toBe(30000);
    expect(DB::table('ledger_transaction_projections')->where('type', 'withdrawal')->value('gross_amount_kobo'))->toBe(30000)
        ->and(BankPayoutAttempt::query()->whereKey($attempt->id)->value('status'))->toBe('succeeded');
});

test('a fully returned bank transfer is compensated once by an independently approved reversal', function (): void {
    config()->set('withdrawals.bank_compensation_enabled', true);
    [$admin, $agent, $customer, $plan, $withdrawal] = bankPayoutFixture($this, '7');
    $attempt = bankAttempt(startBankPayout($this, $admin, $withdrawal));
    $original = LedgerPostingGroup::query()->where('event_type', 'bank_withdrawal')->sole();
    $service = app(ReversalService::class);
    expect(fn () => $service->preview($agent, $original))->toThrow(ConflictHttpException::class, 'proven full provider return');

    $partial = callbackEvent($attempt, 'returned', 'evt-partial', ['return_reference' => 'RET-PARTIAL', 'amount_kobo' => 10000]);
    sendPayoutCallback($this, $partial)->assertOk();
    expect(fn () => $service->preview($agent, $original))->toThrow(ConflictHttpException::class, 'proven full provider return');
    sendPayoutCallback($this, callbackEvent($attempt, 'returned', 'evt-rest', ['return_reference' => 'RET-REST', 'amount_kobo' => 20000]))->assertOk();
    expect(BankPayoutReturn::query()->where('status', 'posted')->sum('amount_kobo'))->toBe(30000)
        ->and(app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'])->toBe(170000);

    $quote = $service->preview($agent, $original);
    $reversal = $service->submit($agent, $original, ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'customer_version' => $quote['customer_version'],
        'assignment_version' => $quote['assignment_version'], 'reason_category' => 'incorrect_payout_record',
        'internal_reason' => 'The transfer was returned in full by the bank.', 'customer_explanation' => 'The bank returned the transfer in full.',
        'evidence_text' => 'Provider return evidence retained.', 'confirmed' => true]);
    $admin->givePermissionTo(AdminPermission::ReversalsReview);
    $review = $service->reviewPreview($admin, $reversal);
    $payload = ['attempt_reference' => (string) Str::uuid(), 'version' => $reversal->version,
        'preview_fingerprint' => $review['preview_fingerprint'], 'decision_reason' => 'Verified full provider return.', 'confirmed' => true];
    $this->actingAs($admin)->withSession(bankSession())->post(route('reversals.approve', $reversal), $payload)->assertRedirect();
    $this->post(route('reversals.approve', $reversal), $payload)->assertRedirect();

    $compensation = LedgerPostingGroup::query()->where('event_type', 'withdrawal_compensation')->with('entries.account')->sole();
    expect(groupLines($compensation))->toBe(['cash_recovery_clearing_ngn:debit:30000', 'customer_savings_liability_ngn:credit:30000'])
        ->and(BankPayoutReturn::query()->where('status', 'consumed')->count())->toBe(2)
        ->and(app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'])->toBe(200000);
    app(LedgerTransactionProjectionService::class)->rebuild();
    expect(DB::table('ledger_transaction_projections')->where('type', 'reversal')->count())->toBe(1);
});

test('a provider exception on the attempt blocks compensation', function (): void {
    config()->set('withdrawals.bank_compensation_enabled', true);
    [$admin, $agent, , , $withdrawal] = bankPayoutFixture($this, '7');
    $attempt = bankAttempt(startBankPayout($this, $admin, $withdrawal));
    sendPayoutCallback($this, callbackEvent($attempt, 'returned', 'evt-full', ['return_reference' => 'RET-FULL']))->assertOk();
    sendPayoutCallback($this, callbackEvent($attempt, 'returned', 'evt-extra', ['return_reference' => 'RET-EXTRA']))->assertOk();
    expect(BankPayoutReturn::query()->where('status', 'exception')->count())->toBe(1);

    expect(fn () => app(ReversalService::class)->preview($agent, LedgerPostingGroup::query()->where('event_type', 'bank_withdrawal')->sole()))
        ->toThrow(ConflictHttpException::class, 'proven full provider return');
});
