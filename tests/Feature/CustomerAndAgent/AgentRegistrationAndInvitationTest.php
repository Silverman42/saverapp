<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\AgentStatus;
use App\Enums\CreationAttemptStatus;
use App\Enums\DeliveryStatus;
use App\Enums\InvitationStatus;
use App\Enums\UserType;
use App\Exceptions\InvitationSenderNotReadyException;
use App\Jobs\DeliverAgentInvitationJob;
use App\Models\AgentProfile;
use App\Models\AgentStatusHistory;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CreationAttempt;
use App\Models\Invitation;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\Auth\AgentInvitationNotification;
use App\Services\AgentRegistrationService;
use App\Services\PublicIdGenerator;
use App\Support\IdentityNormalizer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');

    // Ensure permission catalogue is active for tested permissions
    foreach ([AdminPermission::AgentsManage] as $perm) {
        Permission::firstOrCreate(
            ['name' => $perm->value, 'guard_name' => 'web'],
            ['status' => 'active']
        );
    }

    // Configure a verified sender on the business profile by default
    $business = BusinessProfile::current();
    $business->invitation_sender_email = 'invitations@saverapp.ng';
    $business->invitation_sender_name = 'SaverApp Security';
    $business->is_invitation_sender_verified = true;
    $business->save();
});

/*
|--------------------------------------------------------------------------
| 1. Authorization & Role-Based Access Controls
|--------------------------------------------------------------------------
*/

test('Customer and Agent users cannot access agent registration form', function (): void {
    $customer = User::factory()->customer()->create();
    $agent = User::factory()->agent()->create();

    $this->actingAs($customer)
        ->get(route('agents.create'))
        ->assertForbidden();

    $this->actingAs($agent)
        ->get(route('agents.create'))
        ->assertForbidden();
});

test('Admin without agents.manage permission cannot access registration form or submit registration', function (): void {
    $admin = User::factory()->admin()->create(); // No direct permission granted

    $this->actingAs($admin)
        ->get(route('agents.create'))
        ->assertForbidden();

    $this->actingAs($admin)
        ->post(route('agents.store'), [
            'attempt_reference' => (string) Str::uuid(),
            'name' => 'John Agent',
            'email' => 'john.agent@saverapp.test',
            'phone' => '+2348012345678',
        ])
        ->assertForbidden();
});

test('Admin with agents.manage permission can access registration form', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::AgentsManage->value);

    $response = $this->actingAs($admin)
        ->get(route('agents.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('agents/Create')
            ->has('attempt_reference')
        );

    expect(Str::isUuid($response->inertiaProps('attempt_reference'), version: 4))->toBeTrue();
});

test('registration is aborted if Admin agents.manage permission is revoked before commit', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::AgentsManage->value);

    // Mock service or test commit-time check: directly revoke right before commit
    $reference = (string) Str::uuid();
    $payload = [
        'name' => 'Revoked Admin Agent',
        'email' => 'revoked.admin@saverapp.test',
        'phone' => '+2348011112233',
    ];

    // Revoke permission right before calling register
    $admin->revokePermissionTo(AdminPermission::AgentsManage->value);

    $service = app(AgentRegistrationService::class);

    expect(fn () => $service->register($admin, $reference, $payload))
        ->toThrow(ConflictHttpException::class);

    expect(User::where('email', 'revoked.admin@saverapp.test')->exists())->toBeFalse();
    expect(AgentProfile::where('phone_normalized', '+2348011112233')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| 2. Invitation Sender Readiness & Business Identity
|--------------------------------------------------------------------------
*/

test('registration fails when invitation sender email is a placeholder', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::AgentsManage->value);

    $business = BusinessProfile::current();
    $business->invitation_sender_email = 'hello@example.com';
    $business->is_invitation_sender_verified = true;
    $business->save();

    config(['mail.invitation_sender.address' => 'hello@example.com']);
    config(['mail.from.address' => 'hello@example.com']);

    $service = app(AgentRegistrationService::class);

    expect(fn () => $service->register($admin, (string) Str::uuid(), [
        'name' => 'Agent One',
        'email' => 'agent1@saverapp.test',
        'phone' => '+2348012345678',
    ]))->toThrow(InvitationSenderNotReadyException::class);

    expect(User::where('email', 'agent1@saverapp.test')->exists())->toBeFalse();
});

test('registration fails when invitation sender is unverified', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::AgentsManage->value);

    $business = BusinessProfile::current();
    $business->invitation_sender_email = 'invitations@saverapp.ng';
    $business->is_invitation_sender_verified = false;
    $business->save();

    config(['mail.invitation_sender.verified' => false]);

    $service = app(AgentRegistrationService::class);

    expect(fn () => $service->register($admin, (string) Str::uuid(), [
        'name' => 'Agent Two',
        'email' => 'agent2@saverapp.test',
        'phone' => '+2348012345679',
    ]))->toThrow(InvitationSenderNotReadyException::class);

    expect(User::where('email', 'agent2@saverapp.test')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| 3. Validation & Server-Managed Field Rejections
|--------------------------------------------------------------------------
*/

test('registration rejects client-supplied server-managed fields', function (string $prohibitedField): void {
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::AgentsManage->value);

    $this->actingAs($admin)
        ->post(route('agents.store'), [
            'attempt_reference' => (string) Str::uuid(),
            'name' => 'Hacker Agent',
            'email' => 'hacker@saverapp.test',
            'phone' => '+2348012345678',
            $prohibitedField => 'malicious_override',
        ])
        ->assertSessionHasErrors($prohibitedField);
})->with([
    'operational_status',
    'status',
    'account_state',
    'user_type',
    'role',
    'roles',
    'agent_id',
    'id',
    'created_by',
    'fee',
    'assignment',
    'permission_version',
]);

test('registration validates required fields, phone formatting, and unique constraints', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::AgentsManage->value);

    // Missing required fields
    $this->actingAs($admin)
        ->post(route('agents.store'), [])
        ->assertSessionHasErrors(['attempt_reference', 'name', 'email', 'phone']);

    // Invalid phone format
    $this->actingAs($admin)
        ->post(route('agents.store'), [
            'attempt_reference' => (string) Str::uuid(),
            'name' => 'Test Agent',
            'email' => 'agent.unique@saverapp.test',
            'phone' => 'not-a-valid-phone',
        ])
        ->assertSessionHasErrors(['phone']);

    // Duplicate email
    $existingUser = User::factory()->create(['email' => 'existing@saverapp.test']);
    $this->actingAs($admin)
        ->post(route('agents.store'), [
            'attempt_reference' => (string) Str::uuid(),
            'name' => 'Duplicate Email Agent',
            'email' => 'EXISTING@saverapp.test',
            'phone' => '+2348099887766',
        ])
        ->assertSessionHasErrors(['email']);

    // Duplicate agent phone
    $existingAgent = AgentProfile::factory()->create([
        'phone' => '+2348055554433',
        'phone_normalized' => '+2348055554433',
    ]);
    $this->actingAs($admin)
        ->post(route('agents.store'), [
            'attempt_reference' => (string) Str::uuid(),
            'name' => 'Duplicate Phone Agent',
            'email' => 'new.agent@saverapp.test',
            'phone' => '+2348055554433',
        ])
        ->assertSessionHasErrors(['phone']);
});

/*
|--------------------------------------------------------------------------
| 4. Idempotency & Creation Attempts
|--------------------------------------------------------------------------
*/

test('identical registration replay resolves existing Agent without duplicate writes', function (): void {
    Queue::fake();

    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::AgentsManage->value);

    $attemptReference = (string) Str::uuid();
    $data = [
        'attempt_reference' => $attemptReference,
        'name' => 'Idempotent Agent',
        'email' => 'idempotent@saverapp.test',
        'phone' => '+2348011223344',
        'address' => '12 Marina, Lagos',
        'employment_date' => '2026-09-01',
        'notes' => 'Internal note',
    ];

    // First submission
    $response1 = $this->actingAs($admin)->post(route('agents.store'), $data);
    $response1->assertRedirect();

    $createdAgent = AgentProfile::query()->where('phone_normalized', '+2348011223344')->first();
    expect($createdAgent)->not->toBeNull();
    $response1->assertRedirect(route('agents.show', $createdAgent->agent_id));

    expect(User::where('email', 'idempotent@saverapp.test')->count())->toBe(1);
    expect(AgentProfile::where('phone_normalized', '+2348011223344')->count())->toBe(1);
    expect(Invitation::where('user_id', $createdAgent->user_id)->count())->toBe(1);

    // Second submission with exact same reference and payload
    $response2 = $this->actingAs($admin)->post(route('agents.store'), $data);
    $response2->assertRedirect(route('agents.show', $createdAgent->agent_id));

    // Confirm no duplicates
    expect(User::where('email', 'idempotent@saverapp.test')->count())->toBe(1);
    expect(AgentProfile::where('phone_normalized', '+2348011223344')->count())->toBe(1);
    expect(Invitation::where('user_id', $createdAgent->user_id)->count())->toBe(1);
});

test('repeated submission with same reference but changed payload returns 409 conflict', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::AgentsManage->value);

    $attemptReference = (string) Str::uuid();

    $data1 = [
        'attempt_reference' => $attemptReference,
        'name' => 'Agent One',
        'email' => 'agent.one@saverapp.test',
        'phone' => '+2348011223345',
    ];

    $this->actingAs($admin)->post(route('agents.store'), $data1)->assertRedirect();

    $data2 = [
        'attempt_reference' => $attemptReference,
        'name' => 'Agent One Changed',
        'email' => 'agent.one@saverapp.test',
        'phone' => '+2348011223345',
    ];

    $this->actingAs($admin)
        ->post(route('agents.store'), $data2)
        ->assertStatus(409);
});

test('attempt reference belonging to different admin returns 409 conflict', function (): void {
    $admin1 = User::factory()->admin()->create();
    $admin1->givePermissionTo(AdminPermission::AgentsManage->value);

    $admin2 = User::factory()->admin()->create();
    $admin2->givePermissionTo(AdminPermission::AgentsManage->value);

    $attemptReference = (string) Str::uuid();

    $this->actingAs($admin1)->post(route('agents.store'), [
        'attempt_reference' => $attemptReference,
        'name' => 'Agent Alpha',
        'email' => 'alpha@saverapp.test',
        'phone' => '+2348011223346',
    ])->assertRedirect();

    $this->actingAs($admin2)->post(route('agents.store'), [
        'attempt_reference' => $attemptReference,
        'name' => 'Agent Alpha',
        'email' => 'alpha@saverapp.test',
        'phone' => '+2348011223346',
    ])->assertStatus(409);
});

test('creation attempt lookup endpoint returns committed state or 404', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::AgentsManage->value);

    $attemptReference = (string) Str::uuid();

    $this->actingAs($admin)->post(route('agents.store'), [
        'attempt_reference' => $attemptReference,
        'name' => 'Lookup Agent',
        'email' => 'lookup@saverapp.test',
        'phone' => '+2348011223347',
    ])->assertRedirect();

    $this->actingAs($admin)
        ->getJson(route('agents.attempts.show', $attemptReference))
        ->assertOk()
        ->assertJson([
            'status' => 'committed',
            'result' => [
                'name' => 'Lookup Agent',
                'email' => 'lookup@saverapp.test',
                'operational_status' => 'inactive',
                'account_state' => 'invited',
            ],
        ]);

    // Unknown reference returns 404
    $this->actingAs($admin)
        ->getJson(route('agents.attempts.show', (string) Str::uuid()))
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| 5. Atomic Boundary & State Integrity
|--------------------------------------------------------------------------
*/

test('successful registration atomically provisions Invited user, Inactive profile, invitation challenge, and audit event', function (): void {
    Queue::fake();

    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::AgentsManage->value);

    $reference = (string) Str::uuid();
    $response = $this->actingAs($admin)->post(route('agents.store'), [
        'attempt_reference' => $reference,
        'name' => 'Atomic Agent',
        'email' => 'atomic@saverapp.test',
        'phone' => '+2348022334455',
        'address' => '45 Marina, Lagos',
        'employment_date' => '2026-09-15',
        'notes' => 'Confidential internal note',
    ]);

    $response->assertRedirect();

    // 1. User
    /** @var User $user */
    $user = User::where('email', 'atomic@saverapp.test')->first();
    expect($user)->not->toBeNull()
        ->and($user->user_type)->toBe(UserType::Agent)
        ->and($user->account_state)->toBe(AccountState::Invited)
        ->and($user->password)->toBeNull();

    // 2. Profile
    /** @var AgentProfile $profile */
    $profile = AgentProfile::where('user_id', $user->id)->first();
    expect($profile)->not->toBeNull()
        ->and($profile->agent_id)->toStartWith('AGT-')
        ->and($profile->operational_status)->toBe(AgentStatus::Inactive)
        ->and($profile->phone_normalized)->toBe('+2348022334455')
        ->and($profile->notes)->toBe('Confidential internal note')
        ->and($profile->created_by_user_id)->toBe($admin->id);

    // 3. Status History
    $history = AgentStatusHistory::where('agent_profile_id', $profile->id)->first();
    expect($history)->not->toBeNull()
        ->and($history->from_status)->toBeNull()
        ->and($history->to_status)->toBe(AgentStatus::Inactive->value)
        ->and($history->changed_by_user_id)->toBe($admin->id);

    // 4. Invitation
    $invitation = Invitation::where('user_id', $user->id)->first();
    expect($invitation)->not->toBeNull()
        ->and($invitation->generation)->toBe(1)
        ->and($invitation->status)->toBe(InvitationStatus::PendingDelivery)
        ->and($invitation->delivery_status)->toBe(DeliveryStatus::Pending)
        ->and($invitation->expires_at)->toBeGreaterThan(now()->addHours(23))
        ->and($invitation->token_hash)->not->toBeNull();

    // 5. Creation Attempt
    $attempt = CreationAttempt::where('attempt_reference', $reference)->first();
    expect($attempt)->not->toBeNull()
        ->and($attempt->status)->toBe(CreationAttemptStatus::Committed)
        ->and($attempt->record_id)->toBe($profile->id);

    // 6. Audit Event
    $audit = AuditEvent::where('target_id', $profile->id)->where('event_type', 'agent.registered')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($admin->id)
        ->and($audit->payload)->not->toHaveKey('name')
        ->and($audit->payload['operational_status'])->toBe('inactive')
        ->and($audit->payload['account_state'])->toBe('invited');

    // 7. Queued Job
    Queue::assertPushed(DeliverAgentInvitationJob::class, 1);
});

test('invited user with null password cannot sign in via password credentials', function (): void {
    $user = User::factory()->agent()->invited()->create([
        'email' => 'invited.agent@saverapp.test',
        'password' => null,
    ]);

    $this->post(route('login.store'), [
        'email' => 'invited.agent@saverapp.test',
        'password' => 'AnyPassword123!',
    ])->assertSessionHasErrors();

    expect(Auth::check())->toBeFalse();
});

test('registration rollback leaves no partial records on persistence failure', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::AgentsManage->value);

    $this->mock(PublicIdGenerator::class)->shouldReceive('generateForAgent')
        ->once()
        ->andThrow(new RuntimeException('Simulated database write failure'));

    $service = app(AgentRegistrationService::class);

    expect(fn () => $service->register($admin, (string) Str::uuid(), [
        'name' => 'Failed Agent',
        'email' => 'failed@saverapp.test',
        'phone' => '+2348099887766',
    ]))->toThrow(RuntimeException::class);

    expect(User::where('email', 'failed@saverapp.test')->exists())->toBeFalse();
    expect(AgentProfile::where('phone_normalized', '+2348099887766')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| 6. Queued Delivery, Bounded Retries & Diagnostics
|--------------------------------------------------------------------------
*/

test('DeliverAgentInvitationJob delivers email and updates invitation and audit state', function (): void {
    Notification::fake();

    $admin = User::factory()->admin()->create();
    $user = User::factory()->agent()->invited()->create(['name' => 'Queue Agent', 'email' => 'queue@saverapp.test']);
    $invitation = Invitation::create([
        'user_id' => $user->id,
        'target_email' => $user->email,
        'target_email_normalized' => $user->email_normalized,
        'role' => UserType::Agent->value,
        'token_hash' => hash('sha256', 'plain-token-123'),
        'generation' => 1,
        'status' => InvitationStatus::PendingDelivery,
        'delivery_status' => DeliveryStatus::Pending,
        'expires_at' => now()->addHours(24),
        'invited_by_user_id' => $admin->id,
    ]);

    $job = new DeliverAgentInvitationJob($invitation->id, 'plain-token-123', 1);
    app()->call([$job, 'handle']);

    Notification::assertSentOnDemand(AgentInvitationNotification::class, function ($notification, $channels, $notifiable) use ($user) {
        return $notifiable->routes['mail'] === $user->email;
    });

    $invitation->refresh();
    expect($invitation->status)->toBe(InvitationStatus::Sent)
        ->and($invitation->delivery_status)->toBe(DeliveryStatus::Sent)
        ->and($invitation->sent_at)->not->toBeNull();

    expect(AuditEvent::where('target_id', $invitation->id)->where('event_type', 'invitation.sent')->exists())->toBeTrue();
});

test('DeliverAgentInvitationJob suppresses stale generations, cancelled, or expired invitations', function (): void {
    Notification::fake();

    $admin = User::factory()->admin()->create();
    $user = User::factory()->agent()->invited()->create(['email' => 'stale@saverapp.test']);
    $invitation = Invitation::create([
        'user_id' => $user->id,
        'target_email' => $user->email,
        'target_email_normalized' => $user->email_normalized,
        'role' => UserType::Agent->value,
        'token_hash' => hash('sha256', 'token-gen-2'),
        'generation' => 2, // Current generation is 2
        'status' => InvitationStatus::PendingDelivery,
        'delivery_status' => DeliveryStatus::Pending,
        'expires_at' => now()->addHours(24),
        'invited_by_user_id' => $admin->id,
    ]);

    // Deliver job for generation 1 (stale)
    $job = new DeliverAgentInvitationJob($invitation->id, 'token-gen-1', 1);
    app()->call([$job, 'handle']);

    Notification::assertNothingSent();

    // Cancel invitation and try delivering current generation
    $invitation->status = InvitationStatus::Cancelled;
    $invitation->save();

    $job2 = new DeliverAgentInvitationJob($invitation->id, 'token-gen-2', 2);
    app()->call([$job2, 'handle']);

    Notification::assertNothingSent();
});

test('DeliverAgentInvitationJob records delivery failure without deleting registered agent', function (): void {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->agent()->invited()->create(['email' => 'fail@saverapp.test']);
    $agentProfile = AgentProfile::factory()->create(['user_id' => $user->id]);

    Notification::shouldReceive('send')
        ->once()
        ->andThrow(new RuntimeException('SMTP connection timeout'));

    $invitation = Invitation::create([
        'user_id' => $user->id,
        'target_email' => $user->email,
        'target_email_normalized' => $user->email_normalized,
        'role' => UserType::Agent->value,
        'token_hash' => hash('sha256', 'plain-token-456'),
        'generation' => 1,
        'status' => InvitationStatus::PendingDelivery,
        'delivery_status' => DeliveryStatus::Pending,
        'expires_at' => now()->addHours(24),
        'invited_by_user_id' => $admin->id,
    ]);

    $job = new DeliverAgentInvitationJob($invitation->id, 'plain-token-456', 1);

    try {
        app()->call([$job, 'handle']);
    } catch (Throwable $e) {
        // Expected since attempt < tries
    }

    $invitation->refresh();
    expect($invitation->status)->toBe(InvitationStatus::DeliveryFailed)
        ->and($invitation->delivery_status)->toBe(DeliveryStatus::Failed)
        ->and($invitation->delivery_error)->toContain('RuntimeException');

    // Agent profile and User must be preserved!
    expect(User::where('id', $user->id)->exists())->toBeTrue();
    expect(AgentProfile::where('id', $agentProfile->id)->exists())->toBeTrue();
    expect(AuditEvent::where('target_id', $invitation->id)->where('event_type', 'invitation.delivery_failed')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 7. Invitation Resend, Rate Limits & Token Rotation
|--------------------------------------------------------------------------
*/

test('Admin can resend invitation with token rotation, generation increment, and delivery dispatch', function (): void {
    Queue::fake();

    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::AgentsManage->value);

    $agentUser = User::factory()->agent()->invited()->create(['email' => 'resend@saverapp.test']);
    $agentProfile = AgentProfile::factory()->create(['user_id' => $agentUser->id]);

    $this->travelTo(now()->subMinutes(2));

    $oldInvitation = Invitation::create([
        'user_id' => $agentUser->id,
        'target_email' => $agentUser->email,
        'target_email_normalized' => $agentUser->email_normalized,
        'role' => UserType::Agent->value,
        'token_hash' => hash('sha256', 'old-token'),
        'generation' => 1,
        'status' => InvitationStatus::Sent,
        'delivery_status' => DeliveryStatus::Sent,
        'expires_at' => now()->addHours(24),
        'invited_by_user_id' => $admin->id,
    ]);

    $this->travelBack();

    $this->actingAs($admin)
        ->from(route('agents.show', $agentProfile->agent_id))
        ->post(route('agents.invitations.resend', $agentProfile->agent_id), [
            'reason' => 'User requested new invitation link',
        ])
        ->assertRedirect(route('agents.show', $agentProfile->agent_id));

    $oldInvitation->refresh();
    expect($oldInvitation->status)->toBe(InvitationStatus::Cancelled);

    /** @var Invitation $newInvitation */
    $newInvitation = Invitation::where('user_id', $agentUser->id)->latest('generation')->first();
    expect($newInvitation->generation)->toBe(2)
        ->and($newInvitation->token_hash)->not->toBe($oldInvitation->token_hash)
        ->and($newInvitation->status)->toBe(InvitationStatus::PendingDelivery);

    Queue::assertPushed(DeliverAgentInvitationJob::class, function ($job) use ($newInvitation) {
        return $job->invitationId === $newInvitation->id && $job->generation === 2;
    });

    expect(AuditEvent::where('target_id', $newInvitation->id)->where('event_type', 'invitation.resent')->exists())->toBeTrue();
});

test('invitation resend is throttled to 1 per minute and 5 per 24 hours', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::AgentsManage->value);

    $agentUser = User::factory()->agent()->invited()->create(['email' => 'throttled@saverapp.test']);
    $agentProfile = AgentProfile::factory()->create(['user_id' => $agentUser->id]);

    // Create an invitation created 10 seconds ago
    Invitation::create([
        'user_id' => $agentUser->id,
        'target_email' => $agentUser->email,
        'target_email_normalized' => $agentUser->email_normalized,
        'role' => UserType::Agent->value,
        'token_hash' => hash('sha256', 'token-1'),
        'generation' => 1,
        'status' => InvitationStatus::Sent,
        'delivery_status' => DeliveryStatus::Sent,
        'expires_at' => now()->addHours(24),
        'created_at' => now()->subSeconds(10),
        'invited_by_user_id' => $admin->id,
    ]);

    // Resend immediately should be rejected
    $this->actingAs($admin)
        ->post(route('agents.invitations.resend', $agentProfile->agent_id))
        ->assertSessionHasErrors(['resend']);

    // Now test 5 per 24 hours limit
    Invitation::query()->delete();
    for ($i = 1; $i <= 5; $i++) {
        Invitation::create([
            'user_id' => $agentUser->id,
            'target_email' => $agentUser->email,
            'target_email_normalized' => $agentUser->email_normalized,
            'role' => UserType::Agent->value,
            'token_hash' => hash('sha256', "token-{$i}"),
            'generation' => $i,
            'status' => InvitationStatus::Cancelled,
            'delivery_status' => DeliveryStatus::Sent,
            'expires_at' => now()->addHours(24),
            'created_at' => now()->subMinutes(6 - $i)->subMinutes(5), // older than 1 minute, but within 24h
            'invited_by_user_id' => $admin->id,
        ]);
    }

    $this->actingAs($admin)
        ->post(route('agents.invitations.resend', $agentProfile->agent_id))
        ->assertSessionHasErrors(['resend']);
});

/*
|--------------------------------------------------------------------------
| 8. Email Correction & Cancellation
|--------------------------------------------------------------------------
*/

test('Admin can correct email for invited agent, invalidating prior invitation and issuing new challenge', function (): void {
    Queue::fake();

    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::AgentsManage->value);

    $agentUser = User::factory()->agent()->invited()->create([
        'email' => 'wrong.email@saverapp.test',
        'email_normalized' => IdentityNormalizer::normalizeEmail('wrong.email@saverapp.test'),
    ]);
    $agentProfile = AgentProfile::factory()->create(['user_id' => $agentUser->id]);

    $priorInvitation = Invitation::create([
        'user_id' => $agentUser->id,
        'target_email' => $agentUser->email,
        'target_email_normalized' => $agentUser->email_normalized,
        'role' => UserType::Agent->value,
        'token_hash' => hash('sha256', 'wrong-email-token'),
        'generation' => 1,
        'status' => InvitationStatus::Sent,
        'delivery_status' => DeliveryStatus::Sent,
        'expires_at' => now()->addHours(24),
        'invited_by_user_id' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->from(route('agents.show', $agentProfile->agent_id))
        ->post(route('agents.invitations.correct-email', $agentProfile->agent_id), [
            'email' => 'correct.email@saverapp.test',
            'reason' => 'Typo in original address given on onboarding form',
        ])
        ->assertRedirect(route('agents.show', $agentProfile->agent_id));

    $priorInvitation->refresh();
    expect($priorInvitation->status)->toBe(InvitationStatus::Cancelled);

    $agentUser->refresh();
    expect($agentUser->email)->toBe('correct.email@saverapp.test')
        ->and($agentUser->email_normalized)->toBe(IdentityNormalizer::normalizeEmail('correct.email@saverapp.test'));

    /** @var Invitation $newInvitation */
    $newInvitation = Invitation::where('user_id', $agentUser->id)->latest('generation')->first();
    expect($newInvitation->generation)->toBe(2)
        ->and($newInvitation->target_email)->toBe('correct.email@saverapp.test')
        ->and($newInvitation->status)->toBe(InvitationStatus::PendingDelivery);

    expect(AuditEvent::where('target_id', $newInvitation->id)->where('event_type', 'invitation.email_corrected')->exists())->toBeTrue();
});

test('Admin can cancel invitation, invalidating challenge', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::AgentsManage->value);

    $agentUser = User::factory()->agent()->invited()->create(['email' => 'cancel.me@saverapp.test']);
    $agentProfile = AgentProfile::factory()->create(['user_id' => $agentUser->id]);

    $invitation = Invitation::create([
        'user_id' => $agentUser->id,
        'target_email' => $agentUser->email,
        'target_email_normalized' => $agentUser->email_normalized,
        'role' => UserType::Agent->value,
        'token_hash' => hash('sha256', 'plain-cancel-token'),
        'generation' => 1,
        'status' => InvitationStatus::Sent,
        'delivery_status' => DeliveryStatus::Sent,
        'expires_at' => now()->addHours(24),
        'invited_by_user_id' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->from(route('agents.show', $agentProfile->agent_id))
        ->post(route('agents.invitations.cancel', $agentProfile->agent_id), [
            'reason' => 'Candidate rescinded offer',
        ])
        ->assertRedirect(route('agents.show', $agentProfile->agent_id));

    $invitation->refresh();
    expect($invitation->status)->toBe(InvitationStatus::Cancelled)
        ->and($invitation->cancellation_reason)->toBe('Candidate rescinded offer')
        ->and($invitation->cancelled_by_user_id)->toBe($admin->id);

    expect(AuditEvent::where('target_id', $agentProfile->id)->where('event_type', 'invitation.cancelled')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 9. Activation Flow: Password Setup, Account State & MFA Transition
|--------------------------------------------------------------------------
*/

test('public challenge page transitions invitation to opened and renders ready state', function (): void {
    $plainToken = Str::random(64);
    $admin = User::factory()->admin()->create();
    $user = User::factory()->agent()->invited()->create(['name' => 'Jane Agent', 'email' => 'jane@saverapp.test']);
    $invitation = Invitation::create([
        'user_id' => $user->id,
        'target_email' => $user->email,
        'target_email_normalized' => $user->email_normalized,
        'role' => UserType::Agent->value,
        'token_hash' => hash('sha256', $plainToken),
        'generation' => 1,
        'status' => InvitationStatus::Sent,
        'delivery_status' => DeliveryStatus::Sent,
        'expires_at' => now()->addHours(24),
        'invited_by_user_id' => $admin->id,
    ]);

    $this->get(route('invitations.agent.show', $plainToken))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/AgentActivation')
            ->where('status', 'ready')
            ->where('name', 'Jane Agent')
            ->where('email', 'jane@saverapp.test')
            ->where('business_name', 'SaverApp')
        );

    $invitation->refresh();
    expect($invitation->status)->toBe(InvitationStatus::Opened)
        ->and($invitation->opened_at)->not->toBeNull();

    expect(AuditEvent::where('target_id', $invitation->id)->where('event_type', 'invitation.opened')->exists())->toBeTrue();
});

test('public challenge page shows expired, cancelled, and invalid states accurately', function (): void {
    $admin = User::factory()->admin()->create();

    // 1. Invalid token
    $this->get(route('invitations.agent.show', 'completely-invalid-token'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/AgentActivation')
            ->where('status', 'invalid')
        );

    // 2. Expired token (>24h)
    $expiredToken = Str::random(64);
    $user1 = User::factory()->agent()->invited()->create();
    Invitation::create([
        'user_id' => $user1->id,
        'target_email' => $user1->email,
        'target_email_normalized' => $user1->email_normalized,
        'role' => UserType::Agent->value,
        'token_hash' => hash('sha256', $expiredToken),
        'generation' => 1,
        'status' => InvitationStatus::Sent,
        'delivery_status' => DeliveryStatus::Sent,
        'expires_at' => now()->subMinutes(5),
        'invited_by_user_id' => $admin->id,
    ]);

    $this->get(route('invitations.agent.show', $expiredToken))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/AgentActivation')
            ->where('status', 'expired')
        );

    // 3. Cancelled token
    $cancelledToken = Str::random(64);
    $user2 = User::factory()->agent()->invited()->create();
    Invitation::create([
        'user_id' => $user2->id,
        'target_email' => $user2->email,
        'target_email_normalized' => $user2->email_normalized,
        'role' => UserType::Agent->value,
        'token_hash' => hash('sha256', $cancelledToken),
        'generation' => 1,
        'status' => InvitationStatus::Cancelled,
        'delivery_status' => DeliveryStatus::Sent,
        'expires_at' => now()->addHours(24),
        'invited_by_user_id' => $admin->id,
    ]);

    $this->get(route('invitations.agent.show', $cancelledToken))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/AgentActivation')
            ->where('status', 'cancelled')
        );
});

test('activation requires policy-compliant password and enters MFA setup with Inactive operational status', function (): void {
    $admin = User::factory()->admin()->create();
    $plainToken = Str::random(64);
    $user = User::factory()->agent()->invited()->create([
        'name' => 'Activate Agent',
        'email' => 'activate@saverapp.test',
        'password' => null,
    ]);
    $agentProfile = AgentProfile::factory()->create([
        'user_id' => $user->id,
        'operational_status' => AgentStatus::Inactive,
    ]);

    $invitation = Invitation::create([
        'user_id' => $user->id,
        'target_email' => $user->email,
        'target_email_normalized' => $user->email_normalized,
        'role' => UserType::Agent->value,
        'token_hash' => hash('sha256', $plainToken),
        'generation' => 1,
        'status' => InvitationStatus::Opened,
        'delivery_status' => DeliveryStatus::Sent,
        'expires_at' => now()->addHours(24),
        'invited_by_user_id' => $admin->id,
    ]);

    // Weak password rejected
    $this->post(route('invitations.agent.activate', $plainToken), [
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors(['password']);

    // Valid compliant password
    $validPassword = 'SecurePassword123!';
    $response = $this->post(route('invitations.agent.activate', $plainToken), [
        'password' => $validPassword,
        'password_confirmation' => $validPassword,
    ]);

    $response->assertRedirect(route('two-factor.enrolment'));

    $user->refresh();
    expect($user->account_state)->toBe(AccountState::MfaSetupRequired)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check($validPassword, $user->password))->toBeTrue();

    // Authenticated into session
    expect(Auth::id())->toBe($user->id);

    // Agent operational status must REMAIN Inactive
    $agentProfile->refresh();
    expect($agentProfile->operational_status)->toBe(AgentStatus::Inactive);

    // Invitation marked Activated
    $invitation->refresh();
    expect($invitation->status)->toBe(InvitationStatus::Activated)
        ->and($invitation->activated_at)->not->toBeNull();

    expect(AuditEvent::where('target_id', $user->id)->where('event_type', 'agent.activated')->exists())->toBeTrue();

    // Second activation with the same token must fail (single-use)
    Auth::guard('web')->logout();
    $this->post(route('invitations.agent.activate', $plainToken), [
        'password' => $validPassword,
        'password_confirmation' => $validPassword,
    ])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| 10. Directory & Profile Privacy
|--------------------------------------------------------------------------
*/

test('invitation secrets are omitted from profile page props and hidden from non-admin viewers', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::AgentsManage->value);

    $invitedUser = User::factory()->agent()->invited()->create(['name' => 'Invited Agent']);
    $invitedProfile = AgentProfile::factory()->create([
        'user_id' => $invitedUser->id,
        'notes' => 'Top secret administrative note',
    ]);

    $plainToken = Str::random(64);
    $invitation = Invitation::create([
        'user_id' => $invitedUser->id,
        'target_email' => $invitedUser->email,
        'target_email_normalized' => $invitedUser->email_normalized,
        'role' => UserType::Agent->value,
        'token_hash' => hash('sha256', $plainToken),
        'generation' => 1,
        'status' => InvitationStatus::Sent,
        'delivery_status' => DeliveryStatus::Sent,
        'expires_at' => now()->addHours(24),
        'invited_by_user_id' => $admin->id,
    ]);

    // Admin with agents.manage views profile
    $this->actingAs($admin)
        ->get(route('agents.show', $invitedProfile->agent_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('agents/Show')
            ->where('agent.actions.can_manage_invitation', true)
            ->where('agent.invitation.status', 'sent')
            ->where('agent.invitation.delivery_status', 'sent')
            ->where('agent.notes', 'Top secret administrative note')
            ->missing('agent.invitation.token')
            ->missing('agent.invitation.token_hash')
        );

    // Active Agent viewing own profile cannot see notes or invitation props
    $activeAgent = User::factory()->agent()->active()->create(['name' => 'Active Self Agent']);
    $activeProfile = AgentProfile::factory()->active()->create([
        'user_id' => $activeAgent->id,
        'notes' => 'Internal agent note',
    ]);

    $this->actingAs($activeAgent)
        ->get(route('agents.show', $activeProfile->agent_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('agents/Show')
            ->where('agent.actions.can_manage_invitation', false)
            ->missing('agent.invitation')
            ->missing('agent.notes')
        );
});
