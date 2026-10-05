<?php

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AgentTrustedDeviceService;
use App\Services\AuthenticationAbuseService;
use App\Services\ResumeCookieService;
use App\Services\SessionManagerService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function authAuditEvents(string $eventType, User $user): array
{
    return AuditEvent::query()->where('event_type', $eventType)->where('target_id', $user->id)->get()->all();
}

test('failed and successful fresh authentication are audited without the submitted password', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('fresh-authentication.store'), ['password' => 'wrong-secret-value'])
        ->assertSessionHasErrors('password');
    $this->actingAs($user)->post(route('fresh-authentication.store'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    $failed = authAuditEvents('auth.fresh_authentication_failed', $user);
    expect($failed)->toHaveCount(1)
        ->and($failed[0]->payload)->toBe(['outcome' => 'password_mismatch'])
        ->and(authAuditEvents('auth.fresh_authentication_succeeded', $user))->toHaveCount(1)
        ->and(DB::table('audit_events')->where('payload', 'like', '%wrong-secret-value%')->exists())->toBeFalse();
});

test('a password reset request is audited for a known account only and never stores the token', function (): void {
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
    $this->post(route('password.email'), ['email' => 'nobody@example.test'])->assertSessionHasNoErrors();

    $events = AuditEvent::query()->where('event_type', 'auth.password_reset_requested')->get();
    $token = DB::table('password_reset_tokens')->value('token');
    expect($events)->toHaveCount(1)
        ->and($events[0]->target_id)->toBe($user->id)
        ->and(json_encode($events[0]->payload))->not->toContain((string) $token);
});

test('signing out is audited', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('logout'));

    expect(authAuditEvents('auth.signed_out', $user))->toHaveCount(1);
});

test('Agent trusted-device creation and revocation are audited without the device token', function (): void {
    $agent = User::factory()->agent()->withTwoFactor()->create()->fresh();
    $service = app(AgentTrustedDeviceService::class);
    $request = Request::create('/', 'GET', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh) Chrome/120']);

    $cookie = $service->createTrustedDevice($agent, $request);
    $service->revokeTrustedDevices($agent);
    $service->revokeTrustedDevices($agent);

    expect(authAuditEvents('auth.trusted_device_created', $agent))->toHaveCount(1)
        ->and(authAuditEvents('auth.trusted_device_revoked', $agent))->toHaveCount(1)
        ->and(authAuditEvents('auth.trusted_device_revoked', $agent)[0]->payload)->toBe(['device_count' => 1])
        ->and(DB::table('audit_events')->where('payload', 'like', '%'.$cookie->getValue().'%')->exists())->toBeFalse();
});

test('a suspected-compromise session revocation notifies only the account holder', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    app(AuthenticationAbuseService::class)->revokeSessionsForSuspectedCompromise($user, 'token reuse');

    $this->actingAs($user)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
        ->where('inbox.items.0.title', 'Your sessions were signed out for your protection'));
    $this->actingAs($other)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
        ->where('inbox.items', []));
});

test('an inactivity expiry is audited as an expiry and not as a sign-out', function (): void {
    $customer = User::factory()->customer()->create();
    $this->actingAs($customer)->get(route('customer.dashboard'))->assertOk();

    Carbon::setTestNow(Carbon::now()->addDays(8));
    $this->get(route('customer.dashboard'))->assertRedirect(route('login'));
    Carbon::setTestNow();

    $expired = authAuditEvents('auth.session_expired', $customer);
    expect($expired)->toHaveCount(1)
        ->and($expired[0]->payload)->toBe(['outcome' => 'inactivity'])
        ->and(authAuditEvents('auth.signed_out', $customer))->toBe([]);
});

test('reaching the concurrent-device limit is audited', function (): void {
    $customer = User::factory()->customer()->create();
    foreach (range(1, 5) as $i) {
        DB::table(config('session.table', 'sessions'))->insert(['id' => "limit-session-{$i}", 'user_id' => $customer->id,
            'ip_address' => '192.168.1.'.$i, 'user_agent' => 'Mozilla/5.0 (iPhone)', 'payload' => base64_encode(serialize([])),
            'last_activity' => Carbon::now()->timestamp, 'created_at' => Carbon::now()->timestamp]);
    }

    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirect(route('device-eviction'));

    expect(authAuditEvents('auth.device_limit_reached', $customer)[0]->payload)->toBe(['device_count' => 5]);
});

test('a sign-in is audited as a new device only when the device has not been seen before', function (): void {
    $customer = User::factory()->customer()->create();
    $agent = 'Mozilla/5.0 (Macintosh) Chrome/120';

    $this->withHeader('User-Agent', $agent)->post(route('login.store'), ['email' => $customer->email, 'password' => 'password']);
    expect(authAuditEvents('auth.new_device_sign_in', $customer))->toHaveCount(1);

    $this->post(route('logout'));
    DB::table(config('session.table', 'sessions'))->insert(['id' => 'known-device-session', 'user_id' => $customer->id,
        'ip_address' => '10.0.0.1', 'user_agent' => $agent, 'payload' => base64_encode(serialize([])),
        'last_activity' => Carbon::now()->timestamp, 'created_at' => Carbon::now()->timestamp]);
    $this->withHeader('User-Agent', $agent)->post(route('login.store'), ['email' => $customer->email, 'password' => 'password']);

    expect(authAuditEvents('auth.new_device_sign_in', $customer))->toHaveCount(1);
});

test('a remember-cookie renewal is audited as a session renewal', function (): void {
    $user = User::factory()->create();

    app(SessionManagerService::class)->recordSignInContext($user, request(), viaRemember: true);

    expect(authAuditEvents('auth.session_renewed', $user)[0]->payload)->toBe(['outcome' => 'remember_cookie'])
        ->and(authAuditEvents('auth.new_device_sign_in', $user))->toBe([]);
});

test('a rejected resume cookie is audited with its fallback reason and no cookie is audited as nothing', function (): void {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $service = app(ResumeCookieService::class);

    expect($service->consumeResumeDestination($admin, Request::create('/')))->toBeNull()
        ->and(authAuditEvents('auth.resume_cookie_rejected', $admin))->toBe([]);

    $tampered = Request::create('/', cookies: [ResumeCookieService::COOKIE_NAME => 'not-an-encrypted-value']);
    expect($service->consumeResumeDestination($admin, $tampered))->toBeNull()
        ->and(authAuditEvents('auth.resume_cookie_rejected', $admin)[0]->payload)->toBe(['outcome' => 'undecryptable'])
        ->and(DB::table('audit_events')->where('payload', 'like', '%not-an-encrypted-value%')->exists())->toBeFalse();
});
