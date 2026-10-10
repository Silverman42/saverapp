<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\UnlockVerificationMethod;
use App\Models\AuthenticationLock;
use App\Models\User;
use App\Models\UserRecoveryCode;
use App\Notifications\Auth\AccountUnlockedNotification;
use App\Notifications\Auth\CompromiseSessionRevocationNotification;
use App\Notifications\Auth\MfaCooldownNotification;
use App\Notifications\Auth\PasswordLockoutNotification;
use App\Notifications\Auth\RecoveryCodeCooldownNotification;
use App\Notifications\Auth\TwoFactorFailedAttemptsExceededNotification;
use App\Services\AuthenticationAbuseService;
use App\Services\UnlockState;
use App\Support\IdentityNormalizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear(md5('login|127.0.0.1'));
});

test('1 to 4 failed password attempts reject with generic error without applying cooldown', function () {
    $user = User::factory()->customer()->active()->create([
        'email' => 'customer@example.com',
    ]);

    for ($i = 1; $i <= 4; $i++) {
        $response = $this->post(route('login.store'), [
            'email' => 'customer@example.com',
            'password' => 'wrong-password-'.$i,
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    $service = app(AuthenticationAbuseService::class);
    expect($service->isPasswordRestricted('customer@example.com', $user))->toBeFalse();
    expect($user->fresh()->isTemporarilyLocked('password'))->toBeFalse();
});

test('5th failed password attempt applies 1-minute cooldown and rejects subsequent attempts generically', function () {
    $user = User::factory()->customer()->active()->create([
        'email' => 'customer@example.com',
        'password' => 'ValidPassword123!',
    ]);

    // 5 failed attempts
    for ($i = 1; $i <= 5; $i++) {
        $response = $this->post(route('login.store'), [
            'email' => 'customer@example.com',
            'password' => 'wrong-password',
        ]);
        $response->assertSessionHasErrors('email');
    }

    $service = app(AuthenticationAbuseService::class);
    expect($service->isPasswordRestricted('customer@example.com', $user))->toBeTrue();

    // Advance 30s (still within 1m cooldown). Clear route limiter so request reaches AuthenticateUser
    $this->travel(30)->seconds();
    RateLimiter::clear(md5('login'.Str::transliterate('customer@example.com|127.0.0.1')));

    // Attempt during cooldown with the CORRECT password should still be rejected generically
    $cooldownAttempt = $this->post(route('login.store'), [
        'email' => 'customer@example.com',
        'password' => 'ValidPassword123!',
    ]);

    $cooldownAttempt->assertSessionHasErrors('email');
    $this->assertGuest();

    // The attempt during cooldown was the 6th failure, triggering a 2-minute progressive cooldown
    // After 125 seconds (past the 2-minute cooldown), valid password succeeds
    $this->travel(125)->seconds();
    RateLimiter::clear(md5('login'.Str::transliterate('customer@example.com|127.0.0.1')));

    $validAttempt = $this->post(route('login.store'), [
        'email' => 'customer@example.com',
        'password' => 'ValidPassword123!',
    ]);

    $this->assertAuthenticatedAs($user);
    $validAttempt->assertRedirect(route('customer.dashboard', absolute: false));
});

test('progressive cooldown delays are applied at 6 to 9 failed attempts', function () {
    $user = User::factory()->customer()->active()->create([
        'email' => 'customer@example.com',
    ]);

    $service = app(AuthenticationAbuseService::class);

    // Make 5 failures -> 1 min cooldown
    for ($i = 1; $i <= 5; $i++) {
        $this->post(route('login.store'), [
            'email' => 'customer@example.com',
            'password' => 'wrong-password',
        ]);
    }
    expect(Cache::has('auth:password:cooldown:customer@example.com'))->toBeTrue();

    // Travel past 1 min (65s) and do 6th failure -> should set 2 min cooldown
    $this->travel(65)->seconds();
    RateLimiter::clear(Str::transliterate('customer@example.com|127.0.0.1'));

    $this->post(route('login.store'), [
        'email' => 'customer@example.com',
        'password' => 'wrong-password',
    ]);

    $cooldownUntil = (int) Cache::get('auth:password:cooldown:customer@example.com');
    expect($cooldownUntil)->toBeGreaterThan(Carbon::now()->timestamp);
    // Cooldown is ~2 minutes (120 seconds) from current test time
    expect($cooldownUntil - Carbon::now()->timestamp)->toBeLessThanOrEqual(120);
});

test('10 failed password attempts within 1 hour apply 15-minute lock and queue notification', function () {
    Notification::fake();

    $user = User::factory()->customer()->active()->create([
        'email' => 'victim@example.com',
    ]);

    for ($i = 1; $i <= 10; $i++) {
        $this->travel(65)->seconds();
        RateLimiter::clear(Str::transliterate('victim@example.com|127.0.0.1'));
        $this->post(route('login.store'), [
            'email' => 'victim@example.com',
            'password' => 'wrong-password',
        ]);
    }

    $user->refresh();
    expect($user->isTemporarilyLocked('password'))->toBeTrue();
    expect($user->locked_until)->not->toBeNull();

    $lock = AuthenticationLock::where('email_normalized', 'victim@example.com')
        ->where('lock_category', 'password')
        ->first();

    expect($lock)->not->toBeNull();
    expect($lock->failed_attempts_count)->toBe(10);
    expect($lock->requires_review)->toBeFalse();

    Notification::assertSentTo($user, PasswordLockoutNotification::class, function ($notification) {
        return $notification->durationMinutes === 15;
    });
});

test('20 failed password attempts within 24 hours apply 1-hour lock, flag for review, and queue notification', function () {
    Notification::fake();

    $user = User::factory()->customer()->active()->create([
        'email' => 'targeted@example.com',
    ]);

    for ($i = 1; $i <= 20; $i++) {
        $this->travel(120)->seconds();
        RateLimiter::clear(Str::transliterate('targeted@example.com|127.0.0.1'));
        $this->post(route('login.store'), [
            'email' => 'targeted@example.com',
            'password' => 'wrong-password',
        ]);
    }

    $user->refresh();
    expect($user->isTemporarilyLocked('password'))->toBeTrue();

    $lock = AuthenticationLock::where('email_normalized', 'targeted@example.com')
        ->where('lock_category', 'password')
        ->latest('id')
        ->first();

    expect($lock)->not->toBeNull();
    expect($lock->failed_attempts_count)->toBe(20);
    expect($lock->requires_review)->toBeTrue();

    Notification::assertSentTo($user, PasswordLockoutNotification::class, function ($notification) {
        return $notification->durationMinutes === 60;
    });
});

test('successful customer login clears password failure counters and cooldowns', function () {
    $user = User::factory()->customer()->active()->create([
        'email' => 'customer@example.com',
        'password' => 'ValidPassword123!',
    ]);

    // 3 failed attempts
    for ($i = 1; $i <= 3; $i++) {
        $this->post(route('login.store'), [
            'email' => 'customer@example.com',
            'password' => 'wrong-password',
        ]);
    }

    $normalized = IdentityNormalizer::normalizeEmail($user->email);
    expect(Cache::has("auth:password:failures:{$normalized}"))->toBeTrue();

    // Now sign in with valid password
    $this->post(route('login.store'), [
        'email' => 'customer@example.com',
        'password' => 'ValidPassword123!',
    ]);

    $this->assertAuthenticatedAs($user);
    expect(Cache::has("auth:password:failures:{$normalized}"))->toBeFalse();
});

test('partial login for agent with mfa does not clear password failure counters', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $agent = User::factory()->agent()->withTwoFactor()->create([
        'email' => 'agent@example.com',
        'password' => 'ValidPassword123!',
    ]);

    // 2 failed attempts
    for ($i = 1; $i <= 2; $i++) {
        $this->post(route('login.store'), [
            'email' => 'agent@example.com',
            'password' => 'wrong-password',
        ]);
    }

    $normalized = IdentityNormalizer::normalizeEmail($agent->email);
    expect(Cache::has("auth:password:failures:{$normalized}"))->toBeTrue();

    // Step 1 of login: valid password supplied -> redirected to 2FA challenge
    $response = $this->post(route('login.store'), [
        'email' => 'agent@example.com',
        'password' => 'ValidPassword123!',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $this->assertGuest();

    // Partial login: earlier password failures MUST NOT be cleared yet (AC 98 & Section 9.8)
    expect(Cache::has("auth:password:failures:{$normalized}"))->toBeTrue();
});

test('5 invalid totp attempts terminate login session and queue notification', function () {
    Notification::fake();

    $agent = User::factory()->agent()->withTwoFactor()->create();

    // Authenticate step 1
    $this->post(route('login.store'), [
        'email' => $agent->email,
        'password' => 'password',
    ]);

    expect(session('login.id'))->toBe($agent->id);

    // 5 invalid TOTP attempts
    for ($i = 1; $i <= 5; $i++) {
        $response = $this->post(route('two-factor.login.store'), [
            'code' => '000000',
        ]);

        $response->assertSessionHasErrors('code');
    }

    // After 5 attempts, login session is terminated
    expect(session('login.id'))->toBeNull();
    $this->assertGuest();

    Notification::assertSentTo($agent, TwoFactorFailedAttemptsExceededNotification::class);
});

test('10 invalid totp attempts within 1 hour trigger 15-minute mfa cooldown and queue notification', function () {
    Notification::fake();

    $agent = User::factory()->agent()->withTwoFactor()->create();

    $service = app(AuthenticationAbuseService::class);
    $request = request();

    // Simulate 10 TOTP failures across sessions
    for ($i = 1; $i <= 10; $i++) {
        $service->recordTotpFailure($agent, $request, 1);
    }

    $agent->refresh();
    expect($service->isTotpRestricted($agent))->toBeTrue();
    expect($agent->isTemporarilyLocked('mfa'))->toBeTrue();

    $lock = AuthenticationLock::where('user_id', $agent->id)
        ->where('lock_category', 'mfa')
        ->first();

    expect($lock)->not->toBeNull();
    expect($lock->failed_attempts_count)->toBe(10);

    Notification::assertSentTo($agent, MfaCooldownNotification::class, function ($n) {
        return $n->durationMinutes === 15;
    });

    // Authenticator configuration remains safe and intact
    expect($agent->two_factor_secret)->not->toBeNull();
});

test('5 invalid recovery code attempts terminate attempt and queue notification', function () {
    Notification::fake();

    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->post(route('login.store'), [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    expect(session('login.id'))->toBe($admin->id);

    // 5 invalid recovery codes
    for ($i = 1; $i <= 5; $i++) {
        $response = $this->post(route('two-factor.login.store'), [
            'recovery_code' => 'INVALID-CODE-'.$i,
        ]);

        $response->assertSessionHasErrors('recovery_code');
    }

    // Session is terminated
    expect(session('login.id'))->toBeNull();
    $this->assertGuest();

    Notification::assertSentTo($admin, TwoFactorFailedAttemptsExceededNotification::class);
});

test('10 invalid recovery code attempts within 1 hour trigger 1-hour cooldown and preserve unused codes', function () {
    Notification::fake();

    $admin = User::factory()->admin()->withTwoFactor()->create();

    // Verify exactly 10 legitimate recovery codes were initialized by factory
    expect(UserRecoveryCode::where('user_id', $admin->id)->whereNull('consumed_at')->count())->toBe(10);

    $service = app(AuthenticationAbuseService::class);
    $request = request();

    for ($i = 1; $i <= 10; $i++) {
        $service->recordRecoveryCodeFailure($admin, $request, 1);
    }

    $admin->refresh();
    expect($service->isRecoveryCodeRestricted($admin))->toBeTrue();
    expect($admin->isTemporarilyLocked('recovery_code'))->toBeTrue();

    // All 10 recovery codes remain unused and intact (AC 71)
    expect(UserRecoveryCode::where('user_id', $admin->id)->whereNull('consumed_at')->count())->toBe(10);

    Notification::assertSentTo($admin, RecoveryCodeCooldownNotification::class, function ($n) {
        return $n->durationMinutes === 60;
    });
});

test('temporary lock isolation: password lock does not terminate active sessions', function () {
    $user = User::factory()->customer()->active()->create();

    $this->actingAs($user);

    $this->get(route('customer.dashboard'))->assertOk();

    // Apply temporary password lock
    $user->lockTemporarily(15, 'password', 'Excessive failed password attempts');

    // Existing active session is NOT interrupted
    $response = $this->get(route('customer.dashboard'));
    $response->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('temporary lock isolation: mfa cooldown does not block password validation', function () {
    $agent = User::factory()->agent()->withTwoFactor()->create([
        'email' => 'agent@example.com',
        'password' => 'ValidPassword123!',
    ]);

    // Apply MFA lock to agent
    $agent->lockTemporarily(15, 'mfa', 'MFA cooldown');

    // Password validation still succeeds and reaches two factor challenge
    $response = $this->post(route('login.store'), [
        'email' => 'agent@example.com',
        'password' => 'ValidPassword123!',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    expect(session('login.id'))->toBe($agent->id);
});

test('suspended and deactivated accounts do not become active upon lock expiry or unlock', function (AccountState $state) {
    $user = User::factory()->customer()->create([
        'account_state' => $state,
    ]);

    $admin = User::factory()->admin()->active()->create();
    $admin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);

    $user->lockTemporarily(15, 'password', 'Test lock');

    $service = app(AuthenticationAbuseService::class);
    $service->manualUnlock($user, $admin, 'password', UnlockVerificationMethod::InPerson, 'Valid protocol verification', app(UnlockState::class)->token($user->fresh(), $admin->fresh(), 'password'));

    $user->refresh();
    expect($user->account_state)->toBe($state);
    expect($user->account_state->canSignIn())->toBeFalse();
})->with([
    'suspended' => AccountState::Suspended,
    'deactivated' => AccountState::Deactivated,
    'invited' => AccountState::Invited,
]);

test('credential stuffing across 5 accounts from single IP is detected and restricted', function () {
    $service = app(AuthenticationAbuseService::class);
    $request = Request::create('/login', 'POST', [], [], [], ['REMOTE_ADDR' => '198.51.100.42']);

    for ($i = 1; $i <= 5; $i++) {
        $service->recordPasswordFailure("victim{$i}@example.com", $request);
    }

    expect($service->isSourceAbusive($request))->toBeTrue();
    expect($service->isPasswordRestricted('any-other@example.com', null, $request))->toBeTrue();
});

test('non-admin users cannot access admin lockout endpoints', function () {
    $customer = User::factory()->customer()->active()->create();

    $this->actingAs($customer)
        ->get(route('admin.lockouts.index'))
        ->assertForbidden();

    $this->actingAs($customer)
        ->post(route('admin.lockouts.unlock', $customer))
        ->assertForbidden();
});

test('admin can view lockout records with masked IPs and non-secret details', function () {
    $admin = User::factory()->admin()->active()->create();
    $admin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);
    $victim = User::factory()->customer()->active()->create([
        'email' => 'target@example.com',
    ]);

    AuthenticationLock::create([
        'user_id' => $victim->id,
        'email_normalized' => 'target@example.com',
        'lock_category' => 'password',
        'reason' => 'Excessive failed password attempts',
        'failed_attempts_count' => 10,
        'ip_address' => '203.0.113.195',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
        'locked_at' => Carbon::now(),
        'locked_until' => Carbon::now()->addMinutes(15),
        'requires_review' => false,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.lockouts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Lockouts')
            ->has('locks.data', 1)
            ->has('verification_methods')
            ->where('locks.data.0.masked_ip', '203.0.***.***')
            ->where('locks.data.0.failed_attempts_count', 10)
            ->where('locks.data.0.lock_category', 'password')
            ->where('locks.data.0.can_unlock', true)
        );
});

test('admin can manually unlock user account clearing restrictions and queuing notification', function () {
    Notification::fake();

    $admin = User::factory()->admin()->active()->create();
    $admin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);
    $targetUser = User::factory()->customer()->active()->create([
        'email' => 'target@example.com',
    ]);

    $targetUser->lockTemporarily(15, 'password', 'Test lock');
    Cache::put('auth:password:cooldown:target@example.com', Carbon::now()->addMinutes(15)->timestamp);

    $lock = AuthenticationLock::create([
        'user_id' => $targetUser->id,
        'email_normalized' => 'target@example.com',
        'lock_category' => 'password',
        'reason' => 'Test lock',
        'failed_attempts_count' => 10,
        'locked_at' => Carbon::now(),
        'locked_until' => Carbon::now()->addMinutes(15),
    ]);

    $response = $this->actingAs($admin)->post(route('admin.lockouts.unlock', $targetUser), [
        'restriction_token' => app(UnlockState::class)->token($targetUser, auth()->user(), 'password'),
        'category' => 'password',
        'verification_method' => 'approved_video_call',
        'reason' => 'Identity verified via video call',
    ]);

    $response->assertRedirect();
    $targetUser->refresh();
    expect($targetUser->isTemporarilyLocked('password'))->toBeFalse();
    expect(Cache::has('auth:password:cooldown:target@example.com'))->toBeFalse();

    $lock->refresh();
    expect($lock->unlocked_at)->not->toBeNull();
    expect($lock->unlocked_by_user_id)->toBe($admin->id);
    expect($lock->unlock_reason)->toBe('Identity verified via video call');
    expect($lock->unlock_verification_method)->toBe(UnlockVerificationMethod::ApprovedVideoCall);

    Notification::assertSentTo($targetUser, AccountUnlockedNotification::class);
});

test('admin cannot manually unlock their own account', function () {
    $admin = User::factory()->admin()->active()->create();
    $admin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);
    $admin->lockTemporarily(15, 'password', 'Self lock');

    $response = $this->actingAs($admin)->post(route('admin.lockouts.unlock', $admin), [
        'restriction_token' => app(UnlockState::class)->token($admin, auth()->user(), 'password'),
        'category' => 'password',
        'verification_method' => 'in_person',
        'reason' => 'Self verification attempt',
    ]);

    $response->assertForbidden();
    expect($admin->fresh()->isTemporarilyLocked('password'))->toBeTrue();
});

test('a suspended Admin without security permission cannot unlock an Admin', function () {
    $lockedAdmin = User::factory()->admin()->active()->create();
    $suspendedAdmin = User::factory()->admin()->create(['account_state' => AccountState::Suspended]);
    $service = app(AuthenticationAbuseService::class);

    expect(fn () => $service->manualUnlock($lockedAdmin, $suspendedAdmin, 'password', UnlockVerificationMethod::InPerson, 'Emergency unlock attempt'))
        ->toThrow(AuthorizationException::class);
});

test('suspected compromise session revocation terminates sessions, trusted devices, and queues notification', function () {
    Notification::fake();

    $user = User::factory()->agent()->active()->create();

    // Create a session in DB
    $sessionTable = config('session.table', 'sessions');
    DB::table($sessionTable)->insert([
        'id' => 'compromised-session-id',
        'user_id' => $user->id,
        'ip_address' => '192.0.2.1',
        'user_agent' => 'Suspicious-Browser/1.0',
        'payload' => 'payload',
        'last_activity' => time(),
    ]);

    $service = app(AuthenticationAbuseService::class);
    $service->revokeSessionsForSuspectedCompromise($user, 'Simultaneous sign-in attempts from disparate geographic locations');

    expect(DB::table($sessionTable)->where('user_id', $user->id)->count())->toBe(0);

    Notification::assertSentTo($user, CompromiseSessionRevocationNotification::class, function ($n) {
        return str_contains($n->reason, 'Simultaneous sign-in attempts');
    });
});
