<?php

use App\Enums\AccountState;
use App\Jobs\ExpirePendingTwoFactorSetup;
use App\Models\AgentProfile;
use App\Models\User;
use App\Models\UserRecoveryCode;
use App\Notifications\Auth\AuthenticatorEnrolledNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;

test('agent or admin without MFA is redirected to two-factor enrolment after password login', function () {
    $agent = User::factory()->agent()->create();

    $response = $this->post(route('login.store'), [
        'email' => $agent->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($agent);
    $response->assertRedirect(route('two-factor.enrolment'));
    expect($agent->fresh()->account_state)->toBe(AccountState::MfaSetupRequired);
});

test('user in mfa_setup_required cannot access protected app routes and is redirected to enrolment', function () {
    $admin = User::factory()->admin()->mfaSetupRequired()->create();

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertRedirect(route('two-factor.enrolment'));

    $this->actingAs($admin)
        ->get(route('security.edit'))
        ->assertRedirect(route('two-factor.enrolment'));
});

test('customer cannot access two factor enrolment', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get(route('two-factor.enrolment'))
        ->assertForbidden();
});

test('enrolment page renders with secure headers and pending secret details', function () {
    $agent = User::factory()->agent()->mfaSetupRequired()->create();

    $response = $this->actingAs($agent)->get(route('two-factor.enrolment'));

    $response->assertOk();
    $response->assertHeader('Cache-Control', 'no-store, private');
    $response->assertHeader('Pragma', 'no-cache');
    $response->assertHeader('Referrer-Policy', 'no-referrer');

    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/TwoFactorEnrolment')
        ->has('qrCodeSvg')
        ->has('manualSetupKey')
        ->where('isReplacement', false)
        ->where('hasConfirmed', false)
    );

    $fresh = $agent->refresh();
    expect($fresh->two_factor_pending_secret)->not->toBeNull()
        ->and($fresh->two_factor_pending_expires_at)->not->toBeNull();
});

test('confirming enrolment with invalid TOTP code fails validation', function () {
    $agent = User::factory()->agent()->mfaSetupRequired()->create();

    $this->actingAs($agent)->get(route('two-factor.enrolment'));

    $response = $this->actingAs($agent)->post(route('two-factor.enrolment.confirm'), [
        'code' => '000000',
    ]);

    $response->assertSessionHasErrors('code');
    expect($agent->fresh()->two_factor_confirmed_at)->toBeNull();
});

test('confirming enrolment with valid TOTP code creates 10 recovery codes and flashes them once', function () {
    Notification::fake();

    $agent = User::factory()->agent()->mfaSetupRequired()->create();

    // Visit page to initialize pending secret
    $this->actingAs($agent)->get(route('two-factor.enrolment'));
    $fresh = $agent->refresh();

    $secret = decrypt($fresh->two_factor_pending_secret);
    $validCode = app(Google2FA::class)->getCurrentOtp($secret);

    $response = $this->actingAs($agent)->post(route('two-factor.enrolment.confirm'), [
        'code' => $validCode,
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertSessionHas('recoveryCodes');

    $codes = session('recoveryCodes');
    expect($codes)->toHaveCount(10);

    $refreshed = $agent->refresh();
    expect($refreshed->two_factor_secret)->not->toBeNull()
        ->and($refreshed->two_factor_confirmed_at)->not->toBeNull()
        ->and($refreshed->two_factor_pending_secret)->toBeNull()
        ->and($refreshed->recoveryCodes()->count())->toBe(10);

    // Assert recovery codes stored as SHA-256 hashes
    foreach ($codes as $plainCode) {
        $expectedHash = hash('sha256', $plainCode);
        expect(UserRecoveryCode::where('user_id', $agent->id)->where('code_hash', $expectedHash)->exists())->toBeTrue();
    }

    Notification::assertSentTo($agent, AuthenticatorEnrolledNotification::class);
});

test('acknowledging recovery codes completes setup and transitions user to active state', function () {
    $agent = User::factory()->agent()->mfaSetupRequired()->create();
    AgentProfile::factory()->create(['user_id' => $agent->id]);

    // Visit page and confirm TOTP
    $this->actingAs($agent)->get(route('two-factor.enrolment'));
    $fresh = $agent->refresh();
    $validCode = app(Google2FA::class)->getCurrentOtp(decrypt($fresh->two_factor_pending_secret));

    $this->actingAs($agent)->post(route('two-factor.enrolment.confirm'), [
        'code' => $validCode,
    ]);

    $ackResponse = $this->actingAs($agent)->post(route('two-factor.enrolment.acknowledge'));

    $ackResponse->assertRedirect(route('dashboard'));

    $refreshed = $agent->refresh();
    expect($refreshed->account_state)->toBe(AccountState::Active)
        ->and($refreshed->recovery_codes_acknowledged_at)->not->toBeNull();

    // Now agent can access agent dashboard without redirection
    $this->actingAs($refreshed)
        ->get(route('agent.dashboard'))
        ->assertOk();
});

test('expired pending two-factor setup is cleaned up by expiration job without affecting active secret', function () {
    $activeAdmin = User::factory()->admin()->withTwoFactor()->create([
        'two_factor_pending_secret' => encrypt('PENDINGSECRET123'),
        'two_factor_pending_expires_at' => now()->subMinutes(5),
    ]);

    $pendingAgent = User::factory()->agent()->mfaSetupRequired()->create([
        'two_factor_pending_secret' => encrypt('PENDINGAGENT123'),
        'two_factor_pending_expires_at' => now()->subMinutes(5),
    ]);

    (new ExpirePendingTwoFactorSetup($activeAdmin->id, $activeAdmin->two_factor_pending_expires_at->toISOString()))->handle();
    (new ExpirePendingTwoFactorSetup($pendingAgent->id, $pendingAgent->two_factor_pending_expires_at->toISOString()))->handle();

    $freshAdmin = $activeAdmin->refresh();
    $freshAgent = $pendingAgent->refresh();

    // Pending secrets are cleared
    expect($freshAdmin->two_factor_pending_secret)->toBeNull()
        ->and($freshAgent->two_factor_pending_secret)->toBeNull();

    // Active secret remains intact
    expect($freshAdmin->two_factor_secret)->not->toBeNull()
        ->and($freshAdmin->hasEnabledTwoFactorAuthentication())->toBeTrue();
});
