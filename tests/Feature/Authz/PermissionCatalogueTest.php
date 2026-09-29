<?php

use App\Enums\AdminPermission;
use App\Models\Permission;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

test('spatie permission configuration matches application security specification', function () {
    expect(config('permission.models.permission'))->toBe(Permission::class);
    expect(config('permission.register_permission_check_method'))->toBeFalse();
    expect(config('permission.teams'))->toBeFalse();
    expect(config('permission.enable_wildcard_permission'))->toBeFalse();
    expect(config('permission.display_permission_in_exception'))->toBeFalse();
    expect(config('permission.display_role_in_exception'))->toBeFalse();
    expect(config('permission.events_enabled'))->toBeTrue();
});

test('permission tables exist with required custom metadata columns', function () {
    expect(Schema::hasTable('permissions'))->toBeTrue();
    expect(Schema::hasTable('roles'))->toBeTrue();
    expect(Schema::hasTable('model_has_permissions'))->toBeTrue();
    expect(Schema::hasTable('model_has_roles'))->toBeTrue();
    expect(Schema::hasTable('role_has_permissions'))->toBeTrue();

    expect(Schema::hasColumns('permissions', [
        'id',
        'name',
        'guard_name',
        'display_name',
        'description',
        'status',
        'introduced_at',
        'retired_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

test('closed permission catalogue is seeded with exactly 14 active permissions', function () {
    $permissions = Permission::query()->where('guard_name', 'web')->get();

    expect($permissions)->toHaveCount(14);

    $expectedEnumValues = AdminPermission::values();
    $dbPermissionNames = $permissions->pluck('name')->all();

    sort($expectedEnumValues);
    sort($dbPermissionNames);

    expect($dbPermissionNames)->toBe($expectedEnumValues);

    foreach ($permissions as $permission) {
        expect($permission->status)->toBe('active');
        expect($permission->isActive())->toBeTrue();
        expect($permission->isRetired())->toBeFalse();
        expect($permission->introduced_at)->not->toBeNull();
        expect($permission->retired_at)->toBeNull();

        $enumCase = AdminPermission::from($permission->name);
        expect($permission->display_name)->toBe($enumCase->displayName());
        expect($permission->description)->toBe($enumCase->description());
    }
});

test('admin permission enum matches section 7.2 specification exactly', function () {
    $expected = [
        'admins.manage' => [
            'displayName' => 'Admin management',
            'description' => "Invite Admins; assign initial Admin permissions; change another Admin's permissions; suspend, reactivate, or deactivate another Admin; manage Admin invitations; and perform the Admin-recovery actions assigned to this permission.",
        ],
        'agents.manage' => [
            'displayName' => 'Agent management',
            'description' => 'Register, invite, update, suspend, reactivate, deactivate, and manage invitation actions for Agents. Agent assisted recovery remains a security operation.',
        ],
        'customers.manage' => [
            'displayName' => 'Customer management',
            'description' => 'Update existing Customer profiles and statuses business-wide and manage existing Customer invitations. It does not allow an Admin to create a Customer.',
        ],
        'customers.reassign' => [
            'displayName' => 'Customer reassignment',
            'description' => 'Reassign a Customer from one Agent to another through the approved reassignment workflow.',
        ],
        'withdrawals.review' => [
            'displayName' => 'Withdrawal approval',
            'description' => 'Review, approve, or reject Customer withdrawal requests. It does not record a collection or bypass withdrawal validation.',
        ],
        'reversals.review' => [
            'displayName' => 'Transaction-reversal approval',
            'description' => 'Review, approve, or reject transaction-reversal requests. It does not silently edit the original transaction.',
        ],
        'fees.manage' => [
            'displayName' => 'Fee management',
            'description' => 'Configure fee rules and perform the Admin fee actions defined by the Fees module. It does not merge fee earnings with Customer liabilities.',
        ],
        'deductions.manage' => [
            'displayName' => 'Deduction management',
            'description' => 'Perform the Admin deduction actions defined by the Deductions module, subject to confirmation and audit rules.',
        ],
        'reconciliation.manage' => [
            'displayName' => 'Reconciliation management',
            'description' => 'Review Agent collection submissions, record reconciliation outcomes, and resolve reconciliation exceptions through the approved workflow.',
        ],
        'financial.periods.manage' => [
            'displayName' => 'Financial period management',
            'description' => 'Open, close, and reopen cash receipt booking months with fresh authentication, a reason, and audit history.',
        ],
        'business.settings.manage' => [
            'displayName' => 'Business configuration',
            'description' => 'Update general and operational business settings. Security-sensitive changes require fresh authentication.',
        ],
        'security.operations.manage' => [
            'displayName' => 'Security operations',
            'description' => 'View non-secret security events, review Customer assisted recovery, manage permitted authentication locks, and perform the Agent security operations assigned to this permission.',
        ],
        'audit.view' => [
            'displayName' => 'Audit-log access',
            'description' => 'Search and view business audit events, subject to masking and export restrictions.',
        ],
        'reports.export' => [
            'displayName' => 'Report export',
            'description' => 'Export business reports and statements containing business-wide or multi-Customer information.',
        ],
    ];

    expect(AdminPermission::cases())->toHaveCount(14);

    foreach (AdminPermission::cases() as $case) {
        expect($expected)->toHaveKey($case->value);
        expect($case->displayName())->toBe($expected[$case->value]['displayName']);
        expect($case->description())->toBe($expected[$case->value]['description']);
    }
});

test('permission model scopes and retirement helpers behave correctly', function () {
    $activePerm = Permission::query()->where('name', AdminPermission::AuditView->value)->firstOrFail();
    expect($activePerm->isActive())->toBeTrue();
    expect($activePerm->isRetired())->toBeFalse();

    // Create a temporary retired permission to test scopes
    $retiredPerm = Permission::create([
        'name' => 'test.retired.permission',
        'guard_name' => 'web',
        'display_name' => 'Test Retired',
        'description' => 'Test description',
        'status' => 'retired',
        'introduced_at' => Carbon::now()->subYear(),
        'retired_at' => Carbon::now(),
    ]);

    expect($retiredPerm->isActive())->toBeFalse();
    expect($retiredPerm->isRetired())->toBeTrue();
    expect($retiredPerm->introduced_at)->toBeInstanceOf(CarbonInterface::class);
    expect($retiredPerm->retired_at)->toBeInstanceOf(CarbonInterface::class);

    $activeList = Permission::active()->pluck('name')->all();
    expect($activeList)->toContain(AdminPermission::AuditView->value);
    expect($activeList)->not->toContain('test.retired.permission');

    $retiredList = Permission::retired()->pluck('name')->all();
    expect($retiredList)->toContain('test.retired.permission');
    expect($retiredList)->not->toContain(AdminPermission::AuditView->value);

    // Test markRetired helper
    $retiredPerm->update(['status' => 'active', 'retired_at' => null]);
    expect($retiredPerm->fresh()->isActive())->toBeTrue();

    $retiredPerm->markRetired();
    expect($retiredPerm->fresh()->isRetired())->toBeTrue();
    expect($retiredPerm->fresh()->retired_at)->not->toBeNull();

    $retiredPerm->delete();
});

test('user model incorporates has roles trait and interacts with permission model', function () {
    expect(in_array(HasRoles::class, class_uses_recursive(User::class), true))->toBeTrue();

    $user = User::factory()->admin()->create();

    expect($user->permissions)->toBeEmpty();
    expect($user->roles)->toHaveCount(1);
    expect($user->hasRole('admin'))->toBeTrue();

    $permission = Permission::query()->where('name', AdminPermission::AdminsManage->value)->firstOrFail();
    $user->givePermissionTo($permission);

    expect($user->hasDirectPermission($permission->name))->toBeTrue();
    $this->assertDatabaseHas('model_has_permissions', [
        'permission_id' => $permission->id,
        'model_id' => $user->id,
        'model_type' => User::class,
    ]);

    $user->revokePermissionTo($permission);
    expect($user->hasDirectPermission($permission->name))->toBeFalse();
});

test('permission cache can be forgotten without error', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(true)->toBeTrue();
});
