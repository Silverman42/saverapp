<?php

use App\Enums\AdminPermission;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\AuditCapture;
use App\Services\CashExecutionService;
use App\Services\WithdrawalBalanceService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../CashExecutionFixtures.php';

test('WDL-AC-028 a fault at any posting step commits none of the bundle and the proven handoff posts once later', function (string $pattern, int $occurrence): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Custodian confirms the exact Customer handoff.', 'confirmed' => true])->assertRedirect();
    $tables = ['fee_obligations', 'fee_obligation_entries', 'ledger_entries', 'ledger_transaction_references', 'withdrawal_events'];
    $before = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    $seen = 0;
    $armed = true;
    DB::listen(static function (QueryExecuted $query) use (&$seen, &$armed, $pattern, $occurrence): void {
        if ($armed && preg_match($pattern, $query->sql) && ++$seen === $occurrence) {
            $armed = false;
            throw new RuntimeException('Injected posting-step failure.');
        }
    });

    expect(fn () => app(CashExecutionService::class)->confirmReceipt($customer->user, $execution))->toThrow(RuntimeException::class);
    expect(collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all())->toBe($before)
        ->and(DB::table('withdrawal_reservations')->value('status'))->toBe('live')
        ->and($withdrawal->fresh()->state)->toBe('outcome_unknown')
        ->and($execution->fresh()->status)->toBe('outcome_unknown')
        ->and(LedgerPostingGroup::query()->where('source_type', 'withdrawal')->count())->toBe(0);

    $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    $this->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    expect(LedgerPostingGroup::query()->where('source_type', 'withdrawal')->count())->toBe(1)
        ->and(DB::table('withdrawal_reservations')->value('status'))->not->toBe('live')
        ->and(app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'])->toBe(70000);
})->with([
    'fee obligation' => ['/\Ainsert into ["`]fee_obligations["`]/i', 1],
    'fee entry' => ['/\Ainsert into ["`]fee_obligation_entries["`]/i', 1],
    'liability line' => ['/\Ainsert into ["`]ledger_entries["`]/i', 2],
    'payout line' => ['/\Ainsert into ["`]ledger_entries["`]/i', 3],
    'reservation consumption' => ['/\Aupdate ["`]withdrawal_reservations["`]/i', 1],
    'execution status' => ['/\Aupdate ["`]cash_executions["`]/i', 1],
    'request status' => ['/\Aupdate ["`]withdrawal_requests["`]/i', 1],
    'transaction reference' => ['/\Ainsert into ["`]ledger_transaction_references["`]/i', 1],
]);

test('WDL-AC-034 a balance owner outage fails safely at preview submit and approval', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::WithdrawalsReview);
    $balances = Mockery::mock(WithdrawalBalanceService::class)->makePartial();
    $balances->shouldReceive('position')->andThrow(new RuntimeException('Balance owner unavailable.'));
    app()->instance(WithdrawalBalanceService::class, $balances);

    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id),
        withdrawalPayload($customer, $assignment, $plan))->assertStatus(503);
    $this->actingAs($admin)->withSession(cashSession())->post(route('withdrawals.approve', $withdrawal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => 1, 'confirmed' => true, 'decision_note' => 'Reviewed',
    ])->assertStatus(503);
    expect($withdrawal->fresh()->state)->toBe('pending_review')->and($withdrawal->fresh()->version)->toBe(1);
});

test('WDL-AC-034 an audit owner outage persists no request or decision', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $audit = Mockery::mock(AuditCapture::class)->makePartial();
    $audit->shouldReceive('record')->andThrow(new RuntimeException('Audit owner unavailable.'));
    app()->instance(AuditCapture::class, $audit);

    expect(fn () => submittedWithdrawal($agent, $customer, $assignment, $plan))->toThrow(RuntimeException::class);
    expect(WithdrawalRequest::count())->toBe(0)->and(DB::table('withdrawal_reservations')->count())->toBe(0);
});
