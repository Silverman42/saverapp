<?php

use App\Models\User;
use App\Models\UserRecoveryCode;
use App\Notifications\Auth\RecoveryCodesRegeneratedNotification;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

test('getting recovery codes endpoint returns zero raw codes and non secret metadata', function () {
    $admin = User::factory()->admin()->withTwoFactor(['rec-1', 'rec-2', 'rec-3'])->create();

    $response = $this->actingAs($admin)->getJson(route('two-factor.recovery-codes'));

    $response->assertOk();
    $response->assertHeader('Cache-Control', 'no-store, private');
    $response->assertHeader('Pragma', 'no-cache');
    $response->assertHeader('Referrer-Policy', 'no-referrer');

    $response->assertJson([
        'codes' => [],
        'remaining' => 3,
        'acknowledged' => true,
    ]);
});

test('regenerating recovery codes requires valid password and active totp', function () {
    $agent = User::factory()->agent()->withTwoFactor()->create();
    $totp = app(Google2FA::class)->getCurrentOtp(decrypt($agent->two_factor_secret));

    // Wrong password fails
    $this->actingAs($agent)->post(route('two-factor.regenerate-recovery-codes'), [
        'password' => 'wrong-password',
        'code' => $totp,
    ])->assertSessionHasErrors('password');

    // Wrong TOTP fails
    $this->actingAs($agent)->post(route('two-factor.regenerate-recovery-codes'), [
        'password' => 'password',
        'code' => '000000',
    ])->assertSessionHasErrors('code');
});

test('regenerating recovery codes invalidates old codes, creates 10 new codes, and sends notification', function () {
    Notification::fake();

    $admin = User::factory()->admin()->withTwoFactor(['old-one', 'old-two'])->create();
    $totp = app(Google2FA::class)->getCurrentOtp(decrypt($admin->two_factor_secret));

    $response = $this->actingAs($admin)->post(route('two-factor.regenerate-recovery-codes'), [
        'password' => 'password',
        'code' => $totp,
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertSessionHas('recoveryCodes');

    $codes = session('recoveryCodes');
    expect($codes)->toHaveCount(10);

    // Old codes deleted
    expect(UserRecoveryCode::where('user_id', $admin->id)->where('code_hash', hash('sha256', 'old-one'))->exists())->toBeFalse();

    // New 10 codes stored as SHA-256 hashes
    expect(UserRecoveryCode::where('user_id', $admin->id)->where('code_hash', hash('sha256', $codes[0]))->exists())->toBeTrue()
        ->and($admin->unconsumedRecoveryCodesCount())->toBe(10);

    Notification::assertSentTo($admin, RecoveryCodesRegeneratedNotification::class);
});

test('self service disabling of MFA is forbidden for admin and agent', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $agent = User::factory()->agent()->withTwoFactor()->create();

    $this->actingAs($admin)->delete(route('two-factor.disable'))
        ->assertForbidden();

    $this->actingAs($agent)->delete(route('two-factor.disable'))
        ->assertForbidden();

    expect($admin->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue()
        ->and($agent->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});
