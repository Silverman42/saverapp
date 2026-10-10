<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\AuthenticatorState;
use App\Enums\DeliveryStatus;
use App\Enums\InvitationStatus;
use App\Enums\LockNotificationStatus;
use App\Enums\UserType;
use App\Jobs\DeliverAgentInvitationJob;
use App\Models\AgentProfile;
use App\Models\AgentTrustedDevice;
use App\Models\AuditEvent;
use App\Models\AuthenticationLock;
use App\Models\BusinessProfile;
use App\Models\CollectionReceipt;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeRule;
use App\Models\FeeSnapshot;
use App\Models\Invitation;
use App\Models\PendingEmailChange;
use App\Models\StaffRecovery;
use App\Models\User;
use App\Models\UserRecoveryCode;
use App\Notifications\Auth\CustomerInvitationNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\SessionRevokedNotification;
use App\Notifications\Auth\StaffRecoveryActivationNotification;
use App\Notifications\EmailChangeNotification;
use App\Services\AgentTrustedDeviceService;
use App\Services\AuthenticationAbuseService;
use App\Services\CollectionService;
use App\Services\PermissionManagementService;
use App\Services\ResumeCookieService;
use App\Services\SessionManagerService;
use App\Services\UnlockState;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

require_once __DIR__.'/../../CollectionFixtures.php';

beforeEach(function (): void {
    config()->set('collections.enabled', true);
});

test('AUTH-AC-066: an expired Agent session records no collection and the retry after sign-in posts once', function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Africa/Lagos'));
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(2);
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $route = route('customers.collections.store', $customer->customer_id);

    $this->actingAs($agent)->get(route('agent.dashboard'))->assertOk();
    $this->travel(61)->minutes();

    $this->post($route, $payload)->assertRedirect(route('login'));
    $this->assertGuest();
    expect(CollectionReceipt::query()->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);

    $this->actingAs($agent->fresh());
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent->fresh(), $customer->fresh(), $payload)['preview_fingerprint'];
    $this->post($route, $payload)->assertRedirect();
    $this->post($route, $payload)->assertRedirect();

    expect(CollectionReceipt::query()->count())->toBe(1)
        ->and(CollectionReceipt::query()->first()->attempt_reference)->toBe($payload['attempt_reference'])
        ->and(DB::table('ledger_posting_groups')->where('event_type', 'cash_contribution')->count())->toBe(1);
});

/** Encrypt a resume payload exactly as the cookie service does, with one field overridden. */
function authResumeCookie(User $user, array $overrides = []): Request
{
    $payload = [...['user_hash' => hash_hmac('sha256', (string) $user->id, (string) config('app.key')), 'user_type' => $user->user_type->value,
        'path' => '/admin/access', 'saved_at' => now()->timestamp, 'access_version' => (int) $user->lifecycle_access_version], ...$overrides];

    return Request::create('/login', 'GET', [], [ResumeCookieService::COOKIE_NAME => Crypt::encrypt($payload)]);
}

function authAgent(): User
{
    $agent = User::factory()->agent()->withTwoFactor()->create();
    AgentProfile::factory()->active()->create(['user_id' => $agent->id]);

    return $agent;
}

test('AUTH-AC-055/056/057: continuous activity still ends each session at its absolute lifetime', function (string $type, string $dashboard, int $stepMinutes, int $maxMinutes): void {
    $user = match ($type) {
        'customer' => CustomerProfile::factory()->create()->user,
        'agent' => authAgent(),
        'admin' => User::factory()->admin()->withTwoFactor()->create(),
    };
    $this->actingAs($user)->get(route($dashboard))->assertOk();

    for ($elapsed = $stepMinutes; $elapsed <= $maxMinutes; $elapsed += $stepMinutes) {
        $this->travel($stepMinutes)->minutes();
        $this->get(route($dashboard))->assertOk();
    }
    $this->travel($stepMinutes)->minutes();

    $this->get(route($dashboard))->assertRedirect(route('login'));
    $this->assertGuest();
})->with([
    'customer 30 days' => ['customer', 'customer.dashboard', 6 * 24 * 60, 30 * 24 * 60],
    'agent 24 hours' => ['agent', 'agent.dashboard', 50, 24 * 60],
    'admin 24 hours' => ['admin', 'admin.dashboard', 25, 24 * 60],
]);

test('AUTH-AC-062/063: a saved destination is restored only when it is internal, current, account-bound and role-appropriate', function (): void {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $agent = authAgent();
    $service = app(ResumeCookieService::class);

    expect($service->consumeResumeDestination($admin, authResumeCookie($admin)))->toBe('/admin/access');
    foreach ([
        ['saved_at' => now()->subHours(25)->timestamp],
        ['path' => '//evil.example.test/admin'],
        ['path' => 'https://evil.example.test'],
        ['path' => '/no-such-page'],
        ['access_version' => $admin->lifecycle_access_version + 1],
        ['user_hash' => hash_hmac('sha256', (string) $agent->id, (string) config('app.key'))],
    ] as $tampered) {
        expect($service->consumeResumeDestination($admin, authResumeCookie($admin, $tampered)))->toBeNull();
    }
    expect($service->consumeResumeDestination($agent, authResumeCookie($agent)))->toBeNull()
        ->and(DB::table('audit_events')->where('event_type', 'auth.resume_cookie_rejected')->count())->toBe(7);
});

test('AUTH-AC-064/068: suspected compromise and sign-out everywhere end remember, device and resume access', function (string $trigger): void {
    $agent = authAgent();
    $agent->forceFill(['remember_token' => 'remembered-token'])->save();
    AgentTrustedDevice::create(['user_id' => $agent->id, 'device_token_hash' => hash('sha256', 'device'), 'device_name' => 'Agent phone', 'trusted_until' => now()->addDays(30)]);
    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $agent->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'Test', 'payload' => '', 'last_activity' => now()->timestamp]);
    $resume = authResumeCookie($agent, ['path' => '/agent/dashboard']);
    $versionBefore = (int) $agent->lifecycle_access_version;

    $trigger === 'compromise'
        ? app(AuthenticationAbuseService::class)->revokeSessionsForSuspectedCompromise($agent, 'Unusual sign-in pattern')
        : app(SessionManagerService::class)->revokeAllSessionsAndTrustedDevices($agent);

    $fresh = $agent->fresh();
    expect(DB::table('sessions')->where('user_id', $agent->id)->count())->toBe(0)
        ->and(AgentTrustedDevice::query()->where('user_id', $agent->id)->count())->toBe(0)
        ->and($fresh->lifecycle_access_version)->toBe($versionBefore + 1)
        ->and(app(ResumeCookieService::class)->consumeResumeDestination($fresh, $resume))->toBeNull();
    if ($trigger === 'compromise') {
        expect($fresh->remember_token)->not->toBe('remembered-token');
    }
})->with(['compromise', 'sign out everywhere']);

test('AUTH-AC-067: the resume cookie is encrypted, HTTP-only, secure and same-site', function (): void {
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $cookie = $this->actingAs($admin)->get(route('admin.access.index'))->assertOk()->getCookie(ResumeCookieService::COOKIE_NAME, false);

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getValue())->not->toContain('/admin/access')->not->toContain($admin->email)->not->toContain('"'.$admin->id.'"');
});

test('AUTH-AC-047: an Agent or Admin email change needs password and authenticator freshness', function (string $type): void {
    Notification::fake();
    $user = $type === 'agent' ? authAgent() : User::factory()->admin()->withTwoFactor()->create();
    $passwordOnly = ['auth.fresh_until' => now()->timestamp + 600, 'auth.password_confirmed_at' => now()->timestamp];

    $this->actingAs($user)->withSession($passwordOnly)->post(route('email-change.store'), ['email' => 'changed-'.$type.'@example.test'])
        ->assertRedirect(route('fresh-authentication'));
    expect(PendingEmailChange::query()->count())->toBe(0);

    $this->withSession([...$passwordOnly, 'auth.mfa_confirmed_at' => now()->timestamp])
        ->post(route('email-change.store'), ['email' => 'changed-'.$type.'@example.test'])->assertRedirect(route('profile.edit'));
    expect(PendingEmailChange::query()->sole()->user_id)->toBe($user->id)
        ->and($user->fresh()->email)->toBe($user->email);
})->with(['agent', 'admin']);

test('AUTH-AC-052: staff cannot overwrite the email of an activated Agent or Admin', function (): void {
    BusinessProfile::current()->forceFill(['invitation_sender_email' => 'invitations@saverapp.ng', 'invitation_sender_name' => 'SaverApp Security',
        'is_invitation_sender_verified' => true])->save();
    $manager = User::factory()->admin()->withTwoFactor()->create();
    $manager->givePermissionTo([AdminPermission::AgentsManage, AdminPermission::AdminsManage]);
    $agent = authAgent();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $fresh = ['auth.fresh_until' => now()->timestamp + 600, 'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];

    $this->actingAs($manager)->withSession($fresh)->postJson(route('agents.invitations.correct-email', $agent->agentProfile->agent_id),
        ['email' => 'agent-overwrite@example.test', 'reason' => 'Asked by phone'])->assertConflict();
    $this->postJson(route('admin.access.invitations.correct-email', $admin), ['email' => 'admin-overwrite@example.test', 'reason' => 'Asked by phone'])->assertConflict();

    expect($agent->fresh()->email)->toBe($agent->email)->and($admin->fresh()->email)->toBe($admin->email);
});

test('AUTH-AC-015/016: a reset never activates or restores an account and preserves permissions and assignments', function (): void {
    $deactivated = User::factory()->customer()->create(['account_state' => AccountState::Deactivated]);
    $this->post(route('password.update'), ['token' => Password::createToken($deactivated), 'email' => $deactivated->email,
        'password' => 'new-secure-passphrase-15', 'password_confirmation' => 'new-secure-passphrase-15'])->assertRedirect(route('login'));
    expect($deactivated->fresh()->account_state)->toBe(AccountState::Deactivated);

    $invited = User::factory()->customer()->invited()->create();
    $this->post(route('password.update'), ['token' => Password::createToken($invited), 'email' => $invited->email,
        'password' => 'new-secure-passphrase-15', 'password_confirmation' => 'new-secure-passphrase-15'])->assertSessionHasErrors('email');
    expect($invited->fresh()->account_state)->toBe(AccountState::Invited)->and($invited->fresh()->password)->toBe($invited->password);

    $admin = User::factory()->admin()->withTwoFactor(['rec-1', 'rec-2'])->create();
    $admin->givePermissionTo([AdminPermission::AuditView, AdminPermission::FeesManage]);
    $agent = authAgent();
    $customer = CustomerProfile::factory()->create();
    CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $agent->agentProfile->id]);
    $this->post(route('password.update'), ['token' => Password::createToken($admin), 'email' => $admin->email, 'recovery_code' => 'rec-1',
        'password' => 'admin-secure-8', 'password_confirmation' => 'admin-secure-8'])->assertSessionHasNoErrors();
    $this->post(route('password.update'), ['token' => Password::createToken($agent), 'email' => $agent->email, 'recovery_code' => 'code-one',
        'password' => 'agent-secure-8', 'password_confirmation' => 'agent-secure-8'])->assertSessionHasNoErrors();

    expect(Hash::check('admin-secure-8', $admin->fresh()->password))->toBeTrue()
        ->and($admin->fresh()->getDirectPermissions()->pluck('name')->sort()->values()->all())->toBe([AdminPermission::AuditView->value, AdminPermission::FeesManage->value])
        ->and($admin->fresh()->two_factor_secret)->toBe($admin->two_factor_secret)
        ->and($customer->fresh()->currentAssignment->agent_profile_id)->toBe($agent->agentProfile->id)
        ->and($agent->fresh()->user_type)->toBe($agent->user_type);
});

test('AUTH-AC-033: enrolment keeps the authenticator secret and QR code out of logs and audit records', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message.json_encode($message->context);
    });
    $agent = User::factory()->agent()->mfaSetupRequired()->create();
    $page = $this->actingAs($agent)->get(route('two-factor.enrolment'))->assertOk()->viewData('page')['props'];
    $secret = decrypt($agent->fresh()->two_factor_pending_secret);

    $this->post(route('two-factor.enrolment.confirm'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertSessionHasNoErrors();

    $audit = DB::table('audit_events')->where('target_id', $agent->id)->pluck('payload')->implode(' ');
    expect($agent->fresh()->two_factor_confirmed_at)->not->toBeNull()
        ->and($audit)->not->toContain($secret)->not->toContain($page['manualSetupKey'])->not->toContain('<svg')
        ->and(implode(' ', $logged))->not->toContain($secret)->not->toContain($page['manualSetupKey'])->not->toContain('<svg');
});

/** Every stored audit payload and canonical audit content, for secret-absence checks. */
function authAuditText(): string
{
    return DB::table('audit_events')->pluck('payload')->merge(DB::table('canonical_audit_events')->pluck('content'))->implode(' ');
}

/** @return array<string, int> */
function authFreshSession(): array
{
    $now = Carbon::now()->timestamp;

    return ['auth.fresh_until' => $now + 600, 'auth.password_confirmed_at' => $now, 'auth.mfa_confirmed_at' => $now];
}

function authStaffManager(): User
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::AgentsManage);

    return $admin;
}

function authStaffRecovery(object $test, User $actor, User $target, string $email): StaffRecovery
{
    $test->actingAs($actor)->withSession(authFreshSession())->post(route('admin.staff-recoveries.store', $target), [
        'email' => $email, 'procedure_reference' => 'ID-CHECK-7', 'notes' => 'Checked passport against HR record in person.',
        'verified_at' => now()->subHour()->toIso8601String(), 'identity_verified' => true,
    ])->assertSessionHasNoErrors();

    return StaffRecovery::query()->where('user_id', $target->id)->latest('id')->firstOrFail();
}

function authStaffDecide(object $test, User $actor, StaffRecovery $recovery, string $action): void
{
    $test->actingAs($actor)->withSession(authFreshSession())->post(route('admin.staff-recoveries.decide', [$recovery->reference, $action]), [
        'version' => $recovery->fresh()->version, 'reason' => 'Identity confirmed independently.',
    ])->assertSessionHasNoErrors();
}

function authLockPassword(User $user): void
{
    foreach (range(1, 10) as $attempt) {
        app(AuthenticationAbuseService::class)->recordPasswordFailure($user->email, request(), $user);
    }
}

test('AUTH-AC-010: sign-in, password failure, lock, reset and MFA events are audited without the submitted secrets', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00:00'));
    $customer = User::factory()->customer()->create();
    $agent = User::factory()->agent()->mfaSetupRequired()->create();
    $resetToken = Password::createToken($customer);

    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])->assertRedirect();
    $this->post(route('logout'));
    foreach (range(1, 10) as $attempt) {
        $this->travel(65)->seconds();
        $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'Wrong-Secret-Value-'.$attempt]);
    }
    $this->post(route('password.update'), ['token' => $resetToken, 'email' => $customer->email,
        'password' => 'Reset-Secret-Passphrase-2026', 'password_confirmation' => 'Reset-Secret-Passphrase-2026'])->assertRedirect(route('login'));
    $this->actingAs($agent)->get(route('two-factor.enrolment'))->assertOk();

    $events = AuditEvent::query()->whereIn('event_type', ['auth.login_succeeded', 'auth.password_failed', 'auth.lock_created', 'auth.password_reset', 'auth.mfa_changed'])
        ->get()->groupBy('event_type')->map->count()->sortKeys()->all();
    expect($events)->toBe(['auth.lock_created' => 1, 'auth.login_succeeded' => 1, 'auth.mfa_changed' => 1, 'auth.password_failed' => 10, 'auth.password_reset' => 1])
        ->and(AuditEvent::query()->where('event_type', 'auth.lock_created')->sole()->payload)->toMatchArray(['category' => 'password', 'attempt_count' => 10])
        ->and(authAuditText())->not->toContain('Wrong-Secret-Value')->not->toContain('Reset-Secret-Passphrase-2026')->not->toContain($resetToken)
        ->not->toContain(decrypt($agent->fresh()->two_factor_pending_secret));
});

test('AUTH-AC-010: a failed second-factor sign-in is audited without the submitted code', function (string $field, string $code, string $category): void {
    $agent = User::factory()->agent()->withTwoFactor()->create();
    $this->post(route('login.store'), ['email' => $agent->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));

    $this->post(route('two-factor.login.store'), [$field => $code])->assertSessionHasErrors($field);

    expect(AuditEvent::query()->where('event_type', 'auth.mfa_failed')->where('target_id', $agent->id)->sole()->payload)
        ->toEqual(['category' => $category, 'attempt_count' => 1])
        ->and(authAuditText())->not->toContain($code);
})->with([
    'authenticator code' => ['code', '914276', 'totp'],
    'recovery code' => ['recovery_code', 'not-a-real-recovery-code', 'recovery_code'],
]);

test('AUTH-AC-023: assisted-recovery approval, rejection and completion are audited without the link token or new password', function (): void {
    Notification::fake();
    $requester = authStaffManager();
    $approver = authStaffManager();
    $recovering = User::factory()->agent()->withTwoFactor()->create(['email' => 'agent.old@example.test']);
    $refused = User::factory()->agent()->withTwoFactor()->create();
    $approved = authStaffRecovery($this, $requester, $recovering, 'agent.new@example.test');
    $rejected = authStaffRecovery($this, $requester, $refused, $refused->email);
    authStaffDecide($this, $approver, $approved, 'approve');
    authStaffDecide($this, $approver, $rejected, 'reject');
    $token = null;
    Notification::assertSentOnDemand(StaffRecoveryActivationNotification::class, function (StaffRecoveryActivationNotification $notification) use (&$token): bool {
        $token = str($notification->activationUrl)->after('#token=')->toString();

        return true;
    });
    auth()->logout();

    $this->post(route('staff-recovery.activate', $approved->reference), ['token' => $token,
        'password' => 'Str0ng!Recovered#2026', 'password_confirmation' => 'Str0ng!Recovered#2026'])->assertRedirect(route('two-factor.enrolment'));

    $decisions = AuditEvent::query()->whereIn('event_type', ['auth.staff_recovery_approved', 'auth.staff_recovery_rejected', 'auth.staff_recovery_completed'])
        ->orderBy('id')->get(['event_type', 'target_id', 'actor_id', 'target_reference']);
    expect($decisions->map->only(['event_type', 'target_id', 'actor_id', 'target_reference'])->all())->toBe([
        ['event_type' => 'auth.staff_recovery_approved', 'target_id' => $recovering->id, 'actor_id' => $approver->id, 'target_reference' => $approved->reference],
        ['event_type' => 'auth.staff_recovery_rejected', 'target_id' => $refused->id, 'actor_id' => $approver->id, 'target_reference' => $rejected->reference],
        ['event_type' => 'auth.staff_recovery_completed', 'target_id' => $recovering->id, 'actor_id' => null, 'target_reference' => $approved->reference],
    ])->and(authAuditText())->not->toContain($token)->not->toContain('Str0ng!Recovered#2026')->not->toContain((string) $recovering->fresh()->password);
});

test('AUTH-AC-034: enrolment, recovery-code use and regeneration are audited without the secret or codes', function (): void {
    $enrolling = User::factory()->agent()->mfaSetupRequired()->create();
    $recovering = User::factory()->agent()->withTwoFactor()->create();
    $regenerating = User::factory()->admin()->withTwoFactor()->create();

    $this->post(route('login.store'), ['email' => $recovering->email, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'code-one'])->assertRedirect(route('two-factor.enrolment'));
    auth()->logout();
    $this->actingAs($enrolling)->get(route('two-factor.enrolment'))->assertOk();
    $secret = decrypt($enrolling->fresh()->two_factor_pending_secret);
    $this->post(route('two-factor.enrolment.confirm'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertSessionHasNoErrors();
    $enrolmentCodes = session('recoveryCodes');
    $this->actingAs($regenerating)->post(route('two-factor.regenerate-recovery-codes'), [
        'password' => 'password', 'code' => app(Google2FA::class)->getCurrentOtp(decrypt($regenerating->two_factor_secret)),
    ])->assertSessionHasNoErrors();
    $regeneratedCodes = session('recoveryCodes');

    expect(AuditEvent::query()->where('event_type', 'auth.mfa_changed')->where('target_id', $enrolling->id)->count())->toBe(2)
        ->and(AuditEvent::query()->where('event_type', 'auth.recovery_codes_used')->where('target_id', $recovering->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('event_type', 'auth.recovery_codes_regenerated')->where('target_id', $regenerating->id)->count())->toBe(1)
        ->and(authAuditText())->not->toContain($secret)->not->toContain('JBSWY3DPEHPK3PXP')->not->toContain('code-one');
    foreach ([...$enrolmentCodes, ...$regeneratedCodes] as $plainCode) {
        expect(authAuditText())->not->toContain($plainCode);
    }
});

test('AUTH-AC-068: an Admin permission change applied to an active session is audited at the refresh', function (): void {
    $manager = User::factory()->admin()->withTwoFactor()->create();
    $manager->givePermissionTo(AdminPermission::AdminsManage);
    $target = User::factory()->admin()->withTwoFactor()->create();
    $target->givePermissionTo([AdminPermission::AuditView, AdminPermission::FeesManage]);
    $this->actingAs($target)->get(route('admin.dashboard'))->assertOk();
    $versionBefore = $target->fresh()->permission_version;
    app(PermissionManagementService::class)->updatePermissions($manager, $target, [AdminPermission::AuditView->value], 'Fees duty ended', $versionBefore);

    $this->get(route('admin.dashboard'))->assertOk();
    $this->get(route('admin.dashboard'))->assertOk();

    expect(AuditEvent::query()->where('event_type', 'authorization.session_refreshed')->where('target_id', $target->id)->sole()->payload)
        ->toBe(['from_version' => $versionBefore, 'to_version' => $versionBefore + 1]);
});

test('AUTH-AC-068: evicting a device at the concurrent-device limit is audited without session identifiers or the password', function (): void {
    $customer = User::factory()->customer()->create();
    foreach (range(1, 5) as $i) {
        DB::table('sessions')->insert(['id' => "closure-a-session-{$i}", 'user_id' => $customer->id, 'ip_address' => '192.168.1.'.$i,
            'user_agent' => 'Mozilla/5.0 (iPhone)', 'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp, 'created_at' => now()->timestamp]);
    }
    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])->assertRedirect(route('device-eviction'));

    $this->post(route('device-eviction.confirm'), ['session_id' => 'closure-a-session-1'])->assertRedirect();

    expect(AuditEvent::query()->where('event_type', 'auth.device_limit_reached')->where('target_id', $customer->id)->sole()->payload)->toBe(['device_count' => 5])
        ->and(AuditEvent::query()->where('event_type', 'auth.session_revoked')->where('target_id', $customer->id)->sole()->payload)->toBe(['changed_fields' => ['evictSession']])
        ->and(authAuditText())->not->toContain('closure-a-session-1');
    $this->assertDatabaseMissing('sessions', ['id' => 'closure-a-session-1']);
});

test('AUTH-AC-011: a reset request returns the same generic response for any account type or state', function (Closure $email): void {
    $response = $this->from(route('password.request'))->post(route('password.email'), ['email' => $email()]);

    $response->assertRedirect(route('password.request'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', "If an account exists for this email, we've sent password reset instructions.");
})->with([
    'unknown email' => [fn (): string => 'nobody@example.test'],
    'deactivated customer' => [fn (): string => User::factory()->customer()->deactivated()->create()->email],
    'active agent' => [fn (): string => User::factory()->agent()->withTwoFactor()->create()->email],
    'active admin' => [fn (): string => User::factory()->admin()->withTwoFactor()->create()->email],
    'agent in mfa setup' => [fn (): string => User::factory()->agent()->mfaSetupRequired()->create()->email],
    'admin in mfa setup' => [fn (): string => User::factory()->admin()->mfaSetupRequired()->create()->email],
]);

test('AUTH-AC-072: exhausting reset requests and assisted-recovery activation leaves password, authenticator and recovery-code sign-in open', function (): void {
    Notification::fake([ResetPasswordNotification::class]);
    $agent = User::factory()->agent()->withTwoFactor()->create();
    $this->post(route('password.email'), ['email' => $agent->email]);
    $this->post(route('password.email'), ['email' => $agent->email]);
    foreach (range(1, 5) as $attempt) {
        $this->post(route('staff-recovery.activate', (string) Str::uuid()), ['token' => str_repeat('x', 64)]);
    }
    $this->post(route('staff-recovery.activate', (string) Str::uuid()), ['token' => str_repeat('x', 64)])->assertTooManyRequests();

    $this->post(route('login.store'), ['email' => $agent->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
    $this->post(route('two-factor.login.store'), ['code' => app(Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP')])->assertRedirect();
    $this->assertAuthenticatedAs($agent);
    auth()->logout();
    $this->post(route('login.store'), ['email' => $agent->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'code-two'])->assertRedirect(route('two-factor.enrolment'));

    $this->assertAuthenticatedAs($agent);
    Notification::assertSentToTimes($agent, ResetPasswordNotification::class, 1);
});

test('AUTH-AC-072: password, authenticator and recovery-code locks leave reset requests and assisted-recovery activation open', function (): void {
    Notification::fake();
    $agent = User::factory()->agent()->withTwoFactor()->create();
    $abuse = app(AuthenticationAbuseService::class);
    foreach (range(1, 10) as $attempt) {
        $abuse->recordTotpFailure($agent, request(), 1);
        $abuse->recordRecoveryCodeFailure($agent, request(), 1);
    }
    authLockPassword($agent);

    $this->post(route('password.email'), ['email' => $agent->email])->assertSessionHasNoErrors();
    $this->post(route('staff-recovery.activate', (string) Str::uuid()), ['token' => str_repeat('x', 64)])->assertSessionHasErrors('password');

    Notification::assertSentTo($agent, ResetPasswordNotification::class);
});

test('AUTH-AC-072: an invitation resend limit and password and reset counters leave invitation activation open', function (): void {
    Queue::fake([DeliverAgentInvitationJob::class]);
    BusinessProfile::current()->forceFill(['invitation_sender_email' => 'invitations@saverapp.ng', 'invitation_sender_name' => 'SaverApp Security',
        'is_invitation_sender_verified' => true])->save();
    $manager = User::factory()->admin()->withTwoFactor()->create();
    $manager->givePermissionTo(AdminPermission::AdminsManage);
    $this->actingAs($manager)->withSession(authFreshSession())->post(route('admin.access.invitations.store'), ['attempt_reference' => (string) Str::uuid(),
        'name' => 'Ngozi Admin', 'email' => 'ngozi.admin@example.test', 'permissions' => [AdminPermission::AuditView->value], 'confirmed' => true])->assertSessionHasNoErrors();
    $invited = User::query()->where('email', 'ngozi.admin@example.test')->sole();
    $this->travel(2)->minutes();
    $this->post(route('admin.access.invitations.resend', $invited))->assertSessionHasNoErrors();
    $this->post(route('admin.access.invitations.resend', $invited))->assertSessionHasErrors('resend');
    auth()->logout();
    authLockPassword($invited);
    $this->post(route('password.email'), ['email' => $invited->email]);
    $this->post(route('password.email'), ['email' => $invited->email]);

    $this->post(route('invitations.admin.activate', Queue::pushed(DeliverAgentInvitationJob::class)->last()->plainToken), ['identity_confirmed' => true,
        'access_accepted' => true, 'password' => 'Str0ng!Admin#Pass2026', 'password_confirmation' => 'Str0ng!Admin#Pass2026'])
        ->assertRedirect(route('two-factor.enrolment'));

    expect($invited->fresh()->account_state)->toBe(AccountState::MfaSetupRequired);
});

test('AUTH-AC-072: repeated activation attempts on one invitation link are limited without blocking other links or sign-in', function (): void {
    $customer = User::factory()->customer()->create();
    $guessed = route('invitations.customer.activate', str_repeat('a', 64));
    foreach (range(1, 5) as $attempt) {
        $this->post($guessed, ['password' => 'a-long-customer-passphrase'])->assertForbidden();
    }

    $this->post($guessed, ['password' => 'a-long-customer-passphrase'])->assertTooManyRequests();

    $this->get(route('invitations.customer.show', str_repeat('b', 64)))->assertOk();
    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])->assertRedirect(route('customer.dashboard', absolute: false));
});

test('AUTH-AC-072: reaching the assisted-recovery request limit leaves the signed-in password change open', function (): void {
    $admin = authStaffManager();
    $agent = User::factory()->agent()->withTwoFactor()->create();
    foreach (range(1, 6) as $attempt) {
        $this->actingAs($admin)->withSession(authFreshSession())->post(route('admin.staff-recoveries.store', $agent), []);
    }

    $this->put(route('user-password.update'), ['current_password' => 'password', 'password' => 'Changed-Admin-Pass-2026',
        'password_confirmation' => 'Changed-Admin-Pass-2026'])->assertRedirect()->assertSessionHasNoErrors();

    expect(Hash::check('Changed-Admin-Pass-2026', $admin->fresh()->password))->toBeTrue();
});

test('AUTH-AC-073: a temporary password lock preserves Admin roles, permissions, state and the active session', function (): void {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::AuditView, AdminPermission::FeesManage]);
    $roles = $admin->getRoleNames()->all();
    $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

    authLockPassword($admin);

    $fresh = $admin->fresh();
    expect($fresh->isTemporarilyLocked('password'))->toBeTrue()
        ->and($fresh->getRoleNames()->all())->toBe($roles)
        ->and($fresh->getDirectPermissions()->pluck('name')->sort()->values()->all())->toBe([AdminPermission::AuditView->value, AdminPermission::FeesManage->value])
        ->and($fresh->account_state)->toBe(AccountState::Active);
    $this->get(route('admin.dashboard'))->assertOk();
});

test('AUTH-AC-073: a temporary password lock preserves the Agent customer assignment, state and the active session', function (): void {
    $agent = User::factory()->agent()->withTwoFactor()->create();
    AgentProfile::factory()->active()->create(['user_id' => $agent->id]);
    $customer = CustomerProfile::factory()->create();
    CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $agent->agentProfile->id]);
    $this->actingAs($agent)->get(route('agent.dashboard'))->assertOk();

    authLockPassword($agent);

    expect($agent->fresh()->isTemporarilyLocked('password'))->toBeTrue()
        ->and($customer->fresh()->currentAssignment->agent_profile_id)->toBe($agent->agentProfile->id)
        ->and($agent->fresh()->account_state)->toBe(AccountState::Active);
    $this->get(route('agent.dashboard'))->assertOk();
});

test('AUTH-AC-075: a password reset with the other factor clears the password lock but keeps the factor lock', function (string $lockedFactor, string $usedFactor): void {
    $agent = User::factory()->agent()->withTwoFactor()->create();
    $abuse = app(AuthenticationAbuseService::class);
    foreach (range(1, 10) as $attempt) {
        $lockedFactor === 'mfa' ? $abuse->recordTotpFailure($agent, request(), 1) : $abuse->recordRecoveryCodeFailure($agent, request(), 1);
    }
    authLockPassword($agent);
    $proof = $usedFactor === 'code' ? ['code' => app(Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP')] : ['recovery_code' => 'code-one'];

    $this->post(route('password.update'), ['token' => Password::createToken($agent), 'email' => $agent->email, ...$proof,
        'password' => 'agent-reset-8', 'password_confirmation' => 'agent-reset-8'])->assertSessionHasNoErrors();

    $fresh = $agent->fresh();
    expect($abuse->isPasswordRestricted($fresh->email, $fresh))->toBeFalse()
        ->and($fresh->isTemporarilyLocked('password'))->toBeFalse()
        ->and($fresh->isTemporarilyLocked($lockedFactor))->toBeTrue();
})->with([
    'authenticator locked, recovery code used' => ['mfa', 'recovery_code'],
    'recovery codes locked, authenticator used' => ['recovery_code', 'code'],
]);

test('AUTH-AC-075: a password reset refuses a factor that is in its cooldown', function (string $field, string $lock): void {
    $agent = User::factory()->agent()->withTwoFactor()->create();
    $abuse = app(AuthenticationAbuseService::class);
    foreach (range(1, 10) as $attempt) {
        $field === 'code' ? $abuse->recordTotpFailure($agent, request(), 1) : $abuse->recordRecoveryCodeFailure($agent, request(), 1);
    }
    $value = $field === 'code' ? app(Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP') : 'code-one';

    $this->post(route('password.update'), ['token' => Password::createToken($agent), 'email' => $agent->email, $field => $value,
        'password' => 'agent-reset-8', 'password_confirmation' => 'agent-reset-8'])->assertSessionHasErrors($field);

    expect(Hash::check('agent-reset-8', $agent->fresh()->password))->toBeFalse()
        ->and($agent->fresh()->isTemporarilyLocked($lock))->toBeTrue();
})->with([
    'authenticator code' => ['code', 'mfa'],
    'recovery code' => ['recovery_code', 'recovery_code'],
]);

test('AUTH-AC-076: lock expiry and counter reset leave a non-active account in its state', function (AccountState $state): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00:00'));
    $user = User::factory()->customer()->create(['account_state' => $state]);
    authLockPassword($user);
    $this->travel(61)->minutes();
    app(AuthenticationAbuseService::class)->clearPasswordFailures($user->email_normalized, $user->fresh());

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertGuest();
    expect($user->fresh()->isTemporarilyLocked())->toBeFalse()
        ->and($user->fresh()->account_state)->toBe($state);
})->with([
    'suspended' => AccountState::Suspended,
    'deactivated' => AccountState::Deactivated,
    'invited' => AccountState::Invited,
]);

/** A code from the authenticator's next time step, which is accepted even after the current step was consumed. */
function authNextOtp(string $encryptedSecret): string
{
    $engine = app(Google2FA::class);

    return $engine->oathTotp(decrypt($encryptedSecret), $engine->getTimestamp() + 1);
}

test('AUTH-AC-020: approving staff recovery revokes sessions, trusted devices, reset links, email changes and invitations before activation', function (): void {
    Notification::fake();
    $agent = authAgent();
    $requester = User::factory()->admin()->withTwoFactor()->create();
    $approver = User::factory()->admin()->withTwoFactor()->create();
    $requester->givePermissionTo(AdminPermission::AgentsManage->value);
    $approver->givePermissionTo(AdminPermission::AgentsManage->value);
    $fresh = ['auth.fresh_until' => now()->timestamp + 600, 'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
    DB::table('sessions')->insert(['id' => 'agent-phone-session', 'user_id' => $agent->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'Agent phone', 'payload' => '', 'last_activity' => now()->timestamp]);
    AgentTrustedDevice::create(['user_id' => $agent->id, 'device_token_hash' => hash('sha256', 'device'), 'device_name' => 'Agent phone', 'trusted_until' => now()->addDays(30)]);
    Password::createToken($agent);
    PendingEmailChange::create(['user_id' => $agent->id, 'current_email' => $agent->email, 'proposed_email' => 'pending.new@example.test',
        'proposed_email_normalized' => 'pending.new@example.test', 'current_token_hash' => hash('sha256', 'current'),
        'proposed_token_hash' => hash('sha256', 'proposed'), 'expires_at' => now()->addDay()]);
    $invitation = Invitation::create(['user_id' => $agent->id, 'role' => 'agent', 'target_email' => $agent->email,
        'target_email_normalized' => $agent->email_normalized, 'token_hash' => hash('sha256', 'open-invitation'), 'generation' => 1,
        'status' => InvitationStatus::Sent, 'delivery_status' => DeliveryStatus::Pending, 'expires_at' => now()->addDay(), 'invited_by_user_id' => $requester->id]);
    $this->actingAs($requester)->withSession($fresh)->post(route('admin.staff-recoveries.store', $agent), [
        'email' => $agent->email, 'procedure_reference' => 'ID-CHECK-7', 'notes' => 'Checked passport against HR record in person.',
        'verified_at' => now()->subHour()->toIso8601String(), 'identity_verified' => true,
    ])->assertSessionHasNoErrors();
    $recovery = StaffRecovery::query()->where('user_id', $agent->id)->sole();

    $this->actingAs($approver)->withSession($fresh)->post(route('admin.staff-recoveries.decide', [$recovery->reference, 'approve']), [
        'version' => $recovery->version, 'reason' => 'Identity confirmed independently.',
    ])->assertSessionHasNoErrors();

    expect($recovery->fresh()->state)->toBe('awaiting_activation')
        ->and(DB::table('sessions')->where('user_id', $agent->id)->count())->toBe(0)
        ->and(AgentTrustedDevice::query()->where('user_id', $agent->id)->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->where('email', $agent->email)->count())->toBe(0)
        ->and(PendingEmailChange::query()->where('user_id', $agent->id)->count())->toBe(0)
        ->and($invitation->fresh()->status)->toBe(InvitationStatus::Cancelled)
        ->and($agent->fresh()->password)->toBeNull();
});

test('AUTH-AC-026: an expired pending enrolment cannot be confirmed and leaves the account without an authenticator', function (string $type, string $dashboard): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00:00'));
    $user = User::factory()->{$type}()->mfaSetupRequired()->create();
    $this->actingAs($user)->get(route('two-factor.enrolment'))->assertOk();
    $pendingCode = app(Google2FA::class)->getCurrentOtp(decrypt($user->fresh()->two_factor_pending_secret));
    $this->travel(11)->minutes();

    $this->post(route('two-factor.enrolment.confirm'), ['code' => $pendingCode])
        ->assertSessionHasErrors(['code' => 'The authenticator enrolment session has expired. Please restart enrolment.']);

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->account_state)->toBe(AccountState::MfaSetupRequired)
        ->and(UserRecoveryCode::query()->where('user_id', $user->id)->count())->toBe(0);
    $this->get(route($dashboard))->assertRedirect(route('two-factor.enrolment'));
    expect($user->two_factor_pending_secret)->toBeNull();
})->with([
    'agent' => ['agent', 'agent.dashboard'],
    'admin' => ['admin', 'admin.dashboard'],
]);

test('AUTH-AC-028: a failed replacement start keeps the current authenticator active', function (string $password, string $code, string $errorField): void {
    $agent = authAgent();
    $originalSecret = $agent->two_factor_secret;

    $this->actingAs($agent)->post(route('two-factor.replace'), ['current_password' => $password, 'current_code' => $code])
        ->assertSessionHasErrors($errorField);

    expect($agent->fresh()->two_factor_secret)->toBe($originalSecret)
        ->and($agent->fresh()->two_factor_pending_secret)->toBeNull();
    $this->post(route('logout'));
    $this->post(route('login'), ['email' => $agent->email, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['code' => authNextOtp($originalSecret)])
        ->assertRedirect(route('agent.dashboard', absolute: false));
})->with([
    'wrong current password' => ['wrong-password', '123456', 'current_password'],
    'wrong current code' => ['password', '000000', 'current_code'],
]);

test('AUTH-AC-028: a wrong code from the new authenticator keeps the current authenticator active', function (): void {
    $agent = authAgent();
    $originalSecret = $agent->two_factor_secret;
    $this->actingAs($agent)->post(route('two-factor.replace'), [
        'current_password' => 'password', 'current_code' => app(Google2FA::class)->getCurrentOtp(decrypt($originalSecret)),
    ])->assertSessionHasNoErrors();

    $this->post(route('two-factor.replace.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');

    expect($agent->fresh()->two_factor_secret)->toBe($originalSecret);
    $this->post(route('logout'));
    $this->post(route('login'), ['email' => $agent->email, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['code' => authNextOtp($originalSecret)])
        ->assertRedirect(route('agent.dashboard', absolute: false));
});

test('AUTH-AC-028: an expired replacement is discarded and the current authenticator stays active', function (): void {
    $agent = authAgent();
    $originalSecret = $agent->two_factor_secret;
    $this->actingAs($agent)->post(route('two-factor.replace'), [
        'current_password' => 'password', 'current_code' => app(Google2FA::class)->getCurrentOtp(decrypt($originalSecret)),
    ])->assertSessionHasNoErrors();
    $pendingSecret = decrypt($agent->fresh()->two_factor_pending_secret);
    $this->travel(11)->minutes();

    $this->post(route('two-factor.replace.confirm'), ['code' => app(Google2FA::class)->getCurrentOtp($pendingSecret)])
        ->assertSessionHasErrors(['code' => 'The replacement session has expired. Your current authenticator remains active.']);

    $fresh = $agent->fresh();
    expect($fresh->two_factor_secret)->toBe($originalSecret)
        ->and($fresh->two_factor_pending_secret)->toBeNull()
        ->and($fresh->authenticator_state)->toBe(AuthenticatorState::Active);
});

test('AUTH-AC-029: replacing an Agent authenticator revokes trusted devices, other sessions, old codes and the old secret', function (): void {
    Notification::fake();
    $agent = authAgent();
    $originalSecret = $agent->two_factor_secret;
    AgentTrustedDevice::create(['user_id' => $agent->id, 'device_token_hash' => hash('sha256', 'device'), 'device_name' => 'Agent phone', 'trusted_until' => now()->addDays(30)]);
    DB::table('sessions')->insert(['id' => 'other-agent-session', 'user_id' => $agent->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'Agent tablet', 'payload' => '', 'last_activity' => now()->timestamp]);
    $this->actingAs($agent)->post(route('two-factor.replace'), [
        'current_password' => 'password', 'current_code' => app(Google2FA::class)->getCurrentOtp(decrypt($originalSecret)),
    ])->assertSessionHasNoErrors();
    $newCode = app(Google2FA::class)->getCurrentOtp(decrypt($agent->fresh()->two_factor_pending_secret));

    $this->post(route('two-factor.replace.confirm'), ['code' => $newCode])->assertSessionHasNoErrors();

    expect(AgentTrustedDevice::query()->where('user_id', $agent->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', 'other-agent-session')->exists())->toBeFalse()
        ->and(UserRecoveryCode::query()->where('user_id', $agent->id)->where('code_hash', hash('sha256', 'code-one'))->exists())->toBeFalse()
        ->and($agent->fresh()->two_factor_secret)->not->toBe($originalSecret);
    $this->post(route('logout'));
    $this->post(route('login'), ['email' => $agent->email, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['code' => authNextOtp($originalSecret)])->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('AUTH-AC-031: a lost authenticator is replaced through one recovery code, a confirmed new authenticator and a fresh code set', function (): void {
    Notification::fake();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $originalSecret = $admin->two_factor_secret;
    $this->post(route('login'), ['email' => $admin->email, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'code-one'])->assertRedirect(route('two-factor.enrolment'));
    $this->get(route('two-factor.enrolment'))->assertInertia(fn (Assert $page) => $page->component('auth/TwoFactorEnrolment')->where('isReplacement', true));
    $newCode = app(Google2FA::class)->getCurrentOtp(decrypt($admin->fresh()->two_factor_pending_secret));

    $response = $this->post(route('two-factor.enrolment.confirm'), ['code' => $newCode]);

    $response->assertSessionHasNoErrors();
    $newCodes = session('recoveryCodes');
    expect($newCodes)->toHaveCount(10)
        ->and($admin->fresh()->two_factor_secret)->not->toBe($originalSecret)
        ->and(UserRecoveryCode::query()->where('user_id', $admin->id)->whereNull('consumed_at')->count())->toBe(10)
        ->and(UserRecoveryCode::query()->where('user_id', $admin->id)->where('code_hash', hash('sha256', 'code-two'))->exists())->toBeFalse();
    $this->post(route('two-factor.enrolment.acknowledge'))->assertRedirect(route('dashboard'));
    $this->get(route('admin.dashboard'))->assertOk();
    $this->post(route('logout'));
    $this->post(route('login'), ['email' => $admin->email, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['recovery_code' => $newCodes[0]])->assertRedirect(route('two-factor.enrolment'));
});

test('AUTH-AC-032: a user who passed only the password step is offered assisted recovery and cannot reach a dashboard', function (string $type, string $dashboard): void {
    $user = $type === 'agent' ? authAgent() : User::factory()->admin()->withTwoFactor()->create();
    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));

    $this->get(route($dashboard))->assertRedirect(route('login'));

    $this->assertGuest();
    $this->get(route('two-factor.login'))->assertInertia(fn (Assert $page) => $page->component('auth/TwoFactorChallenge'));
    expect(route('auth.assisted-recovery', absolute: false))->toBe('/assisted-recovery');
    $this->get('/assisted-recovery')->assertInertia(fn (Assert $page) => $page->component('auth/AssistedRecoveryHandoff'));
})->with([
    'agent' => ['agent', 'agent.dashboard'],
    'admin' => ['admin', 'admin.dashboard'],
]);

/** Configure a verified invitation sender and publish a registration fee rule through the Admin flow. */
function authRegistrationRule(object $test): FeeRule
{
    BusinessProfile::current()->forceFill(['invitation_sender_email' => 'invitations@saverapp.ng', 'invitation_sender_name' => 'SaverApp Security',
        'is_invitation_sender_verified' => true])->save();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $terms = ['kind' => 'registration', 'name' => 'Registration terms', 'model' => 'fixed', 'amount_ngn' => '500.00',
        'customer_description' => 'Agreed account registration fee.', 'publication_reason' => 'Approved registration terms.'];
    $fingerprint = $test->actingAs($admin)->postJson(route('admin.fees.registration.preview'), $terms)->assertOk()->json('preview_fingerprint');
    $test->withSession(authFreshSession())->postJson(route('admin.fees.registration.store'), [...$terms, 'confirmed' => true, 'preview_fingerprint' => $fingerprint])->assertRedirect();

    return FeeRule::query()->latest('version')->firstOrFail();
}

/**
 * Register an invited Customer through an Agent and return the delivered invitation token. Needs Notification::fake().
 *
 * @return array{0: User, 1: CustomerProfile, 2: string}
 */
function authInviteCustomer(object $test, string $email = 'invited.customer@example.test', string $phone = '+2348031234567'): array
{
    $rule = authRegistrationRule($test);
    $agent = authAgent();
    $test->actingAs($agent)->post(route('customers.store'), ['attempt_reference' => (string) Str::uuid(), 'fee_rule_version' => $rule->version,
        'name' => 'Invited Customer', 'email' => $email, 'phone' => $phone])->assertRedirect();

    return [$agent, User::query()->where('email_normalized', $email)->sole()->customerProfile, authLatestInvitationToken()];
}

function authLatestInvitationToken(): string
{
    return Notification::sent(new AnonymousNotifiable, CustomerInvitationNotification::class)->last()->plainToken;
}

/** @return array{0: string, 1: string} the current-email and proposed-email confirmation tokens of the latest request */
function authEmailChangeTokens(): array
{
    return Notification::sent(new AnonymousNotifiable, EmailChangeNotification::class)->slice(-2)
        ->map(fn (EmailChangeNotification $notification): string => substr((string) parse_url($notification->actionUrl, PHP_URL_FRAGMENT), 6))->values()->all();
}

function authCompleteEmailChange(object $test, User $user, string $email): void
{
    $test->actingAs($user)->withSession(authFreshSession())->post(route('email-change.store'), ['email' => $email])->assertRedirect(route('profile.edit'));
    $pending = PendingEmailChange::query()->where('user_id', $user->id)->sole();
    [$currentToken, $proposedToken] = authEmailChangeTokens();
    $test->post(route('email-change.confirm', $pending->id), ['token' => $currentToken]);
    $test->post(route('email-change.confirm', $pending->id), ['token' => $proposedToken])->assertRedirect(route('login'));
}

/** @return array<string, mixed> */
function authActivationPayload(): array
{
    return ['fee_acknowledged' => true, 'password' => 'customer-passphrase-2026', 'password_confirmation' => 'customer-passphrase-2026'];
}

test('AUTH-AC-038: a Customer invitation works for just under seven days and its expiry keeps the Customer records', function (): void {
    Notification::fake();
    $this->travelTo('2026-10-01 09:00:00');
    [$agent, $customer, $token] = authInviteCustomer($this);
    Auth::logout();
    $this->travelTo('2026-10-08 08:00:00');
    $this->get(route('invitations.customer.show', $token))->assertInertia(fn (Assert $page) => $page->where('status', 'ready'));

    $this->travelTo('2026-10-08 09:00:01');

    $this->post(route('invitations.customer.activate', $token), authActivationPayload())->assertForbidden();
    $this->get(route('invitations.customer.show', $token))->assertInertia(fn (Assert $page) => $page->where('status', 'expired'));
    $this->assertGuest();
    expect($customer->user->fresh()->account_state)->toBe(AccountState::Invited)
        ->and($customer->user->fresh()->password)->toBeNull()
        ->and($customer->fresh()->currentAssignment->agent_profile_id)->toBe($agent->agentProfile->id)
        ->and(FeeSnapshot::query()->where('customer_profile_id', $customer->id)->sole()->acknowledged_at)->toBeNull()
        ->and(FeeObligation::query()->where('customer_profile_id', $customer->id)->count())->toBe(1);
});

test('AUTH-AC-039: resending a Customer invitation restarts the seven-day expiry and refuses the older token', function (): void {
    Notification::fake();
    $this->travelTo('2026-10-01 09:00:00');
    [$agent, $customer, $oldToken] = authInviteCustomer($this);
    $this->travelTo('2026-10-03 09:00:00');
    $this->flushSession();

    $this->actingAs($agent)->post(route('customers.invitations.resend', $customer->customer_id))->assertSessionHasNoErrors()->assertRedirect();

    $latest = Invitation::query()->where('user_id', $customer->user_id)->latest('generation')->first();
    expect($latest->generation)->toBe(2)
        ->and($latest->expires_at->toDateTimeString())->toBe('2026-10-10 09:00:00');
    Auth::logout();
    $this->post(route('invitations.customer.activate', $oldToken), authActivationPayload())->assertForbidden();
    expect($customer->user->fresh()->account_state)->toBe(AccountState::Invited);
    $this->get(route('invitations.customer.show', authLatestInvitationToken()))->assertInertia(fn (Assert $page) => $page->where('status', 'ready'));
});

test('AUTH-AC-040: correcting an invited email to one used by another account is refused without change', function (): void {
    Notification::fake();
    [$agent, $customer] = authInviteCustomer($this);
    User::factory()->customer()->create(['email' => 'taken@example.test']);

    $this->actingAs($agent)->post(route('customers.invitations.correct-email', $customer->customer_id), ['email' => 'Taken@Example.test', 'reason' => 'Customer gave a new address'])
        ->assertSessionHasErrors('email');

    expect($customer->user->fresh()->email)->toBe('invited.customer@example.test')
        ->and(Invitation::query()->where('user_id', $customer->user_id)->sole()->status)->toBe(InvitationStatus::Sent);
});

test('AUTH-AC-040: after an invited email correction the link sent to the previous email cannot activate', function (): void {
    Notification::fake();
    [$agent, $customer, $previousToken] = authInviteCustomer($this);
    $this->actingAs($agent)->post(route('customers.invitations.correct-email', $customer->customer_id), ['email' => 'corrected@example.test', 'reason' => 'Typo in the address'])
        ->assertSessionHasNoErrors();
    Auth::logout();

    $this->post(route('invitations.customer.activate', $previousToken), authActivationPayload())->assertForbidden();

    $this->assertGuest();
    expect($customer->user->fresh()->account_state)->toBe(AccountState::Invited)
        ->and($customer->user->fresh()->email)->toBe('corrected@example.test');
});

test('AUTH-AC-041: a cancelled Customer invitation cannot activate and the Customer profile is kept', function (): void {
    Notification::fake();
    [$agent, $customer, $token] = authInviteCustomer($this);
    $this->actingAs($agent)->post(route('customers.invitations.cancel', $customer->customer_id), ['reason' => 'Customer withdrew'])->assertSessionHasNoErrors();
    Auth::logout();

    $this->post(route('invitations.customer.activate', $token), authActivationPayload())->assertForbidden();

    $this->assertGuest();
    expect($customer->user->fresh()->account_state)->toBe(AccountState::Invited)
        ->and($customer->user->fresh()->password)->toBeNull()
        ->and($customer->fresh())->not->toBeNull()
        ->and(FeeObligation::query()->where('customer_profile_id', $customer->id)->count())->toBe(1);
});

test('AUTH-AC-042: a phone already held by a Customer is refused when entered in another local format', function (string $phone): void {
    $rule = authRegistrationRule($this);
    CustomerProfile::factory()->create(['phone' => '+2348031234567']);

    $this->actingAs(authAgent())->post(route('customers.store'), ['attempt_reference' => (string) Str::uuid(), 'fee_rule_version' => $rule->version,
        'name' => 'Second Customer', 'email' => 'second.customer@example.test', 'phone' => $phone])->assertSessionHasErrors('phone');

    expect(CustomerProfile::query()->count())->toBe(1)
        ->and(User::query()->where('email_normalized', 'second.customer@example.test')->exists())->toBeFalse();
})->with([
    'national trunk prefix' => ['08031234567'],
    'spaced national format' => ['0803 123 4567'],
    'country code without plus' => ['2348031234567'],
    'international with trunk zero' => ['+234 (0) 803-123-4567'],
]);

test('AUTH-AC-045: a Customer invitation token is refused by Agent and Admin activation and by email-change confirmation', function (): void {
    Notification::fake();
    [, $customer, $token] = authInviteCustomer($this);
    $other = User::factory()->customer()->create();
    $this->actingAs($other)->withSession(authFreshSession())->post(route('email-change.store'), ['email' => 'other.new@example.test']);
    $pending = PendingEmailChange::query()->sole();
    Auth::logout();

    $this->post(route('invitations.agent.activate', $token), ['password' => 'agent-passphrase-2026', 'password_confirmation' => 'agent-passphrase-2026'])->assertForbidden();
    $this->post(route('invitations.admin.activate', $token), ['password' => 'admin-passphrase-2026', 'password_confirmation' => 'admin-passphrase-2026'])->assertForbidden();
    $this->post(route('email-change.confirm', $pending->id), ['token' => $token])->assertSessionHasErrors('token');

    $this->assertGuest();
    expect($customer->user->fresh()->account_state)->toBe(AccountState::Invited)
        ->and($customer->user->fresh()->user_type)->toBe(UserType::Customer)
        ->and($pending->fresh()->current_confirmed_at)->toBeNull()
        ->and($pending->fresh()->proposed_confirmed_at)->toBeNull();
});

test('AUTH-AC-045: password reset and email-change tokens are refused by Customer invitation activation', function (): void {
    Notification::fake();
    [, $customer] = authInviteCustomer($this);
    $resetToken = Password::createToken($customer->user);
    $active = User::factory()->customer()->create();
    $this->actingAs($active)->withSession(authFreshSession())->post(route('email-change.store'), ['email' => 'active.new@example.test']);
    [$emailChangeToken] = authEmailChangeTokens();
    Auth::logout();

    $this->post(route('invitations.customer.activate', $resetToken), authActivationPayload())->assertForbidden();
    $this->post(route('invitations.customer.activate', $emailChangeToken), authActivationPayload())->assertForbidden();

    $this->assertGuest();
    expect($customer->user->fresh()->account_state)->toBe(AccountState::Invited)
        ->and($customer->user->fresh()->password)->toBeNull();
});

test('AUTH-AC-046: an active Customer without fresh password confirmation cannot start an email change', function (array $session): void {
    Notification::fake();
    $customer = User::factory()->customer()->create(['email' => 'customer.current@example.test']);

    $this->actingAs($customer)->withSession($session)->post(route('email-change.store'), ['email' => 'customer.new@example.test'])
        ->assertRedirect(route('fresh-authentication'));

    expect(PendingEmailChange::query()->count())->toBe(0)
        ->and($customer->fresh()->email)->toBe('customer.current@example.test');
    Notification::assertNothingSent();
})->with([
    'never confirmed' => [[]],
    'confirmation window elapsed' => fn (): array => [['auth.fresh_until' => now()->timestamp - 1, 'auth.password_confirmed_at' => now()->timestamp - 601]],
]);

test('AUTH-AC-048: a pending proposed email is reserved against other users while the current email still signs in', function (): void {
    Notification::fake();
    $rule = authRegistrationRule($this);
    $requester = User::factory()->customer()->create(['email' => 'requester@example.test']);
    $other = User::factory()->customer()->create();
    $this->actingAs($requester)->withSession(authFreshSession())->post(route('email-change.store'), ['email' => 'reserved@example.test'])->assertRedirect(route('profile.edit'));

    $this->actingAs($other)->withSession(authFreshSession())->post(route('email-change.store'), ['email' => 'Reserved@Example.test'])
        ->assertSessionHasErrors(['email' => 'The email address is reserved or already in use.']);
    $this->actingAs(authAgent())->post(route('customers.store'), ['attempt_reference' => (string) Str::uuid(), 'fee_rule_version' => $rule->version,
        'name' => 'Reserved Customer', 'email' => 'reserved@example.test', 'phone' => '+2348039990000'])->assertSessionHasErrors('email');
    Auth::logout();
    $this->post(route('login.store'), ['email' => 'reserved@example.test', 'password' => 'password']);
    $this->assertGuest();
    $this->post(route('login.store'), ['email' => 'requester@example.test', 'password' => 'password'])->assertRedirect(route('customer.dashboard', absolute: false));

    $this->assertAuthenticatedAs($requester);
    expect(PendingEmailChange::query()->sole()->user_id)->toBe($requester->id)
        ->and(User::query()->where('email_normalized', 'reserved@example.test')->exists())->toBeFalse();
});

test('AUTH-AC-049: a replaced email-change request cannot confirm anything or change the email', function (): void {
    Notification::fake();
    $customer = User::factory()->customer()->create(['email' => 'customer.current@example.test']);
    $this->actingAs($customer)->withSession(authFreshSession())->post(route('email-change.store'), ['email' => 'first.choice@example.test']);
    $replaced = PendingEmailChange::query()->sole();
    [$replacedCurrentToken, $replacedProposedToken] = authEmailChangeTokens();

    $this->post(route('email-change.store'), ['email' => 'second.choice@example.test'])->assertRedirect(route('profile.edit'));
    $replacement = PendingEmailChange::query()->sole();
    foreach ([$replacedCurrentToken, $replacedProposedToken] as $token) {
        $this->post(route('email-change.confirm', $replaced->id), ['token' => $token])->assertSessionHasErrors('token');
        $this->post(route('email-change.confirm', $replacement->id), ['token' => $token])->assertSessionHasErrors('token');
    }

    expect($replacement->id)->not->toBe($replaced->id)
        ->and($replacement->fresh()->proposed_email)->toBe('second.choice@example.test')
        ->and($replacement->fresh()->current_confirmed_at)->toBeNull()
        ->and($replacement->fresh()->proposed_confirmed_at)->toBeNull()
        ->and($customer->fresh()->email)->toBe('customer.current@example.test');
});

test('AUTH-AC-050: an email change cannot complete while a recovery link is outstanding, so the link stays bound to the current email', function (): void {
    Notification::fake();
    $agent = authAgent();
    $requester = authStaffManager();
    $approver = authStaffManager();
    $recovery = authStaffRecovery($this, $requester, $agent, 'agent.recovered@example.test');
    authStaffDecide($this, $approver, $recovery, 'approve');
    $tokenHash = $recovery->fresh()->activation_token_hash;
    $proposedToken = str_repeat('p', 40);
    $pending = PendingEmailChange::create(['user_id' => $agent->id, 'current_email' => $agent->email, 'proposed_email' => 'agent.changed@example.test',
        'proposed_email_normalized' => 'agent.changed@example.test', 'current_token_hash' => hash('sha256', 'current'),
        'proposed_token_hash' => hash('sha256', $proposedToken), 'current_confirmed_at' => now(), 'expires_at' => now()->addMinutes(30)]);

    $this->post(route('email-change.confirm', $pending->id), ['token' => $proposedToken])->assertSessionHasErrors('token');

    expect($agent->fresh()->email)->toBe($agent->email)
        ->and(PendingEmailChange::query()->whereKey($pending->id)->exists())->toBeFalse()
        ->and($recovery->fresh()->state)->toBe('awaiting_activation')
        ->and($recovery->fresh()->activation_token_hash)->toBe($tokenHash);
});

test('AUTH-AC-051: an Agent email change keeps the password, authenticator, user type and Customer assignments', function (): void {
    Notification::fake();
    $agent = authAgent();
    $customer = CustomerProfile::factory()->create();
    CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $agent->agentProfile->id]);

    authCompleteEmailChange($this, $agent, 'agent.changed@example.test');

    $fresh = $agent->fresh();
    expect($fresh->email)->toBe('agent.changed@example.test')
        ->and($fresh->password)->toBe($agent->password)
        ->and($fresh->two_factor_secret)->toBe($agent->two_factor_secret)
        ->and($fresh->user_type)->toBe(UserType::Agent)
        ->and($customer->fresh()->currentAssignment->agent_profile_id)->toBe($agent->agentProfile->id);
});

test('AUTH-AC-051: an Admin email change keeps the password, authenticator, user type and permissions', function (): void {
    Notification::fake();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::AuditView, AdminPermission::FeesManage]);

    authCompleteEmailChange($this, $admin, 'admin.changed@example.test');

    $fresh = $admin->fresh();
    expect($fresh->email)->toBe('admin.changed@example.test')
        ->and($fresh->password)->toBe($admin->password)
        ->and($fresh->two_factor_secret)->toBe($admin->two_factor_secret)
        ->and($fresh->user_type)->toBe(UserType::Admin)
        ->and($fresh->getDirectPermissions()->pluck('name')->sort()->values()->all())->toBe([AdminPermission::AuditView->value, AdminPermission::FeesManage->value]);
});

test('AUTH-AC-054: a completed email change tells the account holder whom to contact for their role', function (string $type, string $contact): void {
    Notification::fake();
    $user = match ($type) {
        'customer' => CustomerProfile::factory()->create()->user,
        'agent' => authAgent(),
        'admin' => User::factory()->admin()->withTwoFactor()->create(),
    };

    authCompleteEmailChange($this, $user, "{$type}.changed@example.test");

    $completion = Notification::sent(new AnonymousNotifiable, EmailChangeNotification::class)->slice(-2)->values();
    expect($completion)->toHaveCount(2)
        ->and($completion->pluck('subject')->unique()->all())->toBe(['Your account email address changed'])
        ->and($completion[0]->message)->toContain("contact {$contact} immediately");
})->with([
    'customer' => ['customer', 'your Agent'],
    'agent' => ['agent', 'an Admin'],
    'admin' => ['admin', 'an Admin'],
]);

test('AUTH-AC-054: an Admin email change notifies the Admin managers but not the Admin or other Admins', function (): void {
    Notification::fake();
    $manager = User::factory()->admin()->withTwoFactor()->create();
    $manager->givePermissionTo(AdminPermission::AdminsManage);
    $bystander = User::factory()->admin()->withTwoFactor()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();

    authCompleteEmailChange($this, $admin, 'admin.changed@example.test');

    $audit = AuditEvent::query()->where('event_type', 'user.email_changed')->where('target_id', $admin->id)->sole();
    expect(DB::table('audit_notification_intents')->where('audit_event_id', $audit->id)->get(['recipient_user_id', 'audience_type'])
        ->map(fn (object $intent): array => (array) $intent)->all())->toBe([['recipient_user_id' => $manager->id, 'audience_type' => 'admin_manager']]);
    $this->actingAs($manager)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
        ->where('inbox.items.0.title', 'Admin email address changed'));
});

test('AUTH-AC-054: a Customer or Agent email change sends no Admin manager notice', function (string $type): void {
    Notification::fake();
    $manager = User::factory()->admin()->withTwoFactor()->create();
    $manager->givePermissionTo(AdminPermission::AdminsManage);
    $user = $type === 'agent' ? authAgent() : CustomerProfile::factory()->create()->user;

    authCompleteEmailChange($this, $user, "{$type}.changed@example.test");

    $audit = AuditEvent::query()->where('event_type', 'user.email_changed')->where('target_id', $user->id)->sole();
    expect(DB::table('audit_notification_intents')->where('audit_event_id', $audit->id)->exists())->toBeFalse();
})->with(['customer', 'agent']);

test('AUTH-AC-079: Admins see the lock category, reason, timing, source, attempts and the owner notice result', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00:00', 'UTC'));
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::SecurityOperationsManage);
    $customer = User::factory()->customer()->create();
    $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.45']);
    foreach (range(1, 10) as $attempt) {
        app(AuthenticationAbuseService::class)->recordPasswordFailure($customer->email, $request, $customer);
    }

    $response = $this->actingAs($admin)->get(route('admin.lockouts.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('locks.data.0.lock_category', 'password')
        ->where('locks.data.0.reason', 'Excessive failed password attempts (10 within 1 hour)')
        ->where('locks.data.0.locked_until', '2026-10-10T09:15:00+00:00')
        ->where('locks.data.0.masked_ip', '203.0.***.***')
        ->where('locks.data.0.failed_attempts_count', 10)
        ->where('locks.data.0.notification_status', 'sent')
        ->where('locks.data.0.notification_status_label', 'Emailed'));
});

test('AUTH-AC-079: a lock notice that cannot be emailed is recorded as failed', function (): void {
    Mail::extend('failing', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Mail server unavailable');
        }

        public function __toString(): string
        {
            return 'failing';
        }
    });
    config()->set('mail.mailers.failing', ['transport' => 'failing']);
    config()->set('mail.default', 'failing');
    $agent = User::factory()->agent()->withTwoFactor()->create();

    try {
        authLockPassword($agent);
    } catch (TransportException) {
    }

    expect(AuthenticationLock::query()->where('user_id', $agent->id)->orderBy('id')->first()->notification_status)->toBe(LockNotificationStatus::Failed);
});

test('AUTH-AC-080: one account attacked from five sources is restricted and audited although no source exceeds its own limit', function (): void {
    $target = User::factory()->customer()->create();
    $bystander = User::factory()->customer()->create();
    foreach (range(1, 5) as $source) {
        $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$source}"])
            ->post(route('login.store'), ['email' => $target->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
    }

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.200'])->post(route('login.store'), ['email' => $target->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->post(route('login.store'), ['email' => $bystander->email, 'password' => 'password'])
        ->assertRedirect(route('customer.dashboard', absolute: false));

    expect(AuditEvent::query()->where('event_type', 'auth.distributed_attack_detected')->sole()->payload)
        ->toEqual(['category' => 'many_sources', 'attempt_count' => 5]);
});

test('AUTH-AC-080: password spraying across twenty accounts restricts each account that then fails', function (): void {
    $abuse = app(AuthenticationAbuseService::class);
    $sprayed = User::factory()->customer()->create();
    $untouched = User::factory()->customer()->create();
    foreach (range(1, 49) as $attempt) {
        $abuse->recordPasswordFailure('sprayed-'.($attempt % 20).'@example.test', Request::create('/login', 'POST', server: ['REMOTE_ADDR' => "203.0.113.{$attempt}"]));
    }
    expect($abuse->isPasswordRestricted($sprayed->email, $sprayed))->toBeFalse();

    $abuse->recordPasswordFailure($sprayed->email, Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.50']), $sprayed);

    expect($abuse->isPasswordRestricted($sprayed->email, $sprayed))->toBeTrue()
        ->and($abuse->isPasswordRestricted($untouched->email, $untouched))->toBeFalse()
        ->and(AuditEvent::query()->where('event_type', 'auth.distributed_attack_detected')->sole()->payload)
        ->toEqual(['category' => 'password_spray', 'attempt_count' => 50]);
});

/** Store an active database session row for a user so it counts towards the device limit. */
function authSession(User $user, string $id): void
{
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '192.0.2.10', 'user_agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0',
        'payload' => '', 'last_activity' => now()->timestamp, 'created_at' => now()->timestamp]);
}

test('AUTH-AC-056: a third Agent device must revoke one of the two active devices before signing in', function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00:00'));
    $agent = authAgent();
    authSession($agent, 'agent-device-1');
    authSession($agent, 'agent-device-2');

    $this->post(route('login.store'), ['email' => $agent->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
    $this->post(route('two-factor.login.store'), ['code' => app(Google2FA::class)->getCurrentOtp(decrypt($agent->two_factor_secret))])
        ->assertRedirect(route('device-eviction'));

    $this->assertGuest();
    expect(DB::table('sessions')->where('user_id', $agent->id)->pluck('id')->sort()->values()->all())->toBe(['agent-device-1', 'agent-device-2']);

    $this->post(route('device-eviction.confirm'), ['session_id' => 'agent-device-1'])->assertRedirect(route('agent.dashboard', absolute: false));

    $this->assertAuthenticatedAs($agent);
    expect(DB::table('sessions')->where('user_id', $agent->id)->pluck('id')->all())->toBe(['agent-device-2']);
    Notification::assertSentTo($agent, SessionRevokedNotification::class);
});

test('AUTH-AC-059: a trusted Agent device skips the authenticator at 29 days and requires it again after 30 days', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00:00'));
    $agent = authAgent();
    $this->post(route('login.store'), ['email' => $agent->email, 'password' => 'password']);
    $deviceToken = $this->post(route('two-factor.login.store'), ['code' => app(Google2FA::class)->getCurrentOtp(decrypt($agent->two_factor_secret)), 'trust_device' => '1'])
        ->getCookie(AgentTrustedDeviceService::COOKIE_NAME, false)->getValue();
    $this->post(route('logout'));

    $this->travelTo(CarbonImmutable::parse('2026-11-08 09:00:00'));
    $this->withUnencryptedCookie(AgentTrustedDeviceService::COOKIE_NAME, $deviceToken)
        ->post(route('login.store'), ['email' => $agent->email, 'password' => 'password'])->assertRedirect(route('agent.dashboard', absolute: false));
    $this->assertAuthenticatedAs($agent);
    $this->post(route('logout'));

    $this->travelTo(CarbonImmutable::parse('2026-11-09 09:00:01'));
    $this->withUnencryptedCookie(AgentTrustedDeviceService::COOKIE_NAME, $deviceToken)
        ->post(route('login.store'), ['email' => $agent->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

test('AUTH-AC-059: a session started on a trusted Agent device still ends at the Agent idle and absolute limits', function (int $stepMinutes, int $activeSteps): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00:00'));
    $agent = authAgent();
    AgentTrustedDevice::create(['user_id' => $agent->id, 'device_token_hash' => AgentTrustedDevice::hashToken('closure-d-device-token'),
        'device_name' => 'Agent phone', 'trusted_until' => now()->addDays(30)]);
    $this->withUnencryptedCookie(AgentTrustedDeviceService::COOKIE_NAME, 'closure-d-device-token')
        ->post(route('login.store'), ['email' => $agent->email, 'password' => 'password'])->assertRedirect(route('agent.dashboard', absolute: false));

    for ($step = 1; $step <= $activeSteps; $step++) {
        $this->travel($stepMinutes)->minutes();
        $this->get(route('agent.dashboard'))->assertOk();
    }
    $this->travel($stepMinutes)->minutes();

    $this->get(route('agent.dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
})->with([
    'idle after 61 minutes' => [61, 0],
    'absolute after 24 hours of activity' => [50, 28],
]);

test('AUTH-AC-060: fresh authentication expires 10 minutes after confirmation despite ordinary requests at 5 and 9 minutes', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00:00'));
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $this->actingAs($admin)->withSession(['auth.fresh_until' => now()->timestamp + 600, 'auth.password_confirmed_at' => now()->timestamp,
        'auth.mfa_confirmed_at' => now()->timestamp])->get(route('admin.dashboard'))->assertOk();

    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:05:00'));
    $this->get(route('admin.dashboard'))->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:09:00'));
    $this->get(route('admin.dashboard'))->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:10:00'));
    $this->get(route('security.edit'))->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:10:01'));

    $this->get(route('security.edit'))->assertRedirect(route('fresh-authentication'));
});

test('AUTH-AC-061: each eligible visit replaces the saved destination and the cookie expires 24 hours after the latest visit', function (string $type, string $dashboard): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00:00'));
    $user = $type === 'agent' ? authAgent() : User::factory()->admin()->withTwoFactor()->create();
    $this->actingAs($user)->get(route($dashboard))->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:30:00'));

    $cookie = $this->get(route('profile.edit'))->assertOk()->getCookie(ResumeCookieService::COOKIE_NAME, false);

    expect(Crypt::decrypt($cookie->getValue())['path'])->toBe('/settings/profile')
        ->and($cookie->getExpiresTime())->toBe(CarbonImmutable::parse('2026-10-11 09:30:00')->timestamp);
    $request = fn (): Request => Request::create('/login', 'GET', [], [ResumeCookieService::COOKIE_NAME => $cookie->getValue()]);
    $this->travelTo(CarbonImmutable::parse('2026-10-11 09:30:00'));
    expect(app(ResumeCookieService::class)->consumeResumeDestination($user, $request()))->toBe('/settings/profile');
    $this->travelTo(CarbonImmutable::parse('2026-10-11 09:30:01'));
    expect(app(ResumeCookieService::class)->consumeResumeDestination($user, $request()))->toBeNull();
})->with([
    'admin' => ['admin', 'admin.dashboard'],
    'agent' => ['agent', 'agent.dashboard'],
]);

test('AUTH-AC-067: the session and trusted-device cookies are HTTP-only, secure, same-site and expose no identifier or token', function (string $cookieName): void {
    $agent = authAgent();
    $agent->forceFill(['remember_token' => 'closure-d-remember-token'])->save();
    $this->post(route('login.store'), ['email' => $agent->email, 'password' => 'password']);

    $response = $this->post(route('two-factor.login.store'), ['code' => app(Google2FA::class)->getCurrentOtp(decrypt($agent->two_factor_secret)), 'trust_device' => '1']);

    $cookie = $response->getCookie($cookieName === 'session' ? config('session.cookie') : $cookieName, false);
    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getValue())->not->toContain($agent->email)->not->toContain('closure-d-remember-token')->not->toContain(app('session')->getId())
        ->not->toContain(AgentTrustedDevice::query()->sole()->device_token_hash)->not->toContain('"'.$agent->id.'"');
})->with([
    'session cookie' => ['session'],
    'trusted-device cookie' => [AgentTrustedDeviceService::COOKIE_NAME],
]);

test('AUTH-AC-077: an active permitted Admin unlocks another Admin without changing password, MFA, permissions or status', function (): void {
    Notification::fake();
    $actor = User::factory()->admin()->withTwoFactor()->create();
    $actor->givePermissionTo(AdminPermission::SecurityOperationsManage);
    $target = User::factory()->admin()->withTwoFactor()->create();
    $target->givePermissionTo(AdminPermission::AuditView);
    $target->lockTemporarily(15, 'password', 'Excessive failed password attempts');
    $before = $target->fresh();

    $this->actingAs($actor)->post(route('admin.lockouts.unlock', $target), ['restriction_token' => app(UnlockState::class)->token($before, $actor, 'password'),
        'category' => 'password', 'verification_method' => 'in_person', 'reason' => 'Identity verified in person'])->assertRedirect();

    $after = $target->fresh();
    expect($after->isTemporarilyLocked('password'))->toBeFalse()
        ->and($after->password)->toBe($before->password)
        ->and($after->two_factor_secret)->toBe($before->two_factor_secret)
        ->and($after->account_state)->toBe(AccountState::Active)
        ->and($after->getDirectPermissions()->pluck('name')->all())->toBe([AdminPermission::AuditView->value])
        ->and(DB::table('audit_events')->where('event_type', 'auth.manual_unlock')->sole()->actor_id)->toBe($actor->id);
});
