<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\AgentStatus;
use App\Enums\DeliveryStatus;
use App\Enums\InvitationStatus;
use App\Jobs\DeliverAgentStatusNotificationIntent;
use App\Jobs\DeliverProfileNotificationIntent;
use App\Jobs\MaterializeNotificationIntent;
use App\Models\AgentStatusNotificationIntent;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\AgentStatusNotification;
use App\Services\AgentLifecycleService;
use App\Services\AgentStatusManagementService;
use App\Services\AuthorizationService;
use App\Services\CustomerNameCorrectionService;
use App\Services\InvitationDeliveryIssues;
use App\Services\NotificationPipeline;
use App\Services\ProfileManagementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\CreatesLifecycleAgents;
use Tests\CreatesLifecycleCustomers;

uses(CreatesLifecycleCustomers::class, CreatesLifecycleAgents::class);

function managementInvitation(User $user, User $actor): Invitation
{
    $user->forceFill(['account_state' => AccountState::Invited])->save();

    return Invitation::create(['user_id' => $user->id, 'role' => $user->user_type->value, 'target_email' => $user->email,
        'target_email_normalized' => $user->email_normalized, 'token_hash' => hash('sha256', 'never-expose-this-challenge'),
        'generation' => 1, 'status' => InvitationStatus::PendingDelivery, 'delivery_status' => DeliveryStatus::Pending,
        'expires_at' => now()->addDay(), 'invited_by_user_id' => $actor->id]);
}

test('delivery endpoints deny guests self-service and baseline Admins without revealing records', function () {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $this->getJson(route('customers.delivery.index', $customer->customer_id))->assertUnauthorized();
    $baseline = User::factory()->admin()->withTwoFactor()->create();
    $this->actingAs($baseline)->getJson(route('customers.delivery.index', $customer->customer_id))->assertForbidden();
    $this->actingAs($customer->user)->getJson(route('customers.delivery.index', $customer->customer_id))->assertForbidden();
    $this->actingAs($agent->user)->getJson(route('agents.delivery.index', $agent->agent_id))->assertForbidden();
    $this->actingAs($baseline)->getJson(route('customers.delivery.index', 'CUS-999999'))->assertNotFound();
});

test('invitation issues are durable deduplicated scoped and contain no address or challenge', function () {
    Queue::fake();
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $invitation = managementInvitation($customer->user, $agent->user);
    $issues = app(InvitationDeliveryIssues::class);
    $issues->record($invitation->id, 1, 'queue_unavailable', 0);
    $issues->record($invitation->id, 1, 'acceptance_unknown', 2);
    $this->assertDatabaseCount('invitation_delivery_issues', 1);
    $this->assertDatabaseCount('invitation_issue_notification_intents', 2);
    $this->assertDatabaseCount('notification_events', 1);
    $this->assertDatabaseHas('invitations', ['id' => $invitation->id, 'delivery_status' => 'uncertain']);
    $this->actingAs($admin)->getJson(route('customers.delivery.index', $customer->customer_id))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.0.status', 'Pending (uncertain)')
        ->assertJsonPath('data.0.attempt_count', 2)->assertDontSee($customer->user->email)->assertDontSee('never-expose-this-challenge');
    $this->actingAs($agent->user)->getJson(route('customers.delivery.index', $customer->customer_id))->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($admin)->getJson(route('customers.delivery.index', $customer->customer_id).'?per_page=12')->assertUnprocessable();
    Queue::assertPushed(MaterializeNotificationIntent::class);
});

test('invitation manager notices disappear after authority or invitation validity changes', function () {
    Queue::fake();
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $invitation = managementInvitation($customer->user, $agent->user);
    app(InvitationDeliveryIssues::class)->record($invitation->id, 1, 'queue_unavailable', 0);
    $intent = DB::table('notification_inbox_intents')->where('recipient_user_id', $admin->id)->first();
    $pipeline = app(NotificationPipeline::class);
    $pipeline->materialize((int) $intent->id);
    $pipeline->materialize((int) $intent->id);
    $this->assertDatabaseCount('notifications', 1);
    $this->assertDatabaseCount('notification_inbox_attempts', 1);
    expect($pipeline->isRecipientEligible((int) $intent->id))->toBeTrue();
    $admin->revokePermissionTo(AdminPermission::CustomersManage);
    expect($pipeline->isRecipientEligible((int) $intent->id))->toBeFalse();
    $this->actingAs($admin)->getJson(route('customers.delivery.index', $customer->customer_id))->assertForbidden();
    $agentIntent = DB::table('notification_inbox_intents')->where('recipient_user_id', $agent->user_id)->first();
    $invitation->forceFill(['status' => InvitationStatus::Cancelled])->save();
    expect($pipeline->isRecipientEligible((int) $agentIntent->id))->toBeFalse();
    $this->assertDatabaseCount('invitation_delivery_issues', 1);
});

test('required issue capture failure rolls back delivery state audit and all intents', function () {
    Queue::fake();
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $invitation = managementInvitation($customer->user, $agent->user);
    $this->mock(NotificationPipeline::class)->shouldReceive('capture')->once()->andThrow(new RuntimeException('Capture unavailable'));
    expect(fn () => app(InvitationDeliveryIssues::class)->record($invitation->id, 1, 'queue_unavailable', 0))->toThrow(RuntimeException::class);
    expect($invitation->fresh()->delivery_status)->toBe(DeliveryStatus::Pending);
    $this->assertDatabaseCount('invitation_delivery_issues', 0);
    $this->assertDatabaseMissing('audit_events', ['event_type' => 'invitation.delivery_failed']);
    $this->assertDatabaseCount('invitation_issue_notification_intents', 0);
});

test('management validation and denials have accurate audit outcomes without submitted identity', function () {
    [$admin, $customer] = $this->createLifecycleFixture();
    $this->actingAs($admin)->patchJson(route('customers.update', $customer->customer_id), ['version' => 'invalid', 'address' => 'SECRET-FAILED-IDENTITY'])->assertUnprocessable();
    $this->assertDatabaseHas('canonical_audit_events', ['event_type' => 'customer.management_attempt', 'outcome' => 'Failed']);
    $this->assertDatabaseHas('audit_events', ['event_type' => 'customer.management_attempt']);
    expect(DB::table('canonical_audit_events')->where('event_type', 'customer.management_attempt')->value('content'))->not->toContain('SECRET-FAILED-IDENTITY');
    $baseline = User::factory()->admin()->withTwoFactor()->create();
    $this->actingAs($baseline)->patchJson(route('customers.update', $customer->customer_id), ['version' => 1])->assertForbidden();
    $this->assertDatabaseHas('canonical_audit_events', ['event_type' => 'customer.management_attempt', 'outcome' => 'Denied']);
    $this->assertDatabaseCount('profile_notification_intents', 0);
});

test('conflicting and failed management attempts retain safe canonical outcomes', function (string $kind, int $status, string $outcome, string $category) {
    [$admin, $customer] = $this->createLifecycleFixture();
    $exception = $kind === 'conflict' ? new ConflictHttpException('Internal conflict details') : new RuntimeException('Internal system details');
    $this->mock(ProfileManagementService::class)->shouldReceive('updateCustomer')->once()->andThrow($exception);
    $this->actingAs($admin)->patchJson(route('customers.update', $customer->customer_id), [
        'version' => $customer->version, 'address' => 'SECRET-FAILED-IDENTITY',
    ])->assertStatus($status);
    $this->assertDatabaseHas('canonical_audit_events', ['event_type' => 'customer.management_attempt', 'outcome' => $outcome]);
    $record = DB::table('canonical_audit_events')->where('event_type', 'customer.management_attempt')->firstOrFail();
    expect($record->content)->toContain($category)->not->toContain('SECRET-FAILED-IDENTITY', 'Internal conflict details', 'Internal system details');
})->with([
    'conflict' => ['conflict', 409, 'Conflict', 'state_conflict'],
    'system' => ['system', 500, 'Failed', 'system_failed'],
]);

test('unavailability is notified once across inactivity suspension and offboarding and restoration is meaningful', function () {
    Queue::fake();
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $admin->givePermissionTo(AdminPermission::AgentsManage);
    $status = app(AgentStatusManagementService::class);
    $status->transition($admin, $agent, AgentStatus::Inactive, 1, 'Private staffing details', 'Contact management.');
    app(AgentLifecycleService::class)->execute($admin, $agent->fresh(), 'suspend', $this->agentLifecyclePayload($agent), $this->agentLifecycleRequest($admin));
    $this->assertDatabaseCount('agent_lifecycle_notification_intents', 2);
    expect(DB::table('agent_status_notification_intents')->where('audience_type', 'assigned_customer')->count())->toBe(2);
    app(AgentLifecycleService::class)->execute($admin, $agent->fresh(), 'restore', $this->agentLifecyclePayload($agent), $this->agentLifecycleRequest($admin));
    expect(DB::table('agent_lifecycle_notification_intents')->where('audience_type', 'assigned_customer')->count())->toBe(0);
    $status->transition($admin, $agent->fresh(), AgentStatus::Active, $agent->fresh()->version, 'Ready', 'Ready for service.');
    expect(DB::table('agent_status_notification_intents')->where('purpose', 'agent_available')->count())->toBe(2);
    $owner = DB::table('agent_status_notification_intents')->where('purpose', 'agent_available')->where('channel', 'database')->first();
    $id = app(NotificationPipeline::class)->capture('agent_status', (int) $owner->id, false);
    app(NotificationPipeline::class)->materialize($id);
    $this->assertDatabaseHas('notification_inbox_intents', ['id' => $id, 'status' => 'delivered']);
    expect(DB::table('notifications')->value('data'))->not->toContain('Private staffing details');
    Queue::assertPushed(MaterializeNotificationIntent::class);
});

test('live service interruption reaches a reassignment-only manager and clears on resolution or revocation', function () {
    Queue::fake();
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $admin->givePermissionTo(AdminPermission::AgentsManage);
    $manager = User::factory()->admin()->withTwoFactor()->create();
    $manager->givePermissionTo(AdminPermission::CustomersReassign);
    app(AgentStatusManagementService::class)->transition($admin, $agent, AgentStatus::Inactive, 1, 'Private reason', 'Contact management.');
    $intent = DB::table('notification_inbox_intents')->where('recipient_user_id', $manager->id)->firstOrFail();
    expect(app(NotificationPipeline::class)->isRecipientEligible((int) $intent->id))->toBeTrue();
    app(NotificationPipeline::class)->materialize((int) $intent->id);
    $notice = DB::table('notifications')->where('notifiable_id', $manager->id)->firstOrFail();
    expect($notice->data)->not->toContain('Private reason');
    $manager->revokePermissionTo(AdminPermission::CustomersReassign);
    expect(app(NotificationPipeline::class)->isRecipientEligible((int) $intent->id))->toBeFalse();
    $manager->givePermissionTo(AdminPermission::CustomersReassign);
    app(AgentStatusManagementService::class)->transition($admin, $agent->fresh(), AgentStatus::Active, $agent->fresh()->version, 'Ready', 'Ready for service.');
    expect(app(NotificationPipeline::class)->isRecipientEligible((int) $intent->id))->toBeFalse();
});

test('Agent delivery diagnostics require agents.manage and never expose raw messages', function () {
    Queue::fake();
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    app(AgentStatusManagementService::class)->transition($admin, $agent, AgentStatus::Inactive, 1, 'SECRET-PERSONNEL-REASON', 'Please contact management.');
    $this->actingAs($admin)->getJson(route('agents.delivery.index', $agent->agent_id))->assertOk()
        ->assertJsonCount(2, 'data')->assertDontSee('SECRET-PERSONNEL-REASON')->assertDontSee($agent->user->email);
    $admin->revokePermissionTo(AdminPermission::AgentsManage);
    $admin->givePermissionTo(AdminPermission::CustomersManage);
    $this->actingAs($admin)->getJson(route('agents.delivery.index', $agent->agent_id))->assertForbidden();
    Queue::assertPushed(DeliverAgentStatusNotificationIntent::class);
});

test('proposal outcomes notify only the Customer and still-authorized requester with linked protected history', function (string $decision) {
    Queue::fake();
    [$admin, $customer] = $this->createLifecycleFixture();
    $service = app(CustomerNameCorrectionService::class);
    $correction = $service->proposeOrCorrectBeforeActivation($admin, $customer, 'Proposed Example Name', 'PRIVATE-PROPOSAL-EVIDENCE', $customer->version);
    if ($decision === 'cancelled') {
        $service->cancel($admin, $customer, $correction->id);
    } else {
        $service->resolve($customer->user, $customer, $correction->id, $decision);
    }
    $outcome = DB::table('profile_change_histories')->where('event_type', 'customer.name_correction_'.$decision)->first();
    expect($outcome)->not->toBeNull();
    $this->assertDatabaseHas('canonical_audit_events', ['legacy_audit_event_id' => $outcome->audit_event_id]);
    $notices = DB::table('profile_notification_intents')->where('profile_change_history_id', $outcome->id)->get();
    expect($notices)->not->toBeEmpty();
    expect($notices->pluck('payload')->implode(' '))->not->toContain('PRIVATE-PROPOSAL-EVIDENCE');
    if ($decision !== 'cancelled') {
        $this->assertDatabaseHas('profile_notification_intents', ['profile_change_history_id' => $outcome->id, 'recipient_user_id' => $admin->id, 'audience_type' => 'customer_manager']);
        $id = (int) DB::table('notification_inbox_intents')->where('recipient_user_id', $admin->id)->value('id');
        $admin->revokePermissionTo(AdminPermission::CustomersManage);
        expect(app(NotificationPipeline::class)->isRecipientEligible($id))->toBeFalse();
    }
    Queue::assertPushed(DeliverProfileNotificationIntent::class);
})->with(['accepted', 'rejected', 'cancelled']);

test('email diagnostics retain local evidence across configuration changes and never infer delivery', function () {
    Queue::fake();
    Notification::fake();
    config()->set('mail.default', 'array');
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    app(AgentStatusManagementService::class)->transition($admin, $agent, AgentStatus::Inactive, 1, 'Internal review', 'Contact management.');
    $owner = AgentStatusNotificationIntent::query()->where('channel', 'mail')->firstOrFail();
    (new DeliverAgentStatusNotificationIntent($owner->id))->handle(app(AuthorizationService::class));
    config()->set('mail.default', 'smtp');
    $response = $this->actingAs($admin)->getJson(route('agents.delivery.index', $agent->agent_id))->assertOk();
    $mail = collect($response->json('data'))->firstWhere('channel', 'email');
    expect($mail['status'])->toBe('Local verification only');
    expect($mail['attempt_count'])->toBe(1);
    Notification::assertSentTo($agent->user, AgentStatusNotification::class);
});

test('mail dispatch outages leave registered work for the existing scheduled drain', function () {
    [$admin, $agent] = $this->createAgentLifecycleFixture();
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('Queue unavailable'));
    app(AgentStatusManagementService::class)->transition($admin, $agent, AgentStatus::Inactive, 1, 'Internal review', 'Contact management.');
    $mail = AgentStatusNotificationIntent::query()->where('channel', 'mail')->firstOrFail();
    $this->assertDatabaseHas('management_mail_dispatches', [
        'owner_family' => 'agent_status', 'owner_id' => $mail->id, 'status' => 'pending',
    ]);
    expect($mail->fresh()->status)->toBe('pending');

    Queue::fake();
    $this->artisan('notifications:drain')->assertSuccessful();
    Queue::assertPushed(DeliverAgentStatusNotificationIntent::class);
    $this->assertDatabaseHas('management_mail_dispatches', [
        'owner_family' => 'agent_status', 'owner_id' => $mail->id, 'dispatch_count' => 1,
    ]);
});

test('a registration queue outage records one invitation issue without reporting registration failure', function () {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $invitation = managementInvitation($customer->user, $agent->user);
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('Queue unavailable'));
    app(InvitationDeliveryIssues::class)->dispatch($invitation->id, 'never-expose-this-challenge', 1);
    expect($invitation->fresh()->delivery_status)->toBe(DeliveryStatus::Failed);
    $this->assertDatabaseCount('invitation_delivery_issues', 1);
    $this->assertModelExists($customer);
    $this->assertDatabaseCount('customer_assignments', 1);
});
