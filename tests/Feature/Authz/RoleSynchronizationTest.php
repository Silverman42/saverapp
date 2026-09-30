<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\Permission;
use App\Models\PermissionGrantHistory;
use App\Models\User;
use App\Services\RoleSynchronizationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

test('fixed role catalogue contains exactly three web roles and role_has_permissions is empty', function () {
    $roles = Role::where('guard_name', 'web')->get();

    expect($roles)->toHaveCount(3);

    $expectedRoleNames = [UserType::Customer->value, UserType::Agent->value, UserType::Admin->value];
    $dbRoleNames = $roles->pluck('name')->all();

    sort($expectedRoleNames);
    sort($dbRoleNames);

    expect($dbRoleNames)->toBe($expectedRoleNames);

    // Verify role_has_permissions pivot is completely empty
    expect(DB::table('role_has_permissions')->count())->toBe(0);
});

test('creating user automatically assigns exactly one matching Spatie role', function () {
    $customer = User::factory()->customer()->create();
    $agent = User::factory()->agent()->create();
    $admin = User::factory()->admin()->create();

    expect($customer->roles)->toHaveCount(1)
        ->and($customer->hasRole(UserType::Customer->value))->toBeTrue()
        ->and($customer->roles->first()->name)->toBe('customer');

    expect($agent->roles)->toHaveCount(1)
        ->and($agent->hasRole(UserType::Agent->value))->toBeTrue()
        ->and($agent->roles->first()->name)->toBe('agent');

    expect($admin->roles)->toHaveCount(1)
        ->and($admin->hasRole(UserType::Admin->value))->toBeTrue()
        ->and($admin->roles->first()->name)->toBe('admin');
});

test('ordinary newly created admins receive zero granular permissions', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->permissions)->toHaveCount(0)
        ->and($admin->hasRole('admin'))->toBeTrue()
        ->and(DB::table('model_has_permissions')->where('model_id', $admin->id)->count())->toBe(0);
});

test('changing user_type on existing user is rejected without modifying user or role pivots', function () {
    $customer = User::factory()->customer()->create();
    $originalUserType = $customer->user_type;

    expect(function () use ($customer) {
        $customer->user_type = UserType::Admin;
        $customer->save();
    })->toThrow(RuntimeException::class, 'Cannot change user_type on an existing user; roles are immutable.');

    $refreshed = $customer->fresh();
    expect($refreshed->user_type)->toBe($originalUserType)
        ->and($refreshed->roles)->toHaveCount(1)
        ->and($refreshed->hasRole('customer'))->toBeTrue()
        ->and($refreshed->hasRole('admin'))->toBeFalse();
});

test('EnsureUserType middleware permits synchronized users and fails closed on missing, mismatched, or additional roles', function () {
    $customer = User::factory()->customer()->create();

    // 1. Synchronized customer accessing customer dashboard succeeds
    $this->actingAs($customer)
        ->get(route('customer.dashboard'))
        ->assertOk();

    // 2. Synchronized customer accessing agent or admin dashboard fails (403)
    $this->actingAs($customer)
        ->get(route('agent.dashboard'))
        ->assertForbidden();

    $this->actingAs($customer)
        ->get(route('admin.dashboard'))
        ->assertForbidden();

    // 3. User with missing role fails (403)
    $customer->roles()->detach();
    $customer->unsetRelation('roles');
    expect($customer->fresh()->roles)->toHaveCount(0);

    $this->actingAs($customer)
        ->get(route('customer.dashboard'))
        ->assertForbidden();

    // 4. User with mismatched role fails (403)
    $customer->assignRole('agent');
    expect($customer->fresh()->roles->pluck('name')->all())->toBe(['agent']);

    $this->actingAs($customer)
        ->get(route('customer.dashboard'))
        ->assertForbidden();

    // 5. User with additional role drift fails (403)
    $customer->assignRole('customer');
    expect($customer->fresh()->roles)->toHaveCount(2);

    $this->actingAs($customer)
        ->get(route('customer.dashboard'))
        ->assertForbidden();
});

test('bootstrapAdmin selects earliest active admin and grants the catalogue with shared system_seed batch history', function () {
    $service = app(RoleSynchronizationService::class);

    // Create older inactive admin
    $inactiveAdmin = User::factory()->admin()->create([
        'account_state' => AccountState::Suspended,
        'created_at' => Carbon::now()->subDays(10),
    ]);

    // Create earliest active admin
    $earliestAdmin = User::factory()->admin()->create([
        'account_state' => AccountState::Active,
        'created_at' => Carbon::now()->subDays(5),
    ]);

    // Create later active admin
    $laterAdmin = User::factory()->admin()->create([
        'account_state' => AccountState::Active,
        'created_at' => Carbon::now()->subDays(1),
    ]);

    $bootstrapped = $service->bootstrapAdmin();

    expect($bootstrapped->id)->toBe($earliestAdmin->id);

    expect($earliestAdmin->permissions)->toHaveCount(count(AdminPermission::bootstrapValues()));
    $grantedNames = $earliestAdmin->permissions->pluck('name')->all();
    sort($grantedNames);
    $expectedNames = AdminPermission::bootstrapValues();
    sort($expectedNames);
    expect($grantedNames)->toBe($expectedNames);

    // Verify other admins have 0 permissions
    expect($inactiveAdmin->fresh()->permissions)->toHaveCount(0)
        ->and($laterAdmin->fresh()->permissions)->toHaveCount(0);

    // Verify append-only history records share exactly one batch_id and system_seed source
    $histories = PermissionGrantHistory::where('user_id', $earliestAdmin->id)->get();
    expect($histories)->toHaveCount(count(AdminPermission::bootstrapValues()));

    $batchIds = $histories->pluck('batch_id')->unique();
    expect($batchIds)->toHaveCount(1)
        ->and(Str::isUuid($batchIds->first()))->toBeTrue();

    foreach ($histories as $history) {
        expect($history->action)->toBe('grant')
            ->and($history->source)->toBe('system_seed')
            ->and($history->actor_user_id)->toBeNull()
            ->and($history->permission_version)->toBe(1);
    }
});

test('bootstrapAdmin is idempotent and rejects non-active or non-admin targets', function () {
    $service = app(RoleSynchronizationService::class);

    $admin = User::factory()->admin()->create([
        'account_state' => AccountState::Active,
    ]);

    $service->bootstrapAdmin($admin);
    expect($admin->permissions)->toHaveCount(count(AdminPermission::bootstrapValues()));
    $initialHistoryCount = PermissionGrantHistory::where('user_id', $admin->id)->count();
    expect($initialHistoryCount)->toBe(count(AdminPermission::bootstrapValues()));

    // Call bootstrapAdmin again: must be idempotent, no duplicate grants or history records
    $service->bootstrapAdmin($admin);
    expect($admin->permissions)->toHaveCount(count(AdminPermission::bootstrapValues()))
        ->and(PermissionGrantHistory::where('user_id', $admin->id)->count())->toBe(count(AdminPermission::bootstrapValues()));

    // Rejects non-admin targets
    $customer = User::factory()->customer()->create();
    expect(fn () => $service->bootstrapAdmin($customer))
        ->toThrow(InvalidArgumentException::class, 'Target user must be an Administrator.');

    // Rejects non-active targets
    $suspendedAdmin = User::factory()->admin()->create([
        'account_state' => AccountState::Suspended,
    ]);
    expect(fn () => $service->bootstrapAdmin($suspendedAdmin))
        ->toThrow(InvalidArgumentException::class, 'Target user must be an active Administrator.');
});

test('bootstrapAdmin returns null when no active admin exists', function () {
    $service = app(RoleSynchronizationService::class);

    User::where('user_type', UserType::Admin->value)->delete();

    expect($service->bootstrapAdmin())->toBeNull();
});

test('bootstrap never grants cash execution and preserves separately delegated cash authority', function (): void {
    $admin = User::factory()->admin()->create();
    $service = app(RoleSynchronizationService::class);
    $service->bootstrapAdmin($admin);
    expect($admin->hasDirectPermission(AdminPermission::CashExecute))->toBeFalse();
    $admin->givePermissionTo(AdminPermission::CashExecute);
    $service->bootstrapAdmin($admin);
    expect($admin->fresh()->hasDirectPermission(AdminPermission::CashExecute))->toBeTrue()
        ->and(PermissionGrantHistory::query()->where('user_id', $admin->id)->where('source', 'system_seed')
            ->where('permission_code', AdminPermission::CashExecute->value)->exists())->toBeFalse();
});

test('migration aborts and rolls back on unexpected pre-existing roles', function () {
    $migration = require database_path('migrations/2026_09_20_224000_create_fixed_roles_and_bootstrap_admin_grants.php');

    Role::create(['name' => 'super-admin', 'guard_name' => 'web']);

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'Unexpected pre-existing roles detected in roles table: super-admin');

    // Clean up
    Role::where('name', 'super-admin')->delete();
});

test('migration aborts and rolls back on invalid existing user_type', function () {
    $migration = require database_path('migrations/2026_09_20_224000_create_fixed_roles_and_bootstrap_admin_grants.php');

    DB::table('users')->insert([
        'name' => 'Invalid User',
        'email' => 'invalid@example.test',
        'email_normalized' => 'invalid@example.test',
        'password' => 'secret',
        'user_type' => 'manager',
        'account_state' => 'active',
        'authenticator_state' => 'unconfigured',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'Invalid user_type detected on existing users');

    DB::table('users')->where('email', 'invalid@example.test')->delete();
});

test('migration aborts and rolls back on conflicting existing role assignments', function () {
    $migration = require database_path('migrations/2026_09_20_224000_create_fixed_roles_and_bootstrap_admin_grants.php');

    $user = User::factory()->customer()->create();

    // Assign multiple roles to create conflict
    $user->assignRole('agent');
    expect($user->roles)->toHaveCount(2);

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'Conflicting existing role assignments: users with multiple roles detected');
});

test('authz:check-role-sync succeeds on synchronized database and does not mutate data', function () {
    $admin = User::factory()->admin()->create(['account_state' => AccountState::Active]);
    app(RoleSynchronizationService::class)->bootstrapAdmin($admin);

    $usersCountBefore = User::count();
    $rolesCountBefore = Role::count();
    $permissionsCountBefore = Permission::count();
    $grantsCountBefore = DB::table('model_has_permissions')->count();
    $historiesCountBefore = PermissionGrantHistory::count();

    $this->artisan('authz:check-role-sync')
        ->expectsOutputToContain('Role synchronization and authorization invariants are valid.')
        ->assertSuccessful();

    // Confirm no mutations occurred
    expect(User::count())->toBe($usersCountBefore)
        ->and(Role::count())->toBe($rolesCountBefore)
        ->and(Permission::count())->toBe($permissionsCountBefore)
        ->and(DB::table('model_has_permissions')->count())->toBe($grantsCountBefore)
        ->and(PermissionGrantHistory::count())->toBe($historiesCountBefore);
});

test('authz:check-role-sync reports drift for unexpected roles, missing roles, or empty pivot violations', function () {
    // Insert an unexpected role
    DB::table('roles')->insert([
        'name' => 'auditor',
        'guard_name' => 'web',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('authz:check-role-sync')
        ->expectsOutputToContain('Role synchronization drift detected')
        ->expectsOutputToContain("Unexpected role 'auditor' with guard 'web' detected in roles table.")
        ->assertFailed();

    DB::table('roles')->where('name', 'auditor')->delete();

    // Violate empty pivot
    $adminRole = Role::findByName('admin', 'web');
    $firstPerm = Permission::first();
    DB::table('role_has_permissions')->insert([
        'permission_id' => $firstPerm->id,
        'role_id' => $adminRole->id,
    ]);

    $this->artisan('authz:check-role-sync')
        ->expectsOutputToContain('role_has_permissions pivot is not empty')
        ->assertFailed();

    DB::table('role_has_permissions')->delete();
});

test('authz:check-role-sync reports drift for non-admin direct grants or bootstrap provenance issues', function () {
    $customer = User::factory()->customer()->create();
    $firstPerm = Permission::first();

    // Illegally grant a direct permission to a Customer
    DB::table('model_has_permissions')->insert([
        'permission_id' => $firstPerm->id,
        'model_type' => User::class,
        'model_id' => $customer->id,
    ]);

    $this->artisan('authz:check-role-sync')
        ->expectsOutputToContain("Non-admin user #{$customer->id} (customer) has direct grant '{$firstPerm->name}'.")
        ->assertFailed();

    DB::table('model_has_permissions')->where('model_id', $customer->id)->delete();
});
