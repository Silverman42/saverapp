<?php

use App\Enums\AuthenticatorState;
use App\Models\User;
use App\Models\UserRecoveryCode;
use App\Notifications\Auth\AuthenticatorReplacedNotification;
use App\Notifications\Auth\AuthenticatorReplacementCancelledNotification;
use App\Notifications\Auth\AuthenticatorReplacementStartedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

test('starting replacement requires valid current password and valid current totp', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $totp = app(Google2FA::class)->getCurrentOtp(decrypt($admin->two_factor_secret));

    // Wrong password fails
    $wrongPass = $this->actingAs($admin)->post(route('two-factor.replace'), [
        'current_password' => 'wrong-password',
        'current_code' => $totp,
    ]);
    $wrongPass->assertSessionHasErrors('current_password');

    // Wrong TOTP code fails
    $wrongTotp = $this->actingAs($admin)->post(route('two-factor.replace'), [
        'current_password' => 'password',
        'current_code' => '000000',
    ]);
    $wrongTotp->assertSessionHasErrors('current_code');
});

test('starting replacement initiates pending state and dispatches notification', function () {
    Notification::fake();

    $agent = User::factory()->agent()->withTwoFactor()->create();
    $totp = app(Google2FA::class)->getCurrentOtp(decrypt($agent->two_factor_secret));

    $response = $this->actingAs($agent)->post(route('two-factor.replace'), [
        'current_password' => 'password',
        'current_code' => $totp,
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertSessionHas('replacementSetup');

    $fresh = $agent->refresh();
    expect($fresh->authenticator_state)->toBe(AuthenticatorState::ReplacementPending)
        ->and($fresh->two_factor_pending_secret)->not->toBeNull()
        ->and($fresh->two_factor_pending_purpose)->toBe('replacement')
        ->and($fresh->two_factor_pending_expires_at)->not->toBeNull();

    Notification::assertSentTo($agent, AuthenticatorReplacementStartedNotification::class);
});

test('cancelling replacement clears pending secret, preserves active secret, and dispatches notification', function () {
    Notification::fake();

    $agent = User::factory()->agent()->withTwoFactor()->create();
    $originalSecret = $agent->two_factor_secret;
    $totp = app(Google2FA::class)->getCurrentOtp(decrypt($originalSecret));

    // Start replacement
    $this->actingAs($agent)->post(route('two-factor.replace'), [
        'current_password' => 'password',
        'current_code' => $totp,
    ]);

    // Cancel replacement
    $cancelResponse = $this->actingAs($agent)->delete(route('two-factor.replace.cancel'));
    $cancelResponse->assertSessionHasNoErrors();

    $fresh = $agent->refresh();
    expect($fresh->authenticator_state)->toBe(AuthenticatorState::Active)
        ->and($fresh->two_factor_pending_secret)->toBeNull()
        ->and($fresh->two_factor_pending_purpose)->toBeNull()
        ->and($fresh->two_factor_secret)->toBe($originalSecret);

    Notification::assertSentTo($agent, AuthenticatorReplacementCancelledNotification::class);
});

test('confirming replacement updates secret, replaces recovery codes, revokes other sessions, and dispatches notification', function () {
    Notification::fake();

    $admin = User::factory()->admin()->withTwoFactor(['old-code-1', 'old-code-2'])->create();
    $originalSecret = $admin->two_factor_secret;
    $totp = app(Google2FA::class)->getCurrentOtp(decrypt($originalSecret));

    // Create an auxiliary active session in sessions table to verify session revocation
    DB::table('sessions')->insert([
        'id' => 'other-session-token',
        'user_id' => $admin->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'AnotherBrowser',
        'payload' => 'payload',
        'last_activity' => time(),
    ]);

    // Start replacement
    $this->actingAs($admin)->post(route('two-factor.replace'), [
        'current_password' => 'password',
        'current_code' => $totp,
    ]);

    $fresh = $admin->refresh();
    $newSecret = decrypt($fresh->two_factor_pending_secret);
    $newTotp = app(Google2FA::class)->getCurrentOtp($newSecret);

    // Confirm replacement with code from NEW secret
    $confirmResponse = $this->actingAs($admin)->post(route('two-factor.replace.confirm'), [
        'code' => $newTotp,
    ]);

    $confirmResponse->assertSessionHasNoErrors();
    $confirmResponse->assertSessionHas('recoveryCodes');

    $newCodes = session('recoveryCodes');
    expect($newCodes)->toHaveCount(10);

    $refreshed = $admin->refresh();
    expect($refreshed->two_factor_secret)->not->toBe($originalSecret)
        ->and($refreshed->authenticator_state)->toBe(AuthenticatorState::Active)
        ->and($refreshed->two_factor_pending_secret)->toBeNull()
        ->and($refreshed->recoveryCodes()->count())->toBe(10);

    // Assert old recovery codes are deleted and new hashed codes exist
    expect(UserRecoveryCode::where('user_id', $admin->id)->where('code_hash', hash('sha256', 'old-code-1'))->exists())->toBeFalse();
    expect(UserRecoveryCode::where('user_id', $admin->id)->where('code_hash', hash('sha256', $newCodes[0]))->exists())->toBeTrue();

    // Assert other session was revoked
    expect(DB::table('sessions')->where('id', 'other-session-token')->exists())->toBeFalse();

    Notification::assertSentTo($admin, AuthenticatorReplacedNotification::class);
});
