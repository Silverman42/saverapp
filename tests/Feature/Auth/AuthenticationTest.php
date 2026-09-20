<?php

use App\Enums\AccountState;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;
use PragmaRX\Google2FA\Google2FA;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('customer can authenticate using the login screen and is routed to customer dashboard', function () {
    $user = User::factory()->customer()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('customer.dashboard', absolute: false));
});

test('users can authenticate with mixed-case and whitespace-padded email', function () {
    $user = User::factory()->customer()->create([
        'email' => 'Jane.Doe+Savings@Example.COM',
    ]);

    $response = $this->post(route('login.store'), [
        'email' => '   JANE.DOE+SAVINGS@EXAMPLE.COM   ',
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('customer.dashboard', absolute: false));
});

test('agent with confirmed two factor redirects to two factor challenge and upon challenge completion lands on agent dashboard', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $agent = User::factory()->agent()->withTwoFactor()->create();

    $response = $this->post(route('login.store'), [
        'email' => $agent->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $agent->id);
    $this->assertGuest();

    $totp = app(Google2FA::class)->getCurrentOtp(decrypt($agent->two_factor_secret));

    $twoFactorResponse = $this->post(route('two-factor.login.store'), [
        'code' => $totp,
    ]);

    $this->assertAuthenticatedAs($agent);
    $twoFactorResponse->assertRedirect(route('agent.dashboard', absolute: false));
});

test('admin with confirmed two factor redirects to two factor challenge and upon challenge completion lands on admin dashboard', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $admin = User::factory()->admin()->withTwoFactor()->create();

    $response = $this->post(route('login.store'), [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $admin->id);
    $this->assertGuest();

    $totp = app(Google2FA::class)->getCurrentOtp(decrypt($admin->two_factor_secret));

    $twoFactorResponse = $this->post(route('two-factor.login.store'), [
        'code' => $totp,
    ]);

    $this->assertAuthenticatedAs($admin);
    $twoFactorResponse->assertRedirect(route('admin.dashboard', absolute: false));
});

test('dashboard route acts as compatibility dispatcher redirecting each role to their dashboard', function () {
    $customer = User::factory()->customer()->create();
    $agent = User::factory()->agent()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertRedirect(route('customer.dashboard', absolute: false));

    $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertRedirect(route('agent.dashboard', absolute: false));

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertRedirect(route('admin.dashboard', absolute: false));
});

test('each role can access their own dashboard placeholder', function () {
    $customer = User::factory()->customer()->create();
    $agent = User::factory()->agent()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($customer)->get(route('customer.dashboard'))->assertOk();
    $this->actingAs($agent)->get(route('agent.dashboard'))->assertOk();
    $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
});

test('direct access to another role dashboard returns 403 forbidden', function () {
    $customer = User::factory()->customer()->create();
    $agent = User::factory()->agent()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($customer)->get(route('agent.dashboard'))->assertForbidden();
    $this->actingAs($customer)->get(route('admin.dashboard'))->assertForbidden();

    $this->actingAs($agent)->get(route('customer.dashboard'))->assertForbidden();
    $this->actingAs($agent)->get(route('admin.dashboard'))->assertForbidden();

    $this->actingAs($admin)->get(route('customer.dashboard'))->assertForbidden();
    $this->actingAs($admin)->get(route('agent.dashboard'))->assertForbidden();
});

test('unauthenticated access to dashboards redirects to login', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->get(route('customer.dashboard'))->assertRedirect(route('login'));
    $this->get(route('agent.dashboard'))->assertRedirect(route('login'));
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

test('all credential and account state failures return identical generic error without leaking details', function (Closure $createUser, string $password) {
    $user = $createUser();

    $response = $this->post(route('login.store'), [
        'email' => $user?->email ?? 'nonexistent@example.com',
        'password' => $password,
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors([
        'email' => 'We could not sign you in with those details.',
    ]);
})->with([
    'unknown email' => [fn () => null, 'password'],
    'wrong password' => [fn () => User::factory()->customer()->create(), 'wrong-password'],
    'invited user' => [fn () => User::factory()->invited()->create(), 'password'],
    'suspended user' => [fn () => User::factory()->suspended()->create(), 'password'],
    'deactivated user' => [fn () => User::factory()->deactivated()->create(), 'password'],
    'password locked user' => [fn () => User::factory()->customer()->temporarilyLocked('password')->create(), 'password'],
]);

test('agent or admin without confirmed totp transitions to mfa_setup_required and enters restricted setup session', function (Closure $createUser) {
    $user = $createUser();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('two-factor.enrolment', absolute: false));
    expect($user->fresh()->account_state)->toBe(AccountState::MfaSetupRequired);
})->with([
    'agent without totp' => [fn () => User::factory()->agent()->create()],
    'admin without totp' => [fn () => User::factory()->admin()->create()],
    'user already in mfa_setup_required' => [fn () => User::factory()->mfaSetupRequired()->create()],
]);

test('non-password temporary lock does not block password sign in', function () {
    $user = User::factory()->customer()->temporarilyLocked('mfa')->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('customer.dashboard', absolute: false));
});

test('temporary password lock does not terminate existing active session', function () {
    $user = User::factory()->customer()->active()->create();

    $this->actingAs($user);

    $this->get(route('customer.dashboard'))->assertOk();

    $user->lockTemporarily(15, 'password', 'Suspicious login pattern');

    $response = $this->get(route('customer.dashboard'));
    $response->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('authenticated session loses access immediately after suspension or deactivation', function (AccountState $state) {
    $user = User::factory()->customer()->active()->create();

    $this->actingAs($user);

    $this->get(route('customer.dashboard'))->assertOk();

    $user->account_state = $state;
    $user->save();

    $response = $this->get(route('customer.dashboard'));

    $response->assertRedirect(route('login'));
    $this->assertGuest();
})->with([
    'suspended' => AccountState::Suspended,
    'deactivated' => AccountState::Deactivated,
    'invited' => AccountState::Invited,
]);

test('authenticated session is redirected to enrolment immediately after transitioning to mfa_setup_required', function () {
    $user = User::factory()->agent()->active()->withTwoFactor()->create();

    $this->actingAs($user);

    $this->get(route('agent.dashboard'))->assertOk();

    $user->account_state = AccountState::MfaSetupRequired;
    $user->save();

    $response = $this->get(route('agent.dashboard'));

    $response->assertRedirect(route('two-factor.enrolment'));
    $this->assertAuthenticatedAs($user);
});

test('passkey and registration endpoints are not available', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['email' => 'test@example.com'])->assertNotFound();
    $this->get('/.well-known/passkey-endpoints')->assertNotFound();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});
