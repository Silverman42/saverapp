<?php

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

test('fresh authentication screen requires authentication', function () {
    $this->get(route('fresh-authentication'))
        ->assertRedirect(route('login'));
});

test('fresh authentication screen renders with role-appropriate props for customer', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get(route('fresh-authentication'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/FreshAuthentication')
            ->where('requiresTwoFactor', false)
            ->where('userType', UserType::Customer->value)
            ->where('email', $customer->email),
        );
});

test('fresh authentication screen renders with two factor requirement for agent and admin', function () {
    $agent = User::factory()->agent()->withTwoFactor()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($agent)
        ->get(route('fresh-authentication'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/FreshAuthentication')
            ->where('requiresTwoFactor', true)
            ->where('userType', UserType::Agent->value),
        );

    $this->actingAs($admin)
        ->get(route('fresh-authentication'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/FreshAuthentication')
            ->where('requiresTwoFactor', true)
            ->where('userType', UserType::Admin->value),
        );
});

test('already fresh session redirects to intended destination', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->withSession([
            'auth.fresh_until' => Carbon::now()->timestamp + 600,
            'auth.password_confirmed_at' => Carbon::now()->timestamp,
        ])
        ->get(route('fresh-authentication'))
        ->assertRedirect(route('dashboard'));
});

test('customer can confirm fresh authentication with valid password alone', function () {
    $customer = User::factory()->customer()->create([
        'password' => Hash::make('CorrectPassword123!'),
    ]);

    $response = $this->actingAs($customer)
        ->post(route('fresh-authentication.store'), [
            'password' => 'CorrectPassword123!',
        ]);

    $response->assertRedirect(route('dashboard'));
    $this->assertNotNull(session('auth.fresh_until'));
    $this->assertNotNull(session('auth.password_confirmed_at'));
    $this->assertNull(session('auth.mfa_confirmed_at'));
});

test('customer confirmation fails with invalid password', function () {
    $customer = User::factory()->customer()->create([
        'password' => Hash::make('CorrectPassword123!'),
    ]);

    $response = $this->actingAs($customer)
        ->from(route('fresh-authentication'))
        ->post(route('fresh-authentication.store'), [
            'password' => 'WrongPassword!',
        ]);

    $response->assertRedirect(route('fresh-authentication'));
    $response->assertSessionHasErrors('password');
    $this->assertNull(session('auth.fresh_until'));
});

test('agent and admin require both valid password and 6-digit TOTP code', function () {
    $engine = app(Google2FA::class);
    $secret = $engine->generateSecretKey();

    $admin = User::factory()->admin()->create([
        'password' => Hash::make('AdminSecurePassword123!'),
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => Carbon::now(),
    ]);

    // 1. Valid password but missing TOTP fails
    $missingOtpResponse = $this->actingAs($admin)
        ->from(route('fresh-authentication'))
        ->post(route('fresh-authentication.store'), [
            'password' => 'AdminSecurePassword123!',
        ]);

    $missingOtpResponse->assertRedirect(route('fresh-authentication'));
    $missingOtpResponse->assertSessionHasErrors('code');

    // 2. Valid password but wrong TOTP fails
    $wrongOtpResponse = $this->actingAs($admin)
        ->from(route('fresh-authentication'))
        ->post(route('fresh-authentication.store'), [
            'password' => 'AdminSecurePassword123!',
            'code' => '000000',
        ]);

    $wrongOtpResponse->assertRedirect(route('fresh-authentication'));
    $wrongOtpResponse->assertSessionHasErrors('code');

    // 3. Valid password and valid TOTP succeeds
    $validCode = $engine->getCurrentOtp($secret);

    $successResponse = $this->actingAs($admin)
        ->post(route('fresh-authentication.store'), [
            'password' => 'AdminSecurePassword123!',
            'code' => $validCode,
        ]);

    $successResponse->assertRedirect(route('dashboard'));
    $this->assertNotNull(session('auth.fresh_until'));
    $this->assertNotNull(session('auth.password_confirmed_at'));
    $this->assertNotNull(session('auth.mfa_confirmed_at'));
});

test('agent and admin cannot reuse a previously used TOTP code for step-up authentication', function () {
    $engine = app(Google2FA::class);
    $secret = $engine->generateSecretKey();

    $admin = User::factory()->admin()->create([
        'password' => Hash::make('AdminSecurePassword123!'),
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => Carbon::now(),
    ]);

    $validCode = $engine->getCurrentOtp($secret);

    // First use succeeds
    $this->actingAs($admin)
        ->post(route('fresh-authentication.store'), [
            'password' => 'AdminSecurePassword123!',
            'code' => $validCode,
        ])
        ->assertRedirect(route('dashboard'));

    // Second use with the same code is denied due to atomic replay protection
    $this->actingAs($admin)
        ->from(route('fresh-authentication'))
        ->post(route('fresh-authentication.store'), [
            'password' => 'AdminSecurePassword123!',
            'code' => $validCode,
        ])
        ->assertRedirect(route('fresh-authentication'))
        ->assertSessionHasErrors('code');
});

test('recovery codes are rejected for fresh authentication step-up', function () {
    $engine = app(Google2FA::class);
    $secret = $engine->generateSecretKey();

    $admin = User::factory()->admin()->create([
        'password' => Hash::make('AdminSecurePassword123!'),
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => Carbon::now(),
    ]);

    // An 8-10 character recovery code string fails length validation and is never accepted
    $this->actingAs($admin)
        ->from(route('fresh-authentication'))
        ->post(route('fresh-authentication.store'), [
            'password' => 'AdminSecurePassword123!',
            'code' => 'ABCDE-FGHIJ',
        ])
        ->assertRedirect(route('fresh-authentication'))
        ->assertSessionHasErrors('code');
});

test('fresh middleware returns 423 json response when requested by json client', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->getJson(route('security.edit'))
        ->assertStatus(423)
        ->assertJson(['message' => 'Fresh authentication required.']);
});
