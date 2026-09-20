<?php

use App\Models\User;
use App\Models\UserRecoveryCode;
use App\Notifications\Auth\RecoveryCodeUsedNotification;
use App\Notifications\Auth\TwoFactorFailedAttemptsExceededNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
});

test('two factor challenge redirects to login when not authenticated', function () {
    $response = $this->get(route('two-factor.login'));

    $response->assertRedirect(route('login'));
});

test('two factor challenge can be rendered', function () {
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->get(route('two-factor.login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/TwoFactorChallenge'),
        );
});

test('valid totp logs in user and redirects to role dashboard', function () {
    $agent = User::factory()->agent()->withTwoFactor()->create();

    $this->post(route('login'), [
        'email' => $agent->email,
        'password' => 'password',
    ]);

    $totp = app(Google2FA::class)->getCurrentOtp(decrypt($agent->two_factor_secret));

    $response = $this->post(route('two-factor.login.store'), [
        'code' => $totp,
    ]);

    $this->assertAuthenticatedAs($agent);
    $response->assertRedirect(route('agent.dashboard', absolute: false));

    // Freshness timestamps are stored in session
    expect(session('auth.password_confirmed_at'))->not->toBeNull()
        ->and(session('auth.mfa_confirmed_at'))->not->toBeNull();
});

test('totp replay within same validity window is rejected', function () {
    $agent = User::factory()->agent()->withTwoFactor()->create();

    // First login succeeds
    $this->post(route('login'), [
        'email' => $agent->email,
        'password' => 'password',
    ]);

    $totp = app(Google2FA::class)->getCurrentOtp(decrypt($agent->two_factor_secret));

    $this->post(route('two-factor.login.store'), [
        'code' => $totp,
    ])->assertRedirect(route('agent.dashboard', absolute: false));

    $this->post(route('logout'));

    // Second login attempt in the same 30s window using the SAME TOTP code
    $this->post(route('login'), [
        'email' => $agent->email,
        'password' => 'password',
    ]);

    $replayResponse = $this->post(route('two-factor.login.store'), [
        'code' => $totp,
    ]);

    $this->assertGuest();
    $replayResponse->assertSessionHasErrors('code');
});

test('recovery code login consumes single use code, requires replacement, and dispatches notification', function () {
    Notification::fake();

    $admin = User::factory()->admin()->withTwoFactor(['rec-code-1', 'rec-code-2'])->create();

    $this->post(route('login'), [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $response = $this->post(route('two-factor.login.store'), [
        'recovery_code' => 'rec-code-1',
    ]);

    $this->assertAuthenticatedAs($admin);
    // Restricted session requiring replacement redirects to enrolment
    $response->assertRedirect(route('two-factor.enrolment'));

    // Code is consumed
    $usedRecord = UserRecoveryCode::where('user_id', $admin->id)
        ->where('code_hash', hash('sha256', 'rec-code-1'))
        ->first();
    expect($usedRecord->consumed_at)->not->toBeNull()
        ->and($admin->unconsumedRecoveryCodesCount())->toBe(1);

    // Reusing the same recovery code fails
    $this->post(route('logout'));
    $this->post(route('login'), [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $reuseResponse = $this->post(route('two-factor.login.store'), [
        'recovery_code' => 'rec-code-1',
    ]);
    $this->assertGuest();
    $reuseResponse->assertSessionHasErrors('recovery_code');

    Notification::assertSentTo($admin, RecoveryCodeUsedNotification::class);
});

test('5 invalid two factor attempts terminates login challenge session and sends notification', function () {
    Notification::fake();

    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->post(route('login'), [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    for ($i = 1; $i <= 4; $i++) {
        $this->post(route('two-factor.login.store'), [
            'code' => '000000',
        ])->assertSessionHasErrors('code');
        expect(session('login.id'))->toBe($admin->id);
    }

    // 5th failed attempt terminates session
    $fifthResponse = $this->post(route('two-factor.login.store'), [
        'code' => '000000',
    ]);

    $fifthResponse->assertSessionHasErrors('code');
    expect(session('login.id'))->toBeNull();

    // 6th attempt fails because challenge context is gone (redirects to login)
    $this->get(route('two-factor.login'))->assertRedirect(route('login'));

    Notification::assertSentTo($admin, TwoFactorFailedAttemptsExceededNotification::class);
});

test('assisted recovery handoff page is accessible', function () {
    $this->get(route('auth.assisted-recovery'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/AssistedRecoveryHandoff'),
        );
});
