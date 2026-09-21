<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\User;
use App\Notifications\Auth\AdminPasswordResetNotification;
use App\Notifications\Auth\PasswordResetSuccessNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
    Cache::flush();
});

// AC 11: A password-reset request always returns a generic response, regardless of account existence, user type, or status.
test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('password reset request for active customer sends queued notification and returns generic message', function () {
    Notification::fake();

    $user = User::factory()->create(['account_state' => AccountState::Active]);

    $response = $this->post(route('password.email'), ['email' => $user->email]);

    $response
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', "If an account exists for this email, we've sent password reset instructions.");

    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) {
        return $notification instanceof ShouldQueue;
    });
});

test('password reset request for nonexistent email returns identical generic response without sending notification', function () {
    Notification::fake();

    $response = $this->post(route('password.email'), ['email' => 'nonexistent@example.com']);

    $response
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', "If an account exists for this email, we've sent password reset instructions.");

    Notification::assertNothingSent();
});

test('password reset request for invited account returns generic response without sending notification', function () {
    Notification::fake();

    $invitedUser = User::factory()->create([
        'account_state' => AccountState::Invited,
    ]);

    $response = $this->post(route('password.email'), ['email' => $invitedUser->email]);

    $response
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', "If an account exists for this email, we've sent password reset instructions.");

    Notification::assertNothingSent();
});

test('password reset request for suspended or deactivated account sends reset notification', function () {
    Notification::fake();

    $suspendedUser = User::factory()->create([
        'account_state' => AccountState::Suspended,
    ]);

    $response = $this->post(route('password.email'), ['email' => $suspendedUser->email]);

    $response
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', "If an account exists for this email, we've sent password reset instructions.");

    Notification::assertSentTo($suspendedUser, ResetPasswordNotification::class);
});

test('password reset request rate limiting applies abuse controls without changing generic response', function () {
    Notification::fake();

    $user = User::factory()->create();

    // First request succeeds
    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status', "If an account exists for this email, we've sent password reset instructions.");

    Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);

    // Immediate second request from same source/account is rate-limited: suppresses email, still returns generic message
    $response = $this->post(route('password.email'), ['email' => $user->email]);

    $response
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', "If an account exists for this email, we've sent password reset instructions.");

    // No additional email was dispatched
    Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
});

// AC 12: Only the newest unexpired reset link works, and it can be used only once within 15 minutes of issue.
test('reset password screen renders valid state for active token and neutral error for invalid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
        // Valid token
        $response = $this->get(route('password.reset', [
            'token' => $notification->token,
            'email' => $user->email,
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('auth/ResetPassword')
            ->where('isValidToken', true)
            ->where('requiresTwoFactor', false)
            ->where('email', $user->email)
        );

        // Invalid token
        $invalidResponse = $this->get(route('password.reset', [
            'token' => 'invalid-token',
            'email' => $user->email,
        ]));

        $invalidResponse->assertOk();
        $invalidResponse->assertInertia(fn ($page) => $page
            ->component('auth/ResetPassword')
            ->where('isValidToken', false)
        );

        return true;
    });
});

test('password reset token expires after 15 minutes', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
        // Fast-forward 16 minutes (exceeding 15-minute expiry)
        Carbon::setTestNow(now()->addMinutes(16));

        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-secure-passphrase-15',
            'password_confirmation' => 'new-secure-passphrase-15',
        ]);

        $response->assertSessionHasErrors('email');

        Carbon::setTestNow();

        return true;
    });
});

test('requesting a newer reset link invalidates previous reset links', function () {
    Notification::fake();

    $user = User::factory()->create();

    // First request
    $this->post(route('password.email'), ['email' => $user->email]);
    $firstToken = null;
    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use (&$firstToken) {
        $firstToken = $notification->token;

        return true;
    });

    // Clear rate limits to allow second request
    Cache::flush();
    Notification::fake();

    // Second request creates newer token
    $this->post(route('password.email'), ['email' => $user->email]);
    $secondToken = null;
    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use (&$secondToken) {
        $secondToken = $notification->token;

        return true;
    });

    expect($firstToken)->not->toBeNull()
        ->and($secondToken)->not->toBeNull();

    // Attempting reset with older token fails
    $response = $this->post(route('password.update'), [
        'token' => $firstToken,
        'email' => $user->email,
        'password' => 'new-secure-passphrase-15',
        'password_confirmation' => 'new-secure-passphrase-15',
    ]);

    $response->assertSessionHasErrors('email');

    // Reset with newer token succeeds
    $newResponse = $this->post(route('password.update'), [
        'token' => $secondToken,
        'email' => $user->email,
        'password' => 'new-secure-passphrase-15',
        'password_confirmation' => 'new-secure-passphrase-15',
    ]);

    $newResponse->assertSessionHasNoErrors()->assertRedirect(route('login'));
});

test('token is single use and cannot be reused after successful password reset', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
        // First reset succeeds
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-secure-passphrase-15',
            'password_confirmation' => 'new-secure-passphrase-15',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        // Reusing same token fails
        $retryResponse = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'another-passphrase-15',
            'password_confirmation' => 'another-passphrase-15',
        ]);

        $retryResponse->assertSessionHasErrors('email');

        return true;
    });
});

// AC 13: Customer can reset with email link, while Agent or Admin must additionally supply valid authenticator or recovery code.
// Section 4.3: Customer password requires minimum 15 characters.
test('customer can reset password with 15+ characters and does not require second factor', function () {
    Notification::fake();

    $customer = User::factory()->customer()->create();

    $this->post(route('password.email'), ['email' => $customer->email]);

    Notification::assertSentTo($customer, ResetPasswordNotification::class, function ($notification) use ($customer) {
        // Shorter than 15 characters fails for customer
        $shortResponse = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $customer->email,
            'password' => 'short-pass8',
            'password_confirmation' => 'short-pass8',
        ]);

        $shortResponse->assertSessionHasErrors('password');

        // 15+ characters succeeds
        $validResponse = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $customer->email,
            'password' => 'valid-passphrase-over-15-chars',
            'password_confirmation' => 'valid-passphrase-over-15-chars',
        ]);

        $validResponse
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        expect(Hash::check('valid-passphrase-over-15-chars', $customer->refresh()->password))->toBeTrue();

        return true;
    });
});

test('agent with MFA requires valid TOTP code or recovery code to reset password', function () {
    Notification::fake();

    $agent = User::factory()->agent()->withTwoFactor(['code-one', 'code-two'])->create();

    $this->post(route('password.email'), ['email' => $agent->email]);

    Notification::assertSentTo($agent, ResetPasswordNotification::class, function ($notification) use ($agent) {
        // Reset without 2FA code fails
        $noCodeResponse = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $agent->email,
            'password' => 'validpass8',
            'password_confirmation' => 'validpass8',
        ]);

        $noCodeResponse->assertSessionHasErrors('code');

        // Reset with invalid 2FA code fails
        $invalidCodeResponse = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $agent->email,
            'code' => '000000',
            'password' => 'validpass8',
            'password_confirmation' => 'validpass8',
        ]);

        $invalidCodeResponse->assertSessionHasErrors('code');

        // Reset with valid recovery code succeeds and consumes the recovery code
        $validRecoveryResponse = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $agent->email,
            'recovery_code' => 'code-one',
            'password' => 'validpass8',
            'password_confirmation' => 'validpass8',
        ]);

        $validRecoveryResponse
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        $freshAgent = $agent->refresh();
        $usedCode = $freshAgent->recoveryCodes()->where('code_hash', hash('sha256', 'code-one'))->first();
        expect(Hash::check('validpass8', $freshAgent->password))->toBeTrue()
            ->and($usedCode)->not->toBeNull()
            ->and($usedCode->consumed_at)->not->toBeNull();

        return true;
    });
});

test('admin with MFA can reset password using valid TOTP code', function () {
    Notification::fake();

    $admin = User::factory()->admin()->withTwoFactor(['rec-1', 'rec-2'])->create();

    $this->post(route('password.email'), ['email' => $admin->email]);

    Notification::assertSentTo($admin, ResetPasswordNotification::class, function ($notification) use ($admin) {
        $totpCode = app(Google2FA::class)->getCurrentOtp(decrypt($admin->two_factor_secret));

        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $admin->email,
            'code' => $totpCode,
            'password' => 'admin-secure-8',
            'password_confirmation' => 'admin-secure-8',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect(route('login'));

        expect(Hash::check('admin-secure-8', $admin->refresh()->password))->toBeTrue();

        return true;
    });
});

// AC 14: A successful reset revokes every session and trusted device and requires the user to sign in again.
test('successful reset revokes active sessions and does not automatically log the user in', function () {
    Notification::fake();

    $user = User::factory()->create();

    // Create a mock active session in the database
    DB::table('sessions')->insert([
        'id' => 'test-session-id',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit',
        'payload' => 'payload',
        'last_activity' => time(),
    ]);

    $this->assertDatabaseHas('sessions', ['user_id' => $user->id]);

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-secure-passphrase-15',
            'password_confirmation' => 'new-secure-passphrase-15',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        // Assert user is NOT authenticated
        $this->assertGuest();

        // Assert session was deleted from sessions table
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);

        return true;
    });
});

// AC 15: Password reset does not activate an invited account or restore a suspended or deactivated account.
test('suspended or deactivated account remains suspended or deactivated after password reset', function () {
    Notification::fake();

    $suspendedUser = User::factory()->create([
        'account_state' => AccountState::Suspended,
    ]);

    $this->post(route('password.email'), ['email' => $suspendedUser->email]);

    Notification::assertSentTo($suspendedUser, ResetPasswordNotification::class, function ($notification) use ($suspendedUser) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $suspendedUser->email,
            'password' => 'new-secure-passphrase-15',
            'password_confirmation' => 'new-secure-passphrase-15',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect(route('login'));

        // Remains suspended and cannot sign in
        $fresh = $suspendedUser->refresh();
        expect($fresh->account_state)->toBe(AccountState::Suspended)
            ->and($fresh->canSignIn())->toBeFalse();

        return true;
    });
});

// AC 15 & Section 7.6: Temporarily locked by password failures is cleared by successful password reset
test('temporary password failure lock is cleared by successful password reset', function () {
    Notification::fake();

    $user = User::factory()->create();
    $user->lockTemporarily(15, 'password', 'Excessive failed password attempts');

    expect($user->isTemporarilyLocked('password'))->toBeTrue();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-secure-passphrase-15',
            'password_confirmation' => 'new-secure-passphrase-15',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $fresh = $user->refresh();
        expect($fresh->isTemporarilyLocked('password'))->toBeFalse()
            ->and($fresh->locked_until)->toBeNull();

        return true;
    });
});

// AC 16: Password reset preserves roles, Admin permissions, assignments, authenticator configuration, and financial attribution.
test('password reset preserves user roles and MFA configuration', function () {
    Notification::fake();

    $agent = User::factory()->agent()->withTwoFactor(['rec-code-1', 'rec-code-2'])->create();

    $this->post(route('password.email'), ['email' => $agent->email]);

    Notification::assertSentTo($agent, ResetPasswordNotification::class, function ($notification) use ($agent) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $agent->email,
            'recovery_code' => 'rec-code-1',
            'password' => 'new-agent-pass-8',
            'password_confirmation' => 'new-agent-pass-8',
        ])->assertSessionHasNoErrors();

        $fresh = $agent->refresh();
        expect($fresh->user_type)->toBe(UserType::Agent)
            ->and($fresh->two_factor_secret)->not->toBeNull()
            ->and($fresh->hasEnabledTwoFactorAuthentication())->toBeTrue();

        return true;
    });
});

// AC 16 & AUTH-016 & AUTHZ-019: Security notifications are dispatched upon password reset
test('password reset dispatches queued success notification to user and admin notification to other admins', function () {
    Notification::fake();

    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();
    $otherAdmin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);
    $baselineAdmin = User::factory()->admin()->create();
    $customer = User::factory()->customer()->create();

    $this->post(route('password.email'), ['email' => $admin->email]);

    Notification::assertSentTo($admin, ResetPasswordNotification::class, function ($notification) use ($admin, $otherAdmin, $baselineAdmin, $customer) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $admin->email,
            'password' => 'new-admin-pass-8',
            'password_confirmation' => 'new-admin-pass-8',
        ])->assertSessionHasNoErrors();

        // User received PasswordResetSuccessNotification
        Notification::assertSentTo($admin, PasswordResetSuccessNotification::class, function ($notif) {
            return $notif instanceof ShouldQueue;
        });

        // Other active Admin with security.operations.manage received AdminPasswordResetNotification
        Notification::assertSentTo($otherAdmin, AdminPasswordResetNotification::class, function ($notif) use ($admin) {
            return $notif instanceof ShouldQueue && $notif->affectedAdmin->id === $admin->id;
        });

        // Baseline Admin without security.operations.manage did not receive admin notification
        Notification::assertNotSentTo($baselineAdmin, AdminPasswordResetNotification::class);

        // Customer did not receive admin notification
        Notification::assertNotSentTo($customer, AdminPasswordResetNotification::class);

        return true;
    });
});
