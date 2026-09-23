<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\CustomerAssignmentStatus;
use App\Jobs\DeliverProfileNotificationIntent;
use App\Models\AgentProfile;
use App\Models\AgentTrustedDevice;
use App\Models\CustomerAssignment;
use App\Models\CustomerNameCorrection;
use App\Models\CustomerProfile;
use App\Models\PendingEmailChange;
use App\Models\ProfileChangeHistory;
use App\Models\ProfileNotificationIntent;
use App\Models\User;
use App\Notifications\EmailChangeNotification;
use App\Services\AgentEligibilityService;
use App\Services\AuthorizationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('customer profile updates allow optional clears and write protected history once', function (): void {
    $user = User::factory()->customer()->create();
    $profile = CustomerProfile::factory()->withNextOfKin()->create([
        'user_id' => $user->id,
        'address' => '55 Old Street',
        'occupation' => 'Original occupation',
    ]);

    $this->actingAs($user)
        ->patch(route('customers.update', $profile->customer_id), [
            'version' => 1,
            'address' => '',
            'occupation' => '',
            'next_of_kin' => ['full_name' => '', 'relationship' => '', 'phone' => '', 'address' => ''],
        ])
        ->assertRedirect(route('customers.show', $profile->customer_id));

    $profile->refresh();
    expect($profile->address)->toBeNull()
        ->and($profile->occupation)->toBeNull()
        ->and($profile->next_of_kin)->toBeNull()
        ->and($profile->version)->toBe(2);

    $history = ProfileChangeHistory::query()->where('event_type', 'customer.profile_updated')->sole();
    expect($history->changed_fields)->toContain('address', 'occupation', 'next_of_kin')
        ->and($history->before_values['address'])->not->toBeNull()
        ->and($history->after_values['address'])->toBeNull();

    $rawHistory = DB::table('profile_change_histories')->where('id', $history->id)->first();
    $audit = DB::table('audit_events')->where('id', $history->audit_event_id)->value('payload');
    expect($rawHistory->before_values)->not->toContain('55 Old Street')
        ->and($rawHistory->after_values)->not->toContain('Original occupation')
        ->and($audit)->not->toContain('55 Old Street')
        ->and($audit)->not->toContain('Original occupation');

    $this->actingAs($user)
        ->patch(route('customers.update', $profile->customer_id), ['version' => 2, 'address' => null])
        ->assertRedirect(route('customers.show', $profile->customer_id));

    expect($profile->refresh()->version)->toBe(2)
        ->and(ProfileChangeHistory::query()->where('event_type', 'customer.profile_updated')->count())->toBe(1);
});

test('customer ordinary profile endpoint rejects forged identity fields and stale versions', function (): void {
    $user = User::factory()->customer()->create();
    $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
    $originalName = $user->name;

    $this->actingAs($user)
        ->patch(route('customers.update', $profile->customer_id), [
            'version' => 1,
            'address' => '12 New Street',
            'name' => 'Forged Name',
            'email' => 'forged@example.test',
        ])
        ->assertSessionHasErrors('profile');

    expect($user->refresh()->name)->toBe($originalName)
        ->and($profile->refresh()->address)->not->toBe('12 New Street');

    $profile->version = 2;
    $profile->save();
    $this->actingAs($user)
        ->patch(route('customers.update', $profile->customer_id), ['version' => 1, 'address' => '12 New Street'])
        ->assertSessionHasErrors('version');
});

test('customer phone self-service requires fresh authentication and records a normalized change', function (): void {
    Queue::fake();
    $user = User::factory()->customer()->create();
    $profile = CustomerProfile::factory()->create(['user_id' => $user->id, 'phone' => '08011112222']);
    $assignedAgent = User::factory()->agent()->withTwoFactor()->create(['account_state' => AccountState::Active]);
    $assignedAgentProfile = AgentProfile::factory()->active()->create(['user_id' => $assignedAgent->id]);
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $profile->id,
        'agent_profile_id' => $assignedAgentProfile->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => true,
    ]);

    $this->actingAs($user)
        ->post(route('customers.phone.self', $profile->customer_id), [
            'version' => 1,
            'phone' => '08033334444',
        ])
        ->assertRedirect(route('fresh-authentication'));

    expect($profile->refresh()->phone_normalized)->toBe('+2348011112222');

    $this->actingAs($user)
        ->withSession([
            'auth.fresh_until' => now()->timestamp + 600,
            'auth.password_confirmed_at' => now()->timestamp,
        ])
        ->post(route('customers.phone.self', $profile->customer_id), [
            'version' => 1,
            'phone' => '(080) 3333-4444',
        ])
        ->assertRedirect(route('customers.show', $profile->customer_id));

    $history = ProfileChangeHistory::query()->where('event_type', 'customer.phone_changed')->sole();
    $intents = ProfileNotificationIntent::query()
        ->where('profile_change_history_id', $history->id)
        ->get()
        ->map(fn (ProfileNotificationIntent $intent): array => [$intent->recipient_user_id, $intent->channel, $intent->audience_type])
        ->all();

    expect($profile->refresh()->phone_normalized)->toBe('+2348033334444')
        ->and($profile->version)->toBe(2)
        ->and($history->changed_fields)->toBe(['phone'])
        ->and($intents)->toContain(
            [$user->id, 'database', 'subject_customer'],
            [$user->id, 'mail', 'subject_customer'],
            [$assignedAgent->id, 'database', 'current_agent'],
        );

    CustomerProfile::factory()->create(['phone' => '08055556666']);
    $this->actingAs($user)
        ->withSession([
            'auth.fresh_until' => now()->timestamp + 600,
            'auth.password_confirmed_at' => now()->timestamp,
        ])
        ->post(route('customers.phone.self', $profile->customer_id), [
            'version' => 2,
            'phone' => '+2348055556666',
        ])
        ->assertSessionHasErrors('phone');

    expect($profile->refresh()->version)->toBe(2)
        ->and($profile->phone_normalized)->toBe('+2348033334444');
});

test('customer cannot use the pre-activation phone correction route to bypass self-service authentication', function (): void {
    $user = User::factory()->customer()->create();
    $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('customers.phone-corrections.store', $profile->customer_id), [
            'version' => 1,
            'phone' => '08055556666',
            'reason' => 'Update phone',
        ])
        ->assertForbidden();

    expect($profile->refresh()->version)->toBe(1);
});

test('Customer name self-service requires fresh authentication and updates history', function (): void {
    $customer = User::factory()->customer()->create(['account_state' => AccountState::Active]);
    $profile = CustomerProfile::factory()->create(['user_id' => $customer->id]);

    $this->actingAs($customer)
        ->post(route('customers.name.update', $profile->customer_id), [
            'name' => 'Updated Customer',
            'reason' => 'Correcting my display name',
            'version' => 1,
        ])
        ->assertRedirect(route('fresh-authentication'));

    $this->actingAs($customer)
        ->withSession([
            'auth.fresh_until' => now()->timestamp + 600,
            'auth.password_confirmed_at' => now()->timestamp,
        ])
        ->post(route('customers.name.update', $profile->customer_id), [
            'name' => 'Updated Customer',
            'reason' => 'Correcting my display name',
            'version' => 1,
        ])
        ->assertRedirect(route('customers.show', $profile->customer_id));

    expect($customer->refresh()->name)->toBe('Updated Customer')
        ->and($profile->refresh()->version)->toBe(2)
        ->and(ProfileChangeHistory::query()->where('event_type', 'customer.name_changed')->sole()->changed_fields)->toBe(['name']);
});

test('staff can correct a customer phone before activation only with a reason', function (): void {
    $customer = User::factory()->customer()->invited()->unverified()->create();
    $customerProfile = CustomerProfile::factory()->create(['user_id' => $customer->id, 'phone' => '08011112222']);
    $agent = User::factory()->agent()->withTwoFactor()->create(['account_state' => AccountState::Active]);
    $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agent->id]);
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customerProfile->id,
        'agent_profile_id' => $agentProfile->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => true,
    ]);

    $this->actingAs($agent)
        ->post(route('customers.phone-corrections.store', $customerProfile->customer_id), [
            'phone' => '08033334444',
            'reason' => 'Corrected from verified registration record',
            'version' => 1,
        ])
        ->assertRedirect(route('customers.show', $customerProfile->customer_id));

    expect($customerProfile->refresh()->phone_normalized)->toBe('+2348033334444')
        ->and($customerProfile->version)->toBe(2)
        ->and(ProfileChangeHistory::query()->where('event_type', 'customer.phone_changed')->sole()->reason)
        ->toBe('Corrected from verified registration record');
});

test('staff corrections recheck activation state after the edit form was opened', function (): void {
    Queue::fake();
    $customer = User::factory()->customer()->invited()->unverified()->create();
    $customerProfile = CustomerProfile::factory()->create(['user_id' => $customer->id, 'phone' => '08011112222']);
    $agent = User::factory()->agent()->withTwoFactor()->create(['account_state' => AccountState::Active]);
    $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agent->id]);
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customerProfile->id,
        'agent_profile_id' => $agentProfile->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => true,
    ]);

    $this->actingAs($agent)
        ->get(route('customers.edit', $customerProfile->customer_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('customer.can_change_phone', true));

    $customer->forceFill([
        'account_state' => AccountState::Active,
        'email_verified_at' => now(),
    ])->save();

    $this->actingAs($agent)
        ->post(route('customers.phone-corrections.store', $customerProfile->customer_id), [
            'phone' => '08033334444',
            'reason' => 'Corrected from verified registration record',
            'version' => 1,
        ])
        ->assertForbidden();

    $this->actingAs($agent)
        ->post(route('customers.name-corrections.store', $customerProfile->customer_id), [
            'name' => 'Post Activation Proposal',
            'reason' => 'Correct identity spelling',
            'version' => 1,
        ])
        ->assertRedirect(route('customers.show', $customerProfile->customer_id));

    expect($customerProfile->refresh()->phone_normalized)->toBe('+2348011112222')
        ->and($customer->refresh()->name)->not->toBe('Post Activation Proposal')
        ->and(CustomerNameCorrection::query()->sole()->status)->toBe('pending');
});

test('Agent phone self-service requires fresh password and authenticator confirmation', function (): void {
    Queue::fake();
    $agent = User::factory()->agent()->withTwoFactor()->create(['account_state' => AccountState::Active]);
    $profile = AgentProfile::factory()->active()->create(['user_id' => $agent->id, 'phone' => '08011112222']);
    $securityAdmin = User::factory()->admin()->create(['account_state' => AccountState::Active]);
    $securityAdmin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);
    $baselineAdmin = User::factory()->admin()->create(['account_state' => AccountState::Active]);

    $this->actingAs($agent)
        ->post(route('agents.phone.self', $profile->agent_id), ['phone' => '08033334444', 'version' => 1])
        ->assertRedirect(route('fresh-authentication'));

    $this->actingAs($agent)
        ->withSession([
            'auth.fresh_until' => now()->timestamp + 600,
            'auth.password_confirmed_at' => now()->timestamp,
            'auth.mfa_confirmed_at' => now()->timestamp,
        ])
        ->post(route('agents.phone.self', $profile->agent_id), ['phone' => '08033334444', 'version' => 1])
        ->assertRedirect(route('agents.show', $profile->agent_id));

    $history = ProfileChangeHistory::query()->where('event_type', 'agent.phone_changed')->sole();
    $intents = ProfileNotificationIntent::query()
        ->where('profile_change_history_id', $history->id)
        ->get()
        ->map(fn (ProfileNotificationIntent $intent): array => [$intent->recipient_user_id, $intent->channel, $intent->audience_type])
        ->all();

    expect($profile->refresh()->phone_normalized)->toBe('+2348033334444')
        ->and($intents)->toContain(
            [$agent->id, 'database', 'subject_agent'],
            [$agent->id, 'mail', 'subject_agent'],
            [$securityAdmin->id, 'database', 'security_operations_admin'],
        )->not->toContain([$baselineAdmin->id, 'database', 'security_operations_admin']);
});

test('Agent self-service rejects staff-only profile fields', function (): void {
    $agent = User::factory()->agent()->withTwoFactor()->create(['account_state' => AccountState::Active]);
    $profile = AgentProfile::factory()->active()->create(['user_id' => $agent->id, 'notes' => 'Restricted note']);

    $this->actingAs($agent)
        ->patch(route('agents.update', $profile->agent_id), [
            'version' => 1,
            'address' => 'Allowed address',
            'name' => 'Forged Agent Name',
            'notes' => 'Forged note',
        ])
        ->assertSessionHasErrors('profile');

    expect($agent->refresh()->name)->not->toBe('Forged Agent Name')
        ->and($profile->refresh()->address)->not->toBe('Allowed address')
        ->and($profile->notes)->toBe('Restricted note');
});

test('Customer self-service edit props omit staff-only notes and reference data', function (): void {
    $customer = User::factory()->customer()->create();
    $profile = CustomerProfile::factory()->create([
        'user_id' => $customer->id,
        'notes' => 'Staff-only notes',
        'internal_reference' => 'SAFE-REF-001',
    ]);

    $this->actingAs($customer)
        ->get(route('customers.edit', $profile->customer_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('customers/Edit')
            ->missing('customer.notes')
            ->missing('customer.internal_reference')
            ->missing('customer.phone_normalized')
        );
});

test('Agent self-service edit props omit internal notes', function (): void {
    $agent = User::factory()->agent()->withTwoFactor()->create(['account_state' => AccountState::Active]);
    $profile = AgentProfile::factory()->active()->create(['user_id' => $agent->id, 'notes' => 'Agent-only internal note']);

    $this->actingAs($agent)
        ->get(route('agents.edit', $profile->agent_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('agents/Edit')
            ->missing('agent.notes')
            ->missing('agent.phone_normalized')
        );
});

test('stale photo edit removes the newly stored file and preserves the current asset', function (): void {
    Storage::fake('local');
    $user = User::factory()->customer()->create();
    $profile = CustomerProfile::factory()->create([
        'user_id' => $user->id,
        'photo_path' => 'profiles/current.jpg',
        'version' => 2,
    ]);
    Storage::disk('local')->put('profiles/current.jpg', 'current photo');

    $this->actingAs($user)
        ->patch(route('customers.update', $profile->customer_id), [
            'version' => 1,
            'address' => 'New address',
            'photo' => UploadedFile::fake()->image('replacement.png', 200, 200),
        ])
        ->assertSessionHasErrors('version');

    expect(Storage::disk('local')->allFiles())->toBe(['profiles/current.jpg'])
        ->and($profile->refresh()->photo_path)->toBe('profiles/current.jpg')
        ->and($profile->version)->toBe(2);
});

test('notification intent delivery is idempotent for database inbox messages', function (): void {
    Queue::fake();
    $customer = User::factory()->customer()->create();
    $profile = CustomerProfile::factory()->create(['user_id' => $customer->id]);
    $agent = User::factory()->agent()->withTwoFactor()->create(['account_state' => AccountState::Active]);
    $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agent->id]);
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $profile->id,
        'agent_profile_id' => $agentProfile->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => true,
    ]);

    $this->actingAs($customer)
        ->patch(route('customers.update', $profile->customer_id), ['version' => 1, 'address' => '12 Notice Street'])
        ->assertRedirect(route('customers.show', $profile->customer_id));
    $intent = ProfileNotificationIntent::query()->where('recipient_user_id', $agent->id)->sole();
    $job = new DeliverProfileNotificationIntent($intent->id);

    $job->handle(app(AgentEligibilityService::class), app(AuthorizationService::class));
    $job->handle(app(AgentEligibilityService::class), app(AuthorizationService::class));

    expect($agent->notifications()->count())->toBe(1)
        ->and($intent->refresh()->status)->toBe('delivered');
});

test('notification intent is suppressed when an Agent loses Customer assignment before delivery', function (): void {
    Queue::fake();
    $customer = User::factory()->customer()->create();
    $profile = CustomerProfile::factory()->create(['user_id' => $customer->id]);
    $formerAgent = User::factory()->agent()->withTwoFactor()->create(['account_state' => AccountState::Active]);
    $formerAgentProfile = AgentProfile::factory()->active()->create(['user_id' => $formerAgent->id]);
    $assignment = CustomerAssignment::factory()->create([
        'customer_profile_id' => $profile->id,
        'agent_profile_id' => $formerAgentProfile->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => true,
    ]);

    $this->actingAs($customer)
        ->patch(route('customers.update', $profile->customer_id), ['version' => 1, 'address' => '12 Notice Street'])
        ->assertRedirect(route('customers.show', $profile->customer_id));
    $intent = ProfileNotificationIntent::query()->where('recipient_user_id', $formerAgent->id)->sole();

    DB::table('customer_assignments')->where('id', $assignment->id)->update([
        'status' => CustomerAssignmentStatus::Ended->value,
        'is_current' => null,
        'ended_at' => now(),
    ]);
    $replacementAgent = User::factory()->agent()->withTwoFactor()->create(['account_state' => AccountState::Active]);
    $replacementProfile = AgentProfile::factory()->active()->create(['user_id' => $replacementAgent->id]);
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $profile->id,
        'agent_profile_id' => $replacementProfile->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => true,
        'version' => 2,
    ]);

    (new DeliverProfileNotificationIntent($intent->id))->handle(app(AgentEligibilityService::class), app(AuthorizationService::class));

    expect($intent->refresh()->status)->toBe('suppressed')
        ->and($formerAgent->notifications()->count())->toBe(0);
});

test('Agent phone security notice is suppressed when an Admin loses permission before delivery', function (): void {
    Queue::fake();
    $agent = User::factory()->agent()->withTwoFactor()->create(['account_state' => AccountState::Active]);
    $profile = AgentProfile::factory()->active()->create(['user_id' => $agent->id, 'phone' => '08011112222']);
    $securityAdmin = User::factory()->admin()->create(['account_state' => AccountState::Active]);
    $securityAdmin->givePermissionTo(AdminPermission::SecurityOperationsManage->value);

    $this->actingAs($agent)
        ->withSession([
            'auth.fresh_until' => now()->timestamp + 600,
            'auth.password_confirmed_at' => now()->timestamp,
            'auth.mfa_confirmed_at' => now()->timestamp,
        ])
        ->post(route('agents.phone.self', $profile->agent_id), ['phone' => '08033334444', 'version' => 1])
        ->assertRedirect(route('agents.show', $profile->agent_id));

    $intent = ProfileNotificationIntent::query()
        ->where('recipient_user_id', $securityAdmin->id)
        ->where('audience_type', 'security_operations_admin')
        ->sole();
    $securityAdmin->revokePermissionTo(AdminPermission::SecurityOperationsManage->value);

    (new DeliverProfileNotificationIntent($intent->id))
        ->handle(app(AgentEligibilityService::class), app(AuthorizationService::class));

    expect($intent->refresh()->status)->toBe('suppressed')
        ->and($securityAdmin->notifications()->count())->toBe(0);
});

test('staff name proposals can be replaced and accepted only after customer confirmation', function (): void {
    $customer = User::factory()->customer()->create(['account_state' => AccountState::Active]);
    $customerProfile = CustomerProfile::factory()->create(['user_id' => $customer->id]);
    $agent = User::factory()->agent()->withTwoFactor()->create(['account_state' => AccountState::Active]);
    $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agent->id]);
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customerProfile->id,
        'agent_profile_id' => $agentProfile->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => true,
    ]);

    $this->actingAs($agent)
        ->post(route('customers.name-corrections.store', $customerProfile->customer_id), [
            'name' => 'First Proposed Name',
            'reason' => 'Correct identity spelling',
            'version' => 1,
        ])
        ->assertRedirect(route('customers.show', $customerProfile->customer_id));
    $first = CustomerNameCorrection::query()->sole();

    $this->actingAs($agent)
        ->post(route('customers.name-corrections.store', $customerProfile->customer_id), [
            'name' => 'Confirmed Name',
            'reason' => 'Use the confirmed legal spelling',
            'version' => 1,
        ])
        ->assertRedirect(route('customers.show', $customerProfile->customer_id));
    $second = CustomerNameCorrection::query()->where('status', 'pending')->sole();
    expect($first->refresh()->status)->toBe('replaced')
        ->and($customer->refresh()->name)->not->toBe('Confirmed Name');

    $this->actingAs($customer)
        ->withSession([
            'auth.fresh_until' => now()->timestamp + 600,
            'auth.password_confirmed_at' => now()->timestamp,
        ])
        ->post(route('customers.name-corrections.accept', [$customerProfile->customer_id, $second->id]))
        ->assertRedirect(route('customers.show', $customerProfile->customer_id));

    expect($customer->refresh()->name)->toBe('Confirmed Name')
        ->and($customerProfile->refresh()->version)->toBe(2)
        ->and($second->refresh()->status)->toBe('accepted')
        ->and(ProfileChangeHistory::query()->where('event_type', 'customer.name_correction_accepted')->sole()->changed_fields)->toBe(['name']);
});

test('name proposal is invalidated when its requesting Agent loses assignment before acceptance', function (): void {
    $customer = User::factory()->customer()->create(['account_state' => AccountState::Active]);
    $customerProfile = CustomerProfile::factory()->create(['user_id' => $customer->id]);
    $requestingAgent = User::factory()->agent()->withTwoFactor()->create(['account_state' => AccountState::Active]);
    $requestingProfile = AgentProfile::factory()->active()->create(['user_id' => $requestingAgent->id]);
    $firstAssignment = CustomerAssignment::factory()->create([
        'customer_profile_id' => $customerProfile->id,
        'agent_profile_id' => $requestingProfile->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => true,
    ]);

    $this->actingAs($requestingAgent)
        ->post(route('customers.name-corrections.store', $customerProfile->customer_id), [
            'name' => 'Unapproved Name',
            'reason' => 'Correct identity spelling',
            'version' => 1,
        ])
        ->assertRedirect(route('customers.show', $customerProfile->customer_id));
    $correction = CustomerNameCorrection::query()->sole();

    DB::table('customer_assignments')->where('id', $firstAssignment->id)->update([
        'status' => CustomerAssignmentStatus::Ended->value,
        'is_current' => null,
        'ended_at' => now(),
    ]);
    $newAgent = User::factory()->agent()->withTwoFactor()->create(['account_state' => AccountState::Active]);
    $newAgentProfile = AgentProfile::factory()->active()->create(['user_id' => $newAgent->id]);
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customerProfile->id,
        'agent_profile_id' => $newAgentProfile->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => true,
        'version' => 2,
    ]);

    $this->actingAs($customer)
        ->withSession([
            'auth.fresh_until' => now()->timestamp + 600,
            'auth.password_confirmed_at' => now()->timestamp,
        ])
        ->post(route('customers.name-corrections.accept', [$customerProfile->customer_id, $correction->id]))
        ->assertRedirect(route('customers.show', $customerProfile->customer_id));

    expect($correction->refresh()->status)->toBe('invalidated')
        ->and($customer->refresh()->name)->not->toBe('Unapproved Name');
});

test('email change requires both single-use confirmations and revokes sessions and trusted devices', function (): void {
    Notification::fake();
    $user = User::factory()->customer()->create(['email' => 'current@example.test']);
    $currentEmail = $user->email;

    $this->actingAs($user)
        ->withSession([
            'auth.fresh_until' => now()->timestamp + 600,
            'auth.password_confirmed_at' => now()->timestamp,
        ])
        ->post(route('email-change.store'), ['email' => 'new@example.test'])
        ->assertRedirect(route('profile.edit'));

    $pending = PendingEmailChange::query()->sole();
    expect($user->refresh()->email)->toBe($currentEmail);
    Notification::assertSentOnDemandTimes(EmailChangeNotification::class, 2);
    $sent = Notification::sent(new AnonymousNotifiable, EmailChangeNotification::class);
    $tokens = $sent->map(fn (EmailChangeNotification $notification): string => substr((string) parse_url($notification->actionUrl, PHP_URL_FRAGMENT), 6));
    expect($tokens)->toHaveCount(2);

    $this->get(route('email-change.confirm.show', $pending->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/EmailChangeConfirmation')
            ->missing('available')
            ->missing('email')
            ->missing('token')
            ->where('pending_id', $pending->id));
    $this->get(route('email-change.confirm.show', $pending->id + 10000))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/EmailChangeConfirmation')
            ->missing('available')
            ->missing('email')
            ->missing('token'));
    expect($pending->refresh()->current_confirmed_at)->toBeNull()
        ->and($pending->proposed_confirmed_at)->toBeNull();

    $this->post(route('email-change.confirm', $pending->id), ['token' => $tokens[0]])
        ->assertRedirect(route('email-change.confirm.show', ['pendingEmailChange' => $pending->id, 'confirmed' => 1]));
    expect($user->refresh()->email)->toBe($currentEmail)
        ->and($pending->refresh()->current_confirmed_at)->not->toBeNull();

    $this->from(route('email-change.confirm.show', $pending->id))
        ->post(route('email-change.confirm', $pending->id), ['token' => $tokens[0]])
        ->assertSessionHasErrors('token');

    DB::table('sessions')->insert([
        'id' => 'email-change-session',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Test Browser',
        'payload' => 'a:0:{}',
        'last_activity' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);
    AgentTrustedDevice::create([
        'user_id' => $user->id,
        'device_token_hash' => str_repeat('a', 64),
        'device_name' => 'Trusted browser',
        'trusted_until' => now()->addDays(30),
    ]);

    $this->post(route('email-change.confirm', $pending->id), ['token' => $tokens[1]])
        ->assertRedirect(route('login'));

    expect($user->refresh()->email)->toBe('new@example.test')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($user->trustedDevices()->count())->toBe(0)
        ->and(PendingEmailChange::query()->where('user_id', $user->id)->exists())->toBeFalse();

    $history = ProfileChangeHistory::query()->where('event_type', 'user.email_changed')->sole();
    expect($history->before_values['email'])->toBe($currentEmail)
        ->and($history->after_values['email'])->toBe('new@example.test')
        ->and($history->changed_fields)->toBe(['email']);
    $audit = DB::table('audit_events')->where('event_type', 'user.email_changed')->sole();
    expect($audit->payload)->not->toContain($currentEmail)
        ->and($audit->payload)->not->toContain('new@example.test');
});

test('email change requests are limited to three per account per day', function (): void {
    Notification::fake();
    $user = User::factory()->customer()->create();
    $session = [
        'auth.fresh_until' => now()->timestamp + 600,
        'auth.password_confirmed_at' => now()->timestamp,
    ];

    foreach (['one@example.test', 'two@example.test', 'three@example.test'] as $email) {
        $this->actingAs($user)->withSession($session)
            ->post(route('email-change.store'), ['email' => $email])
            ->assertRedirect(route('profile.edit'));
    }

    $this->actingAs($user)->withSession($session)
        ->post(route('email-change.store'), ['email' => 'four@example.test'])
        ->assertSessionHasErrors('email');

    expect(PendingEmailChange::query()->where('user_id', $user->id)->sole()->proposed_email)
        ->toBe('three@example.test');
    Notification::assertSentOnDemandTimes(EmailChangeNotification::class, 6);
});

test('expired email confirmation clears its proposed-address reservation', function (): void {
    Notification::fake();
    $user = User::factory()->customer()->create(['email' => 'current@example.test']);

    $this->actingAs($user)
        ->withSession([
            'auth.fresh_until' => now()->timestamp + 600,
            'auth.password_confirmed_at' => now()->timestamp,
        ])
        ->post(route('email-change.store'), ['email' => 'reserved@example.test'])
        ->assertRedirect(route('profile.edit'));

    $pending = PendingEmailChange::query()->sole();
    $notification = Notification::sent(new AnonymousNotifiable, EmailChangeNotification::class)->first();
    $token = substr((string) parse_url($notification->actionUrl, PHP_URL_FRAGMENT), 6);

    $this->travel(31)->minutes();
    $this->post(route('email-change.confirm', $pending->id), ['token' => $token])
        ->assertSessionHasErrors('token');
    $this->travelBack();

    expect(PendingEmailChange::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($user->refresh()->email)->toBe('current@example.test');
});

test('Customer can reject a proposal, its requester can cancel one, and expired proposals cannot be accepted', function (): void {
    Queue::fake();
    $customer = User::factory()->customer()->create(['account_state' => AccountState::Active]);
    $customerProfile = CustomerProfile::factory()->create(['user_id' => $customer->id]);
    $agent = User::factory()->agent()->withTwoFactor()->create(['account_state' => AccountState::Active]);
    $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agent->id]);
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customerProfile->id,
        'agent_profile_id' => $agentProfile->id,
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => true,
    ]);

    $this->actingAs($agent)
        ->post(route('customers.name-corrections.store', $customerProfile->customer_id), [
            'name' => 'Rejected Proposal',
            'reason' => 'Correct identity spelling',
            'version' => 1,
        ])
        ->assertRedirect(route('customers.show', $customerProfile->customer_id));
    $rejected = CustomerNameCorrection::query()->sole();

    $this->actingAs($customer)
        ->withSession([
            'auth.fresh_until' => now()->timestamp + 600,
            'auth.password_confirmed_at' => now()->timestamp,
        ])
        ->post(route('customers.name-corrections.reject', [$customerProfile->customer_id, $rejected->id]))
        ->assertRedirect(route('customers.show', $customerProfile->customer_id));
    expect($rejected->refresh()->status)->toBe('rejected');

    $this->actingAs($agent)
        ->post(route('customers.name-corrections.store', $customerProfile->customer_id), [
            'name' => 'Cancelled Proposal',
            'reason' => 'Correct identity spelling',
            'version' => 1,
        ])
        ->assertRedirect(route('customers.show', $customerProfile->customer_id));
    $cancelled = CustomerNameCorrection::query()->where('status', 'pending')->sole();

    $this->actingAs($agent)
        ->post(route('customers.name-corrections.cancel', [$customerProfile->customer_id, $cancelled->id]))
        ->assertRedirect(route('customers.show', $customerProfile->customer_id));
    expect($cancelled->refresh()->status)->toBe('cancelled');

    $this->actingAs($agent)
        ->post(route('customers.name-corrections.store', $customerProfile->customer_id), [
            'name' => 'Expired Proposal',
            'reason' => 'Correct identity spelling',
            'version' => 1,
        ])
        ->assertRedirect(route('customers.show', $customerProfile->customer_id));
    $expired = CustomerNameCorrection::query()->where('status', 'pending')->sole();

    $this->travel(8)->days();
    $this->actingAs($customer)
        ->withSession([
            'auth.fresh_until' => now()->timestamp + 600,
            'auth.password_confirmed_at' => now()->timestamp,
            'auth.login_at' => now()->timestamp,
            'auth.last_active_at' => now()->timestamp,
        ])
        ->post(route('customers.name-corrections.accept', [$customerProfile->customer_id, $expired->id]))
        ->assertRedirect(route('customers.show', $customerProfile->customer_id));
    $this->travelBack();

    expect($expired->refresh()->status)->toBe('expired')
        ->and($customer->refresh()->name)->not->toBe('Expired Proposal');
});
