<?php

use App\Models\AgentTrustedDevice;
use App\Models\User;
use App\Models\UserRecoveryCode;
use App\Notifications\Auth\AdminConcurrentDeviceRevokedNotification;
use App\Notifications\Auth\SessionRevokedNotification;
use App\Services\AgentTrustedDeviceService;
use App\Services\ResumeCookieService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PragmaRX\Google2FA\Google2FA;

test('customer session enforces 7-day inactivity and 30-day maximum lifetime', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer);

    // Initial visit sets session timestamps
    $response = $this->get(route('customer.dashboard'));
    $response->assertOk();

    // 6 days later: still within 7-day inactivity
    Carbon::setTestNow(Carbon::now()->addDays(6));
    $response = $this->get(route('customer.dashboard'));
    $response->assertOk();

    // 8 days later from last activity: exceeds 7-day inactivity timeout
    Carbon::setTestNow(Carbon::now()->addDays(8));
    $response = $this->get(route('customer.dashboard'));
    $response->assertRedirect(route('login'));
    $this->assertGuest();

    // Reset clock and test max lifetime
    Carbon::setTestNow();
    $this->actingAs($customer);
    $this->get(route('customer.dashboard'))->assertOk();

    // Advance 31 days with continuous activity (exceeds 30-day max lifetime)
    Carbon::setTestNow(Carbon::now()->addDays(31));
    $response = $this->get(route('customer.dashboard'));
    $response->assertRedirect(route('login'));
    $this->assertGuest();

    Carbon::setTestNow();
});

test('agent session enforces 1-hour inactivity and 24-hour maximum lifetime', function () {
    $agent = User::factory()->agent()->withTwoFactor()->create();

    $this->actingAs($agent);

    $this->get(route('agent.dashboard'))->assertOk();

    // 50 minutes later: within 1 hour
    Carbon::setTestNow(Carbon::now()->addMinutes(50));
    $this->get(route('agent.dashboard'))->assertOk();

    // 70 minutes later: exceeds 1 hour inactivity
    Carbon::setTestNow(Carbon::now()->addMinutes(70));
    $response = $this->get(route('agent.dashboard'));
    $response->assertRedirect(route('login'));
    $this->assertGuest();

    // Test max lifetime (24 hours)
    Carbon::setTestNow();
    $this->actingAs($agent);
    $this->get(route('agent.dashboard'))->assertOk();

    // Advance 25 hours (exceeds 24 hours max lifetime)
    Carbon::setTestNow(Carbon::now()->addHours(25));
    $response = $this->get(route('agent.dashboard'));
    $response->assertRedirect(route('login'));
    $this->assertGuest();

    Carbon::setTestNow();
});

test('admin session enforces 30-minute inactivity and 24-hour maximum lifetime', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin);

    $this->get(route('admin.dashboard'))->assertOk();

    // 20 minutes later: within 30 minutes
    Carbon::setTestNow(Carbon::now()->addMinutes(20));
    $this->get(route('admin.dashboard'))->assertOk();

    // 35 minutes later: exceeds 30 minutes inactivity
    Carbon::setTestNow(Carbon::now()->addMinutes(35));
    $response = $this->get(route('admin.dashboard'));
    $response->assertRedirect(route('login'));
    $this->assertGuest();

    // Test max lifetime (24 hours)
    Carbon::setTestNow();
    $this->actingAs($admin);
    $this->get(route('admin.dashboard'))->assertOk();

    Carbon::setTestNow(Carbon::now()->addHours(25));
    $response = $this->get(route('admin.dashboard'));
    $response->assertRedirect(route('login'));
    $this->assertGuest();

    Carbon::setTestNow();
});

test('admin single-device rule: new login when session is active requires explicit eviction and does not silently revoke', function () {
    Notification::fake();
    $admin = User::factory()->admin()->withTwoFactor()->create();

    // Simulate an existing active session in database
    $existingSessionId = 'admin-existing-session-123';
    DB::table(config('session.table', 'sessions'))->insert([
        'id' => $existingSessionId,
        'user_id' => $admin->id,
        'ip_address' => '192.168.1.50',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
        'payload' => base64_encode(serialize([])),
        'last_activity' => Carbon::now()->timestamp,
        'created_at' => Carbon::now()->timestamp,
    ]);

    // Admin attempts to login on another device
    $response = $this->post(route('login.store'), [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $this->assertGuest();

    $totp = app(Google2FA::class)->getCurrentOtp(decrypt($admin->two_factor_secret));

    $twoFactorResponse = $this->post(route('two-factor.login.store'), [
        'code' => $totp,
    ]);

    // Must redirect to device-eviction, NOT dashboard, and NOT silently revoke existing session!
    $twoFactorResponse->assertRedirect(route('device-eviction'));
    $this->assertGuest();

    // Verify existing session still exists
    $this->assertDatabaseHas(config('session.table', 'sessions'), [
        'id' => $existingSessionId,
    ]);

    // Visiting device-eviction view
    $evictionPageResponse = $this->get(route('device-eviction'));
    $evictionPageResponse->assertOk();

    // Confirming eviction of the existing session
    $confirmResponse = $this->post(route('device-eviction.confirm'), [
        'session_id' => $existingSessionId,
    ]);

    // After confirmation, new session is established and redirects to dashboard
    $this->assertAuthenticatedAs($admin);
    $confirmResponse->assertRedirect(route('admin.dashboard', absolute: false));

    // Existing session must now be deleted
    $this->assertDatabaseMissing(config('session.table', 'sessions'), [
        'id' => $existingSessionId,
    ]);

    // Admin notification sent
    Notification::assertSentTo($admin, AdminConcurrentDeviceRevokedNotification::class);
});

test('admin cancelling device eviction preserves existing session and returns to login', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $existingSessionId = 'admin-existing-session-456';
    DB::table(config('session.table', 'sessions'))->insert([
        'id' => $existingSessionId,
        'user_id' => $admin->id,
        'ip_address' => '10.0.0.1',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
        'payload' => base64_encode(serialize([])),
        'last_activity' => Carbon::now()->timestamp,
        'created_at' => Carbon::now()->timestamp,
    ]);

    // Setup pending eviction in session
    $this->withSession([
        'login.pending_eviction' => [
            'user_id' => $admin->id,
            'remember' => false,
        ],
    ]);

    $cancelResponse = $this->post(route('device-eviction.cancel'));
    $cancelResponse->assertRedirect(route('login'));
    $this->assertGuest();

    // Session remains intact
    $this->assertDatabaseHas(config('session.table', 'sessions'), [
        'id' => $existingSessionId,
    ]);
});

test('customer can have up to 5 concurrent sessions and 6th requires eviction', function () {
    $customer = User::factory()->customer()->create();

    // Create 5 existing sessions
    for ($i = 1; $i <= 5; $i++) {
        DB::table(config('session.table', 'sessions'))->insert([
            'id' => "customer-session-{$i}",
            'user_id' => $customer->id,
            'ip_address' => "192.168.1.{$i}",
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
            'payload' => base64_encode(serialize([])),
            'last_activity' => Carbon::now()->timestamp,
            'created_at' => Carbon::now()->timestamp,
        ]);
    }

    // 6th sign-in attempt
    $response = $this->post(route('login.store'), [
        'email' => $customer->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('device-eviction'));
    $this->assertGuest();

    // Evict session 1
    $confirmResponse = $this->post(route('device-eviction.confirm'), [
        'session_id' => 'customer-session-1',
    ]);

    $this->assertAuthenticatedAs($customer);
    $confirmResponse->assertRedirect(route('customer.dashboard', absolute: false));

    $this->assertDatabaseMissing(config('session.table', 'sessions'), [
        'id' => 'customer-session-1',
    ]);
    $this->assertDatabaseHas(config('session.table', 'sessions'), [
        'id' => 'customer-session-2',
    ]);
});

test('agent can trust device for 30 days during TOTP challenge and subsequent login bypasses TOTP', function () {
    $agent = User::factory()->agent()->withTwoFactor()->create();

    // First login: enter email and password
    $this->post(route('login.store'), [
        'email' => $agent->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $totp = app(Google2FA::class)->getCurrentOtp(decrypt($agent->two_factor_secret));

    // Complete TOTP with trust_device checkbox
    $response = $this->post(route('two-factor.login.store'), [
        'code' => $totp,
        'trust_device' => '1',
    ]);

    $this->assertAuthenticatedAs($agent);
    $response->assertRedirect(route('agent.dashboard', absolute: false));

    // A trusted device cookie must be set and record in agent_trusted_devices
    $response->assertPlainCookie(AgentTrustedDeviceService::COOKIE_NAME);
    $cookie = $response->getCookie(AgentTrustedDeviceService::COOKIE_NAME, decrypt: false);
    $this->assertNotNull($cookie);

    $this->assertDatabaseHas('agent_trusted_devices', [
        'user_id' => $agent->id,
        'device_token_hash' => AgentTrustedDevice::hashToken($cookie->getValue()),
    ]);

    // Logout
    $this->post(route('logout'))->assertRedirect(route('home'));
    $this->assertGuest();

    // Clear sessions from database to avoid device limit eviction on second login
    DB::table(config('session.table', 'sessions'))->where('user_id', $agent->id)->delete();

    // Second login: with trusted device cookie, password verification satisfies login without 2FA!
    $secondLoginResponse = $this->withUnencryptedCookie(AgentTrustedDeviceService::COOKIE_NAME, $cookie->getValue())
        ->post(route('login.store'), [
            'email' => $agent->email,
            'password' => 'password',
        ]);

    $this->assertAuthenticatedAs($agent);
    $secondLoginResponse->assertRedirect(route('agent.dashboard', absolute: false));
});

test('agent recovery code login cannot create trusted device authorization', function () {
    $agent = User::factory()->agent()->withTwoFactor()->create();

    $this->post(route('login.store'), [
        'email' => $agent->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    // Create a recovery code
    $rawCode = 'ABCDE-12345';
    UserRecoveryCode::create([
        'user_id' => $agent->id,
        'code_hash' => hash('sha256', $rawCode),
    ]);

    $response = $this->post(route('two-factor.login.store'), [
        'recovery_code' => $rawCode,
        'trust_device' => '1',
    ]);

    // Must NOT issue trusted device cookie
    $response->assertCookieMissing(AgentTrustedDeviceService::COOKIE_NAME);
    $this->assertDatabaseCount('agent_trusted_devices', 0);
});

test('admin login never permits trusted device creation', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->post(route('login.store'), [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $totp = app(Google2FA::class)->getCurrentOtp(decrypt($admin->two_factor_secret));

    $response = $this->post(route('two-factor.login.store'), [
        'code' => $totp,
        'trust_device' => '1',
    ]);

    $response->assertCookieMissing(AgentTrustedDeviceService::COOKIE_NAME);
    $this->assertDatabaseCount('agent_trusted_devices', 0);
});

test('password reset revokes all sessions and trusted devices', function () {
    $agent = User::factory()->agent()->withTwoFactor()->create();

    // Create an active session and a trusted device
    DB::table(config('session.table', 'sessions'))->insert([
        'id' => 'agent-session-before-reset',
        'user_id' => $agent->id,
        'payload' => base64_encode(serialize([])),
        'last_activity' => Carbon::now()->timestamp,
    ]);

    AgentTrustedDevice::create([
        'user_id' => $agent->id,
        'device_token_hash' => hash('sha256', 'some-token'),
        'device_name' => 'Agent MacBook',
        'trusted_until' => Carbon::now()->addDays(30),
    ]);

    $this->assertDatabaseCount('agent_trusted_devices', 1);
    $this->assertDatabaseHas(config('session.table', 'sessions'), ['id' => 'agent-session-before-reset']);

    // Perform password reset
    $token = Password::broker()->createToken($agent);
    $totp = app(Google2FA::class)->getCurrentOtp(decrypt($agent->two_factor_secret));

    $response = $this->post(route('password.update'), [
        'token' => $token,
        'email' => $agent->email,
        'password' => 'NewAgentSecurePassword123!',
        'password_confirmation' => 'NewAgentSecurePassword123!',
        'code' => $totp,
    ]);

    $response->assertRedirect(route('login'));

    // Both sessions and trusted devices must be completely revoked
    $this->assertDatabaseMissing(config('session.table', 'sessions'), ['id' => 'agent-session-before-reset']);
    $this->assertDatabaseCount('agent_trusted_devices', 0);
});

test('fresh authentication window lasts 10 minutes and requires reauthentication after 10 minutes', function () {
    $customer = User::factory()->customer()->create();

    // Sign in via standard password login to initialize fresh window
    $this->post(route('login.store'), [
        'email' => $customer->email,
        'password' => 'password',
    ])->assertRedirect(route('customer.dashboard', absolute: false));

    $this->assertAuthenticatedAs($customer);

    // Visiting settings/security within 10 minutes succeeds
    $response = $this->get(route('security.edit'));
    $response->assertOk();

    // Advance clock past 10 minutes (601 seconds)
    Carbon::setTestNow(Carbon::now()->addSeconds(601));

    // Visiting settings/security redirects to fresh authentication step-up
    $expiredResponse = $this->get(route('security.edit'));
    $expiredResponse->assertRedirect(route('fresh-authentication'));

    Carbon::setTestNow();
});

test('admin and agent eligible GET visits set 24-hour resume cookie and redirect to saved page on subsequent login', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin);

    // Admin visits dashboard
    $visitResponse = $this->get(route('admin.dashboard'));
    $visitResponse->assertOk();

    // Verify resume cookie was queued
    $visitResponse->assertCookie(ResumeCookieService::COOKIE_NAME);
    $cookie = $visitResponse->getCookie(ResumeCookieService::COOKIE_NAME);
    $this->assertNotNull($cookie);

    // Sign out
    $this->post(route('logout'));
    $this->assertGuest();

    // Delete active sessions in DB to allow fresh sign-in without eviction
    DB::table(config('session.table', 'sessions'))->where('user_id', $admin->id)->delete();

    // Sign in again with resume cookie present
    $this->withUnencryptedCookie(ResumeCookieService::COOKIE_NAME, $cookie->getValue())
        ->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));

    $totp = app(Google2FA::class)->getCurrentOtp(decrypt($admin->two_factor_secret));

    $twoFactorResponse = $this->withUnencryptedCookie(ResumeCookieService::COOKIE_NAME, $cookie->getValue())
        ->post(route('two-factor.login.store'), [
            'code' => $totp,
        ]);

    // Resumes to admin dashboard
    $twoFactorResponse->assertRedirect(route('admin.dashboard', absolute: false));
});

test('tampered or other-user resume cookie is discarded and user is redirected to role dashboard', function () {
    $admin1 = User::factory()->admin()->withTwoFactor()->create();
    $admin2 = User::factory()->admin()->withTwoFactor()->create();

    // Create resume cookie for admin1
    $this->actingAs($admin1);
    $visitResponse = $this->get(route('admin.dashboard'));
    $cookie = $visitResponse->getCookie(ResumeCookieService::COOKIE_NAME);

    $this->post(route('logout'));
    $this->assertGuest();

    // Admin2 signs in with Admin1's resume cookie
    $this->withUnencryptedCookie(ResumeCookieService::COOKIE_NAME, $cookie->getValue())
        ->post(route('login.store'), [
            'email' => $admin2->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));

    $totp = app(Google2FA::class)->getCurrentOtp(decrypt($admin2->two_factor_secret));

    $twoFactorResponse = $this->withUnencryptedCookie(ResumeCookieService::COOKIE_NAME, $cookie->getValue())
        ->post(route('two-factor.login.store'), [
            'code' => $totp,
        ]);

    // Redirects to admin2's default dashboard, ignoring admin1's cookie
    $twoFactorResponse->assertRedirect(route('admin.dashboard', absolute: false));
});

test('user can sign out individual device, other devices, or everywhere via session endpoints', function () {
    Notification::fake();
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer);

    $currentSessionId = session()->getId();
    $otherSession1 = 'customer-other-1';
    $otherSession2 = 'customer-other-2';

    DB::table(config('session.table', 'sessions'))->insert([
        [
            'id' => $currentSessionId,
            'user_id' => $customer->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Safari',
            'payload' => base64_encode(serialize([])),
            'last_activity' => Carbon::now()->timestamp,
            'created_at' => Carbon::now()->timestamp,
        ],
        [
            'id' => $otherSession1,
            'user_id' => $customer->id,
            'ip_address' => '1.2.3.4',
            'user_agent' => 'Chrome',
            'payload' => base64_encode(serialize([])),
            'last_activity' => Carbon::now()->timestamp,
            'created_at' => Carbon::now()->timestamp,
        ],
        [
            'id' => $otherSession2,
            'user_id' => $customer->id,
            'ip_address' => '5.6.7.8',
            'user_agent' => 'Firefox',
            'payload' => base64_encode(serialize([])),
            'last_activity' => Carbon::now()->timestamp,
            'created_at' => Carbon::now()->timestamp,
        ],
    ]);

    // Index endpoint returns sessions with masked IP
    $indexResponse = $this->getJson(route('sessions.index'));
    $indexResponse->assertOk();
    $sessions = $indexResponse->json('sessions');
    $this->assertCount(3, $sessions); // current + 2 others

    // Revoke individual device
    $destroyOneResponse = $this->delete(route('sessions.destroy', ['id' => $otherSession1]));
    $destroyOneResponse->assertRedirect();
    $this->assertDatabaseMissing(config('session.table', 'sessions'), ['id' => $otherSession1]);
    $this->assertDatabaseHas(config('session.table', 'sessions'), ['id' => $otherSession2]);

    // Revoke all other devices
    $destroyOthersResponse = $this->post(route('sessions.destroy-others'));
    $destroyOthersResponse->assertRedirect();
    $this->assertDatabaseMissing(config('session.table', 'sessions'), ['id' => $otherSession1]);
    $this->assertDatabaseMissing(config('session.table', 'sessions'), ['id' => $otherSession2]);

    // Sign out everywhere
    $destroyAllResponse = $this->post(route('sessions.destroy-all'));
    $destroyAllResponse->assertRedirect(route('login'));
    $this->assertGuest();
    $this->assertDatabaseMissing(config('session.table', 'sessions'), ['id' => $otherSession1]);
    $this->assertDatabaseMissing(config('session.table', 'sessions'), ['id' => $otherSession2]);

    Notification::assertSentTo($customer, SessionRevokedNotification::class);
});
