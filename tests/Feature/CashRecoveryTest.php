<?php

use App\Enums\AdminPermission;
use App\Models\CashRecovery;
use App\Models\LedgerPostingGroup;
use App\Services\CashRecoveryService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\ReversalService;
use App\Services\WithdrawalBalanceService;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require_once __DIR__.'/../CashExecutionFixtures.php';

function recoverCashFixture(object $test, object $admin, object $customer, object $execution): CashRecovery
{
    $test->actingAs($admin)->withSession(cashSession())->post(route('cash-executions.return', $execution), [
        'preview_fingerprint' => app(CashRecoveryService::class)->preview($admin, $execution->fresh())['preview_fingerprint'], 'recovery_reference' => (string) Str::uuid(), 'evidence' => 'Full original cash counted back into the controlled till.', 'confirmed' => true,
    ])->assertRedirect();
    $recovery = CashRecovery::query()->sole();
    $test->actingAs($customer->user)->post(route('cash-recoveries.acknowledge', $recovery), ['confirmed' => true])->assertRedirect();

    return $recovery->fresh();
}

test('unknown cash return needs the exact authenticated recipient and preserves the savings reservation', function (): void {
    [$admin, $customer, , $withdrawal] = cashPaymentFixture();
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Handoff recorded.', 'confirmed' => true])->assertRedirect();
    recoverCashFixture($this, $admin, $customer, $execution);
    expect($execution->fresh()->status)->toBe('payment_failed')->and($withdrawal->fresh()->state)->toBe('payment_failed');
    $this->assertDatabaseHas('withdrawal_reservations', ['status' => 'live']);
    $this->assertDatabaseMissing('ledger_posting_groups', ['source_type' => 'withdrawal']);
    $this->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertConflict();
});

test('posted payout compensation requires proven full return and posts the complete gross net fee bundle once', function (): void {
    config()->set('withdrawals.cash_compensation_enabled', true);
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Customer received full net cash.', 'confirmed' => true])->assertRedirect();
    $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    $group = LedgerPostingGroup::query()->where('source_type', 'withdrawal')->sole();
    $agent = $customer->currentAssignment->agentProfile->user;
    $service = app(ReversalService::class);
    expect(fn () => $service->preview($agent, $group))->toThrow(ConflictHttpException::class, 'proven full return');
    $recovery = recoverCashFixture($this, $admin, $customer, $execution);
    expect($recovery->status)->toBe('confirmed')
        ->and(app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'])->toBe(70000);
    $quote = $service->preview($agent, $group);
    $reversal = $service->submit($agent, $group, ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'customer_version' => $quote['customer_version'],
        'assignment_version' => $quote['assignment_version'], 'reason_category' => 'incorrect_payout_record',
        'internal_reason' => 'Original payout erroneous and full cash returned.', 'customer_explanation' => 'Full cash return and payout correction.',
        'evidence_text' => 'Bound return proof retained.', 'confirmed' => true]);
    $admin->givePermissionTo(AdminPermission::ReversalsReview);
    $review = $service->reviewPreview($admin, $reversal);
    $payload = ['attempt_reference' => (string) Str::uuid(), 'version' => $reversal->version,
        'preview_fingerprint' => $review['preview_fingerprint'], 'decision_reason' => 'Verified full cash return.', 'confirmed' => true];
    $this->actingAs($admin)->withSession(cashSession())->post(route('reversals.approve', $reversal), $payload)->assertRedirect();
    $this->post(route('reversals.approve', $reversal), $payload)->assertRedirect();
    expect($recovery->fresh()->status)->toBe('consumed')
        ->and(app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'])->toBe(100000);
    $this->assertDatabaseCount('reversal_requests', 1);
    $this->assertDatabaseHas('withdrawal_reservations', ['status' => 'consumed']);
    app(LedgerTransactionProjectionService::class)->rebuild();
});
