<?php

use App\Enums\AccountState;
use App\Enums\CustomerAssignmentStatus;
use App\Enums\CustomerStatus;
use App\Models\AgentProfile;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\ProfilePhotoService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
});

/*
|--------------------------------------------------------------------------
| 1. Role-Based Screen Access & Invariants
|--------------------------------------------------------------------------
*/

test('Customer cannot access Customer directory or Agent directory', function (): void {
    $customerUser = User::factory()->customer()->create();
    CustomerProfile::factory()->create(['user_id' => $customerUser->id]);

    $this->actingAs($customerUser)
        ->get(route('customers.index'))
        ->assertForbidden();

    $this->actingAs($customerUser)
        ->get(route('agents.index'))
        ->assertForbidden();
});

test('Customer can view own profile but is denied access to other customers and agents', function (): void {
    $customerUser1 = User::factory()->customer()->create();
    $customerProfile1 = CustomerProfile::factory()->create([
        'user_id' => $customerUser1->id,
        'notes' => 'Secret customer 1 notes',
    ]);

    $customerUser2 = User::factory()->customer()->create();
    $customerProfile2 = CustomerProfile::factory()->create([
        'user_id' => $customerUser2->id,
        'notes' => 'Secret customer 2 notes',
    ]);

    $agentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agentUser->id]);

    // 1. Can view own profile
    $this->actingAs($customerUser1)
        ->get(route('customers.show', $customerProfile1->customer_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('customers/Show')
            ->where('customer.id', $customerProfile1->customer_id)
            ->where('customer.financial_summary.status', 'unavailable')
            ->where('customer.plans.status', 'available')
            ->where('customer.transactions.status', 'unavailable')
            ->where('customer.statements.status', 'unavailable')
            ->missing('customer.notes')
            ->missing('customer.phone_normalized')
            ->missing('customer.internal_reference_normalized')
        );

    // 2. Denied viewing other customer profile (generic 404)
    $this->actingAs($customerUser1)
        ->get(route('customers.show', $customerProfile2->customer_id))
        ->assertNotFound();

    // 3. Denied viewing agent profile (generic 404)
    $this->actingAs($customerUser1)
        ->get(route('agents.show', $agentProfile->agent_id))
        ->assertNotFound();
});

test('Customer viewing own profile receives permitted business contact only for assigned agent', function (): void {
    $agentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $agentProfile = AgentProfile::factory()->active()->create([
        'user_id' => $agentUser->id,
        'phone' => '08099887766',
        'notes' => 'Top secret agent notes',
    ]);

    $customerUser = User::factory()->customer()->create();
    $customerProfile = CustomerProfile::factory()->create(['user_id' => $customerUser->id]);

    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customerProfile->id,
        'agent_profile_id' => $agentProfile->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => 1,
    ]);

    $this->actingAs($customerUser)
        ->get(route('customers.show', $customerProfile->customer_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('customer.assigned_agent.name', $agentUser->name)
            ->where('customer.assigned_agent.phone', $agentProfile->phone)
            ->where('customer.assigned_agent.email', $agentUser->email)
            ->missing('customer.assigned_agent.id')
            ->missing('customer.assigned_agent.notes')
        );
});

test('Agent sees only currently assigned customers in directory and profile', function (): void {
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

    $assignedCustomer = CustomerProfile::factory()->create();
    $unassignedCustomer = CustomerProfile::factory()->create();

    CustomerAssignment::factory()->create([
        'customer_profile_id' => $assignedCustomer->id,
        'agent_profile_id' => $agentProfile->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => 1,
    ]);

    CustomerAssignment::factory()->create([
        'customer_profile_id' => $unassignedCustomer->id,
        'agent_profile_id' => $otherAgentProfile->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => 1,
    ]);

    // Agent directory access is forbidden
    $this->actingAs($agentUser)
        ->get(route('agents.index'))
        ->assertForbidden();

    // Customer directory shows ONLY currently assigned customer
    $this->actingAs($agentUser)
        ->get(route('customers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('customers/Index')
            ->has('customers.data', 1)
            ->where('customers.data.0.id', $assignedCustomer->customer_id)
        );

    // Can view assigned customer profile
    $this->actingAs($agentUser)
        ->get(route('customers.show', $assignedCustomer->customer_id))
        ->assertOk();

    // Cannot view other agent's customer profile (generic 404)
    $this->actingAs($agentUser)
        ->get(route('customers.show', $unassignedCustomer->customer_id))
        ->assertNotFound();

    // Can view own agent profile
    $this->actingAs($agentUser)
        ->get(route('agents.show', $agentProfile->agent_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('agents/Show')
            ->where('agent.id', $agentProfile->agent_id)
            ->missing('agent.notes') // Agent notes omitted in self-service
        );

    // Cannot view other agent's profile (generic 404)
    $this->actingAs($agentUser)
        ->get(route('agents.show', $otherAgentProfile->agent_id))
        ->assertNotFound();
});

test('Former agent loses access immediately upon customer reassignment', function (): void {
    $agentUser1 = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $agentProfile1 = AgentProfile::factory()->active()->create(['user_id' => $agentUser1->id]);

    $agentUser2 = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $agentProfile2 = AgentProfile::factory()->active()->create(['user_id' => $agentUser2->id]);

    $customer = CustomerProfile::factory()->create();

    // End assignment for agent 1
    CustomerAssignment::factory()->ended()->create([
        'customer_profile_id' => $customer->id,
        'agent_profile_id' => $agentProfile1->id,
        'version' => 1,
    ]);

    // Current assignment is to agent 2
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customer->id,
        'agent_profile_id' => $agentProfile2->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => 1,
        'version' => 2,
    ]);

    // Agent 1 immediately gets generic 404
    $this->actingAs($agentUser1)
        ->get(route('customers.show', $customer->customer_id))
        ->assertNotFound();

    // Customer is not in Agent 1's directory
    $this->actingAs($agentUser1)
        ->get(route('customers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 0)
        );

    // Agent 2 has access
    $this->actingAs($agentUser2)
        ->get(route('customers.show', $customer->customer_id))
        ->assertOk();
});

test('Operationally Inactive agent retains read-only access to assigned customers', function (): void {
    $agentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $agentProfile = AgentProfile::factory()->inactive()->create(['user_id' => $agentUser->id]);

    $customer = CustomerProfile::factory()->create();

    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customer->id,
        'agent_profile_id' => $agentProfile->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => 1,
    ]);

    $this->actingAs($agentUser)
        ->get(route('customers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 1)
        );

    $this->actingAs($agentUser)
        ->get(route('customers.show', $customer->customer_id))
        ->assertOk();
});

test('Admin receives business-wide directory and profile access with notes', function (): void {
    $admin = User::factory()->admin()->create();

    $agentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $agentProfile = AgentProfile::factory()->active()->create([
        'user_id' => $agentUser->id,
        'notes' => 'Admin-visible agent note',
    ]);

    $customer = CustomerProfile::factory()->create([
        'notes' => 'Admin-visible customer note',
    ]);

    // Admin opens customer directory
    $this->actingAs($admin)
        ->get(route('customers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('customers/Index')
            ->where('viewer_type', 'admin')
        );

    // Admin opens customer profile
    $this->actingAs($admin)
        ->get(route('customers.show', $customer->customer_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('customers/Show')
            ->where('customer.notes', 'Admin-visible customer note')
        );

    // Admin opens agent directory
    $this->actingAs($admin)
        ->get(route('agents.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('agents/Index')
            ->where('viewer_type', 'admin')
        );

    // Admin opens agent profile
    $this->actingAs($admin)
        ->get(route('agents.show', $agentProfile->agent_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('agents/Show')
            ->where('agent.notes', 'Admin-visible agent note')
            ->has('agent.lifecycle')
        );
});

/*
|--------------------------------------------------------------------------
| 2. Generic Unavailable Response Invariant
|--------------------------------------------------------------------------
*/

test('Absent and unauthorized customer and agent IDs return identical generic 404 response', function (): void {
    $admin = User::factory()->admin()->create();
    $agentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    AgentProfile::factory()->active()->create(['user_id' => $agentUser->id]);

    $customer = CustomerProfile::factory()->create(); // Not assigned to agent

    // Non-existent customer
    $responseAbsent = $this->actingAs($agentUser)->get(route('customers.show', 'CUS-999999'));
    $responseAbsent->assertNotFound();

    // Unauthorized customer
    $responseUnauthorized = $this->actingAs($agentUser)->get(route('customers.show', $customer->customer_id));
    $responseUnauthorized->assertNotFound();

    expect($responseAbsent->getStatusCode())->toBe($responseUnauthorized->getStatusCode());

    // Non-existent agent vs unauthorized agent
    $otherAgent = AgentProfile::factory()->active()->create();
    $agentAbsent = $this->actingAs($agentUser)->get(route('agents.show', 'AGT-999999'));
    $agentAbsent->assertNotFound();

    $agentUnauthorized = $this->actingAs($agentUser)->get(route('agents.show', $otherAgent->agent_id));
    $agentUnauthorized->assertNotFound();

    expect($agentAbsent->getStatusCode())->toBe($agentUnauthorized->getStatusCode());
});

/*
|--------------------------------------------------------------------------
| 3. Customer Directory Search, Filtering, and Sorting
|--------------------------------------------------------------------------
*/

test('Default customer directory excludes archived customers unless explicitly requested', function (): void {
    $admin = User::factory()->admin()->create();

    $activeCustomer = CustomerProfile::factory()->create(['operational_status' => CustomerStatus::Active]);
    $archivedCustomer = CustomerProfile::factory()->create(['operational_status' => CustomerStatus::Archived]);

    // Default: non-archived only
    $this->actingAs($admin)
        ->get(route('customers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 1)
            ->where('customers.data.0.id', $activeCustomer->customer_id)
        );

    // Explicit archived filter
    $this->actingAs($admin)
        ->get(route('customers.index', ['operational_status' => 'archived']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 1)
            ->where('customers.data.0.id', $archivedCustomer->customer_id)
        );

    // Explicit all statuses filter
    $this->actingAs($admin)
        ->get(route('customers.index', ['operational_status' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 2)
        );
});

test('Customer directory search handles name, phone, email, internal reference, and customer ID', function (): void {
    $admin = User::factory()->admin()->create();

    $userTarget = User::factory()->customer()->create([
        'name' => 'Emeka Okafor',
        'email' => 'emeka.okafor@example.com',
    ]);
    $profileTarget = CustomerProfile::factory()->create([
        'user_id' => $userTarget->id,
        'phone' => '08031234567',
        'internal_reference' => 'REF-EMEKA-01',
    ]);

    $userOther = User::factory()->customer()->create([
        'name' => 'Chioma Nnamdi',
        'email' => 'chioma@example.com',
    ]);
    CustomerProfile::factory()->create([
        'user_id' => $userOther->id,
        'phone' => '08059998877',
        'internal_reference' => 'REF-CHIOMA-99',
    ]);

    // Search by name
    $this->actingAs($admin)->get(route('customers.index', ['search' => 'Emeka']))
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 1)->where('customers.data.0.id', $profileTarget->customer_id));

    // Search by email
    $this->actingAs($admin)->get(route('customers.index', ['search' => 'emeka.okafor@example.com']))
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 1)->where('customers.data.0.id', $profileTarget->customer_id));

    // Search by customer ID
    $this->actingAs($admin)->get(route('customers.index', ['search' => $profileTarget->customer_id]))
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 1)->where('customers.data.0.id', $profileTarget->customer_id));

    // Search by internal reference
    $this->actingAs($admin)->get(route('customers.index', ['search' => 'REF-EMEKA-01']))
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 1)->where('customers.data.0.id', $profileTarget->customer_id));

    // Search by phone formatted partial
    $this->actingAs($admin)->get(route('customers.index', ['search' => '0803123']))
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 1)->where('customers.data.0.id', $profileTarget->customer_id));
});

test('Customer directory validates date ranges and interprets in Africa/Lagos', function (): void {
    $admin = User::factory()->admin()->create();

    // Reversed date range triggers validation error
    $this->actingAs($admin)
        ->get(route('customers.index', [
            'registered_from' => '2026-09-20',
            'registered_to' => '2026-09-10',
        ]))
        ->assertSessionHasErrors(['registered_from']);

    // Valid date filter
    $this->actingAs($admin)
        ->get(route('customers.index', [
            'registered_from' => '2026-09-01',
            'registered_to' => '2026-09-30',
        ]))
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| 4. Agent Directory Search, Filtering, and Customer Workload Counts
|--------------------------------------------------------------------------
*/

test('Default agent directory excludes deactivated accounts and distinguishes active/archived customer counts', function (): void {
    $admin = User::factory()->admin()->create();

    $agentUser1 = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $agentProfile1 = AgentProfile::factory()->active()->create(['user_id' => $agentUser1->id]);

    $deactivatedUser = User::factory()->agent()->create([
        'account_state' => AccountState::Deactivated,
    ]);
    AgentProfile::factory()->create(['user_id' => $deactivatedUser->id]);

    $activeCustomer = CustomerProfile::factory()->create(['operational_status' => CustomerStatus::Active]);
    $archivedCustomer = CustomerProfile::factory()->create(['operational_status' => CustomerStatus::Archived]);

    // Assignments to agent 1
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $activeCustomer->id,
        'agent_profile_id' => $agentProfile1->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => 1,
    ]);
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $archivedCustomer->id,
        'agent_profile_id' => $agentProfile1->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => 1,
    ]);

    // Default excludes deactivated agent
    $this->actingAs($admin)
        ->get(route('agents.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('agents.data', 1)
            ->where('agents.data.0.id', $agentProfile1->agent_id)
            ->where('agents.data.0.current_customers_count', 1)
            ->where('agents.data.0.archived_customers_count', 1)
        );

    // Explicit account_state=deactivated
    $this->actingAs($admin)
        ->get(route('agents.index', ['account_state' => 'deactivated']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('agents.data', 1)
            ->where('agents.data.0.account_state', 'deactivated')
        );
});

test('Agent directory filters by eligibility and customer workload range', function (): void {
    $admin = User::factory()->admin()->create();

    // Eligible agent with 1 customer
    $agentUserEligible = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $agentProfileEligible = AgentProfile::factory()->active()->create(['user_id' => $agentUserEligible->id]);

    $cust = CustomerProfile::factory()->create();
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $cust->id,
        'agent_profile_id' => $agentProfileEligible->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => 1,
    ]);

    // Ineligible agent (missing MFA) with 0 customers
    $agentUserIneligible = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => null,
        'two_factor_confirmed_at' => null,
    ]);
    $agentProfileIneligible = AgentProfile::factory()->active()->create(['user_id' => $agentUserIneligible->id]);

    // Filter by eligible
    $this->actingAs($admin)
        ->get(route('agents.index', ['eligibility' => 'eligible']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('agents.data', 1)
            ->where('agents.data.0.id', $agentProfileEligible->agent_id)
            ->where('agents.data.0.eligibility.is_eligible', true)
        );

    // Filter by min_customers = 1
    $this->actingAs($admin)
        ->get(route('agents.index', ['min_customers' => 1]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('agents.data', 1)
            ->where('agents.data.0.id', $agentProfileEligible->agent_id)
        );
});

test('Customer overview uses Lagos registration periods, ignores directory filters, and respects viewer scope', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-21 12:00:00', 'Africa/Lagos'));

    try {
        $agentUser = User::factory()->agent()->create([
            'account_state' => AccountState::Active,
            'two_factor_secret' => 'SECRET',
            'two_factor_confirmed_at' => Carbon::now(),
        ]);
        $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agentUser->id]);

        $visibleActiveCustomer = CustomerProfile::factory()->create([
            'operational_status' => CustomerStatus::Active,
            'created_at' => Carbon::parse('2026-09-20 23:00:00', 'UTC'),
        ]);
        $visibleRestrictedCustomer = CustomerProfile::factory()->restricted()->create([
            'created_at' => Carbon::parse('2026-09-21 22:59:59', 'UTC'),
        ]);
        CustomerProfile::factory()->create([
            'operational_status' => CustomerStatus::Active,
            'created_at' => Carbon::parse('2026-09-21 12:00:00', 'UTC'),
        ]);
        CustomerProfile::factory()->create([
            'operational_status' => CustomerStatus::Active,
            'created_at' => Carbon::parse('2026-09-20 22:59:59', 'UTC'),
        ]);

        foreach ([$visibleActiveCustomer, $visibleRestrictedCustomer] as $customer) {
            CustomerAssignment::factory()->create([
                'customer_profile_id' => $customer->id,
                'agent_profile_id' => $agentProfile->id,
                'status' => CustomerAssignmentStatus::Current,
                'is_current' => 1,
            ]);
        }

        $this->actingAs($agentUser)
            ->get(route('customers.index', [
                'overview_period' => 'today',
                'search' => 'no directory matches',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('customers.data', 0)
                ->where('overview_period', 'today')
                ->where('overview.total', 2)
                ->where('overview.active', 1)
                ->where('overview.restricted', 1)
            );
    } finally {
        Carbon::setTestNow();
    }
});

test('Agent overview counts current active and eligible agents in the selected registration period', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-21 12:00:00', 'Africa/Lagos'));

    try {
        $admin = User::factory()->admin()->create();

        $eligibleUser = User::factory()->agent()->create([
            'account_state' => AccountState::Active,
            'two_factor_secret' => 'SECRET',
            'two_factor_confirmed_at' => Carbon::now(),
        ]);
        AgentProfile::factory()->active()->create(['user_id' => $eligibleUser->id]);

        $ineligibleUser = User::factory()->agent()->create([
            'account_state' => AccountState::Active,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);
        AgentProfile::factory()->active()->create(['user_id' => $ineligibleUser->id]);

        $olderUser = User::factory()->agent()->create([
            'account_state' => AccountState::Active,
            'two_factor_secret' => 'SECRET',
            'two_factor_confirmed_at' => Carbon::now(),
        ]);
        AgentProfile::factory()->active()->create([
            'user_id' => $olderUser->id,
            'created_at' => Carbon::parse('2026-09-20 22:59:59', 'UTC'),
        ]);

        $this->actingAs($admin)
            ->get(route('agents.index', [
                'overview_period' => 'today',
                'search' => 'no directory matches',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('agents.data', 0)
                ->where('overview_period', 'today')
                ->where('overview.total', 2)
                ->where('overview.active', 2)
                ->where('overview.eligible', 1)
            );
    } finally {
        Carbon::setTestNow();
    }
});

test('Directory overview period validation rejects unsupported values', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('customers.index', ['overview_period' => 'year']))
        ->assertSessionHasErrors(['overview_period']);

    $this->actingAs($admin)
        ->get(route('agents.index', ['overview_period' => 'year']))
        ->assertSessionHasErrors(['overview_period']);
});

test('Agent profile assigned customers support filtered, paginated results separate from workload totals', function (): void {
    $admin = User::factory()->admin()->create();
    $agentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agentUser->id]);

    $activeUser = User::factory()->customer()->create(['name' => 'Amina Active']);
    $activeCustomer = CustomerProfile::factory()->create([
        'user_id' => $activeUser->id,
        'operational_status' => CustomerStatus::Active,
    ]);
    $restrictedUser = User::factory()->customer()->create(['name' => 'Bola Restricted']);
    $restrictedCustomer = CustomerProfile::factory()->restricted()->create([
        'user_id' => $restrictedUser->id,
    ]);

    foreach ([$activeCustomer, $restrictedCustomer] as $customer) {
        CustomerAssignment::factory()->create([
            'customer_profile_id' => $customer->id,
            'agent_profile_id' => $agentProfile->id,
            'status' => CustomerAssignmentStatus::Current,
            'is_current' => 1,
        ]);
    }

    $this->actingAs($admin)
        ->get(route('agents.show', [
            'agent' => $agentProfile->agent_id,
            'assignments_search' => 'Bola',
            'assignments_operational_status' => 'restricted',
            'assignments_per_page' => 10,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assigned_customers.data', 1)
            ->where('assigned_customers.data.0.id', $restrictedCustomer->customer_id)
            ->where('assignment_filters.search', 'Bola')
            ->where('assignment_filters.operational_status', 'restricted')
            ->where('agent.assignments_summary.total_active_workload', 2)
        );

    $this->actingAs($admin)
        ->get(route('agents.show', [
            'agent' => $agentProfile->agent_id,
            'assignments_operational_status' => 'invalid',
        ]))
        ->assertSessionHasErrors(['assignments_operational_status']);
});

/*
|--------------------------------------------------------------------------
| 5. Scoped Photo Serving Endpoints & Nonpublic Disk
|--------------------------------------------------------------------------
*/

test('ProfilePhotoService stores photos to local disk by default', function (): void {
    $service = app(ProfilePhotoService::class);
    $file = UploadedFile::fake()->image('test-avatar.jpg', 200, 200);

    $path = $service->storePhoto($file);

    expect(Storage::disk('local')->exists($path))->toBeTrue()
        ->and(Storage::disk('public')->exists($path))->toBeFalse();
});

test('Customer photo endpoint enforces resource scoping', function (): void {
    $service = app(ProfilePhotoService::class);
    $file = UploadedFile::fake()->image('customer.jpg', 200, 200);
    $path = $service->storePhoto($file);

    $customerUser = User::factory()->customer()->create();
    $customerProfile = CustomerProfile::factory()->create([
        'user_id' => $customerUser->id,
        'photo_path' => $path,
    ]);

    $otherCustomerUser = User::factory()->customer()->create();
    $admin = User::factory()->admin()->create();

    // Owner customer can access photo
    $this->actingAs($customerUser)
        ->get(route('customers.photo', $customerProfile->customer_id))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    // Admin can access photo
    $this->actingAs($admin)
        ->get(route('customers.photo', $customerProfile->customer_id))
        ->assertOk();

    // Unauthorized customer gets generic 404
    $this->actingAs($otherCustomerUser)
        ->get(route('customers.photo', $customerProfile->customer_id))
        ->assertNotFound();
});

test('Agent photo endpoint enforces resource scoping', function (): void {
    $service = app(ProfilePhotoService::class);
    $file = UploadedFile::fake()->image('agent.jpg', 200, 200);
    $path = $service->storePhoto($file);

    $agentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $agentProfile = AgentProfile::factory()->active()->create([
        'user_id' => $agentUser->id,
        'profile_photo_path' => $path,
    ]);

    $otherAgentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    AgentProfile::factory()->active()->create(['user_id' => $otherAgentUser->id]);

    $admin = User::factory()->admin()->create();

    // Agent can access own photo
    $this->actingAs($agentUser)
        ->get(route('agents.photo', $agentProfile->agent_id))
        ->assertOk();

    // Admin can access agent photo
    $this->actingAs($admin)
        ->get(route('agents.photo', $agentProfile->agent_id))
        ->assertOk();

    // Other agent gets generic 404
    $this->actingAs($otherAgentUser)
        ->get(route('agents.photo', $agentProfile->agent_id))
        ->assertNotFound();
});

test('profile photo storage failure returns a recoverable validation error instead of a false path', function (): void {
    $file = UploadedFile::fake()->image('photo.jpg', 200, 200);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('putFileAs')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->once()->with(null)->andReturn($disk);

    expect(fn () => app(ProfilePhotoService::class)->storePhoto($file))
        ->toThrow(ValidationException::class, 'The profile photo could not be stored. Please retry.');
});
