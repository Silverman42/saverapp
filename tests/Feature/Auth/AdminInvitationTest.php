<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\InvitationStatus;
use App\Jobs\DeliverAgentInvitationJob;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\Invitation;
use App\Models\Permission;
use App\Models\PermissionGrantHistory;
use App\Models\User;
use App\Notifications\Auth\AdminInvitationNotification;
use App\Services\InvitationSenderReadinessService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    foreach (AdminPermission::cases() as $permission) {
        Permission::firstOrCreate(['name' => $permission->value, 'guard_name' => 'web'], ['status' => 'active']);
    }

    $business = BusinessProfile::current();
    $business->invitation_sender_email = 'invitations@saverapp.ng';
    $business->invitation_sender_name = 'SaverApp Security';
    $business->is_invitation_sender_verified = true;
    $business->save();
});

function adminInvitationManager(): User
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::AdminsManage->value);

    return $admin;
}

function adminInvitationFreshSession(): array
{
    $now = Carbon::now()->timestamp;

    return ['auth.fresh_until' => $now + 600, 'auth.password_confirmed_at' => $now, 'auth.mfa_confirmed_at' => $now];
}

/**
 * Invite an Administrator and return the invited user with the plain token from the queued delivery job.
 *
 * @return array{0: User, 1: string}
 */
function adminInvitationIssue(object $test, User $inviter, array $overrides = []): array
{
    Queue::fake();
    $test->actingAs($inviter)->withSession(adminInvitationFreshSession())->post(route('admin.access.invitations.store'), array_merge([
        'attempt_reference' => (string) str()->uuid(),
        'name' => 'Ngozi Admin',
        'email' => 'ngozi.admin@example.test',
        'permissions' => [AdminPermission::AuditView->value],
        'confirmed' => true,
    ], $overrides))->assertSessionHasNoErrors();

    $token = null;
    Queue::assertPushed(DeliverAgentInvitationJob::class, function (DeliverAgentInvitationJob $job) use (&$token): bool {
        $token = $job->plainToken;

        return true;
    });

    return [User::query()->where('email', $overrides['email'] ?? 'ngozi.admin@example.test')->firstOrFail(), $token];
}

test('only an Admin with admins.manage and fresh authentication can open or submit the invitation form', function (): void {
    $baseline = User::factory()->admin()->withTwoFactor()->create();
    $agent = User::factory()->agent()->withTwoFactor()->create();

    $this->actingAs($baseline)->withSession(adminInvitationFreshSession())->get(route('admin.access.invitations.create'))->assertForbidden();
    $this->actingAs($agent)->withSession(adminInvitationFreshSession())->get(route('admin.access.invitations.create'))->assertForbidden();
    $this->actingAs($baseline)->withSession(adminInvitationFreshSession())->post(route('admin.access.invitations.store'), [
        'attempt_reference' => 'ref-1', 'name' => 'X', 'email' => 'x@example.test', 'permissions' => [], 'confirmed' => true,
    ])->assertForbidden();

    $this->actingAs(adminInvitationManager())->withSession(['auth.fresh_until' => 0, 'auth.password_confirmed_at' => 0])
        ->get(route('admin.access.invitations.create'))->assertRedirect(route('fresh-authentication'));
    expect(User::query()->where('email', 'x@example.test')->exists())->toBeFalse();
});

test('an invitation creates an Invited Admin with the chosen permissions, history, audit and a 24-hour link', function (): void {
    $inviter = adminInvitationManager();

    [$invited, $token] = adminInvitationIssue($this, $inviter);

    $invitation = Invitation::query()->where('user_id', $invited->id)->sole();
    expect($invited->account_state)->toBe(AccountState::Invited)
        ->and($invited->password)->toBeNull()
        ->and($invited->getRoleNames()->all())->toBe(['admin'])
        ->and($invited->getDirectPermissions()->pluck('name')->all())->toBe([AdminPermission::AuditView->value])
        ->and(PermissionGrantHistory::query()->where('user_id', $invited->id)->value('source'))->toBe('admin_invitation')
        ->and($invitation->role)->toBe('admin')
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and((int) round(now()->diffInHours($invitation->expires_at)))->toBe(24);

    $audit = AuditEvent::query()->where('event_type', 'admin.invited')->sole();
    expect($audit->actor_id)->toBe($inviter->id)
        ->and($audit->payload['permissions'])->toBe([AdminPermission::AuditView->value])
        ->and(json_encode($audit->payload))->not->toContain($token);
});

test('the queued delivery sends the Administrator invitation email with the admin activation link', function (): void {
    Notification::fake();

    [$invited, $token] = adminInvitationIssue($this, adminInvitationManager());
    (new DeliverAgentInvitationJob(Invitation::query()->where('user_id', $invited->id)->value('id'), $token, 1))
        ->handle(app(InvitationSenderReadinessService::class));

    Notification::assertSentOnDemand(AdminInvitationNotification::class, function (AdminInvitationNotification $notification, array $channels, object $notifiable) use ($token): bool {
        return $notifiable->routes['mail'] === 'ngozi.admin@example.test'
            && str_contains($notification->toMail($notifiable)->actionUrl, route('invitations.admin.show', $token));
    });
});

test('a replayed attempt resolves the same Admin and a changed payload under the same reference conflicts', function (): void {
    $inviter = adminInvitationManager();
    [$invited] = adminInvitationIssue($this, $inviter, ['attempt_reference' => 'admin-attempt-1']);

    $payload = ['attempt_reference' => 'admin-attempt-1', 'name' => 'Ngozi Admin', 'email' => 'ngozi.admin@example.test',
        'permissions' => [AdminPermission::AuditView->value], 'confirmed' => true];
    $this->actingAs($inviter)->withSession(adminInvitationFreshSession())->post(route('admin.access.invitations.store'), $payload)
        ->assertRedirect(route('admin.access.show', $invited->id));
    $this->actingAs($inviter)->withSession(adminInvitationFreshSession())->post(route('admin.access.invitations.store'), [...$payload, 'name' => 'Someone Else'])
        ->assertStatus(409);

    expect(User::query()->where('email', 'ngozi.admin@example.test')->count())->toBe(1);
});

test('duplicate emails, other account types and unknown permissions are rejected without creating an account', function (): void {
    $inviter = adminInvitationManager();
    User::factory()->agent()->create(['email' => 'agent@example.test']);
    User::factory()->admin()->create(['email' => 'active.admin@example.test']);

    foreach (['agent@example.test', 'active.admin@example.test'] as $email) {
        $this->actingAs($inviter)->withSession(adminInvitationFreshSession())->post(route('admin.access.invitations.store'), [
            'attempt_reference' => (string) str()->uuid(), 'name' => 'Dup', 'email' => $email, 'permissions' => [], 'confirmed' => true,
        ])->assertSessionHasErrors('email');
    }
    $this->actingAs($inviter)->withSession(adminInvitationFreshSession())->post(route('admin.access.invitations.store'), [
        'attempt_reference' => (string) str()->uuid(), 'name' => 'Bad', 'email' => 'bad@example.test', 'permissions' => ['not.a.permission'], 'confirmed' => true,
    ])->assertSessionHasErrors('permissions');

    expect(User::query()->where('email', 'bad@example.test')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'agent@example.test')->value('user_type')->value)->toBe('agent');
});

test('activation requires identity confirmation and access acceptance, then enters mandatory MFA setup', function (): void {
    [$invited, $token] = adminInvitationIssue($this, adminInvitationManager());
    auth()->logout();

    $this->get(route('invitations.admin.show', $token))->assertInertia(fn (Assert $page) => $page
        ->component('auth/AdminActivation')->where('status', 'ready')->where('email', 'ngozi.admin@example.test'));
    expect(Invitation::query()->where('user_id', $invited->id)->value('status'))->toBe(InvitationStatus::Opened);

    $this->post(route('invitations.admin.activate', $token), ['password' => 'Str0ng!Admin#Pass2026', 'password_confirmation' => 'Str0ng!Admin#Pass2026'])
        ->assertSessionHasErrors(['identity_confirmed', 'access_accepted']);
    $this->assertGuest();

    $this->post(route('invitations.admin.activate', $token), ['identity_confirmed' => true, 'access_accepted' => true,
        'password' => 'Str0ng!Admin#Pass2026', 'password_confirmation' => 'Str0ng!Admin#Pass2026'])
        ->assertRedirect(route('two-factor.enrolment'));

    expect($invited->fresh()->account_state)->toBe(AccountState::MfaSetupRequired)
        ->and(AuditEvent::query()->where('event_type', 'admin.activated')->where('target_id', $invited->id)->exists())->toBeTrue();
    $this->assertAuthenticatedAs($invited->fresh());

    auth()->logout();
    $this->post(route('invitations.admin.activate', $token), ['identity_confirmed' => true, 'access_accepted' => true,
        'password' => 'Str0ng!Admin#Pass2026', 'password_confirmation' => 'Str0ng!Admin#Pass2026'])->assertForbidden();
});

test('an expired Admin invitation cannot activate and shows no account details', function (): void {
    [, $token] = adminInvitationIssue($this, adminInvitationManager());
    auth()->logout();
    Carbon::setTestNow(now()->addHours(25));

    $this->get(route('invitations.admin.show', $token))->assertInertia(fn (Assert $page) => $page
        ->where('status', 'expired')->missing('email')->missing('token'));
    $this->post(route('invitations.admin.activate', $token), ['identity_confirmed' => true, 'access_accepted' => true,
        'password' => 'Str0ng!Admin#Pass2026', 'password_confirmation' => 'Str0ng!Admin#Pass2026'])->assertForbidden();
    Carbon::setTestNow();
});

test('resend invalidates the earlier link and is limited to one per minute', function (): void {
    $inviter = adminInvitationManager();
    [$invited, $oldToken] = adminInvitationIssue($this, $inviter);

    Carbon::setTestNow(now()->addMinutes(2));
    $this->actingAs($inviter)->withSession(adminInvitationFreshSession())->post(route('admin.access.invitations.resend', $invited))->assertSessionHasNoErrors();
    $this->actingAs($inviter)->withSession(adminInvitationFreshSession())->post(route('admin.access.invitations.resend', $invited))->assertSessionHasErrors('resend');
    Carbon::setTestNow();

    auth()->logout();
    $this->get(route('invitations.admin.show', $oldToken))->assertInertia(fn (Assert $page) => $page->where('status', 'cancelled'));
    expect(Invitation::query()->where('user_id', $invited->id)->latest('generation')->value('generation'))->toBe(2);
});

test('email correction moves the invitation to the new address and cancellation blocks activation without deleting the account', function (): void {
    $inviter = adminInvitationManager();
    [$invited, $firstToken] = adminInvitationIssue($this, $inviter);

    $this->actingAs($inviter)->withSession(adminInvitationFreshSession())->post(route('admin.access.invitations.correct-email', $invited), [
        'email' => 'ngozi.corrected@example.test', 'reason' => 'Typo in the original address',
    ])->assertSessionHasNoErrors();
    expect($invited->fresh()->email)->toBe('ngozi.corrected@example.test');

    $this->actingAs($inviter)->withSession(adminInvitationFreshSession())->post(route('admin.access.invitations.cancel', $invited), ['reason' => 'No longer joining'])
        ->assertSessionHasNoErrors();

    expect(Invitation::query()->where('user_id', $invited->id)->whereNull('cancelled_at')->exists())->toBeFalse()
        ->and($invited->fresh()->account_state)->toBe(AccountState::Invited)
        ->and(AuditEvent::query()->where('event_type', 'invitation.email_corrected')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', 'invitation.cancelled')->exists())->toBeTrue();

    auth()->logout();
    $this->post(route('invitations.admin.activate', $firstToken), ['identity_confirmed' => true, 'access_accepted' => true,
        'password' => 'Str0ng!Admin#Pass2026', 'password_confirmation' => 'Str0ng!Admin#Pass2026'])->assertForbidden();
});

test('only Admin managers can manage an invitation and the access page summarizes it without the token', function (): void {
    $inviter = adminInvitationManager();
    [$invited, $token] = adminInvitationIssue($this, $inviter);
    $baseline = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($baseline)->withSession(adminInvitationFreshSession())->post(route('admin.access.invitations.cancel', $invited), ['reason' => 'x'])
        ->assertForbidden();

    $this->actingAs($inviter)->get(route('admin.access.show', $invited))->assertInertia(fn (Assert $page) => $page
        ->where('invitation.status', 'pending_delivery')->where('invitation.generation', 1)->missing('invitation.token_hash'));
    $this->actingAs($inviter)->get(route('admin.access.show', $invited))->assertDontSee($token);
});
