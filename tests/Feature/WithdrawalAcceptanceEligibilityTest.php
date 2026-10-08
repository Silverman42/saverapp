<?php

use App\Enums\AdminPermission;
use App\Enums\ThriftPlanStatus;
use App\Models\CollectionReceipt;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\CollectionLedgerService;
use App\Services\CollectionReadService;
use App\Services\WithdrawalMethodRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../WithdrawalFixtures.php';

function depositWithdrawalSavings(User $agent, CustomerProfile $customer, CustomerAssignment $assignment, ThriftPlan $plan, int $kobo): void
{
    $template = CollectionReceipt::query()->where('customer_profile_id', $customer->id)->firstOrFail();
    $receipt = $template->replicate(['savings_posting_group_id'])->fill([
        'receipt_reference' => 'TXN-WDL-'.Str::upper(Str::random(6)), 'attempt_reference' => (string) Str::uuid(),
        'tender_amount_kobo' => $kobo, 'savings_amount_kobo' => $kobo, 'recorded_at' => now(),
    ]);
    $receipt->save();
    DB::transaction(function () use ($receipt, $customer, $agent, $kobo): void {
        $group = app(CollectionLedgerService::class)->postCashSavings($receipt->id, $customer->id, $agent->agentProfile->id, $kobo, $agent);
        $receipt->update(['savings_posting_group_id' => $group->id]);
    });
}

/** @return array<string, mixed> */
function withdrawalSubmission(array $payload, array $quote): array
{
    return [...$payload, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'],
        'instruction_attested' => true, 'confirmed' => true];
}

test('WDL-AC-009 invalid amounts, text and protected fields are rejected before any reservation', function (array $patch, string $field): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id),
        [...withdrawalPayload($customer, $assignment, $plan), ...$patch])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(WithdrawalRequest::count())->toBe(0)->and(DB::table('withdrawal_reservations')->count())->toBe(0);
})->with([
    'zero' => [['gross_ngn' => '0'], 'gross_ngn'],
    'negative' => [['gross_ngn' => '-1.00'], 'gross_ngn'],
    'extra decimals' => [['gross_ngn' => '300.001'], 'gross_ngn'],
    'leading zero' => [['gross_ngn' => '0300'], 'gross_ngn'],
    'overflow digits' => [['gross_ngn' => '99999999999'], 'gross_ngn'],
    'above available' => [['gross_ngn' => '9999999999.99'], 'gross_ngn'],
    'non numeric' => [['gross_ngn' => '3e2'], 'gross_ngn'],
    'markup reason' => [['reason' => '<script>x</script>'], 'reason'],
    'control reason' => [['reason' => "Line\x07bell"], 'reason'],
    'long reason' => [['reason' => str_repeat('a', 501)], 'reason'],
    'long notes' => [['internal_notes' => str_repeat('a', 1001)], 'internal_notes'],
    'injected gross kobo' => [['gross_kobo' => 1], 'gross_kobo'],
    'injected net' => [['net_kobo' => 1], 'net_kobo'],
]);

test('WDL-AC-009 valid naira is stored exactly in kobo and a foreign plan is not found', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id),
        [...withdrawalPayload($customer, $assignment, $plan), 'gross_ngn' => '300.05'])
        ->assertOk()->assertJsonPath('gross_kobo', 30005)->assertJsonPath('fee_kobo', 600)->assertJsonPath('net_kobo', 29405);

    $otherCustomer = CustomerProfile::factory()->create();
    $otherPlan = ThriftPlan::create([
        'plan_id' => 'PLN-WDL-002', 'customer_profile_id' => $otherCustomer->id, 'created_by_user_id' => $agent->id,
        'open_customer_profile_id' => $otherCustomer->id, 'status' => ThriftPlanStatus::Active,
        'current_terms_revision' => 1, 'version' => 1,
    ]);
    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id),
        [...withdrawalPayload($customer, $assignment, $plan), 'plan_id' => $otherPlan->plan_id])->assertNotFound();
    expect(WithdrawalRequest::count())->toBe(0);
});

test('WDL-AC-006 Full requires the exact source amount and a later deposit does not enlarge a request', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $payload = [...withdrawalPayload($customer, $assignment, $plan), 'type' => 'full', 'gross_ngn' => '999.99'];
    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('gross_ngn');
    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id), [...$payload, 'gross_ngn' => '1000.00'])
        ->assertOk()->assertJsonPath('gross_kobo', 100000);

    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    depositWithdrawalSavings($agent, $customer, $assignment, $plan, 50000);
    expect($withdrawal->fresh()->gross_amount_kobo)->toBe(30000)
        ->and(DB::table('withdrawal_reservations')->where('owner_reference', $withdrawal->withdrawal_id)->value('gross_amount_kobo'))->toBe(30000)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(150000);
});

test('WDL-AC-007 end-of-cycle rejects an Active or Paused source', function (ThriftPlanStatus $status): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $plan->forceFill(['status' => $status])->save();
    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id),
        [...withdrawalPayload($customer, $assignment, $plan), 'type' => 'end_of_cycle', 'gross_ngn' => '1000.00'])
        ->assertUnprocessable()->assertJsonValidationErrors('type');
})->with([ThriftPlanStatus::Active, ThriftPlanStatus::Paused]);

test('WDL-AC-008 a Closed or Cancelled source fails without moving balances', function (ThriftPlanStatus $status): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $plan->forceFill(['status' => $status])->save();
    $entries = LedgerEntry::count();
    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id),
        withdrawalPayload($customer, $assignment, $plan))->assertUnprocessable()->assertJsonValidationErrors('plan_id');
    expect(LedgerEntry::count())->toBe($entries)->and(DB::table('withdrawal_reservations')->count())->toBe(0);
})->with([ThriftPlanStatus::Closed, ThriftPlanStatus::Cancelled]);

test('WDL-AC-012 a fixed fee at or above gross and an expired quote block submission', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture(fixedWithdrawalFee: true);
    enableFixtureMethod();
    $payload = withdrawalPayload($customer, $assignment, $plan);
    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id), [...$payload, 'gross_ngn' => '6.00'])
        ->assertUnprocessable()->assertJsonValidationErrors('gross_ngn');

    $quote = $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id), $payload)->assertOk()->json();
    $this->travel((int) config('withdrawals.quote_minutes') + 1)->minutes();
    $this->actingAs($agent)->post(route('customers.withdrawals.store', $customer->customer_id), withdrawalSubmission($payload, $quote))
        ->assertConflict();
    expect(WithdrawalRequest::count())->toBe(0)->and(DB::table('withdrawal_reservations')->count())->toBe(0);
});

test('WDL-AC-013 submission reserves gross without any ledger posting', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $groups = LedgerPostingGroup::count();
    $entries = LedgerEntry::count();
    submittedWithdrawal($agent, $customer, $assignment, $plan);
    $position = app(CollectionReadService::class)->position($customer);
    expect(LedgerPostingGroup::count())->toBe($groups)->and(LedgerEntry::count())->toBe($entries)
        ->and($position['liability_kobo'])->toBe(100000)
        ->and(DB::table('fee_obligations')->count())->toBe(0)
        ->and((int) DB::table('withdrawal_reservations')->where('status', 'live')->sum('gross_amount_kobo'))->toBe(30000);
});

test('WDL-AC-015 submitted terms cannot be edited and a terminal release permits a fresh request', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $payload = withdrawalPayload($customer, $assignment, $plan);
    $quote = $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id), $payload)->json();
    $submission = withdrawalSubmission($payload, $quote);
    assertToast($this->actingAs($agent)->post(route('customers.withdrawals.store', $customer->customer_id), $submission)->assertRedirect(), 'success', 'Withdrawal requested');
    $withdrawal = WithdrawalRequest::query()->sole();
    $before = $withdrawal->getAttributes();

    $this->actingAs($agent)->post(route('customers.withdrawals.store', $customer->customer_id), [...$submission, 'gross_ngn' => '400.00'])
        ->assertConflict();
    $this->actingAs($agent)->put(route('withdrawals.show', $withdrawal), ['gross_ngn' => '400.00'])->assertMethodNotAllowed();
    $this->actingAs($agent)->patch(route('withdrawals.show', $withdrawal), ['method' => 'bank_transfer'])->assertMethodNotAllowed();
    expect($withdrawal->fresh()->getAttributes())->toBe($before);

    $this->actingAs($agent)->post(route('withdrawals.cancel', $withdrawal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => 1, 'confirmed' => true, 'internal_reason' => 'Changed instruction',
    ])->assertRedirect();
    $replacement = submittedWithdrawal($agent, $customer, $assignment, $plan);
    expect($replacement->withdrawal_id)->not->toBe($withdrawal->withdrawal_id)
        ->and($withdrawal->fresh()->gross_amount_kobo)->toBe(30000);
});

test('WDL-AC-017 approval requires freshness, the current version and an unchanged destination', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $admin = User::factory()->admin()->create(['two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now()]);
    $admin->givePermissionTo(AdminPermission::WithdrawalsReview->value);
    $now = now()->timestamp;
    $fresh = ['auth.fresh_until' => $now + 600, 'auth.password_confirmed_at' => $now, 'auth.mfa_confirmed_at' => $now];
    $decision = fn (int $version): array => ['attempt_reference' => (string) Str::uuid(), 'version' => $version,
        'confirmed' => true, 'decision_note' => 'Reviewed'];

    $this->actingAs($admin)->post(route('withdrawals.approve', $withdrawal), $decision(1))
        ->assertRedirect(route('fresh-authentication'));
    $this->actingAs($admin)->withSession($fresh)->post(route('withdrawals.approve', $withdrawal), $decision(2))->assertConflict();

    $registry = Mockery::mock(WithdrawalMethodRegistry::class);
    $registry->shouldReceive('resolve')->andReturn([
        'version' => 1, 'destination_reference' => 'verified-customer-cash', 'destination_mask' => 'Changed pickup point',
    ]);
    app()->instance(WithdrawalMethodRegistry::class, $registry);
    $this->actingAs($admin)->withSession($fresh)->post(route('withdrawals.approve', $withdrawal), $decision(1))->assertConflict();
    expect($withdrawal->fresh()->state)->toBe('pending_review')->and($withdrawal->fresh()->version)->toBe(1);
});
