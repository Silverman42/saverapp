<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\CustomerAssignmentStatus;
use App\Enums\FeeRuleModel;
use App\Enums\UserType;
use App\Models\AgentProfile;
use App\Models\AuditEvent;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\User;
use App\Services\RegistrationFeeService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['admin', 'agent', 'customer'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    }

    foreach ([AdminPermission::FeesManage, AdminPermission::CustomersManage] as $permission) {
        Permission::firstOrCreate(['name' => $permission->value, 'guard_name' => 'web'], ['status' => 'active']);
    }
});

function masterProfileAgent(): User
{
    $agent = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => now(),
    ]);
    $agent->assignRole(UserType::Agent->value);
    AgentProfile::factory()->active()->create(['user_id' => $agent->id]);

    return $agent;
}

function masterProfileCustomerFor(User $agent, array $attributes = []): CustomerProfile
{
    $customer = CustomerProfile::factory()->create($attributes);
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customer->id,
        'agent_profile_id' => $agent->agentProfile->id,
        'assigned_by_user_id' => $agent->id,
        'status' => CustomerAssignmentStatus::Current,
    ]);

    return $customer;
}

function masterProfilePublishFeeRule(): void
{
    $admin = User::factory()->admin()->create();
    $admin->assignRole(UserType::Admin->value);
    $admin->givePermissionTo(AdminPermission::FeesManage->value);

    $now = now()->timestamp;
    $request = Request::create(route('admin.fees.registration.store'), 'POST');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put([
        'auth.password_confirmed_at' => $now,
        'auth.mfa_confirmed_at' => $now,
        'auth.fresh_until' => $now + 600,
    ]);

    $feeService = app(RegistrationFeeService::class);
    $data = [
        'name' => 'Registration Fee',
        'model' => FeeRuleModel::NoFee,
        'amount_kobo' => 0,
        'customer_description' => 'No registration fee.',
        'publication_reason' => 'Test setup.',
        'confirmed' => true,
    ];
    $data['preview_fingerprint'] = $feeService->previewPublication($admin, $data)['preview_fingerprint'];

    $feeService->publishRule($admin, $data, $request);
}

/**
 * @return array<string, mixed>
 */
function masterProfileRegistrationPayload(?string $masterCustomerId): array
{
    return [
        'attempt_reference' => (string) Str::uuid(),
        'fee_rule_version' => 1,
        'name' => 'Ada Slave',
        'offline_only' => true,
        'phone' => '+2348031112222',
        'master_customer_id' => $masterCustomerId,
    ];
}

test('an agent registers a customer under one of their master accounts', function (): void {
    masterProfilePublishFeeRule();
    $agent = masterProfileAgent();
    $master = masterProfileCustomerFor($agent);

    $this->actingAs($agent)
        ->post(route('customers.store'), masterProfileRegistrationPayload($master->customer_id))
        ->assertSessionHasNoErrors();

    $slave = CustomerProfile::query()->where('id', '!=', $master->id)->sole();

    expect($slave->master_customer_profile_id)->toBe($master->id)
        ->and($master->slaves()->pluck('id')->all())->toBe([$slave->id])
        ->and(AuditEvent::query()->where('event_type', 'customer.registered')->sole()->payload['master_customer_id'])->toBe($master->customer_id);
});

test('a customer registered without a master account is a master account', function (): void {
    masterProfilePublishFeeRule();
    $agent = masterProfileAgent();

    $this->actingAs($agent)
        ->post(route('customers.store'), masterProfileRegistrationPayload(null))
        ->assertSessionHasNoErrors();

    expect(CustomerProfile::query()->sole()->isMaster())->toBeTrue();
});

test('registration rejects master accounts that are slaves, archived or assigned to another agent', function (string $case): void {
    masterProfilePublishFeeRule();
    $agent = masterProfileAgent();
    $master = match ($case) {
        'slave' => masterProfileCustomerFor($agent, ['master_customer_profile_id' => masterProfileCustomerFor($agent)->id]),
        'archived' => masterProfileCustomerFor($agent, ['operational_status' => 'archived']),
        'other agent' => masterProfileCustomerFor(masterProfileAgent()),
    };
    $customerCount = CustomerProfile::query()->count();

    $this->actingAs($agent)
        ->post(route('customers.store'), masterProfileRegistrationPayload($master->customer_id))
        ->assertSessionHasErrors('master_customer_id');

    expect(CustomerProfile::query()->count())->toBe($customerCount);
})->with(['slave', 'archived', 'other agent']);

test('master candidates only include the agent\'s own non-archived master accounts', function (): void {
    $agent = masterProfileAgent();
    $master = masterProfileCustomerFor($agent);
    $master->user->update(['name' => 'Chidi Master']);
    $otherMaster = masterProfileCustomerFor($agent);
    masterProfileCustomerFor($agent, ['master_customer_profile_id' => $master->id]);
    masterProfileCustomerFor($agent, ['operational_status' => 'archived']);
    masterProfileCustomerFor(masterProfileAgent());

    $this->actingAs($agent)
        ->getJson(route('customers.master-candidates'))
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($agent)
        ->getJson(route('customers.master-candidates', ['search' => 'Chidi']))
        ->assertOk()
        ->assertExactJson(['data' => [['id' => $master->customer_id, 'name' => 'Chidi Master', 'phone' => $master->phone]]]);

    $this->actingAs($agent)
        ->getJson(route('customers.master-candidates', ['exclude' => $master->customer_id]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $otherMaster->customer_id)
        ->assertJsonCount(1, 'data');
});

test('non-agents cannot search master candidates', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole(UserType::Admin->value);

    $this->actingAs($admin)->getJson(route('customers.master-candidates'))->assertForbidden();
});

test('the assigned agent can attach, change and clear a customer\'s master account', function (): void {
    $agent = masterProfileAgent();
    $firstMaster = masterProfileCustomerFor($agent);
    $secondMaster = masterProfileCustomerFor($agent);
    $customer = masterProfileCustomerFor($agent);

    $this->actingAs($agent)
        ->patch(route('customers.update', $customer->customer_id), ['version' => 1, 'master_customer_id' => $firstMaster->customer_id])
        ->assertSessionHasNoErrors();
    expect($customer->refresh()->master_customer_profile_id)->toBe($firstMaster->id);

    $this->actingAs($agent)
        ->patch(route('customers.update', $customer->customer_id), ['version' => 2, 'master_customer_id' => $secondMaster->customer_id])
        ->assertSessionHasNoErrors();
    expect($customer->refresh()->master_customer_profile_id)->toBe($secondMaster->id);

    $this->actingAs($agent)
        ->patch(route('customers.update', $customer->customer_id), ['version' => 3, 'master_customer_id' => null])
        ->assertSessionHasNoErrors();
    expect($customer->refresh()->isMaster())->toBeTrue()
        ->and($customer->version)->toBe(4);
});

test('a master account with slaves cannot be attached to another master', function (): void {
    $agent = masterProfileAgent();
    $master = masterProfileCustomerFor($agent);
    masterProfileCustomerFor($agent, ['master_customer_profile_id' => $master->id]);
    $otherMaster = masterProfileCustomerFor($agent);

    $this->actingAs($agent)
        ->patch(route('customers.update', $master->customer_id), ['version' => 1, 'master_customer_id' => $otherMaster->customer_id])
        ->assertSessionHasErrors('master_customer_id');

    expect($master->refresh()->isMaster())->toBeTrue();
});

test('only the assigned agent can change a customer\'s master account', function (): void {
    $agent = masterProfileAgent();
    $master = masterProfileCustomerFor($agent);
    $customer = masterProfileCustomerFor($agent);

    $admin = User::factory()->admin()->create();
    $admin->assignRole(UserType::Admin->value);
    $admin->givePermissionTo(AdminPermission::CustomersManage->value);

    $this->actingAs($admin)
        ->patch(route('customers.update', $customer->customer_id), ['version' => 1, 'master_customer_id' => $master->customer_id])
        ->assertSessionHasErrors();

    $this->actingAs(masterProfileAgent())
        ->patch(route('customers.update', $customer->customer_id), ['version' => 1, 'master_customer_id' => $master->customer_id])
        ->assertNotFound();

    expect($customer->refresh()->isMaster())->toBeTrue();
});

test('the customer profile page shows the master account and its sub-accounts', function (): void {
    $agent = masterProfileAgent();
    $master = masterProfileCustomerFor($agent);
    $slave = masterProfileCustomerFor($agent, ['master_customer_profile_id' => $master->id]);

    $this->actingAs($agent)
        ->get(route('customers.show', $master->customer_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('customer.linked_accounts.is_master', true)
            ->where('customer.linked_accounts.master', null)
            ->has('customer.linked_accounts.slaves', 1)
            ->where('customer.linked_accounts.slaves.0.id', $slave->customer_id)
            ->where('customer.linked_accounts.slaves.0.can_view', true)
        );

    $this->actingAs($agent)
        ->get(route('customers.show', $slave->customer_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('customer.linked_accounts.is_master', false)
            ->where('customer.linked_accounts.master.id', $master->customer_id)
            ->has('customer.linked_accounts.slaves', 0)
        );
});
