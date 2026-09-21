<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\AuthorizationRestrictionType;
use App\Http\Middleware\RefreshPermissionVersionSession;
use App\Models\AuthorizationRestriction;
use App\Models\Permission;
use App\Models\User;
use App\Services\AuthorizationRestrictionService;
use App\Services\AuthorizationService;
use App\Support\AuthorizationEvidence;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

test('all 13 gates allow explicitly granted active administrators', function () {
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::values());

    $authService = app(AuthorizationService::class);

    foreach (AdminPermission::cases() as $permission) {
        expect($authService->allows($admin, $permission))->toBeTrue()
            ->and(Gate::forUser($admin)->allows($permission->value))->toBeTrue()
            ->and($admin->can($permission->value))->toBeTrue();
    }

    $effectiveCodes = $authService->effectivePermissionCodes($admin);
    expect(count($effectiveCodes))->toBe(13);
    foreach (AdminPermission::values() as $code) {
        expect($effectiveCodes)->toContain($code);
    }
});

test('gates deny baseline-only administrators with zero direct grants', function () {
    $admin = User::factory()->admin()->create();

    $authService = app(AuthorizationService::class);

    foreach (AdminPermission::cases() as $permission) {
        expect($authService->allows($admin, $permission))->toBeFalse()
            ->and(Gate::forUser($admin)->allows($permission->value))->toBeFalse();
    }

    expect($authService->effectivePermissionCodes($admin))->toBe([]);
});

test('gates deny customers and agents even if permissions are mistakenly attached directly', function () {
    $customer = User::factory()->customer()->create();
    $agent = User::factory()->agent()->create();

    // Force attach a direct permission to test fail-closed classification checks
    DB::table('model_has_permissions')->insert([
        [
            'permission_id' => Permission::where('name', AdminPermission::AdminsManage->value)->first()->id,
            'model_type' => User::class,
            'model_id' => $customer->id,
        ],
        [
            'permission_id' => Permission::where('name', AdminPermission::AdminsManage->value)->first()->id,
            'model_type' => User::class,
            'model_id' => $agent->id,
        ],
    ]);

    $authService = app(AuthorizationService::class);

    expect($authService->allows($customer, AdminPermission::AdminsManage))->toBeFalse()
        ->and(Gate::forUser($customer)->allows(AdminPermission::AdminsManage->value))->toBeFalse()
        ->and($authService->effectivePermissionCodes($customer))->toBe([]);

    expect($authService->allows($agent, AdminPermission::AdminsManage))->toBeFalse()
        ->and(Gate::forUser($agent)->allows(AdminPermission::AdminsManage->value))->toBeFalse()
        ->and($authService->effectivePermissionCodes($agent))->toBe([]);
});

test('gates deny administrators with non-active account states', function () {
    $states = [
        AccountState::Invited,
        AccountState::MfaSetupRequired,
        AccountState::Suspended,
        AccountState::Deactivated,
    ];

    $authService = app(AuthorizationService::class);

    foreach ($states as $state) {
        $admin = User::factory()->admin()->create([
            'account_state' => $state,
        ]);
        $admin->givePermissionTo(AdminPermission::AdminsManage->value);

        expect($authService->allows($admin, AdminPermission::AdminsManage))->toBeFalse()
            ->and(Gate::forUser($admin)->allows(AdminPermission::AdminsManage->value))->toBeFalse()
            ->and($authService->effectivePermissionCodes($admin))->toBe([]);
    }
});

test('gates deny administrators experiencing role drift', function () {
    $authService = app(AuthorizationService::class);

    // 1. Missing role
    $admin1 = User::factory()->admin()->create();
    $admin1->givePermissionTo(AdminPermission::AdminsManage->value);
    $admin1->roles()->detach();
    $admin1->unsetRelation('roles');

    expect($authService->allows($admin1, AdminPermission::AdminsManage))->toBeFalse()
        ->and(Gate::forUser($admin1)->allows(AdminPermission::AdminsManage->value))->toBeFalse();

    // 2. Mismatched role
    $admin2 = User::factory()->admin()->create();
    $admin2->givePermissionTo(AdminPermission::AdminsManage->value);
    $agentRole = Role::where('name', 'agent')->where('guard_name', 'web')->first();
    DB::table('model_has_roles')->where('model_id', $admin2->id)->update(['role_id' => $agentRole->id]);
    $admin2->unsetRelation('roles');

    expect($authService->allows($admin2, AdminPermission::AdminsManage))->toBeFalse()
        ->and(Gate::forUser($admin2)->allows(AdminPermission::AdminsManage->value))->toBeFalse();

    // 3. Additional role
    $admin3 = User::factory()->admin()->create();
    $admin3->givePermissionTo(AdminPermission::AdminsManage->value);
    DB::table('model_has_roles')->insert([
        'role_id' => $agentRole->id,
        'model_type' => User::class,
        'model_id' => $admin3->id,
    ]);
    $admin3->unsetRelation('roles');

    expect($authService->allows($admin3, AdminPermission::AdminsManage))->toBeFalse()
        ->and(Gate::forUser($admin3)->allows(AdminPermission::AdminsManage->value))->toBeFalse();
});

test('gates deny retired permissions even when explicitly granted to an administrator', function () {
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::AdminsManage->value);

    $permission = Permission::where('name', AdminPermission::AdminsManage->value)->first();
    $permission->markRetired();

    $authService = app(AuthorizationService::class);

    expect($authService->allows($admin, AdminPermission::AdminsManage))->toBeFalse()
        ->and(Gate::forUser($admin)->allows(AdminPermission::AdminsManage->value))->toBeFalse()
        ->and($authService->effectivePermissionCodes($admin))->not->toContain(AdminPermission::AdminsManage->value);

    // Restore active status for subsequent tests
    $permission->update(['status' => 'active', 'retired_at' => null]);
});

test('role-inherited grants in role_has_permissions never satisfy gate checks', function () {
    $admin = User::factory()->admin()->create();

    // Directly assign permission to the admin role
    $adminRole = Role::where('name', 'admin')->where('guard_name', 'web')->first();
    $permission = Permission::where('name', AdminPermission::AdminsManage->value)->first();

    DB::table('role_has_permissions')->insert([
        'permission_id' => $permission->id,
        'role_id' => $adminRole->id,
    ]);

    app(PermissionRegistrar::class)->clearPermissionsCollection();

    $authService = app(AuthorizationService::class);

    // Baseline admin with role-inherited permission MUST fail
    expect($authService->allows($admin, AdminPermission::AdminsManage))->toBeFalse()
        ->and(Gate::forUser($admin)->allows(AdminPermission::AdminsManage->value))->toBeFalse()
        ->and($authService->effectivePermissionCodes($admin))->toBe([]);

    // Clean up role_has_permissions
    DB::table('role_has_permissions')->truncate();
    app(PermissionRegistrar::class)->clearPermissionsCollection();
});

test('unknown, wildcard, and undefined abilities are denied', function () {
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::values());

    expect(Gate::forUser($admin)->allows('*'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('all'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('admins.*'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('nonexistent.permission'))->toBeFalse();
});

test('post-recovery restriction blocks only admins.manage, preserves direct grant, and expires by timestamp', function () {
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo([
        AdminPermission::AdminsManage->value,
        AdminPermission::AuditView->value,
        AdminPermission::WithdrawalsReview->value,
    ]);

    expect($admin->permission_version)->toBe(1);

    $restrictionService = app(AuthorizationRestrictionService::class);
    $authService = app(AuthorizationService::class);

    // 1. Initial state: all granted permissions allowed
    expect($authService->allows($admin, AdminPermission::AdminsManage))->toBeTrue()
        ->and($authService->allows($admin, AdminPermission::AuditView))->toBeTrue()
        ->and($authService->allows($admin, AdminPermission::WithdrawalsReview))->toBeTrue();

    // 2. Apply post-recovery restriction
    $startedAt = Carbon::now();
    $expiresAt = Carbon::now()->addHours(24);

    $restriction = $restrictionService->apply(
        target: $admin,
        type: AuthorizationRestrictionType::PostRecoveryAdminManagement,
        source: 'assisted_recovery',
        sourceReference: 'req-recovery-123',
        startedAt: $startedAt,
        expiresAt: $expiresAt,
    );

    $admin->refresh();
    expect($admin->permission_version)->toBe(2)
        ->and($restriction->applied_permission_version)->toBe(2)
        ->and($restriction->isEffective())->toBeTrue();

    // Direct grant still preserved in database
    expect($admin->hasDirectPermission(AdminPermission::AdminsManage->value))->toBeTrue();

    // admins.manage is denied, while other granted permissions remain allowed
    expect($authService->allows($admin, AdminPermission::AdminsManage))->toBeFalse()
        ->and(Gate::forUser($admin)->allows(AdminPermission::AdminsManage->value))->toBeFalse()
        ->and($authService->allows($admin, AdminPermission::AuditView))->toBeTrue()
        ->and($authService->allows($admin, AdminPermission::WithdrawalsReview))->toBeTrue();

    $effectiveCodes = $authService->effectivePermissionCodes($admin);
    expect($effectiveCodes)->not->toContain(AdminPermission::AdminsManage->value)
        ->and($effectiveCodes)->toContain(AdminPermission::AuditView->value)
        ->and($effectiveCodes)->toContain(AdminPermission::WithdrawalsReview->value);

    // 3. Expiry by timestamp makes restriction immediately ineffective
    Carbon::setTestNow(Carbon::now()->addHours(25));

    expect($restriction->isEffective())->toBeFalse()
        ->and($restriction->isElapsed())->toBeTrue()
        ->and($authService->allows($admin, AdminPermission::AdminsManage))->toBeTrue()
        ->and(Gate::forUser($admin)->allows(AdminPermission::AdminsManage->value))->toBeTrue();

    Carbon::setTestNow(null);
});

test('restriction application and clearing are idempotent and record metadata', function () {
    $admin = User::factory()->admin()->create();
    $actor = User::factory()->admin()->create();
    $restrictionService = app(AuthorizationRestrictionService::class);

    expect($admin->permission_version)->toBe(1);

    // 1. Apply restriction
    $restriction1 = $restrictionService->apply(
        target: $admin,
        type: AuthorizationRestrictionType::PostRecoveryAdminManagement,
        source: 'assisted_recovery',
        createdBy: $actor,
    );

    $admin->refresh();
    expect($admin->permission_version)->toBe(2);

    // 2. Re-apply restriction: idempotent, returns existing, does NOT advance version
    $restriction2 = $restrictionService->apply(
        target: $admin,
        type: AuthorizationRestrictionType::PostRecoveryAdminManagement,
        source: 'assisted_recovery',
    );

    $admin->refresh();
    expect($restriction2->id)->toBe($restriction1->id)
        ->and($admin->permission_version)->toBe(2);

    // 3. Clear restriction
    $cleared = $restrictionService->clear(
        restriction: $restriction1,
        reason: 'Identity verified by security team',
        clearedBy: $actor,
    );

    $admin->refresh();
    expect($admin->permission_version)->toBe(3)
        ->and($cleared->cleared_at)->not->toBeNull()
        ->and($cleared->clear_reason)->toBe('Identity verified by security team')
        ->and($cleared->cleared_by)->toBe($actor->id)
        ->and($cleared->cleared_permission_version)->toBe(3);

    // 4. Re-clear restriction: idempotent, does NOT advance version
    $recleared = $restrictionService->clear(
        restriction: $cleared,
        reason: 'Duplicate clear attempt',
    );

    $admin->refresh();
    expect($recleared->id)->toBe($cleared->id)
        ->and($admin->permission_version)->toBe(3);
});

test('authz:expire-restrictions command closes elapsed restrictions and advances permission version once per admin', function () {
    $admin1 = User::factory()->admin()->create();
    $admin2 = User::factory()->admin()->create();

    $restrictionService = app(AuthorizationRestrictionService::class);

    // Admin 1 has 2 elapsed restrictions
    $now = Carbon::now();
    $past = $now->copy()->subMinutes(10);

    AuthorizationRestriction::create([
        'user_id' => $admin1->id,
        'restriction_type' => AuthorizationRestrictionType::PostRecoveryAdminManagement,
        'permission_code' => AdminPermission::AdminsManage->value,
        'source' => 'test',
        'started_at' => $past->copy()->subDay(),
        'expires_at' => $past,
        'applied_permission_version' => $admin1->permission_version,
    ]);

    AuthorizationRestriction::create([
        'user_id' => $admin1->id,
        'restriction_type' => AuthorizationRestrictionType::PostRecoveryAdminManagement,
        'permission_code' => AdminPermission::SecurityOperationsManage->value,
        'source' => 'test',
        'started_at' => $past->copy()->subDay(),
        'expires_at' => $past,
        'applied_permission_version' => $admin1->permission_version,
    ]);

    // Admin 2 has 1 active (future) restriction
    AuthorizationRestriction::create([
        'user_id' => $admin2->id,
        'restriction_type' => AuthorizationRestrictionType::PostRecoveryAdminManagement,
        'permission_code' => AdminPermission::AdminsManage->value,
        'source' => 'test',
        'started_at' => $now,
        'expires_at' => $now->copy()->addDay(),
        'applied_permission_version' => $admin2->permission_version,
    ]);

    expect($admin1->permission_version)->toBe(1)
        ->and($admin2->permission_version)->toBe(1);

    $this->artisan('authz:expire-restrictions')
        ->assertSuccessful();

    $admin1->refresh();
    $admin2->refresh();

    // Admin 1 permission version advanced exactly ONCE (from 1 to 2) despite having 2 elapsed restrictions
    expect($admin1->permission_version)->toBe(2);

    // Admin 2 permission version remained unchanged (restriction is still active)
    expect($admin2->permission_version)->toBe(1);

    // Both elapsed restrictions for Admin 1 are now marked cleared
    $elapsedRestrictions = AuthorizationRestriction::where('user_id', $admin1->id)->get();
    foreach ($elapsedRestrictions as $r) {
        expect($r->cleared_at)->not->toBeNull()
            ->and($r->clear_reason)->toBe('Expired by scheduled restriction reaper')
            ->and($r->cleared_permission_version)->toBe(2);
    }
});

test('authorization evidence captures immutable metadata, serializes stably, and reauthorizes correctly', function () {
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::WithdrawalsReview->value);

    $authService = app(AuthorizationService::class);

    // 1. Capture evidence
    $evidence = $authService->captureEvidence(
        user: $admin,
        permission: AdminPermission::WithdrawalsReview,
        subjectType: 'withdrawal_request',
        subjectId: 'wd-999',
        subjectVersion: 1,
    );

    expect($evidence->actorId)->toBe($admin->id)
        ->and($evidence->permission)->toBe(AdminPermission::WithdrawalsReview)
        ->and($evidence->permissionVersion)->toBe(1)
        ->and($evidence->subjectType)->toBe('withdrawal_request')
        ->and($evidence->subjectId)->toBe('wd-999')
        ->and($evidence->subjectVersion)->toBe(1);

    // 2. Stable array serialization & deserialization
    $serialized = $evidence->toArray();
    expect($serialized)->toHaveKeys([
        'actor_id',
        'permission',
        'permission_version',
        'subject_type',
        'subject_id',
        'subject_version',
        'decided_at',
    ])
        ->and($serialized['actor_id'])->toBe($admin->id)
        ->and($serialized['permission'])->toBe('withdrawals.review')
        ->and($serialized['permission_version'])->toBe(1);

    $restored = AuthorizationEvidence::fromArray($serialized);
    expect($restored->actorId)->toBe($evidence->actorId)
        ->and($restored->permission)->toBe($evidence->permission)
        ->and($restored->permissionVersion)->toBe($evidence->permissionVersion)
        ->and($restored->subjectType)->toBe($evidence->subjectType)
        ->and($restored->subjectId)->toBe($evidence->subjectId)
        ->and($restored->subjectVersion)->toBe($evidence->subjectVersion);

    // 3. Reauthorization succeeds with unchanged actor and version
    expect($authService->reauthorize($evidence))->toBeTrue();

    // 4. Capture evidence fails when actor lacks permission
    expect(function () use ($authService, $admin) {
        $authService->captureEvidence($admin, AdminPermission::AdminsManage);
    })->toThrow(AuthorizationException::class);

    // 5. Reauthorization fails if permission version is bumped
    $admin->permission_version = 2;
    $admin->save();

    expect($authService->reauthorize($evidence))->toBeFalse();

    // Reset version to 1 to test permission revocation failure
    $admin->permission_version = 1;
    $admin->save();

    // 6. Reauthorization fails if permission is revoked
    $admin->revokePermissionTo(AdminPermission::WithdrawalsReview->value);
    app(PermissionRegistrar::class)->clearPermissionsCollection();

    expect($authService->reauthorize($evidence))->toBeFalse();
});

test('session middleware initializes auth.permission_version on new session without rotation', function () {
    $admin = User::factory()->admin()->create(['permission_version' => 1]);

    $this->actingAs($admin);
    $this->withSession([]);

    $response = $this->get(route('admin.dashboard'));
    $response->assertOk();

    expect(session('auth.permission_version'))->toBe(1);
});

test('session middleware retains session ID and token when permission version matches', function () {
    $admin = User::factory()->admin()->create(['permission_version' => 1]);

    $middleware = new RefreshPermissionVersionSession;
    $request = Request::create('/admin/dashboard', 'GET');
    $request->setUserResolver(fn () => $admin);
    $session = app('session.store');
    $session->start();
    $session->put('auth.permission_version', 1);
    $initialId = $session->getId();
    $initialToken = $session->token();
    $request->setLaravelSession($session);

    $middleware->handle($request, function ($req) {
        return new Response('ok');
    });

    expect($session->getId())->toBe($initialId)
        ->and($session->token())->toBe($initialToken)
        ->and($session->get('auth.permission_version'))->toBe(1);
});

test('session middleware rotates session ID, regenerates token, and clears stale state on version mismatch', function () {
    $admin = User::factory()->admin()->create(['permission_version' => 2]);

    $middleware = new RefreshPermissionVersionSession;
    $request = Request::create('/admin/dashboard', 'GET');
    $request->setUserResolver(fn () => $admin);
    $session = app('session.store');
    $session->start();
    $session->put('auth.permission_version', 1); // Stale version
    $session->put('auth.permissions', ['stale.permission']);
    $initialId = $session->getId();
    $initialToken = $session->token();
    $request->setLaravelSession($session);

    $middleware->handle($request, function ($req) {
        return new Response('ok');
    });

    expect($session->getId())->not->toBe($initialId)
        ->and($session->token())->not->toBe($initialToken)
        ->and($session->get('auth.permission_version'))->toBe(2)
        ->and($session->has('auth.permissions'))->toBeFalse();
});

test('handle inertia requests shares effective permissions and permission_version', function () {
    $admin = User::factory()->admin()->create(['permission_version' => 3]);
    $admin->givePermissionTo([AdminPermission::AuditView->value, AdminPermission::ReportsExport->value]);

    $response = $this->actingAs($admin)->get(route('admin.dashboard'));
    $response->assertOk();

    $response->assertInertia(function ($page) {
        $page->where('auth.user.permission_version', 3)
            ->where('auth.permissions', function ($permissions) {
                $array = is_array($permissions) ? $permissions : $permissions->all();

                return in_array('audit.view', $array, true)
                    && in_array('reports.export', $array, true)
                    && ! in_array('admins.manage', $array, true);
            });
    });

    // Guest receives empty permissions array and null user
    $this->app['auth']->guard('web')->logout();
    $guestResponse = $this->get(route('home'));
    $guestResponse->assertInertia(function ($page) {
        $page->where('auth.user', null)
            ->where('auth.permissions', []);
    });
});
