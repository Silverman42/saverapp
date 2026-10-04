<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\AgentStatus;
use App\Enums\CustomerAssignmentStatus;
use App\Models\AgentProfile;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../WithdrawalFixtures.php';

function withdrawalReviewer(?AdminPermission $permission = AdminPermission::WithdrawalsReview): User
{
    $admin = User::factory()->admin()->create(['two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now()]);
    if ($permission !== null) {
        $admin->givePermissionTo($permission->value);
    }

    return $admin;
}

/** @return array<string, int> */
function freshWithdrawalSession(): array
{
    $now = now()->timestamp;

    return ['auth.fresh_until' => $now + 600, 'auth.password_confirmed_at' => $now, 'auth.mfa_confirmed_at' => $now];
}

/** @return array<string, mixed> */
function withdrawalDecision(int $version, array $fields = []): array
{
    return ['attempt_reference' => (string) Str::uuid(), 'version' => $version, 'confirmed' => true, ...$fields];
}

function reassignWithdrawalCustomer(CustomerProfile $customer, CustomerAssignment $assignment): User
{
    $replacement = User::factory()->agent()->create(['two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now()]);
    $profile = AgentProfile::factory()->active()->create(['user_id' => $replacement->id]);
    $assignment->forceFill(['status' => CustomerAssignmentStatus::Ended, 'reason' => 'Customer reassigned'])->save();
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customer->id, 'agent_profile_id' => $profile->id,
        'assigned_by_user_id' => $replacement->id, 'status' => CustomerAssignmentStatus::Current, 'version' => 2,
    ]);

    return $replacement;
}

test('WDL-AC-001 Customer and Admin cannot initiate and an Agent cannot approve', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $payload = withdrawalPayload($customer, $assignment, $plan);
    $admin = withdrawalReviewer();
    foreach ([$customer->user, $admin] as $actor) {
        $this->actingAs($actor)->postJson(route('customers.withdrawals.preview', $customer->customer_id), $payload)->assertForbidden();
        $this->actingAs($actor)->get(route('customers.withdrawals.create', $customer->customer_id))->assertForbidden();
    }
    expect(WithdrawalRequest::count())->toBe(0)->and(DB::table('withdrawal_reservations')->count())->toBe(0);

    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $this->actingAs($agent)->withSession(freshWithdrawalSession())
        ->post(route('withdrawals.approve', $withdrawal), withdrawalDecision(1, ['decision_note' => 'Self approval']))
        ->assertForbidden();
    expect($withdrawal->fresh()->state)->toBe('pending_review')->and($withdrawal->fresh()->reviewed_by_user_id)->toBeNull();
});

test('WDL-AC-002 a wrong-grant Admin cannot reject or revoke and one review grant decides alone', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $wrongGrant = withdrawalReviewer(AdminPermission::CashExecute);
    $this->actingAs($wrongGrant)->withSession(freshWithdrawalSession())
        ->post(route('withdrawals.reject', $withdrawal), withdrawalDecision(1, ['internal_reason' => 'No', 'customer_explanation' => 'No']))
        ->assertForbidden();

    $reviewer = withdrawalReviewer();
    $this->actingAs($reviewer)->withSession(freshWithdrawalSession())
        ->post(route('withdrawals.approve', $withdrawal), withdrawalDecision(1, ['decision_note' => 'Reviewed']))
        ->assertRedirect();
    expect($withdrawal->fresh()->state)->toBe('approved');

    $this->actingAs($wrongGrant)->withSession(freshWithdrawalSession())
        ->post(route('withdrawals.revoke', $withdrawal), withdrawalDecision(2, ['internal_reason' => 'No', 'customer_explanation' => 'No']))
        ->assertForbidden();
    expect($withdrawal->fresh()->state)->toBe('approved')
        ->and(DB::table('withdrawal_reservations')->where('owner_reference', $withdrawal->withdrawal_id)->value('status'))->toBe('live');

    $this->actingAs($reviewer)->withSession(freshWithdrawalSession())
        ->post(route('withdrawals.revoke', $withdrawal), withdrawalDecision(2, ['internal_reason' => 'Superseded', 'customer_explanation' => 'Request withdrawn']))
        ->assertRedirect();
    expect($withdrawal->fresh()->state)->toBe('cancelled')
        ->and(DB::table('withdrawal_reservations')->where('owner_reference', $withdrawal->withdrawal_id)->value('status'))->toBe('released');
});

test('WDL-AC-003 an Invited Customer account stays Agent-operable', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $customer->user->forceFill(['account_state' => AccountState::Invited])->save();

    expect(submittedWithdrawal($agent, $customer->fresh(), $assignment, $plan)->state)->toBe('pending_review');
});

test('WDL-AC-003 a suspended or deactivated Agent cannot preview or cancel', function (AccountState $state): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $agent->forceFill(['account_state' => $state])->save();

    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id),
        withdrawalPayload($customer, $assignment, $plan))->assertRedirect(route('login'));
    $this->actingAs($agent)->post(route('withdrawals.cancel', $withdrawal),
        withdrawalDecision(1, ['internal_reason' => 'Customer changed instruction']))->assertRedirect(route('login'));
    $this->assertGuest();
    expect($withdrawal->fresh()->state)->toBe('pending_review')
        ->and(WithdrawalRequest::count())->toBe(1);
})->with([AccountState::Suspended, AccountState::Deactivated]);

test('WDL-AC-021 an Agent cannot cancel an approved request', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $this->actingAs(withdrawalReviewer())->withSession(freshWithdrawalSession())
        ->post(route('withdrawals.approve', $withdrawal), withdrawalDecision(1, ['decision_note' => 'Reviewed']))->assertRedirect();

    $this->actingAs($agent)->post(route('withdrawals.cancel', $withdrawal),
        withdrawalDecision(2, ['internal_reason' => 'Too late']))->assertConflict();
    expect($withdrawal->fresh()->state)->toBe('approved')
        ->and(DB::table('withdrawal_reservations')->where('owner_reference', $withdrawal->withdrawal_id)->value('status'))->toBe('live');
});

test('WDL-AC-022 a former Agent cannot cancel after reassignment and the request is unchanged', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $before = $withdrawal->fresh()->getAttributes();
    reassignWithdrawalCustomer($customer, $assignment);

    $this->actingAs($agent)->post(route('withdrawals.cancel', $withdrawal),
        withdrawalDecision(1, ['internal_reason' => 'Former Agent attempt']))->assertForbidden();
    expect($withdrawal->fresh()->getAttributes())->toBe($before);
});

test('WDL-AC-023 suspending the initiating Agent keeps the request and reservation', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $agent->forceFill(['account_state' => AccountState::Suspended])->save();
    $this->artisan('withdrawals:expire')->assertSuccessful();

    expect($withdrawal->fresh()->state)->toBe('pending_review')
        ->and($withdrawal->fresh()->submitted_by_user_id)->toBe($agent->id)
        ->and(DB::table('withdrawal_reservations')->where('owner_reference', $withdrawal->withdrawal_id)->value('status'))->toBe('live');
});

test('WDL-AC-032 reassignment before replay applies current scope and creates no replacement request', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $attempt = DB::table('withdrawal_attempts')->where('withdrawal_request_id', $withdrawal->id)->value('attempt_reference');
    $replacement = reassignWithdrawalCustomer($customer, $assignment);

    $this->actingAs($agent)->getJson(route('withdrawals.attempts.show', $attempt))->assertForbidden();
    $this->actingAs($replacement)->getJson(route('withdrawals.attempts.show', $attempt))
        ->assertOk()->assertJsonPath('withdrawal_id', $withdrawal->withdrawal_id);
    expect(WithdrawalRequest::count())->toBe(1)
        ->and(DB::table('withdrawal_reservations')->count())->toBe(1);
});

test('WDL-AC-003 an Inactive Agent profile cannot preview or cancel', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $agent->agentProfile->forceFill(['operational_status' => AgentStatus::Inactive])->save();

    $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id),
        withdrawalPayload($customer, $assignment, $plan))->assertForbidden();
    $this->actingAs($agent)->post(route('withdrawals.cancel', $withdrawal),
        withdrawalDecision(1, ['internal_reason' => 'Customer changed instruction']))->assertForbidden();
    expect($withdrawal->fresh()->state)->toBe('pending_review');
});
