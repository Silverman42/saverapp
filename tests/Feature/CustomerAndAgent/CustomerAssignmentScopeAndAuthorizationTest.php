<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\AgentEligibilityCapability;
use App\Enums\AgentEligibilityReason;
use App\Enums\CustomerAssignmentStatus;
use App\Enums\CustomerStatus;
use App\Models\AgentProfile;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\User;
use App\Services\AgentEligibilityService;
use App\Services\CustomerActionAuthorizationGuard;
use App\Services\ResourceScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    // Ensure permission catalogue is active for tested permissions
    foreach ([AdminPermission::CustomersManage, AdminPermission::CustomersReassign, AdminPermission::AgentsManage] as $perm) {
        Permission::firstOrCreate(
            ['name' => $perm->value, 'guard_name' => 'web'],
            ['status' => 'active']
        );
    }
});

/*
|--------------------------------------------------------------------------
| 1. Persistence & Assignment History
|--------------------------------------------------------------------------
*/

test('customer assignment enforces one current assignment per customer', function (): void {
    $customer = CustomerProfile::factory()->create();
    $agent1 = AgentProfile::factory()->create();
    $agent2 = AgentProfile::factory()->create();
    $assigner = User::factory()->admin()->create();

    CustomerAssignment::create([
        'customer_profile_id' => $customer->id,
        'agent_profile_id' => $agent1->id,
        'assigned_by_user_id' => $assigner->id,
        'reason' => 'Initial assignment',
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => 1,
        'effective_at' => Carbon::now(),
        'version' => 1,
    ]);

    expect(fn () => CustomerAssignment::create([
        'customer_profile_id' => $customer->id,
        'agent_profile_id' => $agent2->id,
        'assigned_by_user_id' => $assigner->id,
        'reason' => 'Second current assignment violation',
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => 1,
        'effective_at' => Carbon::now(),
        'version' => 2,
    ]))->toThrow(QueryException::class);
});

test('customer assignment allows multiple ended assignments with null is_current', function (): void {
    $customer = CustomerProfile::factory()->create();
    $agent1 = AgentProfile::factory()->create();
    $agent2 = AgentProfile::factory()->create();
    $agent3 = AgentProfile::factory()->create();
    $assigner = User::factory()->admin()->create();

    $ended1 = CustomerAssignment::create([
        'customer_profile_id' => $customer->id,
        'agent_profile_id' => $agent1->id,
        'assigned_by_user_id' => $assigner->id,
        'reason' => 'First ended',
        'status' => CustomerAssignmentStatus::Ended,
        'is_current' => null,
        'effective_at' => Carbon::now()->subDays(10),
        'ended_at' => Carbon::now()->subDays(5),
        'version' => 1,
    ]);

    $ended2 = CustomerAssignment::create([
        'customer_profile_id' => $customer->id,
        'agent_profile_id' => $agent2->id,
        'assigned_by_user_id' => $assigner->id,
        'reason' => 'Second ended',
        'status' => CustomerAssignmentStatus::Ended,
        'is_current' => null,
        'effective_at' => Carbon::now()->subDays(5),
        'ended_at' => Carbon::now()->subDay(),
        'version' => 2,
    ]);

    $current = CustomerAssignment::create([
        'customer_profile_id' => $customer->id,
        'agent_profile_id' => $agent3->id,
        'assigned_by_user_id' => $assigner->id,
        'reason' => 'Third current',
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => 1,
        'effective_at' => Carbon::now()->subDay(),
        'version' => 3,
    ]);

    expect($customer->assignments()->count())->toBe(3)
        ->and($customer->currentAssignment->id)->toBe($current->id)
        ->and($agent1->historicalAssignments()->count())->toBe(1)
        ->and($agent3->currentAssignments()->count())->toBe(1);
});

test('customer assignment enforces unique per-customer version', function (): void {
    $customer = CustomerProfile::factory()->create();
    $agent1 = AgentProfile::factory()->create();
    $assigner = User::factory()->admin()->create();

    CustomerAssignment::create([
        'customer_profile_id' => $customer->id,
        'agent_profile_id' => $agent1->id,
        'assigned_by_user_id' => $assigner->id,
        'reason' => 'Initial',
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => 1,
        'effective_at' => Carbon::now(),
        'version' => 1,
    ]);

    expect(fn () => CustomerAssignment::create([
        'customer_profile_id' => $customer->id,
        'agent_profile_id' => $agent1->id,
        'assigned_by_user_id' => $assigner->id,
        'reason' => 'Duplicate version',
        'status' => CustomerAssignmentStatus::Ended,
        'is_current' => null,
        'effective_at' => Carbon::now(),
        'ended_at' => Carbon::now(),
        'version' => 1,
    ]))->toThrow(QueryException::class);
});

test('customer assignment attributes are immutable and rows cannot be deleted', function (): void {
    $customer = CustomerProfile::factory()->create();
    $agent = AgentProfile::factory()->create();
    $assigner = User::factory()->admin()->create();

    $assignment = CustomerAssignment::create([
        'customer_profile_id' => $customer->id,
        'agent_profile_id' => $agent->id,
        'assigned_by_user_id' => $assigner->id,
        'reason' => 'Initial assignment',
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => 1,
        'effective_at' => Carbon::now(),
        'version' => 1,
    ]);

    expect(function () use ($assignment): void {
        $assignment->version = 2;
        $assignment->save();
    })->toThrow(RuntimeException::class, 'Cannot modify immutable customer assignment attribute [version].');

    expect(function () use ($assignment): void {
        $assignment->delete();
    })->toThrow(RuntimeException::class, 'Customer assignments are immutable historical records and cannot be deleted.');
});

test('user historical attribution includes assignment participation safeguarding deletion', function (): void {
    $user = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $agent = AgentProfile::factory()->create();

    expect($user->hasHistoricalAttribution())->toBeFalse();

    CustomerAssignment::create([
        'customer_profile_id' => $customer->id,
        'agent_profile_id' => $agent->id,
        'assigned_by_user_id' => $user->id,
        'reason' => 'Assigned by admin',
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => 1,
        'effective_at' => Carbon::now(),
        'version' => 1,
    ]);

    expect($user->fresh()->hasHistoricalAttribution())->toBeTrue();

    // User model deleting hook prevents deletion when attribution exists
    expect(fn () => $user->delete())->toThrow(RuntimeException::class);
});

/*
|--------------------------------------------------------------------------
| 2. Full Eligibility Matrix (AgentEligibilityService)
|--------------------------------------------------------------------------
*/

test('AgentEligibilityService rejects user with missing agent profile', function (): void {
    $agentUser = User::factory()->agent()->create();
    $service = app(AgentEligibilityService::class);

    $result = $service->evaluate($agentUser, AgentEligibilityCapability::ReadAssignedCustomers);

    expect($result->isEligible())->toBeFalse()
        ->and($result->reasonCode)->toBe(AgentEligibilityReason::MissingProfile);
});

test('AgentEligibilityService rejects non-agent user type or role drift', function (): void {
    $service = app(AgentEligibilityService::class);

    $customerUser = User::factory()->customer()->create();
    $result1 = $service->evaluate($customerUser, AgentEligibilityCapability::ReadAssignedCustomers);

    expect($result1->isEligible())->toBeFalse()
        ->and($result1->reasonCode)->toBe(AgentEligibilityReason::RoleDrift);

    // Agent user without Spatie role
    $agentUser = User::factory()->agent()->create();
    $agentProfile = AgentProfile::factory()->create(['user_id' => $agentUser->id]);
    $agentUser->roles()->detach();

    $result2 = $service->evaluate($agentProfile, AgentEligibilityCapability::ReadAssignedCustomers);
    expect($result2->isEligible())->toBeFalse()
        ->and($result2->reasonCode)->toBe(AgentEligibilityReason::RoleDrift);
});

test('AgentEligibilityService rejects unusable account states', function (): void {
    $service = app(AgentEligibilityService::class);

    foreach ([AccountState::Invited, AccountState::Suspended, AccountState::Deactivated] as $state) {
        $agentUser = User::factory()->agent()->create([
            'account_state' => $state,
            'two_factor_secret' => 'SECRET',
            'two_factor_confirmed_at' => Carbon::now(),
        ]);
        $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agentUser->id]);

        $result = $service->evaluate($agentProfile, AgentEligibilityCapability::ReadAssignedCustomers);
        expect($result->isEligible())->toBeFalse()
            ->and($result->reasonCode)->toBe(AgentEligibilityReason::UnusableAccount);
    }
});

test('AgentEligibilityService rejects incomplete MFA onboarding', function (): void {
    $service = app(AgentEligibilityService::class);

    $agentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => null,
        'two_factor_confirmed_at' => null,
    ]);
    $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agentUser->id]);

    $result = $service->evaluate($agentProfile, AgentEligibilityCapability::ReadAssignedCustomers);
    expect($result->isEligible())->toBeFalse()
        ->and($result->reasonCode)->toBe(AgentEligibilityReason::IncompleteMfa);
});

test('AgentEligibilityService allows ReadAssignedCustomers for operationally inactive agent', function (): void {
    $service = app(AgentEligibilityService::class);

    $agentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $agentProfile = AgentProfile::factory()->inactive()->create(['user_id' => $agentUser->id]);

    // Read capability passes for inactive agent
    $readResult = $service->evaluate($agentProfile, AgentEligibilityCapability::ReadAssignedCustomers);
    expect($readResult->isEligible())->toBeTrue();

    // But work capability fails with OperationallyInactive
    $workResult = $service->evaluate($agentProfile, AgentEligibilityCapability::PerformAssignedCustomerWork);
    expect($workResult->isEligible())->toBeFalse()
        ->and($workResult->reasonCode)->toBe(AgentEligibilityReason::OperationallyInactive);

    // And receive assignment fails with OperationallyInactive
    $receiveResult = $service->evaluate($agentProfile, AgentEligibilityCapability::ReceiveAssignment);
    expect($receiveResult->isEligible())->toBeFalse()
        ->and($receiveResult->reasonCode)->toBe(AgentEligibilityReason::OperationallyInactive);
});

test('AgentEligibilityService: temporary lock preserves assigned customer work but blocks receiving new assignments', function (): void {
    $service = app(AgentEligibilityService::class);

    $agentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
        'locked_until' => Carbon::now()->addMinutes(30),
        'lock_category' => 'password',
    ]);
    $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agentUser->id]);

    // Can still perform assigned work via legitimate existing session
    $workResult = $service->evaluate($agentProfile, AgentEligibilityCapability::PerformAssignedCustomerWork);
    expect($workResult->isEligible())->toBeTrue();

    // Cannot receive new assignments while temporarily locked
    $receiveResult = $service->evaluate($agentProfile, AgentEligibilityCapability::ReceiveAssignment);
    expect($receiveResult->isEligible())->toBeFalse()
        ->and($receiveResult->reasonCode)->toBe(AgentEligibilityReason::TemporarilyLocked);
});

test('AgentEligibilityService allows ReceiveAssignment when fully eligible without lock', function (): void {
    $service = app(AgentEligibilityService::class);

    $agentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
        'locked_until' => null,
    ]);
    $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agentUser->id]);

    expect($service->canReceiveAssignment($agentProfile))->toBeTrue()
        ->and($service->canPerformAssignedCustomerWork($agentProfile))->toBeTrue()
        ->and($service->canReadAssignedCustomers($agentProfile))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 3. Resource Scope Service (ResourceScopeService)
|--------------------------------------------------------------------------
*/

test('ResourceScopeService forCustomers restricts Customer to own record', function (): void {
    $customer1 = User::factory()->customer()->create();
    $profile1 = CustomerProfile::factory()->create(['user_id' => $customer1->id]);

    $customer2 = User::factory()->customer()->create();
    $profile2 = CustomerProfile::factory()->create(['user_id' => $customer2->id]);

    $scopeService = app(ResourceScopeService::class);
    $scoped = $scopeService->forCustomers($customer1)->get();

    expect($scoped)->toHaveCount(1)
        ->and($scoped->first()->id)->toBe($profile1->id);
});

test('ResourceScopeService forCustomers restricts Agent to currently assigned customers only', function (): void {
    $agentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agentUser->id]);

    $otherAgentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $otherAgentProfile = AgentProfile::factory()->active()->create(['user_id' => $otherAgentUser->id]);

    $customerAssigned = CustomerProfile::factory()->create();
    $customerFormer = CustomerProfile::factory()->create();
    $customerOther = CustomerProfile::factory()->create();

    // Current assignment to $agentProfile
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customerAssigned->id,
        'agent_profile_id' => $agentProfile->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => 1,
    ]);

    // Former (ended) assignment to $agentProfile (now assigned to otherAgent)
    CustomerAssignment::factory()->ended()->create([
        'customer_profile_id' => $customerFormer->id,
        'agent_profile_id' => $agentProfile->id,
        'version' => 1,
    ]);
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customerFormer->id,
        'agent_profile_id' => $otherAgentProfile->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => 1,
        'version' => 2,
    ]);

    // Customer assigned only to other agent
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customerOther->id,
        'agent_profile_id' => $otherAgentProfile->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => 1,
    ]);

    $scopeService = app(ResourceScopeService::class);
    $scoped = $scopeService->forCustomers($agentUser)->get();

    expect($scoped)->toHaveCount(1)
        ->and($scoped->first()->id)->toBe($customerAssigned->id);
});

test('ResourceScopeService forCustomers gives active synchronized Admin business-wide scope', function (): void {
    $admin = User::factory()->admin()->create(['account_state' => AccountState::Active]);
    CustomerProfile::factory()->count(3)->create();

    $scopeService = app(ResourceScopeService::class);
    $scoped = $scopeService->forCustomers($admin)->get();

    expect($scoped)->toHaveCount(3);
});

test('ResourceScopeService forAgents restricts Agent to own profile and denies Customers', function (): void {
    $agentUser1 = User::factory()->agent()->create(['account_state' => AccountState::Active]);
    $agentProfile1 = AgentProfile::factory()->create(['user_id' => $agentUser1->id]);

    $agentUser2 = User::factory()->agent()->create(['account_state' => AccountState::Active]);
    $agentProfile2 = AgentProfile::factory()->create(['user_id' => $agentUser2->id]);

    $customerUser = User::factory()->customer()->create(['account_state' => AccountState::Active]);

    $adminUser = User::factory()->admin()->create(['account_state' => AccountState::Active]);

    $scopeService = app(ResourceScopeService::class);

    // Agent 1 sees only own profile
    $agent1Scope = $scopeService->forAgents($agentUser1)->get();
    expect($agent1Scope)->toHaveCount(1)
        ->and($agent1Scope->first()->id)->toBe($agentProfile1->id);

    // Customer receives empty scope
    $customerScope = $scopeService->forAgents($customerUser)->get();
    expect($customerScope)->toBeEmpty();

    // Admin sees both agent profiles
    $adminScope = $scopeService->forAgents($adminUser)->get();
    expect($adminScope)->toHaveCount(2);
});

test('ResourceScopeService eligibleAssignmentRecipients coarse query filters correctly', function (): void {
    // 1. Fully eligible
    $u1 = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $p1 = AgentProfile::factory()->active()->create(['user_id' => $u1->id]);

    // 2. Inactive operational status
    $u2 = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    AgentProfile::factory()->inactive()->create(['user_id' => $u2->id]);

    // 3. Temporarily locked
    $u3 = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
        'locked_until' => Carbon::now()->addMinutes(15),
    ]);
    AgentProfile::factory()->active()->create(['user_id' => $u3->id]);

    $scopeService = app(ResourceScopeService::class);
    $recipients = $scopeService->getAuthoritativeEligibleRecipients();

    expect($recipients)->toHaveCount(1)
        ->and($recipients->first()->id)->toBe($p1->id);
});

/*
|--------------------------------------------------------------------------
| 4. Policies (CustomerProfilePolicy & AgentProfilePolicy)
|--------------------------------------------------------------------------
*/

test('CustomerProfilePolicy view policy follows own/current-Agent/Admin scope', function (): void {
    $agentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agentUser->id]);

    $customerUser = User::factory()->customer()->create(['account_state' => AccountState::Active]);
    $customerProfile = CustomerProfile::factory()->create(['user_id' => $customerUser->id]);

    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customerProfile->id,
        'agent_profile_id' => $agentProfile->id,
        'is_current' => 1,
    ]);

    $adminUser = User::factory()->admin()->create(['account_state' => AccountState::Active]);
    $strangerUser = User::factory()->customer()->create(['account_state' => AccountState::Active]);

    expect(Gate::forUser($customerUser)->allows('view', $customerProfile))->toBeTrue()
        ->and(Gate::forUser($agentUser)->allows('view', $customerProfile))->toBeTrue()
        ->and(Gate::forUser($adminUser)->allows('view', $customerProfile))->toBeTrue()
        ->and(Gate::forUser($strangerUser)->allows('view', $customerProfile))->toBeFalse();
});

test('CustomerProfilePolicy create is Agent-only and requires assignment-recipient eligibility; Admin is denied', function (): void {
    $eligibleAgent = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    AgentProfile::factory()->active()->create(['user_id' => $eligibleAgent->id]);

    $inactiveAgent = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    AgentProfile::factory()->inactive()->create(['user_id' => $inactiveAgent->id]);

    $admin = User::factory()->admin()->create(['account_state' => AccountState::Active]);
    $customer = User::factory()->customer()->create(['account_state' => AccountState::Active]);

    expect(Gate::forUser($eligibleAgent)->allows('create', CustomerProfile::class))->toBeTrue()
        ->and(Gate::forUser($inactiveAgent)->allows('create', CustomerProfile::class))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('create', CustomerProfile::class))->toBeFalse()
        ->and(Gate::forUser($customer)->allows('create', CustomerProfile::class))->toBeFalse();
});

test('CustomerProfilePolicy update allows Customer, eligible assigned Agent, or Admin with customers.manage', function (): void {
    $agentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agentUser->id]);

    $customerUser = User::factory()->customer()->create(['account_state' => AccountState::Active]);
    $customerProfile = CustomerProfile::factory()->create(['user_id' => $customerUser->id]);

    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customerProfile->id,
        'agent_profile_id' => $agentProfile->id,
        'is_current' => 1,
    ]);

    $adminWithoutPerm = User::factory()->admin()->create(['account_state' => AccountState::Active]);
    $adminWithPerm = User::factory()->admin()->create(['account_state' => AccountState::Active]);
    $adminWithPerm->givePermissionTo(AdminPermission::CustomersManage->value);

    expect(Gate::forUser($customerUser)->allows('update', $customerProfile))->toBeTrue()
        ->and(Gate::forUser($agentUser)->allows('update', $customerProfile))->toBeTrue()
        ->and(Gate::forUser($adminWithoutPerm)->allows('update', $customerProfile))->toBeFalse()
        ->and(Gate::forUser($adminWithPerm)->allows('update', $customerProfile))->toBeTrue();

    // Archived customer is read-only for updates
    $customerProfile->operational_status = CustomerStatus::Archived;
    $customerProfile->save();

    expect(Gate::forUser($customerUser)->allows('update', $customerProfile))->toBeFalse()
        ->and(Gate::forUser($agentUser)->allows('update', $customerProfile))->toBeFalse()
        ->and(Gate::forUser($adminWithPerm)->allows('update', $customerProfile))->toBeFalse();
});

test('CustomerProfilePolicy reassign requires customers.reassign permission', function (): void {
    $customer = CustomerProfile::factory()->create();

    $agent = User::factory()->agent()->create();
    $adminWithoutPerm = User::factory()->admin()->create();
    $adminWithPerm = User::factory()->admin()->create();
    $adminWithPerm->givePermissionTo(AdminPermission::CustomersReassign->value);

    expect(Gate::forUser($agent)->allows('reassign', $customer))->toBeFalse()
        ->and(Gate::forUser($adminWithoutPerm)->allows('reassign', $customer))->toBeFalse()
        ->and(Gate::forUser($adminWithPerm)->allows('reassign', $customer))->toBeTrue();
});

test('destructive deletion is denied across customer and agent policies', function (): void {
    $customer = CustomerProfile::factory()->create();
    $agent = AgentProfile::factory()->create();
    $admin = User::factory()->admin()->create();

    expect(Gate::forUser($admin)->allows('delete', $customer))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('delete', $agent))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| 5. Commit-Time Guard (CustomerActionAuthorizationGuard)
|--------------------------------------------------------------------------
*/

test('CustomerActionAuthorizationGuard requires an active database transaction', function (): void {
    $guard = app(CustomerActionAuthorizationGuard::class);
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();

    $initialLevel = DB::transactionLevel();
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    try {
        expect(fn () => $guard->lockAndAuthorize($admin, $customer->id, 'view'))
            ->toThrow(RuntimeException::class, 'CustomerActionAuthorizationGuard must be executed within an active database transaction.');
    } finally {
        while (DB::transactionLevel() < $initialLevel) {
            DB::beginTransaction();
        }
    }
});

test('CustomerActionAuthorizationGuard locks and successfully returns context for authorized actor', function (): void {
    $guard = app(CustomerActionAuthorizationGuard::class);

    $agentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agentUser->id]);

    $customer = CustomerProfile::factory()->create(['version' => 1]);
    $assignment = CustomerAssignment::factory()->create([
        'customer_profile_id' => $customer->id,
        'agent_profile_id' => $agentProfile->id,
        'is_current' => 1,
        'version' => 1,
    ]);

    $context = DB::transaction(function () use ($guard, $agentUser, $customer) {
        return $guard->lockAndAuthorize(
            actor: $agentUser,
            customerProfileId: $customer->id,
            ability: 'update',
            expectedCustomerVersion: 1,
            expectedAssignmentVersion: 1,
        );
    });

    expect($context->actor->id)->toBe($agentUser->id)
        ->and($context->customerProfile->id)->toBe($customer->id)
        ->and($context->currentAssignment->id)->toBe($assignment->id);
});

test('CustomerActionAuthorizationGuard fails on stale customer version', function (): void {
    $guard = app(CustomerActionAuthorizationGuard::class);

    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::CustomersManage->value);

    $customer = CustomerProfile::factory()->create(['version' => 2]);

    expect(function () use ($guard, $admin, $customer): void {
        DB::transaction(function () use ($guard, $admin, $customer): void {
            $guard->lockAndAuthorize(
                actor: $admin,
                customerProfileId: $customer->id,
                ability: 'update',
                expectedCustomerVersion: 1, // Stale!
            );
        });
    })->toThrow(ValidationException::class);
});

test('CustomerActionAuthorizationGuard fails on stale assignment version', function (): void {
    $guard = app(CustomerActionAuthorizationGuard::class);

    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::CustomersReassign->value);

    $customer = CustomerProfile::factory()->create();
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customer->id,
        'is_current' => 1,
        'version' => 3,
    ]);

    expect(function () use ($guard, $admin, $customer): void {
        DB::transaction(function () use ($guard, $admin, $customer): void {
            $guard->lockAndAuthorize(
                actor: $admin,
                customerProfileId: $customer->id,
                ability: 'reassign',
                expectedAssignmentVersion: 2, // Stale!
            );
        });
    })->toThrow(ValidationException::class);
});

test('CustomerActionAuthorizationGuard fails if actor lost authority before commit', function (): void {
    $guard = app(CustomerActionAuthorizationGuard::class);

    $agentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $agentProfile = AgentProfile::factory()->inactive()->create(['user_id' => $agentUser->id]); // Inactive!

    $customer = CustomerProfile::factory()->create();
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customer->id,
        'agent_profile_id' => $agentProfile->id,
        'is_current' => 1,
    ]);

    // Attempting 'update' while operationally inactive fails authorization under lock
    expect(function () use ($guard, $agentUser, $customer): void {
        DB::transaction(function () use ($guard, $agentUser, $customer): void {
            $guard->lockAndAuthorize(
                actor: $agentUser,
                customerProfileId: $customer->id,
                ability: 'update',
            );
        });
    })->toThrow(AuthorizationException::class);
});

test('CustomerActionAuthorizationGuard validates target agent eligibility for reassignment', function (): void {
    $guard = app(CustomerActionAuthorizationGuard::class);

    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::CustomersReassign->value);

    $customer = CustomerProfile::factory()->create();
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customer->id,
        'is_current' => 1,
    ]);

    // Target agent is operationally inactive (not eligible to receive assignment)
    $targetAgentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $targetAgentProfile = AgentProfile::factory()->inactive()->create(['user_id' => $targetAgentUser->id]);

    expect(function () use ($guard, $admin, $customer, $targetAgentProfile): void {
        DB::transaction(function () use ($guard, $admin, $customer, $targetAgentProfile): void {
            $guard->lockAndAuthorize(
                actor: $admin,
                customerProfileId: $customer->id,
                ability: 'reassign',
                targetAgentProfileId: $targetAgentProfile->id,
            );
        });
    })->toThrow(AuthorizationException::class);
});
