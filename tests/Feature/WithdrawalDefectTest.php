<?php

use App\Enums\CustomerStatus;
use App\Models\CashExecution;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\WithdrawalEvent;
use App\Services\WithdrawalMethodRegistry;
use App\Services\WithdrawalService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require_once __DIR__.'/../CashExecutionFixtures.php';

function restrictCustomer(object $customer): void
{
    DB::transaction(function () use ($customer): void {
        $customer->forceFill(['operational_status' => CustomerStatus::Restricted])->save();
        app(WithdrawalService::class)->applyCustomerStatus($customer, CustomerStatus::Restricted);
    });
}

test('lifting a restriction records revalidation when the reservation is no longer valid', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    restrictCustomer($customer);
    DB::table('withdrawal_reservations')->where('owner_reference', $withdrawal->withdrawal_id)->update(['status' => 'released']);

    DB::transaction(function () use ($customer): void {
        $customer->forceFill(['operational_status' => CustomerStatus::Active])->save();
        app(WithdrawalService::class)->applyCustomerStatus($customer, CustomerStatus::Active);
    });

    expect($withdrawal->fresh()->held)->toBeTrue()
        ->and($withdrawal->fresh()->hold_reason)->toBe('revalidation_required')
        ->and(WithdrawalEvent::query()->where('withdrawal_request_id', $withdrawal->id)->where('event_type', 'hold_revalidation_required')->count())->toBe(1)
        ->and(DB::table('withdrawal_notification_intents')->where('payload->message', 'like', '%revalidated%')->count())->toBeGreaterThan(0);
});

test('a Customer with an in-flight payout cannot be archived', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    startCashFixture($this, $admin, $withdrawal);
    expect($withdrawal->fresh()->state)->toBe('payout_processing');

    expect(fn () => DB::transaction(fn () => app(WithdrawalService::class)->applyCustomerStatus($customer, CustomerStatus::Archived)))
        ->toThrow(ConflictHttpException::class)
        ->and(app(WithdrawalService::class)->archivalStatus($customer->fresh()))->toBe('blocked');

    $this->post(route('cash-executions.handoff', CashExecution::query()->sole()), ['evidence' => 'Handoff claimed.', 'confirmed' => true])->assertRedirect();
    expect($withdrawal->fresh()->state)->toBe('outcome_unknown')
        ->and(app(WithdrawalService::class)->archivalStatus($customer->fresh()))->toBe('blocked');
});

test('a definitive failed attempt restarts the review window', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $withdrawal->update(['deadline_at' => now()->addDay()]);

    $this->post(route('cash-executions.not-delivered', $execution), ['evidence' => 'Cash never left the till.', 'confirmed' => true])->assertRedirect();

    $fresh = $withdrawal->fresh();
    expect($fresh->state)->toBe('payment_failed')
        ->and($fresh->held)->toBeFalse()
        ->and($fresh->deadline_at->greaterThan(now()->addDays(6)))->toBeTrue();
});

test('a restriction that lands during a payout holds the request once the attempt definitively fails', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    restrictCustomer($customer);
    expect($withdrawal->fresh()->held)->toBeFalse();

    $this->post(route('cash-executions.not-delivered', $execution), ['evidence' => 'Cash never left the till.', 'confirmed' => true])->assertRedirect();

    $fresh = $withdrawal->fresh();
    expect($fresh->state)->toBe('payment_failed')
        ->and($fresh->held)->toBeTrue()
        ->and($fresh->hold_reason)->toBe('customer_restricted')
        ->and(WithdrawalEvent::query()->where('withdrawal_request_id', $withdrawal->id)->where('event_type', 'hold_applied')->count())->toBe(1);
    $this->travel(8)->days();
    $this->artisan('withdrawals:expire')->assertSuccessful();
    expect($withdrawal->fresh()->state)->toBe('payment_failed')
        ->and(DB::table('withdrawal_reservations')->value('status'))->toBe('live');
});

test('one failing expiry item does not roll back the others', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $first = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $reservation = (array) DB::table('withdrawal_reservations')->where('owner_reference', $first->withdrawal_id)->first();
    unset($reservation['id']);
    $copyReservation = DB::table('withdrawal_reservations')->insertGetId([...$reservation, 'owner_reference' => 'WDL-COPY-0001']);
    $request = (array) DB::table('withdrawal_requests')->where('id', $first->id)->first();
    unset($request['id']);
    $secondId = DB::table('withdrawal_requests')->insertGetId([...$request, 'withdrawal_id' => 'WDL-COPY-0001',
        'live_thrift_plan_id' => null, 'withdrawal_reservation_id' => $copyReservation]);
    DB::table('withdrawal_reservations')->where('owner_reference', $first->withdrawal_id)->update(['status' => 'consumed']);
    $this->travel(8)->days();

    $expired = app(WithdrawalService::class)->expireDue();

    expect($expired)->toBe(1)
        ->and($first->fresh()->state)->toBe('pending_review')
        ->and(DB::table('withdrawal_requests')->where('id', $secondId)->value('state'))->toBe('expired');
});

test('the Create page offers a method only when the business setting gate allows it', function (): void {
    [$agent, $customer] = withdrawalFixture();
    config(['withdrawals.cash_enabled' => true, 'withdrawals.cash_certified' => true]);
    expect(app(WithdrawalMethodRegistry::class)->availableMethods())->toBe(['cash']);

    config(['withdrawals.cash_certified' => false]);
    expect(app(WithdrawalMethodRegistry::class)->availableMethods())->toBe([]);
});

test('an unavailable balance owner fails preview with a service-unavailable response', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $group = LedgerPostingGroup::create([
        'posting_reference' => 'TEST-UNATTRIBUTED', 'idempotency_key' => 'test-unattributed',
        'payload_hash' => str_repeat('b', 64), 'source_type' => 'unknown_source', 'source_id' => '1',
        'event_type' => 'unknown_source', 'currency' => 'NGN', 'actor_user_id' => $agent->id,
        'customer_profile_id' => $customer->id, 'occurred_at' => now(), 'committed_at' => now(),
    ]);
    LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => 1,
        'ledger_account_id' => LedgerAccount::query()->where('code', 'customer_savings_liability_ngn')->firstOrFail()->id,
        'side' => 'credit', 'amount_kobo' => 100, 'customer_profile_id' => $customer->id]);

    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id),
        withdrawalPayload($customer, $assignment, $plan))->assertStatus(503);
    expect(DB::table('withdrawal_reservations')->count())->toBe(0);
});
