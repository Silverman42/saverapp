<?php

use App\Enums\AdminPermission;
use App\Models\AuditEvent;
use App\Models\AuthenticationLock;
use App\Models\SecurityCase;
use App\Models\User;
use App\Services\AuthenticationAbuseService;
use App\Services\NotificationInbox;
use App\Services\NotificationPipeline;
use App\Services\SecurityCaseService;
use App\Services\UnlockState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

beforeEach(function () {
    config(['audit.enabled' => true]);
});

function securityViewer(): User
{
    $actor = User::factory()->admin()->withTwoFactor()->create();
    $actor->givePermissionTo(AdminPermission::SecurityOperationsManage);

    return $actor;
}
function securityFixture(?User $target = null): SecurityCase
{
    $event = AuditEvent::record('auth.lock_created', User::class, $target?->id, null, ['category' => 'password', 'lock_id' => 1]);

    return app(SecurityCaseService::class)->signal($event->id, $target?->id);
}
test('security signal deduplicates case and notification recipients', function () {
    Queue::fake();
    $actor = securityViewer();
    $target = User::factory()->customer()->create();
    $case = securityFixture($target);
    app(SecurityCaseService::class)->signal(DB::table('canonical_audit_events')->where('event_type', 'auth.lock_created')->value('legacy_audit_event_id'), $target->id);
    $this->assertDatabaseCount('security_cases', 1);
    $this->assertDatabaseCount('security_notification_intents', 1);
    expect(DB::table('security_notification_intents')->value('recipient_user_id'))->toBe($actor->id);
    expect($target->fresh()->account_state->value)->toBe('active');
});
test('case lifecycle preserves closure and reopen episode', function () {
    Queue::fake();
    $actor = securityViewer();
    $case = securityFixture();
    $service = app(SecurityCaseService::class);
    $case = $service->change($actor, $case, ['expected_version' => 1, 'action' => 'state', 'state' => 'Investigating', 'note' => 'Review started']);
    $case = $service->change($actor, $case, ['expected_version' => 2, 'action' => 'state', 'state' => 'Resolved', 'note' => 'Review completed']);
    $case = $service->change($actor, $case, ['expected_version' => 3, 'action' => 'reopen', 'note' => 'New evidence arrived']);
    expect($case->state)->toBe('Investigating')->and($case->episode)->toBe(2)->and($case->version)->toBe(4);
    expect(DB::table('security_case_transitions')->where('security_case_id', $case->id)->count())->toBe(4);
    expect(DB::table('security_case_transitions')->where('version', 2)->value('note_ciphertext'))->not->toContain('Review started');
});
test('stale case changes fail without another transition', function () {
    Queue::fake();
    $actor = securityViewer();
    $case = securityFixture();
    app(SecurityCaseService::class)->change($actor, $case, ['expected_version' => 1, 'action' => 'note', 'note' => 'First investigator']);
    expect(fn () => app(SecurityCaseService::class)->change($actor, $case, ['expected_version' => 1, 'action' => 'note', 'note' => 'Stale investigator']))->toThrow(ConflictHttpException::class);
    $this->assertDatabaseCount('security_case_transitions', 2);
});
test('ineligible ownership returns to queue with immutable history', function () {
    Queue::fake();
    $actor = securityViewer();
    $owner = securityViewer();
    $case = securityFixture();
    $case = app(SecurityCaseService::class)->change($actor, $case, ['expected_version' => 1, 'action' => 'assign', 'owner_id' => $owner->id]);
    $owner->revokePermissionTo(AdminPermission::SecurityOperationsManage);
    app(SecurityCaseService::class)->releaseOwner($case);
    expect($case->fresh()->owner_id)->toBeNull()->and($case->fresh()->version)->toBe(3);
    expect(AuditEvent::query()->where('event_type', 'security.case_ownership_released')->count())->toBe(1);
});
test('case read is protected and logs access before returning encrypted notes', function () {
    Queue::fake();
    $actor = securityViewer();
    $case = securityFixture();
    app(SecurityCaseService::class)->change($actor, $case, ['expected_version' => 1, 'action' => 'note', 'note' => 'Protected analyst note']);
    $this->actingAs($actor)->get(route('admin.security.show', $case->case_reference))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/security/Show')->where('history.1.note', 'Protected analyst note'));
    expect(AuditEvent::query()->where('event_type', 'security.case_viewed')->count())->toBe(1);
    expect(DB::table('canonical_audit_events')->get()->pluck('content')->implode(''))->not->toContain('Protected analyst note');
});
test('case request requires expected version and rejects invented states', function () {
    Queue::fake();
    $actor = securityViewer();
    $case = securityFixture();
    $this->actingAs($actor)->patch(route('admin.security.update', $case->case_reference), ['action' => 'state', 'state' => 'Suspended', 'note' => 'Wrong authority'])
        ->assertSessionHasErrors(['expected_version', 'state']);
    expect($case->fresh()->state)->toBe('Open');
});
test('revoked security grant denies case and notification retrieval', function () {
    Queue::fake();
    $actor = securityViewer();
    $case = securityFixture();
    $intent = DB::table('notification_inbox_intents')->sole();
    app(NotificationPipeline::class)->materialize($intent->id);
    $actor->revokePermissionTo(AdminPermission::SecurityOperationsManage);
    $this->actingAs($actor)->get(route('admin.security.show', $case->case_reference))->assertForbidden();
    expect(app(NotificationPipeline::class)->recipientScope($actor->fresh())->count())->toBe(0);
});
test('security notices render safe case links once and suppress revoked recipients', function () {
    Queue::fake();
    $actor = securityViewer();
    $case = securityFixture();
    $intent = DB::table('notification_inbox_intents')->sole();
    app(NotificationPipeline::class)->materialize($intent->id);
    app(NotificationPipeline::class)->materialize($intent->id);
    expect(DB::table('notification_inbox_intents')->value('status'))->toBe('delivered');
    expect(app(NotificationInbox::class)->destination($actor, $intent->notification_id))->toBe(route('admin.security.show', $case->case_reference, false));
    $this->assertDatabaseCount('notifications', 1);
});
test('signed manual unlock token prevents stale restriction action', function () {
    Queue::fake();
    Notification::fake();
    $actor = securityViewer();
    $target = User::factory()->customer()->create();
    $target->lockTemporarily(15, 'password', 'Temporary password restriction');
    $lock = AuthenticationLock::create(['user_id' => $target->id, 'email_normalized' => $target->email_normalized,
        'lock_category' => 'password', 'reason' => 'Temporary password restriction', 'failed_attempts_count' => 10,
        'locked_at' => now(), 'locked_until' => now()->addMinutes(15), 'requires_review' => false]);
    $token = app(UnlockState::class)->token($target, $actor, 'password');
    $lock->update(['failed_attempts_count' => 11]);
    expect(fn () => app(AuthenticationAbuseService::class)->manualUnlock($target, $actor, 'password', 'in_person', 'Verified in person', $token))->toThrow(ConflictHttpException::class);
    expect($lock->fresh()->unlocked_at)->toBeNull();
    Notification::assertNothingSent();
});
test('manual unlock preserves credentials and other account state and captures audit', function () {
    Queue::fake();
    Notification::fake();
    $actor = securityViewer();
    $target = User::factory()->customer()->create();
    $target->lockTemporarily(15, 'password', 'Temporary password restriction');
    $password = $target->password;
    $token = app(UnlockState::class)->token($target, $actor, 'password');
    $this->actingAs($actor)->post(route('admin.lockouts.unlock', $target), ['category' => 'password', 'verification_method' => 'in_person', 'reason' => 'Verified in person', 'restriction_token' => $token])->assertRedirect();
    expect($target->fresh()->locked_until)->toBeNull()->and($target->fresh()->password)->toBe($password)->and($target->fresh()->account_state->value)->toBe('active');
    expect(AuditEvent::query()->where('event_type', 'auth.manual_unlock')->count())->toBe(1);
});
test('missing unlock state token is rejected at HTTP boundary', function () {
    $actor = securityViewer();
    $target = User::factory()->customer()->create();
    $this->actingAs($actor)->post(route('admin.lockouts.unlock', $target), ['category' => 'password', 'verification_method' => 'in_person', 'reason' => 'Verified in person'])->assertSessionHasErrors('restriction_token');
});

test('case scope polling rechecks authority without disclosing or reading protected notes', function () {
    Queue::fake();
    $actor = securityViewer();
    $case = securityFixture();
    $response = $this->actingAs($actor)->get(route('admin.security.show', $case->case_reference))->assertOk();
    $version = $response->viewData('page')['version'];
    $this->get(route('admin.security.show', $case->case_reference), ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'admin/security/Show', 'X-Inertia-Partial-Data' => 'scope'])->assertOk()->assertJsonMissingPath('props.history');
    expect(AuditEvent::query()->where('event_type', 'security.case_viewed')->count())->toBe(1);
    $actor->revokePermissionTo(AdminPermission::SecurityOperationsManage);
    $this->get(route('admin.security.show', $case->case_reference), ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'admin/security/Show', 'X-Inertia-Partial-Data' => 'scope'])->assertForbidden();
});

test('closed case accepts protected follow up notes without a next state', function () {
    Queue::fake();
    $actor = securityViewer();
    $case = securityFixture();
    app(SecurityCaseService::class)->change($actor, $case, ['expected_version' => 1, 'action' => 'state', 'state' => 'ClosedNoAction', 'note' => 'No action needed']);
    $this->actingAs($actor)->patch(route('admin.security.update', $case->case_reference), ['expected_version' => 2, 'action' => 'note', 'state' => null, 'note' => 'Retained follow up'])->assertRedirect()->assertSessionHasNoErrors();
    expect($case->fresh()->version)->toBe(3)->and($case->fresh()->state)->toBe('ClosedNoAction');
});

test('Authentication service cannot unlock without the expected state token', function () {
    Queue::fake();
    Notification::fake();
    $actor = securityViewer();
    $target = User::factory()->customer()->create();
    $target->lockTemporarily(15, 'password', 'Temporary restriction');
    expect(fn () => app(AuthenticationAbuseService::class)->manualUnlock($target, $actor, 'password', 'in_person', 'Verified in person'))->toThrow(ValidationException::class);
    expect($target->fresh()->isTemporarilyLocked('password'))->toBeTrue();
    expect(AuditEvent::query()->where('event_type', 'auth.manual_unlock')->exists())->toBeFalse();
    Notification::assertNothingSent();
});
