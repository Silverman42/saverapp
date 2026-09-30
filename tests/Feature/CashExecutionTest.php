<?php

use App\Enums\AdminPermission;
use App\Models\CashExecution;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Services\AuditCapture;
use App\Services\CashExecutionService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\NotificationPipeline;
use App\Services\WithdrawalBalanceService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../CashExecutionFixtures.php';

test('cash acknowledgement rolls back the entire accounting audit and notice bundle on owner failure', function (string $failure): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Custodian confirms the exact Customer handoff.', 'confirmed' => true])->assertRedirect();
    $beforeEvents = DB::table('withdrawal_events')->count();
    $beforeAudit = DB::table('audit_events')->count();
    $beforeNotices = DB::table('withdrawal_notification_intents')->count();
    $beforeCanonical = DB::table('canonical_audit_events')->count();
    $beforeSharedNotices = DB::table('notification_events')->count();
    $class = null;
    if ($failure === 'posting') {
        $inject = true;
        DB::listen(static function (QueryExecuted $query) use (&$inject): void {
            if ($inject && preg_match('/\Ainsert into ["`]ledger_entries["`]/i', $query->sql)) {
                $inject = false;
                throw new RuntimeException('Injected failure after the first ledger line.');
            }
        });
    } else {
        [$class, $method] = match ($failure) {
            'outbox' => [NotificationPipeline::class, 'capture'],
            'audit' => [AuditCapture::class, 'record'],
            default => [LedgerTransactionProjectionService::class, 'projectWithdrawal'],
        };
        $mock = Mockery::mock($class)->makePartial();
        $mock->shouldReceive($method)->once()->andThrow(new RuntimeException('Injected '.$failure.' failure.'));
        app()->instance($class, $mock);
    }
    expect(fn () => app(CashExecutionService::class)->confirmReceipt($customer->user, $execution))->toThrow(RuntimeException::class);
    expect($execution->fresh()->status)->toBe('outcome_unknown')->and($execution->fresh()->customer_acknowledgement)->toBeNull()
        ->and(DB::table('withdrawal_reservations')->value('status'))->toBe('live')
        ->and(LedgerPostingGroup::query()->where('source_type', 'withdrawal')->count())->toBe(0)
        ->and(DB::table('withdrawal_events')->count())->toBe($beforeEvents)
        ->and(DB::table('audit_events')->count())->toBe($beforeAudit)
        ->and(DB::table('withdrawal_notification_intents')->count())->toBe($beforeNotices)
        ->and(DB::table('canonical_audit_events')->count())->toBe($beforeCanonical)
        ->and(DB::table('notification_events')->count())->toBe($beforeSharedNotices);
    if ($class !== null) {
        app()->forgetInstance($class);
    }
    $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    $this->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    expect(LedgerPostingGroup::query()->where('source_type', 'withdrawal')->count())->toBe(1)
        ->and(app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'])->toBe(70000);
})->with(['posting', 'audit', 'outbox', 'projection']);

test('cash requires a separate direct grant and verified remitted funding', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture(false);
    $payload = ['execution_reference' => (string) Str::uuid(), 'version' => 1, 'evidence' => 'Verified custody.', 'confirmed' => true];
    $admin->revokePermissionTo(AdminPermission::CashExecute);
    $this->actingAs($admin)->withSession(cashSession())->postJson(route('withdrawals.cash.start', $withdrawal), $payload)->assertForbidden();
    $admin->givePermissionTo(AdminPermission::CashExecute);
    $this->actingAs($admin->fresh())->postJson(route('withdrawals.cash.start', $withdrawal), $payload)->assertConflict();
    expect(CashExecution::query()->count())->toBe(0)->and($withdrawal->fresh()->state)->toBe('approved');
});

test('Customer acknowledged cash posts gross fee and net exactly once and rebuilds history', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    [$execution, $payload] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('withdrawals.cash.start', $withdrawal), $payload)->assertRedirect();
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Cash handed to the verified Customer.', 'confirmed' => true])->assertRedirect();
    expect($withdrawal->fresh()->state)->toBe('outcome_unknown')
        ->and(DB::table('withdrawal_reservations')->value('status'))->toBe('live');
    $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    $this->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    expect($withdrawal->fresh()->state)->toBe('posted')
        ->and(DB::table('withdrawal_reservations')->value('status'))->toBe('consumed')
        ->and(LedgerPostingGroup::query()->where('source_type', 'withdrawal')->count())->toBe(1)
        ->and(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_available_kobo'])->toBe(70000);
    $group = LedgerPostingGroup::query()->where('source_type', 'withdrawal')->sole();
    expect($group->entries()->where('side', 'debit')->sum('amount_kobo'))->toBe(30000)
        ->and($group->entries()->where('side', 'credit')->sum('amount_kobo'))->toBe(30000);
    app(LedgerTransactionProjectionService::class)->rebuild();
    expect(DB::table('ledger_transaction_projections')->where('type', 'withdrawal')->latest('id')->value('savings_effect_kobo'))->toBe(-30000);
});

test('claimed cash handoff cannot expire be resent or be reported as non-delivery', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Handoff claimed.', 'confirmed' => true])->assertRedirect();
    $this->postJson(route('cash-executions.not-delivered', $execution), ['evidence' => 'No receipt.', 'confirmed' => true])->assertConflict();
    $this->postJson(route('withdrawals.cash.start', $withdrawal), ['execution_reference' => (string) Str::uuid(),
        'version' => $withdrawal->fresh()->version, 'evidence' => 'Second attempt.', 'confirmed' => true])->assertConflict();
    $this->travel(10)->days();
    $this->artisan('withdrawals:expire')->assertSuccessful();
    expect($withdrawal->fresh()->state)->toBe('outcome_unknown')
        ->and(DB::table('withdrawal_reservations')->value('status'))->toBe('live');
});

test('confirmed non-delivery preserves savings reservation and permits a separately identified retry', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.not-delivered', $execution), ['evidence' => 'Cash never left the till.', 'confirmed' => true])->assertRedirect();
    expect($execution->fresh()->status)->toBe('payment_failed')->and($withdrawal->fresh()->state)->toBe('payment_failed')
        ->and(DB::table('withdrawal_reservations')->value('status'))->toBe('live');
    $this->post(route('withdrawals.cash.start', $withdrawal), ['execution_reference' => (string) Str::uuid(),
        'version' => $withdrawal->fresh()->version, 'evidence' => 'New verified attempt.', 'confirmed' => true])->assertRedirect();
    expect(CashExecution::query()->count())->toBe(2);
});

test('another Customer and the custodian cannot acknowledge the recipient cash', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Handoff claimed.', 'confirmed' => true])->assertRedirect();
    $this->postJson(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertForbidden();
    $this->actingAs(User::factory()->customer()->create())->postJson(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertForbidden();
    expect(LedgerPostingGroup::query()->where('source_type', 'withdrawal')->count())->toBe(0);
});
