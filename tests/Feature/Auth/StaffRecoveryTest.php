<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\AuthorizationRestrictionType;
use App\Models\AuditEvent;
use App\Models\Permission;
use App\Models\StaffRecovery;
use App\Models\User;
use App\Notifications\Auth\StaffRecoveryActivationNotification;
use App\Notifications\Auth\StaffRecoverySecurityNotification;
use App\Services\AuthorizationRestrictionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    foreach (AdminPermission::cases() as $permission) {
        Permission::firstOrCreate(['name' => $permission->value, 'guard_name' => 'web'], ['status' => 'active']);
    }
    Notification::fake();
});

function staffRecoveryManager(AdminPermission $permission = AdminPermission::AgentsManage): User
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo($permission->value);

    return $admin;
}

function staffRecoveryFresh(): array
{
    $now = Carbon::now()->timestamp;

    return ['auth.fresh_until' => $now + 600, 'auth.password_confirmed_at' => $now, 'auth.mfa_confirmed_at' => $now];
}

function staffRecoveryRequest(object $test, User $actor, User $target, string $email): StaffRecovery
{
    $test->actingAs($actor)->withSession(staffRecoveryFresh())->post(route('admin.staff-recoveries.store', $target), [
        'email' => $email, 'procedure_reference' => 'ID-CHECK-7', 'notes' => 'Checked passport against HR record in person.',
        'verified_at' => now()->subHour()->toIso8601String(), 'identity_verified' => true,
    ])->assertSessionHasNoErrors();

    return StaffRecovery::query()->where('user_id', $target->id)->latest('id')->firstOrFail();
}

function staffRecoveryDecide(object $test, User $actor, StaffRecovery $recovery, string $action): mixed
{
    return $test->actingAs($actor)->withSession(staffRecoveryFresh())->post(route('admin.staff-recoveries.decide', [$recovery->reference, $action]), [
        'version' => $recovery->fresh()->version, 'reason' => 'Identity confirmed independently.',
    ]);
}

function staffRecoveryLinkToken(string $email): string
{
    $url = null;
    Notification::assertSentOnDemand(StaffRecoveryActivationNotification::class, function (StaffRecoveryActivationNotification $notification, array $channels, object $notifiable) use ($email, &$url): bool {
        if ($notifiable->routes['mail'] !== $email) {
            return false;
        }
        $url = $notification->activationUrl;

        return true;
    });

    return str($url)->after('#token=')->toString();
}

test('Agent recovery changes nothing until a different Administrator approves, then revokes every old factor', function (): void {
    $requester = staffRecoveryManager();
    $approver = staffRecoveryManager();
    $agent = User::factory()->agent()->withTwoFactor()->create(['email' => 'agent.old@example.test']);

    $recovery = staffRecoveryRequest($this, $requester, $agent, 'agent.new@example.test');
    expect($recovery->state)->toBe('awaiting_approval')
        ->and($recovery->required_approvals)->toBe(1)
        ->and($agent->fresh()->password)->not->toBeNull()
        ->and($agent->fresh()->recovery_pending)->toBeFalse();

    staffRecoveryDecide($this, $requester, $recovery, 'approve')->assertSessionHasErrors('recovery');
    expect($recovery->fresh()->state)->toBe('awaiting_approval');

    staffRecoveryDecide($this, $approver, $recovery, 'approve')->assertSessionHasNoErrors();

    $agent->refresh();
    expect($recovery->fresh()->state)->toBe('awaiting_activation')
        ->and($agent->password)->toBeNull()
        ->and($agent->two_factor_secret)->toBeNull()
        ->and($agent->recovery_pending)->toBeTrue()
        ->and(DB::table('two_factor_recovery_codes')->where('user_id', $agent->id)->exists())->toBeFalse();
    Notification::assertSentOnDemand(StaffRecoverySecurityNotification::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'agent.old@example.test');
    staffRecoveryLinkToken('agent.new@example.test');
});

test('the recovering Agent sets their own password from the single-use link and must enrol a new authenticator', function (): void {
    $agent = User::factory()->agent()->withTwoFactor()->create(['email' => 'agent.old@example.test']);
    $approver = staffRecoveryManager();
    $recovery = staffRecoveryRequest($this, staffRecoveryManager(), $agent, 'agent.new@example.test');
    staffRecoveryDecide($this, $approver, $recovery, 'approve');
    $token = staffRecoveryLinkToken('agent.new@example.test');
    auth()->logout();

    $this->get(route('staff-recovery.activation', $recovery->reference))->assertInertia(fn (Assert $page) => $page
        ->component('auth/StaffRecoveryActivation')->missing('email')->missing('token'));

    $this->post(route('staff-recovery.activate', $recovery->reference), ['token' => str_repeat('x', 64),
        'password' => 'Str0ng!Recovered#2026', 'password_confirmation' => 'Str0ng!Recovered#2026'])->assertSessionHasErrors('token');
    $this->post(route('staff-recovery.activate', $recovery->reference), ['token' => $token,
        'password' => 'Str0ng!Recovered#2026', 'password_confirmation' => 'Str0ng!Recovered#2026'])->assertRedirect(route('two-factor.enrolment'));

    $agent->refresh();
    expect($agent->email)->toBe('agent.new@example.test')
        ->and($agent->account_state)->toBe(AccountState::MfaSetupRequired)
        ->and($agent->recovery_pending)->toBeFalse()
        ->and($recovery->fresh()->state)->toBe('completed');
    $this->assertAuthenticatedAs($agent);

    auth()->logout();
    $this->post(route('staff-recovery.activate', $recovery->reference), ['token' => $token,
        'password' => 'Str0ng!Recovered#2026', 'password_confirmation' => 'Str0ng!Recovered#2026'])->assertSessionHasErrors('token');
    expect(DB::table('audit_events')->where('payload', 'like', '%'.$token.'%')->exists())->toBeFalse();
});

test('Admin recovery needs two distinct approvers when two are eligible and restricts Admin management afterwards', function (): void {
    $requester = staffRecoveryManager(AdminPermission::AdminsManage);
    $first = staffRecoveryManager(AdminPermission::AdminsManage);
    $second = staffRecoveryManager(AdminPermission::AdminsManage);
    $target = User::factory()->admin()->withTwoFactor()->create(['email' => 'lost.admin@example.test']);
    $target->givePermissionTo(AdminPermission::AdminsManage->value);

    $recovery = staffRecoveryRequest($this, $requester, $target, 'lost.admin@example.test');
    expect($recovery->required_approvals)->toBe(2);
    $requestedAudit = AuditEvent::query()->where('event_type', 'auth.staff_recovery_requested')->sole();
    expect(DB::table('audit_notification_intents')->where('audit_event_id', $requestedAudit->id)->where('audience_type', 'admin_manager')
        ->pluck('recipient_user_id')->sort()->values()->all())->toBe(collect([$first->id, $second->id])->sort()->values()->all());

    staffRecoveryDecide($this, $first, $recovery, 'approve')->assertSessionHasNoErrors();
    expect($recovery->fresh()->state)->toBe('awaiting_approval')
        ->and($target->fresh()->password)->not->toBeNull();
    staffRecoveryDecide($this, $first, $recovery, 'approve')->assertSessionHasErrors('recovery');

    staffRecoveryDecide($this, $second, $recovery, 'approve')->assertSessionHasNoErrors();
    expect($recovery->fresh()->state)->toBe('awaiting_activation')
        ->and($recovery->approvals()->pluck('approver_user_id')->sort()->values()->all())->toBe(collect([$first->id, $second->id])->sort()->values()->all());

    $token = staffRecoveryLinkToken('lost.admin@example.test');
    auth()->logout();
    $this->post(route('staff-recovery.activate', $recovery->reference), ['token' => $token,
        'password' => 'Str0ng!Recovered#2026', 'password_confirmation' => 'Str0ng!Recovered#2026'])->assertRedirect(route('two-factor.enrolment'));

    $restrictions = app(AuthorizationRestrictionService::class)->getActiveRestrictions($target->fresh());
    expect($restrictions->pluck('restriction_type')->all())->toContain(AuthorizationRestrictionType::PostRecoveryAdminManagement);
});

test('a single remaining eligible Admin approver is enough and none at all points to emergency recovery', function (): void {
    $requester = staffRecoveryManager(AdminPermission::AdminsManage);
    $only = staffRecoveryManager(AdminPermission::AdminsManage);
    $target = User::factory()->admin()->withTwoFactor()->create();

    expect(staffRecoveryRequest($this, $requester, $target, $target->email)->required_approvals)->toBe(1);

    $loneRequester = staffRecoveryManager(AdminPermission::AdminsManage);
    $only->revokePermissionTo(AdminPermission::AdminsManage->value);
    $requester->revokePermissionTo(AdminPermission::AdminsManage->value);
    $lonely = User::factory()->admin()->withTwoFactor()->create();
    $this->actingAs($loneRequester)->withSession(staffRecoveryFresh())->post(route('admin.staff-recoveries.store', $lonely), [
        'email' => $lonely->email, 'procedure_reference' => 'ID', 'notes' => 'Checked.', 'verified_at' => now()->subHour()->toIso8601String(), 'identity_verified' => true,
    ])->assertSessionHasErrors(['recovery' => 'No other eligible Administrator can approve this recovery. Use the final-Admin emergency recovery procedure.']);
});

test('recovery is limited to Administrators with the matching permission and never to self or Customers', function (): void {
    $baseline = User::factory()->admin()->withTwoFactor()->create();
    $agent = User::factory()->agent()->withTwoFactor()->create();
    $manager = staffRecoveryManager(AdminPermission::AdminsManage);

    $this->actingAs($baseline)->withSession(staffRecoveryFresh())->get(route('admin.staff-recoveries.create', $agent))->assertForbidden();
    $this->actingAs($manager)->withSession(staffRecoveryFresh())->get(route('admin.staff-recoveries.create', $manager))->assertForbidden();
    $this->actingAs($manager)->withSession(staffRecoveryFresh())->get(route('admin.staff-recoveries.create', User::factory()->customer()->create()))->assertNotFound();
    $this->actingAs($agent)->withSession(staffRecoveryFresh())->get(route('admin.staff-recoveries.index'))->assertForbidden();
});

test('rejection keeps existing access and an expired link can be reissued', function (): void {
    $agent = User::factory()->agent()->withTwoFactor()->create();
    $approver = staffRecoveryManager();
    $rejected = staffRecoveryRequest($this, staffRecoveryManager(), $agent, $agent->email);
    staffRecoveryDecide($this, $approver, $rejected, 'reject')->assertSessionHasNoErrors();
    expect($rejected->fresh()->state)->toBe('rejected')->and($agent->fresh()->password)->not->toBeNull();

    $recovery = staffRecoveryRequest($this, staffRecoveryManager(), $agent, $agent->email);
    staffRecoveryDecide($this, $approver, $recovery, 'approve');
    $recovery->forceFill(['activation_expires_at' => now()->subMinute()])->save();
    staffRecoveryDecide($this, $approver, $recovery, 'reissue')->assertSessionHasNoErrors();

    expect($recovery->fresh()->state)->toBe('awaiting_activation')
        ->and(AuditEvent::query()->where('event_type', 'auth.staff_recovery_reissued')->exists())->toBeTrue();
});
