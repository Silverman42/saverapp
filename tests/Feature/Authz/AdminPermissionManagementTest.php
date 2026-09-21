<?php

use App\Enums\AdminPermission;
use App\Enums\AuthorizationRestrictionType;
use App\Models\Permission;
use App\Models\PermissionGrantHistory;
use App\Models\User;
use App\Services\AuthorizationRestrictionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('guest is redirected to login from admin access endpoints', function () {
    $this->get(route('admin.access.index'))->assertRedirect(route('login'));
    $this->get('/admin/access/1')->assertRedirect(route('login'));
    $this->put('/admin/access/1/permissions')->assertRedirect(route('login'));
});

test('customers and agents are denied from admin access endpoints with 403', function () {
    $customer = User::factory()->customer()->create();
    $agent = User::factory()->agent()->withTwoFactor()->create();

    $this->actingAs($customer)->get(route('admin.access.index'))->assertForbidden();
    $this->actingAs($agent)->get(route('admin.access.index'))->assertForbidden();
});

test('baseline admin without admins.manage can view directory with concise summaries', function () {
    $currentAdmin = User::factory()->admin()->withTwoFactor()->create();
    $otherAdmin = User::factory()->admin()->withTwoFactor()->create();
    $otherAdmin->givePermissionTo(AdminPermission::AuditView->value);

    $this->actingAs($currentAdmin)
        ->get(route('admin.access.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/access/Index')
            ->where('canManage', false)
            ->has('admins.data', 2)
            ->where('admins.data.0.can_manage', false),
        );
});

test('authorized admin with admins.manage can view directory with full access management flags', function () {
    $currentAdmin = User::factory()->admin()->withTwoFactor()->create(['name' => 'Alice Admin']);
    $currentAdmin->givePermissionTo(AdminPermission::AdminsManage->value);

    $otherAdmin = User::factory()->admin()->withTwoFactor()->create(['name' => 'Bob Admin']);

    $this->actingAs($currentAdmin)
        ->get(route('admin.access.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/access/Index')
            ->where('canManage', true)
            ->where('admins.data.0.can_manage', false) // Alice (self) cannot be managed
            ->where('admins.data.1.can_manage', true), // Bob (other) can be managed
        );
});

test('Admin access directory and permission history support server-side filters', function (): void {
    $manager = User::factory()->admin()->withTwoFactor()->create([
        'name' => 'Directory Manager',
        'email' => 'manager@example.com',
    ]);
    $manager->givePermissionTo(AdminPermission::AdminsManage->value);

    $matchingAdmin = User::factory()->admin()->withTwoFactor()->create([
        'name' => 'Ada Directory',
        'email' => 'ada@example.com',
    ]);
    $otherAdmin = User::factory()->admin()->withTwoFactor()->create([
        'name' => 'Bola Other',
        'email' => 'bola@example.com',
    ]);

    PermissionGrantHistory::create([
        'batch_id' => (string) Str::uuid(),
        'user_id' => $matchingAdmin->id,
        'permission_code' => AdminPermission::AuditView->value,
        'action' => 'grant',
        'source' => 'manual',
        'actor_user_id' => $manager->id,
        'reason' => 'Audit access approved',
        'permission_version' => 1,
    ]);
    PermissionGrantHistory::create([
        'batch_id' => (string) Str::uuid(),
        'user_id' => $matchingAdmin->id,
        'permission_code' => AdminPermission::ReportsExport->value,
        'action' => 'revoke',
        'source' => 'manual',
        'actor_user_id' => $otherAdmin->id,
        'reason' => 'Access review',
        'permission_version' => 2,
    ]);

    $this->actingAs($manager)
        ->get(route('admin.access.index', [
            'search' => 'ada@example.com',
            'account_state' => 'active',
            'per_page' => 15,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('admins.data', 1)
            ->where('admins.data.0.id', $matchingAdmin->id)
            ->where('filters.search', 'ada@example.com')
            ->where('filters.account_state', 'active')
        );

    $this->actingAs($manager)
        ->get(route('admin.access.show', [
            'admin' => $matchingAdmin->id,
            'history_search' => 'Directory Manager',
            'history_action' => 'grant',
            'history_per_page' => 10,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('history.data', 1)
            ->where('history.data.0.permission_code', AdminPermission::AuditView->value)
            ->where('history_filters.search', 'Directory Manager')
            ->where('history_filters.action', 'grant')
        );

    $this->actingAs($manager)
        ->get(route('admin.access.index', ['account_state' => 'invalid']))
        ->assertSessionHasErrors(['account_state']);
});

test('admin access show returns 404 for non-admin user target', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::AdminsManage->value);
    $customer = User::factory()->customer()->create();

    $this->actingAs($admin)
        ->get(route('admin.access.show', $customer->id))
        ->assertNotFound();
});

test('any active admin can view their own permissions with self-management disabled', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::ReportsExport->value);

    $this->actingAs($admin)
        ->get(route('admin.access.show', $admin->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/access/Show')
            ->where('isSelf', true)
            ->where('canManage', false)
            ->where('admin.id', $admin->id)
            ->where('admin.direct_permissions', [AdminPermission::ReportsExport->value]),
        );
});

test('baseline admin without admins.manage cannot view another admin details', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $otherAdmin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->get(route('admin.access.show', $otherAdmin->id))
        ->assertForbidden();
});

test('admin with admins.manage can view another admin details, catalogue, and history', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::AdminsManage->value);

    $otherAdmin = User::factory()->admin()->withTwoFactor()->create();
    $otherAdmin->givePermissionTo(AdminPermission::AuditView->value);

    // Record seed history
    PermissionGrantHistory::create([
        'batch_id' => (string) Str::uuid(),
        'user_id' => $otherAdmin->id,
        'permission_code' => AdminPermission::AuditView->value,
        'action' => 'grant',
        'source' => 'system_seed',
        'actor_user_id' => null,
        'reason' => 'Initial setup',
        'permission_version' => 1,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.access.show', $otherAdmin->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/access/Show')
            ->where('isSelf', false)
            ->where('canManage', true)
            ->where('admin.id', $otherAdmin->id)
            ->has('catalogue', 13)
            ->has('history.data', 1),
        );
});

test('admin under post-recovery restriction cannot view other admin details or manage permissions', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::AdminsManage->value);

    // Apply PostRecoveryAdminManagement restriction
    app(AuthorizationRestrictionService::class)->apply(
        target: $admin,
        type: AuthorizationRestrictionType::PostRecoveryAdminManagement,
        source: 'assisted_recovery',
    );

    $otherAdmin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->get(route('admin.access.show', $otherAdmin->id))
        ->assertForbidden();
});

test('self-management is prohibited when updating permissions', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::AdminsManage->value);

    $this->actingAs($admin)
        ->withSession([
            'auth.fresh_until' => Carbon::now()->timestamp + 600,
            'auth.password_confirmed_at' => Carbon::now()->timestamp,
            'auth.mfa_confirmed_at' => Carbon::now()->timestamp,
        ])
        ->put(route('admin.access.permissions.update', $admin->id), [
            'permissions' => [AdminPermission::AdminsManage->value, AdminPermission::AuditView->value],
            'reason' => 'Attempting self grant',
            'expected_permission_version' => $admin->permission_version,
            'confirmed' => true,
        ])
        ->assertForbidden();
});

test('permission update requires unexpired fresh authentication', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::AdminsManage->value);

    $otherAdmin = User::factory()->admin()->withTwoFactor()->create();

    // Stale/missing fresh authentication redirects to fresh-authentication
    $this->actingAs($admin)
        ->put(route('admin.access.permissions.update', $otherAdmin->id), [
            'permissions' => [AdminPermission::AuditView->value],
            'reason' => 'Missing fresh auth',
            'expected_permission_version' => $otherAdmin->permission_version,
            'confirmed' => true,
        ])
        ->assertRedirect(route('fresh-authentication'));
});

test('validation rejects empty reason, length over 500, and unconfirmed submission', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::AdminsManage->value);
    $otherAdmin = User::factory()->admin()->withTwoFactor()->create();

    $session = [
        'auth.fresh_until' => Carbon::now()->timestamp + 600,
        'auth.password_confirmed_at' => Carbon::now()->timestamp,
        'auth.mfa_confirmed_at' => Carbon::now()->timestamp,
    ];

    // Empty reason
    $this->actingAs($admin)
        ->withSession($session)
        ->put(route('admin.access.permissions.update', $otherAdmin->id), [
            'permissions' => [AdminPermission::AuditView->value],
            'reason' => '',
            'expected_permission_version' => $otherAdmin->permission_version,
            'confirmed' => true,
        ])
        ->assertSessionHasErrors('reason');

    // Reason > 500 characters
    $this->actingAs($admin)
        ->withSession($session)
        ->put(route('admin.access.permissions.update', $otherAdmin->id), [
            'permissions' => [AdminPermission::AuditView->value],
            'reason' => str_repeat('a', 501),
            'expected_permission_version' => $otherAdmin->permission_version,
            'confirmed' => true,
        ])
        ->assertSessionHasErrors('reason');

    // Unconfirmed submission
    $this->actingAs($admin)
        ->withSession($session)
        ->put(route('admin.access.permissions.update', $otherAdmin->id), [
            'permissions' => [AdminPermission::AuditView->value],
            'reason' => 'Valid reason',
            'expected_permission_version' => $otherAdmin->permission_version,
            'confirmed' => false,
        ])
        ->assertSessionHasErrors('confirmed');
});

test('validation rejects unknown, retired, and duplicate permission codes', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::AdminsManage->value);
    $otherAdmin = User::factory()->admin()->withTwoFactor()->create();

    $session = [
        'auth.fresh_until' => Carbon::now()->timestamp + 600,
        'auth.password_confirmed_at' => Carbon::now()->timestamp,
        'auth.mfa_confirmed_at' => Carbon::now()->timestamp,
    ];

    // Duplicate permission codes
    $this->actingAs($admin)
        ->withSession($session)
        ->put(route('admin.access.permissions.update', $otherAdmin->id), [
            'permissions' => [AdminPermission::AuditView->value, AdminPermission::AuditView->value],
            'reason' => 'Assigning duplicates',
            'expected_permission_version' => $otherAdmin->permission_version,
            'confirmed' => true,
        ])
        ->assertSessionHasErrors('permissions.1');

    // Unknown permission code
    $this->actingAs($admin)
        ->withSession($session)
        ->put(route('admin.access.permissions.update', $otherAdmin->id), [
            'permissions' => ['invalid.unknown.permission'],
            'reason' => 'Assigning unknown',
            'expected_permission_version' => $otherAdmin->permission_version,
            'confirmed' => true,
        ])
        ->assertSessionHasErrors('permissions');

    // Retired permission code
    $retired = Permission::where('name', AdminPermission::ReportsExport->value)->first();
    $retired->markRetired();

    $this->actingAs($admin)
        ->withSession($session)
        ->put(route('admin.access.permissions.update', $otherAdmin->id), [
            'permissions' => [AdminPermission::ReportsExport->value],
            'reason' => 'Assigning retired',
            'expected_permission_version' => $otherAdmin->permission_version,
            'confirmed' => true,
        ])
        ->assertSessionHasErrors('permissions');

    // Restore for other tests
    $retired->update(['status' => 'active', 'retired_at' => null]);
});

test('validation rejects no-op submissions when no permissions change', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::AdminsManage->value);

    $otherAdmin = User::factory()->admin()->withTwoFactor()->create();
    $otherAdmin->givePermissionTo(AdminPermission::AuditView->value);

    $session = [
        'auth.fresh_until' => Carbon::now()->timestamp + 600,
        'auth.password_confirmed_at' => Carbon::now()->timestamp,
        'auth.mfa_confirmed_at' => Carbon::now()->timestamp,
    ];

    $this->actingAs($admin)
        ->withSession($session)
        ->put(route('admin.access.permissions.update', $otherAdmin->id), [
            'permissions' => [AdminPermission::AuditView->value], // Exact same permission
            'reason' => 'Submitting no change',
            'expected_permission_version' => $otherAdmin->permission_version,
            'confirmed' => true,
        ])
        ->assertSessionHasErrors('permissions');
});

test('optimistic concurrency rejects stale expected_permission_version', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::AdminsManage->value);

    $otherAdmin = User::factory()->admin()->withTwoFactor()->create();

    $session = [
        'auth.fresh_until' => Carbon::now()->timestamp + 600,
        'auth.password_confirmed_at' => Carbon::now()->timestamp,
        'auth.mfa_confirmed_at' => Carbon::now()->timestamp,
    ];

    // Stale version (target is at version 1, passing version 0)
    $this->actingAs($admin)
        ->withSession($session)
        ->put(route('admin.access.permissions.update', $otherAdmin->id), [
            'permissions' => [AdminPermission::AuditView->value],
            'reason' => 'Concurrency test',
            'expected_permission_version' => $otherAdmin->permission_version - 1,
            'confirmed' => true,
        ])
        ->assertSessionHasErrors('expected_permission_version');
});

test('final-capable-admin safeguard blocks revoking admins.manage from sole capable holder', function () {
    $actingAdmin = User::factory()->admin()->withTwoFactor()->create();
    $actingAdmin->givePermissionTo(AdminPermission::AdminsManage->value);

    $targetAdmin = User::factory()->admin()->withTwoFactor()->create();
    $targetAdmin->givePermissionTo(AdminPermission::AdminsManage->value);

    // If actingAdmin gets restricted right before this attempt, targetAdmin is the ONLY capable holder
    app(AuthorizationRestrictionService::class)->apply(
        target: $actingAdmin,
        type: AuthorizationRestrictionType::PostRecoveryAdminManagement,
        source: 'test_restriction',
    );

    $session = [
        'auth.fresh_until' => Carbon::now()->timestamp + 600,
        'auth.password_confirmed_at' => Carbon::now()->timestamp,
        'auth.mfa_confirmed_at' => Carbon::now()->timestamp,
    ];

    // The actingAdmin is restricted from managing admins, so they receive 403
    $this->actingAs($actingAdmin)
        ->withSession($session)
        ->put(route('admin.access.permissions.update', $targetAdmin->id), [
            'permissions' => [],
            'reason' => 'Revoke admins.manage',
            'expected_permission_version' => $targetAdmin->permission_version,
            'confirmed' => true,
        ])
        ->assertForbidden();
});

test('atomic permission grant updates Spatie pivots, history, and version once', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::AdminsManage->value);

    $target = User::factory()->admin()->withTwoFactor()->create();
    $initialVersion = $target->permission_version;

    $session = [
        'auth.fresh_until' => Carbon::now()->timestamp + 600,
        'auth.password_confirmed_at' => Carbon::now()->timestamp,
        'auth.mfa_confirmed_at' => Carbon::now()->timestamp,
    ];

    $response = $this->actingAs($admin)
        ->withSession($session)
        ->put(route('admin.access.permissions.update', $target->id), [
            'permissions' => [AdminPermission::AuditView->value, AdminPermission::ReportsExport->value],
            'reason' => 'Assigning audit and export permissions for operational review',
            'expected_permission_version' => $initialVersion,
            'confirmed' => true,
        ]);

    $response->assertRedirect(route('admin.access.show', $target->id));

    $target->refresh();
    expect($target->permission_version)->toBe($initialVersion + 1);

    // Direct permissions updated
    $directCodes = $target->getDirectPermissions()->pluck('name')->all();
    expect($directCodes)->toHaveCount(2)
        ->and($directCodes)->toContain(AdminPermission::AuditView->value)
        ->and($directCodes)->toContain(AdminPermission::ReportsExport->value);

    // History appended under one shared batch
    $histories = PermissionGrantHistory::where('user_id', $target->id)->get();
    expect($histories)->toHaveCount(2);

    $batchId = $histories->first()->batch_id;
    foreach ($histories as $h) {
        expect($h->batch_id)->toBe($batchId)
            ->and($h->action)->toBe('grant')
            ->and($h->source)->toBe('permission_change')
            ->and($h->actor_user_id)->toBe($admin->id)
            ->and($h->reason)->toBe('Assigning audit and export permissions for operational review')
            ->and($h->permission_version)->toBe($initialVersion + 1);
    }
});

test('atomic permission revocation updates Spatie pivots, history, and version once', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::AdminsManage->value);

    $target = User::factory()->admin()->withTwoFactor()->create();
    $target->givePermissionTo([AdminPermission::AuditView->value, AdminPermission::ReportsExport->value]);
    $initialVersion = $target->permission_version;

    $session = [
        'auth.fresh_until' => Carbon::now()->timestamp + 600,
        'auth.password_confirmed_at' => Carbon::now()->timestamp,
        'auth.mfa_confirmed_at' => Carbon::now()->timestamp,
    ];

    // Revoke ReportsExport, leaving only AuditView
    $response = $this->actingAs($admin)
        ->withSession($session)
        ->put(route('admin.access.permissions.update', $target->id), [
            'permissions' => [AdminPermission::AuditView->value],
            'reason' => 'Revoking export capability',
            'expected_permission_version' => $initialVersion,
            'confirmed' => true,
        ]);

    $response->assertRedirect(route('admin.access.show', $target->id));

    $target->refresh();
    expect($target->permission_version)->toBe($initialVersion + 1);

    $directCodes = $target->getDirectPermissions()->pluck('name')->all();
    expect($directCodes)->toBe([AdminPermission::AuditView->value]);

    $revocationHistory = PermissionGrantHistory::where('user_id', $target->id)
        ->where('action', 'revoke')
        ->first();

    expect($revocationHistory)->not->toBeNull()
        ->and($revocationHistory->permission_code->value)->toBe(AdminPermission::ReportsExport->value)
        ->and($revocationHistory->actor_user_id)->toBe($admin->id)
        ->and($revocationHistory->reason)->toBe('Revoking export capability')
        ->and($revocationHistory->permission_version)->toBe($initialVersion + 1);
});

test('target active session immediately detects permission version mismatch on next request', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::AdminsManage->value);

    $target = User::factory()->admin()->withTwoFactor()->create();
    $target->givePermissionTo(AdminPermission::AuditView->value);
    $targetOldVersion = $target->permission_version;

    $session = [
        'auth.fresh_until' => Carbon::now()->timestamp + 600,
        'auth.password_confirmed_at' => Carbon::now()->timestamp,
        'auth.mfa_confirmed_at' => Carbon::now()->timestamp,
    ];

    // Admin updates target permissions
    $this->actingAs($admin)
        ->withSession($session)
        ->put(route('admin.access.permissions.update', $target->id), [
            'permissions' => [AdminPermission::AuditView->value, AdminPermission::ReportsExport->value],
            'reason' => 'Adding reports export capability',
            'expected_permission_version' => $targetOldVersion,
            'confirmed' => true,
        ])
        ->assertRedirect(route('admin.access.show', $target->id));

    $target->refresh();

    // Target makes a request with session containing the old version
    $targetResponse = $this->actingAs($target)
        ->withSession([
            'auth.permission_version' => $targetOldVersion,
        ])
        ->get(route('admin.dashboard'));

    $targetResponse->assertOk();

    // Middleware rotated version in session to target's new version
    expect(session('auth.permission_version'))->toBe($target->permission_version);
});
