<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\AuthorizationRestrictionType;
use App\Enums\UnlockVerificationMethod;
use App\Enums\UserType;
use App\Models\AuthenticationLock;
use App\Models\AuthorizationRestriction;
use App\Models\User;
use App\Notifications\Auth\AccountUnlockedNotification;
use App\Notifications\Auth\AdminPasswordResetNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Services\AuthenticationAbuseService;
use App\Services\AuthorizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Cache::flush();
});

test('guest is redirected to login from lockout endpoints', function () {
    $targetUser = User::factory()->customer()->create();

    $this->get(route('admin.lockouts.index'))->assertRedirect(route('login'));
    $this->post(route('admin.lockouts.unlock', $targetUser))->assertRedirect(route('login'));
});

test('customers and agents are denied from lockout endpoints with 403', function () {
    $customer = User::factory()->customer()->create();
    $agent = User::factory()->agent()->withTwoFactor()->create();
    $targetUser = User::factory()->customer()->create();

    $this->actingAs($customer)->get(route('admin.lockouts.index'))->assertForbidden();
    $this->actingAs($customer)->post(route('admin.lockouts.unlock', $targetUser), [
        'category' => 'password',
        'verification_method' => 'in_person',
        'reason' => 'Customer verification attempt',
    ])->assertForbidden();

    $this->actingAs($agent)->get(route('admin.lockouts.index'))->assertForbidden();
    $this->actingAs($agent)->post(route('admin.lockouts.unlock', $targetUser), [
        'category' => 'password',
        'verification_method' => 'in_person',
        'reason' => 'Agent verification attempt',
    ])->assertForbidden();
});

test('baseline admin without security.operations.manage is denied with 403', function () {
    $baselineAdmin = User::factory()->admin()->withTwoFactor()->create();
    $targetUser = User::factory()->customer()->create();

    $this->actingAs($baselineAdmin)->get(route('admin.lockouts.index'))->assertForbidden();
    $this->actingAs($baselineAdmin)->post(route('admin.lockouts.unlock', $targetUser), [
        'category' => 'password',
        'verification_method' => 'in_person',
        'reason' => 'Baseline admin attempt',
    ])->assertForbidden();
});

test('admin with role-inherited permission is denied from lockout endpoints with 403', function () {
    $roleAdmin = User::factory()->admin()->withTwoFactor()->create();
    $adminRole = Role::findByName(UserType::Admin->value, 'web');
    $adminRole->givePermissionTo(AdminPermission::SecurityOperationsManage->value);

    $targetUser = User::factory()->customer()->create();

    $this->actingAs($roleAdmin)->get(route('admin.lockouts.index'))->assertForbidden();
    $this->actingAs($roleAdmin)->post(route('admin.lockouts.unlock', $targetUser), [
        'category' => 'password',
        'verification_method' => 'in_person',
        'reason' => 'Role inherited attempt',
    ])->assertForbidden();

    // Clean up role permission so it does not leak
    $adminRole->revokePermissionTo(AdminPermission::SecurityOperationsManage->value);
});

test('admin with active restriction on security.operations.manage is denied with 403', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);

    // Apply temporary restriction
    AuthorizationRestriction::create([
        'user_id' => $admin->id,
        'restriction_type' => AuthorizationRestrictionType::PostRecoveryAdminManagement,
        'permission_code' => AdminPermission::SecurityOperationsManage->value,
        'source' => 'test',
        'started_at' => Carbon::now(),
        'expires_at' => Carbon::now()->addHours(24),
        'applied_permission_version' => $admin->permission_version,
    ]);

    $targetUser = User::factory()->customer()->create();

    $this->actingAs($admin)->get(route('admin.lockouts.index'))->assertForbidden();
    $this->actingAs($admin)->post(route('admin.lockouts.unlock', $targetUser), [
        'category' => 'password',
        'verification_method' => 'in_person',
        'reason' => 'Restricted admin attempt',
    ])->assertForbidden();
});

test('admin with drifted role is denied with 403', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);

    // Induce drift: assign an additional role
    $admin->assignRole('customer');

    $targetUser = User::factory()->customer()->create();

    $this->actingAs($admin)->get(route('admin.lockouts.index'))->assertForbidden();
    $this->actingAs($admin)->post(route('admin.lockouts.unlock', $targetUser), [
        'category' => 'password',
        'verification_method' => 'in_person',
        'reason' => 'Drifted admin attempt',
    ])->assertForbidden();
});

test('admin with inactive account state is denied from lockout endpoints and redirected to login', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create([
        'account_state' => AccountState::Suspended,
    ]);
    $admin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);

    $targetUser = User::factory()->customer()->create();

    $this->actingAs($admin)->get(route('admin.lockouts.index'))->assertRedirect(route('login'));
    $this->actingAs($admin)->post(route('admin.lockouts.unlock', $targetUser), [
        'category' => 'password',
        'verification_method' => 'in_person',
        'reason' => 'Suspended admin attempt',
    ])->assertRedirect(route('login'));

    expect(app(AuthorizationService::class)->allows($admin, AdminPermission::SecurityOperationsManage))->toBeFalse();
});

test('authorized admin can view lockout index with verification methods and lock records', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);

    $victim = User::factory()->customer()->create([
        'name' => 'Victim User',
        'email' => 'victim@example.com',
    ]);

    $lock = AuthenticationLock::create([
        'user_id' => $victim->id,
        'email_normalized' => 'victim@example.com',
        'lock_category' => 'password',
        'reason' => 'Multiple failed password attempts',
        'failed_attempts_count' => 10,
        'ip_address' => '198.51.100.25',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
        'locked_at' => Carbon::now(),
        'locked_until' => Carbon::now()->addMinutes(15),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.lockouts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Lockouts')
            ->has('locks.data', 1)
            ->where('locks.data.0.id', $lock->id)
            ->where('locks.data.0.masked_ip', '198.51.***.***')
            ->where('locks.data.0.can_unlock', true)
            ->has('verification_methods', count(UnlockVerificationMethod::cases()))
            ->where('verification_methods.0.value', UnlockVerificationMethod::InPerson->value)
            ->where('verification_methods.0.label', UnlockVerificationMethod::InPerson->label())
        );
});

test('manual unlock rejects missing, invalid category, verification method, or short reason with 422', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);
    $targetUser = User::factory()->customer()->create();

    $this->actingAs($admin)->post(route('admin.lockouts.unlock', $targetUser), [])
        ->assertSessionHasErrors(['category', 'verification_method', 'reason']);

    $this->actingAs($admin)->post(route('admin.lockouts.unlock', $targetUser), [
        'category' => 'invalid_category',
        'verification_method' => 'invalid_method',
        'reason' => 'abc', // shorter than 5 chars
    ])->assertSessionHasErrors(['category', 'verification_method', 'reason']);
});

test('manual unlock rejects reason exceeding 255 characters with 422', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);
    $targetUser = User::factory()->customer()->create();

    $this->actingAs($admin)->post(route('admin.lockouts.unlock', $targetUser), [
        'category' => 'password',
        'verification_method' => 'in_person',
        'reason' => str_repeat('a', 256),
    ])->assertSessionHasErrors(['reason']);
});

test('manual unlock rejects unlock when no active lock exists for requested category with 422', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);
    $targetUser = User::factory()->customer()->create();

    // Target user has no locks at all
    $this->actingAs($admin)->post(route('admin.lockouts.unlock', $targetUser), [
        'category' => 'password',
        'verification_method' => 'in_person',
        'reason' => 'Attempting to unlock unlocked account',
    ])->assertSessionHasErrors(['category']);
});

test('manual unlock rejects unlock when lock has expired naturally with 422', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);
    $targetUser = User::factory()->customer()->create();

    // Create an expired lock
    AuthenticationLock::create([
        'user_id' => $targetUser->id,
        'email_normalized' => $targetUser->email,
        'lock_category' => 'password',
        'reason' => 'Old lock',
        'failed_attempts_count' => 10,
        'locked_at' => Carbon::now()->subMinutes(30),
        'locked_until' => Carbon::now()->subMinutes(15), // expired
    ]);

    $this->actingAs($admin)->post(route('admin.lockouts.unlock', $targetUser), [
        'category' => 'password',
        'verification_method' => 'in_person',
        'reason' => 'Attempting to unlock expired lock',
    ])->assertSessionHasErrors(['category']);
});

test('manual unlock clears only requested category and preserves unrelated locks, passwords, MFA, and account state', function () {
    Notification::fake();

    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);

    $targetUser = User::factory()->customer()->active()->create([
        'password' => bcrypt('secret-password-123'),
        'email' => 'customer@example.com',
    ]);

    // Apply password lock and MFA lock
    $targetUser->lockTemporarily(15, 'password', 'Password lock');
    Cache::put('auth:password:failures:customer@example.com', [time()]);
    Cache::put('auth:password:cooldown:customer@example.com', Carbon::now()->addMinutes(15)->timestamp);

    $passwordLock = AuthenticationLock::create([
        'user_id' => $targetUser->id,
        'email_normalized' => 'customer@example.com',
        'lock_category' => 'password',
        'reason' => 'Password lock',
        'failed_attempts_count' => 10,
        'locked_at' => Carbon::now(),
        'locked_until' => Carbon::now()->addMinutes(15),
    ]);

    $mfaLock = AuthenticationLock::create([
        'user_id' => $targetUser->id,
        'email_normalized' => 'customer@example.com',
        'lock_category' => 'mfa',
        'reason' => 'MFA lock',
        'failed_attempts_count' => 10,
        'locked_at' => Carbon::now(),
        'locked_until' => Carbon::now()->addMinutes(30),
    ]);

    // Unlock only password category
    $response = $this->actingAs($admin)->post(route('admin.lockouts.unlock', $targetUser), [
        'category' => 'password',
        'verification_method' => 'verified_phone_callback',
        'reason' => 'Verified phone callback completed with account holder',
    ]);

    $response->assertRedirect();

    // Target user password lock cleared
    $targetUser->refresh();
    expect($targetUser->isTemporarilyLocked('password'))->toBeFalse();
    expect(Cache::has('auth:password:failures:customer@example.com'))->toBeFalse();
    expect(Cache::has('auth:password:cooldown:customer@example.com'))->toBeFalse();

    // Password lock row updated with evidence
    $passwordLock->refresh();
    expect($passwordLock->unlocked_at)->not->toBeNull();
    expect($passwordLock->unlocked_by_user_id)->toBe($admin->id);
    expect($passwordLock->unlock_reason)->toBe('Verified phone callback completed with account holder');
    expect($passwordLock->unlock_verification_method)->toBe(UnlockVerificationMethod::VerifiedPhoneCallback);

    // MFA lock remains active!
    $mfaLock->refresh();
    expect($mfaLock->unlocked_at)->toBeNull();
    expect($mfaLock->isActive())->toBeTrue();
    expect($targetUser->isTemporarilyLocked('mfa'))->toBeTrue();

    // Account state and credentials preserved
    expect($targetUser->account_state)->toBe(AccountState::Active);
    expect(Hash::check('secret-password-123', $targetUser->password))->toBeTrue();

    // Notification queued
    Notification::assertSentTo($targetUser, AccountUnlockedNotification::class, function ($n) {
        return $n instanceof ShouldQueue;
    });
});

test('admin cannot manually unlock their own account and receives 403', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);

    $admin->lockTemporarily(15, 'password', 'Self lock');
    AuthenticationLock::create([
        'user_id' => $admin->id,
        'email_normalized' => $admin->email,
        'lock_category' => 'password',
        'reason' => 'Self lock',
        'failed_attempts_count' => 10,
        'locked_at' => Carbon::now(),
        'locked_until' => Carbon::now()->addMinutes(15),
    ]);

    $response = $this->actingAs($admin)->post(route('admin.lockouts.unlock', $admin), [
        'category' => 'password',
        'verification_method' => 'in_person',
        'reason' => 'Attempting self-unlock',
    ]);

    $response->assertForbidden();
    expect($admin->fresh()->isTemporarilyLocked('password'))->toBeTrue();
});

test('commit-time reauthorization rejects unlock if permission revoked before transaction commit', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);

    $targetUser = User::factory()->customer()->create();
    $targetUser->lockTemporarily(15, 'password', 'Test lock');
    $lock = AuthenticationLock::create([
        'user_id' => $targetUser->id,
        'email_normalized' => $targetUser->email,
        'lock_category' => 'password',
        'reason' => 'Test lock',
        'failed_attempts_count' => 10,
        'locked_at' => Carbon::now(),
        'locked_until' => Carbon::now()->addMinutes(15),
    ]);

    // Revoke permission right before service call executes commit-time check
    $admin->revokePermissionTo(AdminPermission::SecurityOperationsManage->value);

    $service = app(AuthenticationAbuseService::class);

    expect(fn () => $service->manualUnlock($targetUser, $admin, 'password', UnlockVerificationMethod::InPerson, 'Verification reason'))
        ->toThrow(AuthorizationException::class);

    // Ensure no changes were persisted
    $lock->refresh();
    expect($lock->unlocked_at)->toBeNull();
    expect($targetUser->fresh()->isTemporarilyLocked('password'))->toBeTrue();
});

test('commit-time reauthorization rejects unlock if restriction applied before commit', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);

    $targetUser = User::factory()->customer()->create();
    $targetUser->lockTemporarily(15, 'password', 'Test lock');
    $lock = AuthenticationLock::create([
        'user_id' => $targetUser->id,
        'email_normalized' => $targetUser->email,
        'lock_category' => 'password',
        'reason' => 'Test lock',
        'failed_attempts_count' => 10,
        'locked_at' => Carbon::now(),
        'locked_until' => Carbon::now()->addMinutes(15),
    ]);

    // Apply temporary restriction
    AuthorizationRestriction::create([
        'user_id' => $admin->id,
        'restriction_type' => AuthorizationRestrictionType::PostRecoveryAdminManagement,
        'permission_code' => AdminPermission::SecurityOperationsManage->value,
        'source' => 'test',
        'started_at' => Carbon::now(),
        'expires_at' => Carbon::now()->addHours(24),
        'applied_permission_version' => $admin->permission_version,
    ]);

    $service = app(AuthenticationAbuseService::class);

    expect(fn () => $service->manualUnlock($targetUser, $admin, 'password', UnlockVerificationMethod::InPerson, 'Verification reason'))
        ->toThrow(AuthorizationException::class);

    $lock->refresh();
    expect($lock->unlocked_at)->toBeNull();
    expect($targetUser->fresh()->isTemporarilyLocked('password'))->toBeTrue();
});

test('admin password-reset alert reaches only active synchronized Admins with direct security.operations.manage grant', function () {
    Notification::fake();

    // 1. Affected Admin resetting their password
    $affectedAdmin = User::factory()->admin()->create(['email' => 'affected@example.com']);

    // 2. Active Admin with direct security.operations.manage grant (SHOULD receive)
    $authorizedSecurityAdmin = User::factory()->admin()->withTwoFactor()->create(['email' => 'security-admin@example.com']);
    $authorizedSecurityAdmin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);

    // 3. Baseline Admin without grant (SHOULD NOT receive)
    $baselineAdmin = User::factory()->admin()->withTwoFactor()->create(['email' => 'baseline@example.com']);

    // 4. Inactive Admin with grant (SHOULD NOT receive)
    $suspendedAdmin = User::factory()->admin()->withTwoFactor()->create([
        'email' => 'suspended@example.com',
        'account_state' => AccountState::Suspended,
    ]);
    $suspendedAdmin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);

    // 5. Drifted Admin with grant (SHOULD NOT receive)
    $driftedAdmin = User::factory()->admin()->withTwoFactor()->create(['email' => 'drifted@example.com']);
    $driftedAdmin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);
    $driftedAdmin->assignRole('customer');

    // 6. Restricted Admin with grant (SHOULD NOT receive)
    $restrictedAdmin = User::factory()->admin()->withTwoFactor()->create(['email' => 'restricted@example.com']);
    $restrictedAdmin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);
    AuthorizationRestriction::create([
        'user_id' => $restrictedAdmin->id,
        'restriction_type' => AuthorizationRestrictionType::PostRecoveryAdminManagement,
        'permission_code' => AdminPermission::SecurityOperationsManage->value,
        'source' => 'test',
        'started_at' => Carbon::now(),
        'expires_at' => Carbon::now()->addHours(24),
        'applied_permission_version' => $restrictedAdmin->permission_version,
    ]);

    // 7. Role-inherited Admin (SHOULD NOT receive)
    $roleInheritedAdmin = User::factory()->admin()->withTwoFactor()->create(['email' => 'role-inherited@example.com']);
    $adminRole = Role::findByName(UserType::Admin->value, 'web');
    $adminRole->givePermissionTo(AdminPermission::SecurityOperationsManage->value);

    // Request password reset email
    $this->post(route('password.email'), ['email' => $affectedAdmin->email]);

    Notification::assertSentTo($affectedAdmin, ResetPasswordNotification::class, function ($notification) use (
        $affectedAdmin,
        $authorizedSecurityAdmin,
        $baselineAdmin,
        $suspendedAdmin,
        $driftedAdmin,
        $restrictedAdmin,
        $roleInheritedAdmin
    ) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $affectedAdmin->email,
            'password' => 'new-admin-password-123',
            'password_confirmation' => 'new-admin-password-123',
        ])->assertSessionHasNoErrors();

        // 1. Authorized security admin received AdminPasswordResetNotification
        Notification::assertSentTo($authorizedSecurityAdmin, AdminPasswordResetNotification::class, function ($n) use ($affectedAdmin) {
            return $n instanceof ShouldQueue && $n->affectedAdmin->id === $affectedAdmin->id;
        });

        // 2. Exclude affected Admin
        Notification::assertNotSentTo($affectedAdmin, AdminPasswordResetNotification::class);

        // 3. Exclude baseline Admin
        Notification::assertNotSentTo($baselineAdmin, AdminPasswordResetNotification::class);

        // 4. Exclude inactive Admin
        Notification::assertNotSentTo($suspendedAdmin, AdminPasswordResetNotification::class);

        // 5. Exclude drifted Admin
        Notification::assertNotSentTo($driftedAdmin, AdminPasswordResetNotification::class);

        // 6. Exclude restricted Admin
        Notification::assertNotSentTo($restrictedAdmin, AdminPasswordResetNotification::class);

        // 7. Exclude role-inherited Admin
        Notification::assertNotSentTo($roleInheritedAdmin, AdminPasswordResetNotification::class);

        return true;
    });

    // Clean up role permission
    $adminRole->revokePermissionTo(AdminPermission::SecurityOperationsManage->value);
});
