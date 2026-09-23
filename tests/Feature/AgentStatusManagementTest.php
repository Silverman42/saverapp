<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\AgentEligibilityCapability;
use App\Enums\AgentStatus;
use App\Enums\CustomerAssignmentStatus;
use App\Enums\CustomerStatus;
use App\Enums\UserType;
use App\Jobs\DeliverAgentStatusNotificationIntent;
use App\Models\AgentOffboardingCase;
use App\Models\AgentProfile;
use App\Models\AgentStatusNotificationIntent;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CustomerAssignment;
use App\Models\CustomerNameCorrection;
use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\AgentStatusNotification;
use App\Services\AgentEligibilityService;
use App\Services\AgentStatusManagementService;
use App\Services\AuthorizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (UserType::cases() as $type) {
        Role::firstOrCreate(['name' => $type->value, 'guard_name' => 'web']);
    }
    Permission::firstOrCreate(
        ['name' => AdminPermission::AgentsManage->value, 'guard_name' => 'web'],
        ['status' => 'active'],
    );
});

function makeAgentStatusAdmin(bool $authorized = true): User
{
    $admin = User::factory()->admin()->create();
    $admin->assignRole(UserType::Admin->value);
    if ($authorized) {
        $admin->givePermissionTo(AdminPermission::AgentsManage->value);
    }

    return $admin;
}

function makeAgentStatusProfile(bool $active = false): AgentProfile
{
    $user = User::factory()->agent()->withTwoFactor()->create();
    $user->assignRole(UserType::Agent->value);

    return AgentProfile::factory()->create([
        'user_id' => $user->id,
        'operational_status' => $active ? AgentStatus::Active : AgentStatus::Inactive,
    ]);
}

function agentStatusPayload(AgentProfile $agent, string $status, array $overrides = []): array
{
    return array_merge([
        'target_status' => $status,
        'version' => $agent->version,
        'confirmed' => true,
        'reason' => '  Staffing schedule changed.  ',
        'agent_explanation' => '  Your availability has been updated.  ',
    ], $overrides);
}

function assignAgentStatusCustomer(AgentProfile $agent, User $admin, CustomerStatus $status = CustomerStatus::Active): CustomerProfile
{
    $customer = CustomerProfile::factory()->create(['operational_status' => $status]);
    CustomerAssignment::create([
        'customer_profile_id' => $customer->id,
        'agent_profile_id' => $agent->id,
        'assigned_by_user_id' => $admin->id,
        'reason' => 'Existing service assignment',
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => 1,
        'effective_at' => now(),
        'version' => 1,
    ]);

    return $customer;
}

test('authorized Admin activates and inactivates an Agent without changing account access or assignments', function (): void {
    Queue::fake([DeliverAgentStatusNotificationIntent::class]);
    $admin = makeAgentStatusAdmin();
    $agent = makeAgentStatusProfile();
    $customer = assignAgentStatusCustomer($agent, $admin);
    $user = $agent->user;
    $password = $user->password;
    $assignmentId = $customer->currentAssignment->id;

    $this->actingAs($admin)->patch(route('agents.status.update', $agent->agent_id), agentStatusPayload($agent, 'active'))
        ->assertRedirect(route('agents.status.edit', $agent->agent_id));

    $agent->refresh();
    expect($agent->operational_status)->toBe(AgentStatus::Active)
        ->and($agent->version)->toBe(2)
        ->and($agent->updated_by_user_id)->toBe($admin->id)
        ->and($agent->user->fresh()->account_state)->toBe(AccountState::Active)
        ->and($agent->user->fresh()->password)->toBe($password)
        ->and($customer->fresh()->currentAssignment->id)->toBe($assignmentId)
        ->and($customer->fresh()->operational_status)->toBe(CustomerStatus::Active);

    $this->actingAs($admin)->patch(route('agents.status.update', $agent->agent_id), agentStatusPayload($agent, 'inactive'))
        ->assertRedirect(route('agents.status.edit', $agent->agent_id));

    expect($agent->fresh()->operational_status)->toBe(AgentStatus::Inactive)
        ->and($agent->statusHistories()->count())->toBe(2)
        ->and($customer->fresh()->currentAssignment->id)->toBe($assignmentId);
});

test('activation requires completed account onboarding and no open offboarding case', function (): void {
    $admin = makeAgentStatusAdmin();
    $agent = makeAgentStatusProfile();

    $agent->user->forceFill(['account_state' => AccountState::Invited])->save();
    $this->actingAs($admin)->patch(route('agents.status.update', $agent->agent_id), agentStatusPayload($agent, 'active'))
        ->assertSessionHasErrors('target_status');

    $agent->user->forceFill(['account_state' => AccountState::Active, 'email_verified_at' => null])->save();
    $this->actingAs($admin)->patch(route('agents.status.update', $agent->agent_id), agentStatusPayload($agent, 'active'))
        ->assertSessionHasErrors('target_status');

    $agent->user->forceFill(['email_verified_at' => now(), 'two_factor_confirmed_at' => null])->save();
    $this->actingAs($admin)->patch(route('agents.status.update', $agent->agent_id), agentStatusPayload($agent, 'active'))
        ->assertSessionHasErrors('target_status');

    $agent->user->forceFill(['two_factor_confirmed_at' => now()])->save();
    AgentOffboardingCase::factory()->create(['agent_profile_id' => $agent->id]);
    $this->actingAs($admin)->patch(route('agents.status.update', $agent->agent_id), agentStatusPayload($agent, 'active'))
        ->assertSessionHasErrors('target_status');

    expect($agent->fresh()->operational_status)->toBe(AgentStatus::Inactive)
        ->and($agent->statusHistories()->count())->toBe(0);
});

test('only one open offboarding case can exist for an Agent', function (): void {
    $agent = makeAgentStatusProfile();
    AgentOffboardingCase::factory()->create(['agent_profile_id' => $agent->id]);

    expect(fn () => AgentOffboardingCase::factory()->create(['agent_profile_id' => $agent->id]))
        ->toThrow(QueryException::class);
});

test('status endpoints deny unauthenticated, Agent, Customer, and Admin without agents.manage', function (): void {
    $agent = makeAgentStatusProfile();
    $otherAgent = makeAgentStatusProfile();
    $customer = User::factory()->customer()->create();
    $unprivilegedAdmin = makeAgentStatusAdmin(false);

    $this->get(route('agents.status.edit', $agent->agent_id))->assertRedirect();
    $this->actingAs($otherAgent->user)->get(route('agents.status.edit', $agent->agent_id))->assertNotFound();
    $this->actingAs($agent->user)->patch(route('agents.status.update', $agent->agent_id), agentStatusPayload($agent, 'active'))->assertForbidden();
    $this->actingAs($customer)->patch(route('agents.status.update', $agent->agent_id), agentStatusPayload($agent, 'active'))->assertForbidden();
    $this->actingAs($unprivilegedAdmin)->get(route('agents.status.edit', $agent->agent_id))->assertForbidden();
    $this->actingAs($unprivilegedAdmin)->patch(route('agents.status.update', $agent->agent_id), agentStatusPayload($agent, 'active'))->assertForbidden();
    expect($agent->fresh()->operational_status)->toBe(AgentStatus::Inactive);
});

test('transition validates confirmation, explanation, and current version while a repeated status is a no-op', function (): void {
    $admin = makeAgentStatusAdmin();
    $agent = makeAgentStatusProfile();

    $this->actingAs($admin)->patch(route('agents.status.update', $agent->agent_id), agentStatusPayload($agent, 'active', [
        'confirmed' => false,
        'reason' => ' ',
        'agent_explanation' => str_repeat('x', 501),
    ]))->assertSessionHasErrors(['confirmed', 'reason', 'agent_explanation']);

    $this->actingAs($admin)->patch(route('agents.status.update', $agent->agent_id), agentStatusPayload($agent, 'active', ['version' => 99]))
        ->assertSessionHasErrors('version');

    $this->actingAs($admin)->patch(route('agents.status.update', $agent->agent_id), agentStatusPayload($agent, 'inactive'))
        ->assertRedirect();

    expect($agent->fresh()->version)->toBe(1)
        ->and($agent->statusHistories()->count())->toBe(0)
        ->and(AgentStatusNotificationIntent::count())->toBe(0);
});

test('commit-time authority check rejects a stale Admin action after permission loss', function (): void {
    $admin = makeAgentStatusAdmin();
    $agent = makeAgentStatusProfile();
    $admin->revokePermissionTo(AdminPermission::AgentsManage->value);

    expect(fn () => app(AgentStatusManagementService::class)->transition(
        actor: $admin,
        agent: $agent,
        targetStatus: AgentStatus::Active,
        expectedVersion: $agent->version,
        reason: 'Readiness approved',
        agentExplanation: 'You may resume Customer work.',
    ))->toThrow(AuthorizationException::class);

    expect($agent->fresh()->operational_status)->toBe(AgentStatus::Inactive)
        ->and($agent->statusHistories()->count())->toBe(0);
});

test('inactivation blocks Customer work, retains assigned reads, and invalidates pending name proposals', function (): void {
    $admin = makeAgentStatusAdmin();
    $agent = makeAgentStatusProfile(true);
    $customer = assignAgentStatusCustomer($agent, $admin);
    $correction = CustomerNameCorrection::create([
        'customer_profile_id' => $customer->id,
        'requested_by_user_id' => $agent->user_id,
        'current_name' => 'Original Name',
        'proposed_name' => 'Proposed Name',
        'reason' => 'Spelling correction',
        'profile_version' => $customer->version,
        'status' => 'pending',
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($admin)->patch(route('agents.status.update', $agent->agent_id), agentStatusPayload($agent, 'inactive'))->assertRedirect();

    $eligibility = app(AgentEligibilityService::class);
    expect($eligibility->evaluate($agent->fresh(), AgentEligibilityCapability::ReadAssignedCustomers)->isEligible())->toBeTrue()
        ->and($eligibility->evaluate($agent->fresh(), AgentEligibilityCapability::PerformAssignedCustomerWork)->isEligible())->toBeFalse()
        ->and($eligibility->evaluate($agent->fresh(), AgentEligibilityCapability::ReceiveAssignment)->isEligible())->toBeFalse()
        ->and($correction->fresh()->status)->toBe('invalidated')
        ->and($correction->fresh()->resolved_by_user_id)->toBe($admin->id);

    $this->actingAs($agent->user)->get(route('customers.show', $customer->customer_id))->assertOk();
    $this->actingAs($agent->user)->post(route('customers.name-corrections.store', $customer->customer_id), [
        'name' => 'Another Name', 'reason' => 'Further correction', 'version' => $customer->version,
    ])->assertStatus(403);
});

test('temporary authentication lock preserves existing-session work but blocks new assignment receipt', function (): void {
    $agent = makeAgentStatusProfile(true);
    $agent->user->lockTemporarily(15, 'password', 'Temporary login lock');
    $eligibility = app(AgentEligibilityService::class);

    expect($eligibility->canReadAssignedCustomers($agent->fresh()))->toBeTrue()
        ->and($eligibility->canPerformAssignedCustomerWork($agent->fresh()))->toBeTrue()
        ->and($eligibility->canReceiveAssignment($agent->fresh()))->toBeFalse();
});

test('transition records protected history and notices without exposing the internal reason', function (): void {
    Queue::fake([DeliverAgentStatusNotificationIntent::class]);
    $admin = makeAgentStatusAdmin();
    $agent = makeAgentStatusProfile(true);
    $customer = assignAgentStatusCustomer($agent, $admin);
    $archived = assignAgentStatusCustomer($agent, $admin, CustomerStatus::Archived);

    $this->actingAs($admin)->patch(route('agents.status.update', $agent->agent_id), agentStatusPayload($agent, 'inactive', [
        'reason' => 'Private investigation reference 481.',
        'agent_explanation' => 'Please pause Customer work while availability is reviewed.',
    ]))->assertRedirect();

    $history = $agent->statusHistories()->latest('id')->firstOrFail();
    $audit = AuditEvent::query()->findOrFail($history->audit_event_id);
    $intents = AgentStatusNotificationIntent::query()->where('agent_status_history_id', $history->id)->get();

    expect($history->reason)->toBe('Private investigation reference 481.')
        ->and($history->agent_facing_explanation)->toBe('Please pause Customer work while availability is reviewed.')
        ->and($audit->payload)->not->toHaveKey('reason')
        ->and($intents->where('audience_type', 'assigned_customer')->pluck('customer_profile_id')->unique()->values()->all())->toBe([$customer->id])
        ->and($intents->where('audience_type', 'assigned_customer')->count())->toBe(2)
        ->and($intents->pluck('payload')->map(fn (array $payload): string => $payload['message'])->implode(' '))->not->toContain('Private investigation')
        ->and($archived->fresh()->operational_status)->toBe(CustomerStatus::Archived);
});

test('Customer service notices use public business contact and omit staff explanations', function (): void {
    Queue::fake([DeliverAgentStatusNotificationIntent::class]);
    BusinessProfile::current()->forceFill(['support_email' => 'help@example.test'])->save();
    $admin = makeAgentStatusAdmin();
    $agent = makeAgentStatusProfile(true);
    assignAgentStatusCustomer($agent, $admin);

    $this->actingAs($admin)->patch(route('agents.status.update', $agent->agent_id), agentStatusPayload($agent, 'inactive', [
        'reason' => 'Private staffing issue',
        'agent_explanation' => 'Please contact your manager.',
    ]))->assertRedirect();

    $notice = AgentStatusNotificationIntent::query()->where('audience_type', 'assigned_customer')->firstOrFail();
    expect($notice->payload['message'])->toContain('help@example.test')
        ->not->toContain('Private staffing issue')
        ->not->toContain('Please contact your manager');
});

test('delivery rechecks management permission and current Customer assignment', function (): void {
    Queue::fake([DeliverAgentStatusNotificationIntent::class]);
    Notification::fake();
    $admin = makeAgentStatusAdmin();
    $agent = makeAgentStatusProfile(true);
    $customer = assignAgentStatusCustomer($agent, $admin);

    $this->actingAs($admin)->patch(route('agents.status.update', $agent->agent_id), agentStatusPayload($agent, 'inactive'))->assertRedirect();

    $adminIntent = AgentStatusNotificationIntent::query()->where('audience_type', 'managing_admin')->firstOrFail();
    $customerIntent = AgentStatusNotificationIntent::query()->where('audience_type', 'assigned_customer')->where('channel', 'database')->firstOrFail();
    $agentIntent = AgentStatusNotificationIntent::query()->where('audience_type', 'subject_agent')->where('channel', 'mail')->firstOrFail();
    $admin->revokePermissionTo(AdminPermission::AgentsManage->value);
    $customer->currentAssignment->forceFill(['is_current' => null, 'status' => CustomerAssignmentStatus::Ended])->save();

    (new DeliverAgentStatusNotificationIntent($adminIntent->id))->handle(app(AuthorizationService::class));
    (new DeliverAgentStatusNotificationIntent($customerIntent->id))->handle(app(AuthorizationService::class));
    (new DeliverAgentStatusNotificationIntent($agentIntent->id))->handle(app(AuthorizationService::class));

    expect($adminIntent->fresh()->status)->toBe('suppressed')
        ->and($customerIntent->fresh()->status)->toBe('suppressed')
        ->and($agentIntent->fresh()->status)->toBe('delivered');
    Notification::assertSentTo($agent->user, AgentStatusNotification::class);
});

test('delivery is idempotent and suppresses stale unavailability after Agent reactivation', function (): void {
    Bus::fake([DeliverAgentStatusNotificationIntent::class]);
    Notification::fake();
    $admin = makeAgentStatusAdmin();
    $agent = makeAgentStatusProfile(true);
    assignAgentStatusCustomer($agent, $admin);

    $this->actingAs($admin)->patch(route('agents.status.update', $agent->agent_id), agentStatusPayload($agent, 'inactive'))->assertRedirect();

    $agentIntent = AgentStatusNotificationIntent::query()->where('audience_type', 'subject_agent')->where('channel', 'mail')->firstOrFail();
    $customerIntent = AgentStatusNotificationIntent::query()->where('audience_type', 'assigned_customer')->where('channel', 'mail')->firstOrFail();
    expect($customerIntent->status)->toBe('pending');
    (new DeliverAgentStatusNotificationIntent($agentIntent->id))->handle(app(AuthorizationService::class));
    (new DeliverAgentStatusNotificationIntent($agentIntent->id))->handle(app(AuthorizationService::class));
    Notification::assertCount(1);

    $agent->refresh()->forceFill(['operational_status' => AgentStatus::Active])->save();
    expect($agent->fresh()->operational_status)->toBe(AgentStatus::Active);
    (new DeliverAgentStatusNotificationIntent($customerIntent->id))->handle(app(AuthorizationService::class));
    expect($customerIntent->fresh()->status)->toBe('suppressed');
    Notification::assertCount(1);
});

test('management page shows readiness and protected history only to authorized Admin', function (): void {
    $admin = makeAgentStatusAdmin();
    $agent = makeAgentStatusProfile();
    assignAgentStatusCustomer($agent, $admin);

    $this->actingAs($admin)->get(route('agents.status.edit', $agent->agent_id))
        ->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('agents/Status')
        ->where('agent.id', $agent->agent_id)
        ->where('agent.assignment_counts.active', 1)
        ->where('agent.mfa_confirmed', true)
        ->where('agent.assignment_readiness.eligible', false)
        ->has('financial_responsibilities.collections'));
    $this->actingAs($agent->user)->get(route('agents.status.edit', $agent->agent_id))->assertForbidden();
});
