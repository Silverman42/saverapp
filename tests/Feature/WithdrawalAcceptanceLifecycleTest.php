<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Models\CashExecution;
use App\Models\CustomerProfile;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WithdrawalEvent;
use App\Models\WithdrawalRequest;
use App\Services\CollectionReadService;
use App\Services\WithdrawalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../CashExecutionFixtures.php';

function setWithdrawalCustomerStatus(CustomerProfile $customer, CustomerStatus $status): void
{
    DB::transaction(function () use ($customer, $status): void {
        $customer->forceFill(['operational_status' => $status])->save();
        app(WithdrawalService::class)->applyCustomerStatus($customer, $status);
    });
}

function lifecycleReviewer(): User
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::WithdrawalsReview, AdminPermission::CashExecute]);

    return $admin;
}

/** @return array<string, mixed> */
function lifecycleDecision(WithdrawalRequest $withdrawal, array $fields = []): array
{
    return ['attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->fresh()->version, 'confirmed' => true, ...$fields];
}

function reservationStatus(WithdrawalRequest $withdrawal): ?string
{
    return DB::table('withdrawal_reservations')->where('owner_reference', $withdrawal->withdrawal_id)->value('status');
}

test('WDL-AC-004 Inactive existing savings pass and Restricted or Archived initiation fails', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $payload = withdrawalPayload($customer, $assignment, $plan);
    foreach ([CustomerStatus::Restricted, CustomerStatus::Archived] as $status) {
        $customer->forceFill(['operational_status' => $status])->save();
        $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id), $payload)->assertForbidden();
    }
    $customer->forceFill(['operational_status' => CustomerStatus::Inactive])->save();
    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id), $payload)->assertOk();
    expect(WithdrawalRequest::count())->toBe(0);
});

test('WDL-AC-004 a restriction blocks approval and payout while retaining the reservation', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    setWithdrawalCustomerStatus($customer, CustomerStatus::Restricted);
    $admin = lifecycleReviewer();

    $this->actingAs($admin)->withSession(cashSession())
        ->post(route('withdrawals.approve', $withdrawal), lifecycleDecision($withdrawal, ['decision_note' => 'Reviewed']))
        ->assertConflict();
    expect($withdrawal->fresh()->state)->toBe('pending_review')->and($withdrawal->fresh()->held)->toBeTrue()
        ->and(reservationStatus($withdrawal))->toBe('live');
});

test('WDL-AC-005 a lifted hold on an approved request revalidates without paying and a held revocation releases', function (): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    $entries = LedgerEntry::count();
    setWithdrawalCustomerStatus($customer, CustomerStatus::Restricted);
    expect($withdrawal->fresh()->held)->toBeTrue()->and($withdrawal->fresh()->state)->toBe('approved');

    $this->actingAs($admin)->withSession(cashSession())->post(route('withdrawals.cash.start', $withdrawal), [
        'execution_reference' => (string) Str::uuid(), 'version' => $withdrawal->fresh()->version,
        'evidence' => 'Business till; Customer present.', 'confirmed' => true,
    ])->assertConflict();

    setWithdrawalCustomerStatus($customer, CustomerStatus::Active);
    expect($withdrawal->fresh()->held)->toBeFalse()->and($withdrawal->fresh()->state)->toBe('approved')
        ->and(CashExecution::count())->toBe(0)->and(LedgerEntry::count())->toBe($entries)
        ->and(WithdrawalEvent::query()->where('withdrawal_request_id', $withdrawal->id)->where('event_type', 'hold_lifted')->count())->toBe(1);

    setWithdrawalCustomerStatus($customer, CustomerStatus::Restricted);
    $this->actingAs($admin)->withSession(cashSession())->post(route('withdrawals.revoke', $withdrawal),
        lifecycleDecision($withdrawal, ['internal_reason' => 'Account under review', 'customer_explanation' => 'Request withdrawn']))
        ->assertRedirect();
    expect($withdrawal->fresh()->state)->toBe('cancelled')->and(reservationStatus($withdrawal))->toBe('released')
        ->and(LedgerEntry::count())->toBe($entries);
});

test('WDL-AC-016 undocumented transitions are refused without events or liability effects', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $admin = lifecycleReviewer();
    $session = cashSession();
    $explained = ['internal_reason' => 'Invalid', 'customer_explanation' => 'Invalid'];

    $this->actingAs($admin)->withSession($session)->post(route('withdrawals.revoke', $withdrawal), lifecycleDecision($withdrawal, $explained))->assertConflict();
    $this->actingAs($admin)->withSession($session)->post(route('withdrawals.reject', $withdrawal), lifecycleDecision($withdrawal, $explained))->assertRedirect();
    $events = WithdrawalEvent::query()->where('withdrawal_request_id', $withdrawal->id)->count();

    $this->actingAs($admin)->withSession($session)->post(route('withdrawals.approve', $withdrawal), lifecycleDecision($withdrawal, ['decision_note' => 'Late']))->assertConflict();
    $this->actingAs($admin)->withSession($session)->post(route('withdrawals.reject', $withdrawal), lifecycleDecision($withdrawal, $explained))->assertConflict();
    $this->actingAs($admin)->withSession($session)->post(route('withdrawals.revoke', $withdrawal), lifecycleDecision($withdrawal, $explained))->assertConflict();
    $this->actingAs($agent)->post(route('withdrawals.cancel', $withdrawal), lifecycleDecision($withdrawal, ['internal_reason' => 'Late']))->assertConflict();
    $this->actingAs($admin)->withSession($session)->post(route('withdrawals.approve', $withdrawal),
        lifecycleDecision($withdrawal, ['decision_note' => 'Edit', 'state' => 'approved']))->assertSessionHasErrors('state');

    expect($withdrawal->fresh()->state)->toBe('rejected')
        ->and(WithdrawalEvent::query()->where('withdrawal_request_id', $withdrawal->id)->count())->toBe($events)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000);
});

test('WDL-AC-020 approved and payment-failed requests expire safely after their deadline', function (string $state): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    $withdrawal->update(['state' => $state]);
    $this->travel(8)->days();
    $this->artisan('withdrawals:expire')->assertSuccessful();

    expect($withdrawal->fresh()->state)->toBe('expired')->and(reservationStatus($withdrawal))->toBe('released')
        ->and($withdrawal->fresh()->live_thrift_plan_id)->toBeNull()
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000);
})->with(['approved', 'payment_failed']);

test('WDL-AC-020 processing and unknown outcomes never auto-release', function (string $state): void {
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture();
    $withdrawal->update(['state' => $state]);
    $this->travel(30)->days();
    $this->artisan('withdrawals:expire')->assertSuccessful();

    expect($withdrawal->fresh()->state)->toBe($state)->and(reservationStatus($withdrawal))->toBe('live');
})->with(['payout_processing', 'outcome_unknown']);

test('WDL-AC-020 a lifted restriction restores at least the 24-hour review window', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $this->travel(6 * 24 + 23)->hours();
    setWithdrawalCustomerStatus($customer, CustomerStatus::Restricted);
    $this->travel(2)->days();
    setWithdrawalCustomerStatus($customer, CustomerStatus::Active);

    expect($withdrawal->fresh()->deadline_at->greaterThanOrEqualTo(now()->addHours((int) config('withdrawals.restored_review_hours'))->subSecond()))->toBeTrue();
    $this->artisan('withdrawals:expire')->assertSuccessful();
    expect($withdrawal->fresh()->state)->toBe('pending_review');
});

test('WDL-AC-043 a second live request for the same cycle is blocked even when funds fit', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    submittedWithdrawal($agent, $customer, $assignment, $plan);
    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id),
        [...withdrawalPayload($customer, $assignment, $plan), 'gross_ngn' => '100.00'])->assertConflict();
    expect(WithdrawalRequest::count())->toBe(1)->and(DB::table('withdrawal_reservations')->count())->toBe(1);
});
