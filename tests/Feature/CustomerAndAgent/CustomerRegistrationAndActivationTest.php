<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\CreationAttemptStatus;
use App\Enums\CustomerAssignmentStatus;
use App\Enums\CustomerStatus;
use App\Enums\DeliveryStatus;
use App\Enums\FeeObligationStatus;
use App\Enums\FeeRuleModel;
use App\Enums\InvitationStatus;
use App\Enums\UserType;
use App\Jobs\DeliverCustomerInvitationJob;
use App\Models\AgentProfile;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CreationAttempt;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\CustomerStatusHistory;
use App\Models\FeeObligation;
use App\Models\FeeRule;
use App\Models\FeeSnapshot;
use App\Models\Invitation;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\Auth\CustomerInvitationNotification;
use App\Services\InvitationSenderReadinessService;
use App\Services\RegistrationFeeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');

    // Ensure roles exist
    foreach (['admin', 'agent', 'customer'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    }

    // Ensure permissions catalogue is active for tested permissions
    foreach ([AdminPermission::FeesManage, AdminPermission::CustomersManage] as $perm) {
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

/**
 * Helper to publish a registration fee rule.
 */
function publishFeeRule(
    int $amountKobo = 50000,
    FeeRuleModel $model = FeeRuleModel::Fixed,
    string $name = 'Standard Registration Fee',
    string $customerDesc = 'Account onboarding fee.',
    string $reason = 'Approved operational fee.'
): FeeRule {
    $admin = User::factory()->admin()->create();
    $admin->assignRole(UserType::Admin->value);
    $admin->givePermissionTo(AdminPermission::FeesManage->value);

    $request = Request::create(route('admin.fees.registration.store'), 'POST');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put(freshAdminSession());

    return app(RegistrationFeeService::class)->publishRule($admin, [
        'name' => $name,
        'model' => $model,
        'amount_kobo' => $amountKobo,
        'customer_description' => $customerDesc,
        'publication_reason' => $reason,
    ], $request);
}

/**
 * Helper to create an eligible active agent.
 */
function createEligibleAgent(): User
{
    $user = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $user->assignRole(UserType::Agent->value);

    AgentProfile::factory()->active()->create([
        'user_id' => $user->id,
    ]);

    return $user;
}

/**
 * Helper to generate fresh session attributes for Admin.
 */
function freshAdminSession(): array
{
    $now = Carbon::now()->timestamp;

    return [
        'auth.password_confirmed_at' => $now,
        'auth.mfa_confirmed_at' => $now,
        'auth.fresh_until' => $now + 600,
    ];
}

/*
|--------------------------------------------------------------------------
| 1. Admin Registration Fee Rule Management
|--------------------------------------------------------------------------
*/

test('Non-admin or admin without fees.manage cannot view or publish registration fee rules', function (): void {
    $customer = User::factory()->customer()->create();
    $customer->assignRole(UserType::Customer->value);

    $agent = createEligibleAgent();

    $adminWithoutPerm = User::factory()->admin()->create();
    $adminWithoutPerm->assignRole(UserType::Admin->value);

    $this->actingAs($customer)->get(route('admin.fees.registration.index'))->assertForbidden();
    $this->actingAs($agent)->get(route('admin.fees.registration.index'))->assertForbidden();
    $this->actingAs($adminWithoutPerm)->get(route('admin.fees.registration.index'))->assertForbidden();

    $this->actingAs($adminWithoutPerm)
        ->withSession(freshAdminSession())
        ->post(route('admin.fees.registration.store'), [
            'name' => 'Fee 2026',
            'model' => 'fixed',
            'amount_ngn' => 1000,
            'customer_description' => 'Desc',
            'publication_reason' => 'Reason',
        ])->assertForbidden();
});

test('Admin without fresh session is challenged when publishing registration fee rule', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole(UserType::Admin->value);
    $admin->givePermissionTo(AdminPermission::FeesManage->value);

    // No fresh session -> fresh middleware redirects to fresh-authentication
    $response = $this->actingAs($admin)->post(route('admin.fees.registration.store'), [
        'name' => 'Fee 2026',
        'model' => 'fixed',
        'amount_ngn' => 500,
        'customer_description' => 'Onboarding charge',
        'publication_reason' => 'Annual policy update',
    ]);

    $response->assertRedirect(route('fresh-authentication'));
});

test('Admin with fresh session and fees.manage can view and publish fixed registration fee rule', function (int|float|string $amountNgn, int $amountKobo, string $formattedAmount): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole(UserType::Admin->value);
    $admin->givePermissionTo(AdminPermission::FeesManage->value);

    $this->actingAs($admin)
        ->get(route('admin.fees.registration.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/fees/RegistrationFee')
            ->has('current_rule')
            ->has('rules')
        );

    $response = $this->actingAs($admin)
        ->withSession(freshAdminSession())
        ->post(route('admin.fees.registration.store'), [
            'name' => 'Standard Customer Fee 2026',
            'model' => 'fixed',
            'amount_ngn' => $amountNgn,
            'customer_description' => 'Mandatory onboarding charge for new accounts.',
            'publication_reason' => 'Annual governance tariff schedule approval.',
        ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('admin.fees.registration.index'));

    $currentRule = FeeRule::currentRegistration()->first();
    expect($currentRule)->not->toBeNull();
    expect($currentRule->version)->toBe(1);
    expect($currentRule->name)->toBe('Standard Customer Fee 2026');
    expect($currentRule->model)->toBe(FeeRuleModel::Fixed);
    expect($currentRule->amount_kobo)->toBe($amountKobo);
    expect($currentRule->formattedAmount())->toBe($formattedAmount);
    expect($currentRule->published_by_user_id)->toBe($admin->id);

    expect(AuditEvent::query()->where('event_type', 'fee_rule.published')->exists())->toBeTrue();
})->with([
    'numeric whole amount' => [500, 50000, '₦500.00'],
    'numeric decimal amount' => [500.25, 50025, '₦500.25'],
    'numeric minimum amount' => [0.01, 1, '₦0.01'],
    'decimal string amount' => ['500.00', 50000, '₦500.00'],
]);

test('Admin cannot publish a fixed registration fee with an invalid amount', function (mixed $amountNgn): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole(UserType::Admin->value);
    $admin->givePermissionTo(AdminPermission::FeesManage->value);

    $this->actingAs($admin)
        ->withSession(freshAdminSession())
        ->post(route('admin.fees.registration.store'), [
            'name' => 'Standard Customer Fee 2026',
            'model' => 'fixed',
            'amount_ngn' => $amountNgn,
            'customer_description' => 'Mandatory onboarding charge for new accounts.',
            'publication_reason' => 'Approved tariff schedule.',
        ])
        ->assertSessionHasErrors('amount_ngn');

    expect(FeeRule::query()->count())->toBe(0);
    expect(AuditEvent::query()->where('event_type', 'fee_rule.published')->exists())->toBeFalse();
})->with([
    'non-numeric amount' => ['invalid'],
    'negative amount' => [-1],
    'excess decimal places' => [500.001],
    'boolean amount' => [true],
]);

test('Admin can publish explicit zero fee rule', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole(UserType::Admin->value);
    $admin->givePermissionTo(AdminPermission::FeesManage->value);

    $this->actingAs($admin)
        ->withSession(freshAdminSession())
        ->post(route('admin.fees.registration.store'), [
            'name' => 'Zero Fee Promotion',
            'model' => 'no_fee',
            'customer_description' => 'Complimentary account registration.',
            'publication_reason' => 'Q4 growth initiative approved by board.',
        ])
        ->assertRedirect(route('admin.fees.registration.index'));

    $currentRule = FeeRule::currentRegistration()->first();
    expect($currentRule)->not->toBeNull();
    expect($currentRule->model)->toBe(FeeRuleModel::NoFee);
    expect($currentRule->amount_kobo)->toBe(0);
    expect($currentRule->isZero())->toBeTrue();
    expect($currentRule->formattedAmount())->toBe('Free');
});

test('Admin cannot publish a registration fee rule with an explicitly past effective time', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole(UserType::Admin->value);
    $admin->givePermissionTo(AdminPermission::FeesManage->value);

    $this->actingAs($admin)
        ->withSession(freshAdminSession())
        ->post(route('admin.fees.registration.store'), [
            'name' => 'Backdated Registration Fee',
            'model' => 'fixed',
            'amount_ngn' => '500.00',
            'customer_description' => 'Mandatory onboarding charge for new accounts.',
            'publication_reason' => 'Approved tariff schedule.',
            'effective_at' => now()->subMinute()->toDateTimeString(),
        ])
        ->assertSessionHasErrors(['effective_at' => 'Fee rules cannot be published retroactively.']);

    expect(FeeRule::query()->count())->toBe(0);
    expect(AuditEvent::query()->where('event_type', 'fee_rule.published')->exists())->toBeFalse();
});

test('Publishing a new rule atomically retires the prior rule and increments version monotonically', function (): void {
    $ruleV1 = publishFeeRule(amountKobo: 50000, name: 'V1 Fee');
    expect($ruleV1->version)->toBe(1);
    expect($ruleV1->retired_at)->toBeNull();

    $ruleV2 = publishFeeRule(amountKobo: 75000, name: 'V2 Fee');
    expect($ruleV2->version)->toBe(2);
    expect($ruleV2->retired_at)->toBeNull();

    $ruleV1->refresh();
    expect($ruleV1->retired_at)->not->toBeNull();

    $current = FeeRule::currentRegistration()->first();
    expect($current->id)->toBe($ruleV2->id);
    expect($current->version)->toBe(2);
});

test('FeeRule records are immutable and cannot be updated or deleted', function (): void {
    $rule = publishFeeRule();

    expect(fn () => $rule->update(['name' => 'Mutated Name']))
        ->toThrow(RuntimeException::class);

    expect(fn () => $rule->delete())
        ->toThrow(RuntimeException::class);
});

/*
|--------------------------------------------------------------------------
| 2. Customer Registration Authorization & Boundaries
|--------------------------------------------------------------------------
*/

test('Customers and Admins cannot access customer registration form or store customers', function (): void {
    publishFeeRule();

    $customer = User::factory()->customer()->create();
    $customer->assignRole(UserType::Customer->value);

    $admin = User::factory()->admin()->create();
    $admin->assignRole(UserType::Admin->value);
    $admin->givePermissionTo(AdminPermission::CustomersManage->value);

    // Customer creation is Agent-only per CAM specification
    $this->actingAs($customer)->get(route('customers.create'))->assertForbidden();
    $this->actingAs($admin)->get(route('customers.create'))->assertForbidden();

    $payload = [
        'attempt_reference' => (string) Str::uuid(),
        'fee_rule_version' => 1,
        'name' => 'Test Customer',
        'email' => 'customer@test.ng',
        'phone' => '+2348011112222',
    ];

    $this->actingAs($customer)->post(route('customers.store'), $payload)->assertForbidden();
    $this->actingAs($admin)->post(route('customers.store'), $payload)->assertForbidden();
});

test('Inactive or suspended Agent cannot access customer registration or store customers', function (): void {
    publishFeeRule();

    $agentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => Carbon::now(),
    ]);
    $agentUser->assignRole(UserType::Agent->value);

    AgentProfile::factory()->inactive()->create([
        'user_id' => $agentUser->id,
    ]);

    $this->actingAs($agentUser)->get(route('customers.create'))->assertForbidden();

    $payload = [
        'attempt_reference' => (string) Str::uuid(),
        'fee_rule_version' => 1,
        'name' => 'Test Customer',
        'email' => 'customer@test.ng',
        'phone' => '+2348011112222',
    ];

    $this->actingAs($agentUser)->post(route('customers.store'), $payload)->assertForbidden();
});

test('Customer registration fails closed if no active registration fee rule exists', function (): void {
    // No fee rule published
    $agent = createEligibleAgent();

    $this->actingAs($agent)
        ->get(route('customers.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('customers/Create')
            ->where('fee_preview.available', false)
        );

    $this->actingAs($agent)
        ->post(route('customers.store'), [
            'attempt_reference' => (string) Str::uuid(),
            'fee_rule_version' => 1,
            'name' => 'Grace Obi',
            'email' => 'grace@saverapp.test',
            'phone' => '+2348022223333',
        ])
        ->assertStatus(409);
});

test('Customer registration fails closed if submitted fee rule version does not match active rule', function (): void {
    $rule = publishFeeRule(amountKobo: 50000); // Version 1
    $agent = createEligibleAgent();

    $this->actingAs($agent)
        ->post(route('customers.store'), [
            'attempt_reference' => (string) Str::uuid(),
            'fee_rule_version' => 999, // Mismatched version
            'name' => 'Grace Obi',
            'email' => 'grace@saverapp.test',
            'phone' => '+2348022223333',
        ])
        ->assertStatus(409);
});

/*
|--------------------------------------------------------------------------
| 3. Prohibited Fields Defense & Validation
|--------------------------------------------------------------------------
*/

test('Client-supplied server-managed fields are rejected', function (): void {
    $rule = publishFeeRule();
    $agent = createEligibleAgent();

    $prohibited = [
        'operational_status' => 'active',
        'account_state' => 'active',
        'user_type' => 'admin',
        'roles' => ['admin'],
        'customer_id' => 'CUS-999999',
        'fee' => 0,
        'fee_snapshot' => [],
    ];

    foreach ($prohibited as $key => $val) {
        $response = $this->actingAs($agent)
            ->post(route('customers.store'), [
                'attempt_reference' => (string) Str::uuid(),
                'fee_rule_version' => $rule->version,
                'name' => 'Malicious Probe',
                'email' => 'probe@saverapp.test',
                'phone' => '+2348033334444',
                $key => $val,
            ]);

        $response->assertSessionHasErrors([$key]);
    }
});

test('Phone and email uniqueness are enforced', function (): void {
    $rule = publishFeeRule();
    $agent = createEligibleAgent();

    $existingCustomer = CustomerProfile::factory()->create([
        'phone' => '+2348012345678',
    ]);
    $existingUser = $existingCustomer->user;

    // Duplicate email
    $this->actingAs($agent)
        ->post(route('customers.store'), [
            'attempt_reference' => (string) Str::uuid(),
            'fee_rule_version' => $rule->version,
            'name' => 'Duplicate Email',
            'email' => $existingUser->email,
            'phone' => '+2348099998888',
        ])
        ->assertSessionHasErrors(['email']);

    // Duplicate phone
    $this->actingAs($agent)
        ->post(route('customers.store'), [
            'attempt_reference' => (string) Str::uuid(),
            'fee_rule_version' => $rule->version,
            'name' => 'Duplicate Phone',
            'email' => 'unique@saverapp.test',
            'phone' => '+2348012345678',
        ])
        ->assertSessionHasErrors(['phone']);
});

/*
|--------------------------------------------------------------------------
| 4. Atomic Registration Execution & Persistence
|--------------------------------------------------------------------------
*/

test('Eligible Agent successfully registers customer with positive fee rule', function (): void {
    Queue::fake();

    $rule = publishFeeRule(amountKobo: 50000, name: 'Standard NGN 500');
    $agent = createEligibleAgent();
    $agentProfile = $agent->agentProfile;

    $attemptRef = (string) Str::uuid();

    $response = $this->actingAs($agent)
        ->post(route('customers.store'), [
            'attempt_reference' => $attemptRef,
            'fee_rule_version' => $rule->version,
            'name' => 'Chidi Okonkwo',
            'email' => 'chidi.okonkwo@example.ng',
            'phone' => '+2348012345678',
            'address' => '15 Awolowo Road, Ikoyi, Lagos',
            'gender' => 'male',
            'occupation' => 'Architect',
            'internal_reference' => 'BR-001',
            'notes' => 'Referred by branch manager.',
            'next_of_kin' => [
                'full_name' => 'Amaka Okonkwo',
                'relationship' => 'Spouse',
                'phone' => '+2348098765432',
                'address' => '15 Awolowo Road, Ikoyi, Lagos',
            ],
        ]);

    $user = User::where('email_normalized', 'chidi.okonkwo@example.ng')->first();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Chidi Okonkwo');
    expect($user->user_type)->toBe(UserType::Customer);
    expect($user->account_state)->toBe(AccountState::Invited);
    expect($user->email_verified_at)->toBeNull();
    expect($user->password)->toBeNull();

    $customer = CustomerProfile::where('user_id', $user->id)->first();
    expect($customer)->not->toBeNull();
    expect($customer->customer_id)->toStartWith('CUS-');
    expect($customer->operational_status)->toBe(CustomerStatus::Active);
    expect($customer->internal_reference)->toBe('BR-001');
    expect($customer->next_of_kin['full_name'])->toBe('Amaka Okonkwo');

    // Self-assignment
    $assignment = CustomerAssignment::where('customer_profile_id', $customer->id)->first();
    expect($assignment)->not->toBeNull();
    expect($assignment->agent_profile_id)->toBe($agentProfile->id);
    expect($assignment->status)->toBe(CustomerAssignmentStatus::Current);

    // Customer status history
    $history = CustomerStatusHistory::where('customer_profile_id', $customer->id)->first();
    expect($history)->not->toBeNull();
    expect($history->from_status)->toBeNull();
    expect($history->to_status)->toBe(CustomerStatus::Active);
    expect($history->changed_by_user_id)->toBe($agent->id);

    // Fee Snapshot & Obligation
    $snapshot = FeeSnapshot::where('customer_profile_id', $customer->id)->first();
    expect($snapshot)->not->toBeNull();
    expect($snapshot->amount_kobo)->toBe(50000);
    expect($snapshot->currency)->toBe('NGN');
    expect($snapshot->acknowledged_at)->toBeNull();

    $obligation = FeeObligation::where('customer_profile_id', $customer->id)->first();
    expect($obligation)->not->toBeNull();
    expect($obligation->amount_kobo)->toBe(50000);
    expect($obligation->status)->toBe(FeeObligationStatus::Pending);

    // Invitation
    $invitation = Invitation::where('user_id', $user->id)->first();
    expect($invitation)->not->toBeNull();
    expect($invitation->role)->toBe(UserType::Customer->value);
    expect($invitation->status)->toBe(InvitationStatus::PendingDelivery);
    expect($invitation->expires_at->gt(now()->addDays(6)))->toBeTrue();

    // CreationAttempt
    $attempt = CreationAttempt::where('attempt_reference', $attemptRef)->first();
    expect($attempt)->not->toBeNull();
    expect($attempt->status)->toBe(CreationAttemptStatus::Committed);
    expect($attempt->record_id)->toBe($customer->id);

    // Post-commit delivery job queued
    Queue::assertPushed(DeliverCustomerInvitationJob::class, function ($job) use ($invitation) {
        return $job->invitationId === $invitation->id;
    });

    $response->assertRedirect(route('customers.show', $customer->customer_id));
});

test('Customer registration with explicit zero fee creates FeeSnapshot without FeeObligation', function (): void {
    Queue::fake();

    $rule = publishFeeRule(amountKobo: 0, model: FeeRuleModel::NoFee, name: 'Zero Fee');
    $agent = createEligibleAgent();

    $this->actingAs($agent)
        ->post(route('customers.store'), [
            'attempt_reference' => (string) Str::uuid(),
            'fee_rule_version' => $rule->version,
            'name' => 'Free Customer',
            'email' => 'free.customer@saverapp.test',
            'phone' => '+2348077778888',
        ])
        ->assertRedirect();

    $user = User::where('email_normalized', 'free.customer@saverapp.test')->first();
    $customer = CustomerProfile::where('user_id', $user->id)->first();

    $snapshot = FeeSnapshot::where('customer_profile_id', $customer->id)->first();
    expect($snapshot)->not->toBeNull();
    expect($snapshot->amount_kobo)->toBe(0);
    expect($snapshot->isZero())->toBeTrue();

    // No payable obligation generated for explicit zero fee
    expect(FeeObligation::where('customer_profile_id', $customer->id)->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| 5. Idempotent Replay & Creation Attempt Scoping
|--------------------------------------------------------------------------
*/

test('Duplicate submission with identical attempt reference replays existing customer record', function (): void {
    $rule = publishFeeRule();
    $agent = createEligibleAgent();
    $attemptRef = (string) Str::uuid();

    $payload = [
        'attempt_reference' => $attemptRef,
        'fee_rule_version' => $rule->version,
        'name' => 'Idempotent Customer',
        'email' => 'idempotent@saverapp.test',
        'phone' => '+2348011223344',
    ];

    $this->actingAs($agent)->post(route('customers.store'), $payload)->assertRedirect();

    $customerCount = CustomerProfile::count();
    $userCount = User::count();

    // Replay identical submission
    $replayResponse = $this->actingAs($agent)->post(route('customers.store'), $payload);
    $replayResponse->assertRedirect();

    expect(CustomerProfile::count())->toBe($customerCount);
    expect(User::count())->toBe($userCount);
});

test('Duplicate submission with identical attempt reference but mutated payload returns 409 conflict', function (): void {
    $rule = publishFeeRule();
    $agent = createEligibleAgent();
    $attemptRef = (string) Str::uuid();

    $payload = [
        'attempt_reference' => $attemptRef,
        'fee_rule_version' => $rule->version,
        'name' => 'Initial Customer',
        'email' => 'initial@saverapp.test',
        'phone' => '+2348011223355',
    ];

    $this->actingAs($agent)->post(route('customers.store'), $payload)->assertRedirect();

    $mutatedPayload = array_merge($payload, [
        'email' => 'mutated@saverapp.test',
    ]);

    $this->actingAs($agent)
        ->post(route('customers.store'), $mutatedPayload)
        ->assertStatus(409);
});

test('Attempt lookup endpoint returns status to owning agent and denies other users', function (): void {
    $rule = publishFeeRule();
    $agent1 = createEligibleAgent();
    $agent2 = createEligibleAgent();
    $attemptRef = (string) Str::uuid();

    $this->actingAs($agent1)->post(route('customers.store'), [
        'attempt_reference' => $attemptRef,
        'fee_rule_version' => $rule->version,
        'name' => 'Lookup Customer',
        'email' => 'lookup@saverapp.test',
        'phone' => '+2348011223366',
    ])->assertRedirect();

    $this->actingAs($agent1)
        ->getJson(route('customers.attempts.show', $attemptRef))
        ->assertOk()
        ->assertJson([
            'status' => 'committed',
        ]);

    $this->actingAs($agent2)
        ->getJson(route('customers.attempts.show', $attemptRef))
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| 6. Post-Commit Delivery Job & Notification
|--------------------------------------------------------------------------
*/

test('DeliverCustomerInvitationJob sends notification with fee terms and challenge token', function (): void {
    Notification::fake();
    Queue::fake();

    $rule = publishFeeRule(amountKobo: 50000);
    $agent = createEligibleAgent();

    $this->actingAs($agent)->post(route('customers.store'), [
        'attempt_reference' => (string) Str::uuid(),
        'fee_rule_version' => $rule->version,
        'name' => 'Delivery Customer',
        'email' => 'delivery@saverapp.test',
        'phone' => '+2348011223377',
    ]);

    $invitation = Invitation::where('target_email_normalized', 'delivery@saverapp.test')->first();
    expect($invitation)->not->toBeNull();

    $plainToken = 'test-plain-token-123';
    $invitation->token_hash = hash('sha256', $plainToken);
    $invitation->save();

    $job = new DeliverCustomerInvitationJob($invitation->id, $plainToken, 1);
    $job->handle(app(InvitationSenderReadinessService::class));

    $invitation->refresh();
    expect($invitation->status)->toBe(InvitationStatus::Sent);
    expect($invitation->delivery_status)->toBe(DeliveryStatus::Sent);
    expect($invitation->sent_at)->not->toBeNull();

    Notification::assertSentOnDemand(
        CustomerInvitationNotification::class,
        function ($notification, $channels, $notifiable) use ($invitation, $plainToken) {
            expect($notifiable->routes['mail'])->toBe($invitation->target_email);
            expect($notification->plainToken)->toBe($plainToken);
            expect($notification->feeFormatted)->toBe('₦500.00');

            return true;
        }
    );

    expect(AuditEvent::query()->where('event_type', 'invitation.sent')->exists())->toBeTrue();
});

test('DeliverCustomerInvitationJob suppresses delivery for stale or cancelled invitations', function (): void {
    Notification::fake();
    Queue::fake();

    $rule = publishFeeRule();
    $agent = createEligibleAgent();

    $this->actingAs($agent)->post(route('customers.store'), [
        'attempt_reference' => (string) Str::uuid(),
        'fee_rule_version' => $rule->version,
        'name' => 'Cancelled Customer',
        'email' => 'cancelled@saverapp.test',
        'phone' => '+2348011223388',
    ]);

    $invitation = Invitation::where('target_email_normalized', 'cancelled@saverapp.test')->first();
    expect($invitation)->not->toBeNull();

    $invitation->status = InvitationStatus::Cancelled;
    $invitation->save();

    $job = new DeliverCustomerInvitationJob($invitation->id, 'any-token', 1);
    $job->handle(app(InvitationSenderReadinessService::class));

    Notification::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| 7. Customer Invitation Management (Resend, Correct Email, Cancel)
|--------------------------------------------------------------------------
*/

test('Assigned Agent and Admin with customers.manage can resend invitation', function (): void {
    Queue::fake();

    $rule = publishFeeRule();
    $agent = createEligibleAgent();

    $this->actingAs($agent)->post(route('customers.store'), [
        'attempt_reference' => (string) Str::uuid(),
        'fee_rule_version' => $rule->version,
        'name' => 'Resend Customer',
        'email' => 'resend@saverapp.test',
        'phone' => '+2348011223399',
    ]);

    $user = User::where('email_normalized', 'resend@saverapp.test')->first();
    $customer = CustomerProfile::where('user_id', $user->id)->first();
    $invitation = Invitation::where('user_id', $user->id)->first();

    // Fast-forward past initial 1-minute cooldown
    $invitation->created_at = now()->subMinutes(2);
    $invitation->save();

    // Assigned Agent resends
    $this->actingAs($agent)
        ->from(route('customers.show', $customer->customer_id))
        ->post(route('customers.invitations.resend', $customer->customer_id))
        ->assertRedirect(route('customers.show', $customer->customer_id));

    $latestInvitation = Invitation::where('user_id', $user->id)->latest('generation')->first();
    expect($latestInvitation)->not->toBeNull();
    expect($latestInvitation->generation)->toBe(2);
    expect($latestInvitation->status)->toBe(InvitationStatus::PendingDelivery);

    $invitation->refresh();
    expect($invitation->status)->toBe(InvitationStatus::Cancelled);

    // Original FeeSnapshot and FeeObligation are preserved
    expect(FeeSnapshot::where('customer_profile_id', $customer->id)->count())->toBe(1);
    expect(FeeObligation::where('customer_profile_id', $customer->id)->count())->toBe(1);
});

test('Resend invitation enforces 1 per minute cooldown and 5 per day rate limit', function (): void {
    Queue::fake();

    $rule = publishFeeRule();
    $agent = createEligibleAgent();

    $this->actingAs($agent)->post(route('customers.store'), [
        'attempt_reference' => (string) Str::uuid(),
        'fee_rule_version' => $rule->version,
        'name' => 'Rate Limited Customer',
        'email' => 'ratelimit@saverapp.test',
        'phone' => '+2348011223300',
    ]);

    $user = User::where('email_normalized', 'ratelimit@saverapp.test')->first();
    $customer = CustomerProfile::where('user_id', $user->id)->first();

    // 1-minute cooldown hit on immediate second attempt (created_at is now)
    $this->actingAs($agent)
        ->from(route('customers.show', $customer->customer_id))
        ->post(route('customers.invitations.resend', $customer->customer_id))
        ->assertSessionHasErrors(['resend']);

    // 5 per day rate limit: create 5 invitations today
    $invitation = Invitation::where('user_id', $user->id)->first();
    $invitation->created_at = now()->subMinutes(2);
    $invitation->save();

    for ($i = 0; $i < 4; $i++) {
        Invitation::create([
            'user_id' => $user->id,
            'target_email' => $user->email,
            'target_email_normalized' => $user->email_normalized,
            'role' => UserType::Customer->value,
            'token_hash' => hash('sha256', "extra-token-{$i}"),
            'generation' => $i + 2,
            'status' => InvitationStatus::Cancelled,
            'delivery_status' => DeliveryStatus::Sent,
            'expires_at' => now()->addDays(7),
            'invited_by_user_id' => $agent->id,
            'created_at' => now()->subMinutes(2),
        ]);
    }

    $this->actingAs($agent)
        ->from(route('customers.show', $customer->customer_id))
        ->post(route('customers.invitations.resend', $customer->customer_id))
        ->assertSessionHasErrors(['resend']);
});

test('Unrelated Agent cannot resend, correct email, or cancel another agents customer invitation', function (): void {
    $rule = publishFeeRule();
    $agent1 = createEligibleAgent();
    $agent2 = createEligibleAgent();

    $this->actingAs($agent1)->post(route('customers.store'), [
        'attempt_reference' => (string) Str::uuid(),
        'fee_rule_version' => $rule->version,
        'name' => 'Guarded Customer',
        'email' => 'guarded@saverapp.test',
        'phone' => '+2348011223301',
    ]);

    $customer = CustomerProfile::where('phone', '+2348011223301')->first();

    $this->actingAs($agent2)
        ->post(route('customers.invitations.resend', $customer->customer_id))
        ->assertNotFound();

    $this->actingAs($agent2)
        ->post(route('customers.invitations.correct-email', $customer->customer_id), [
            'email' => 'new.guarded@saverapp.test',
            'reason' => 'Unauthorized attempt',
        ])
        ->assertNotFound();

    $this->actingAs($agent2)
        ->post(route('customers.invitations.cancel', $customer->customer_id), [
            'reason' => 'Unauthorized cancel',
        ])
        ->assertNotFound();
});

test('Assigned Agent can correct customer email, dispatching new invitation and preserving fee terms', function (): void {
    Queue::fake();

    $rule = publishFeeRule(amountKobo: 50000);
    $agent = createEligibleAgent();

    $this->actingAs($agent)->post(route('customers.store'), [
        'attempt_reference' => (string) Str::uuid(),
        'fee_rule_version' => $rule->version,
        'name' => 'Typo Customer',
        'email' => 'typo@saverapp.test',
        'phone' => '+2348011223302',
    ]);

    $user = User::where('email_normalized', 'typo@saverapp.test')->first();
    $customer = CustomerProfile::where('user_id', $user->id)->first();
    $initialInvitation = Invitation::where('user_id', $user->id)->first();

    $response = $this->actingAs($agent)
        ->from(route('customers.show', $customer->customer_id))
        ->post(
            route('customers.invitations.correct-email', $customer->customer_id),
            [
                'email' => 'corrected@saverapp.test',
                'reason' => 'Typo in domain suffix corrected by customer request.',
            ]
        );

    $response->assertRedirect(route('customers.show', $customer->customer_id));

    $user->refresh();
    expect($user->email)->toBe('corrected@saverapp.test');
    expect($user->email_normalized)->toBe('corrected@saverapp.test');

    $initialInvitation->refresh();
    expect($initialInvitation->status)->toBe(InvitationStatus::Cancelled);

    $newInvitation = Invitation::where('user_id', $user->id)->latest('generation')->first();
    expect($newInvitation->id)->not->toBe($initialInvitation->id);
    expect($newInvitation->target_email_normalized)->toBe('corrected@saverapp.test');
    expect($newInvitation->status)->toBe(InvitationStatus::PendingDelivery);

    // Fee terms preserved
    expect(FeeSnapshot::where('customer_profile_id', $customer->id)->count())->toBe(1);
    expect(FeeObligation::where('customer_profile_id', $customer->id)->count())->toBe(1);
});

test('Assigned Agent can cancel customer invitation, preserving fee terms', function (): void {
    $rule = publishFeeRule();
    $agent = createEligibleAgent();

    $this->actingAs($agent)->post(route('customers.store'), [
        'attempt_reference' => (string) Str::uuid(),
        'fee_rule_version' => $rule->version,
        'name' => 'To Cancel Customer',
        'email' => 'tocancel@saverapp.test',
        'phone' => '+2348011223303',
    ]);

    $user = User::where('email_normalized', 'tocancel@saverapp.test')->first();
    $customer = CustomerProfile::where('user_id', $user->id)->first();
    $invitation = Invitation::where('user_id', $user->id)->first();

    $this->actingAs($agent)
        ->from(route('customers.show', $customer->customer_id))
        ->post(
            route('customers.invitations.cancel', $customer->customer_id),
            ['reason' => 'Customer withdrew registration request.']
        )->assertRedirect(route('customers.show', $customer->customer_id));

    $invitation->refresh();
    expect($invitation->status)->toBe(InvitationStatus::Cancelled);
    expect($invitation->cancelled_at)->not->toBeNull();

    // Fee terms preserved
    expect(FeeSnapshot::where('customer_profile_id', $customer->id)->count())->toBe(1);
    expect(FeeObligation::where('customer_profile_id', $customer->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| 8. Public Customer Activation
|--------------------------------------------------------------------------
*/

test('Public customer activation page renders fee terms disclosure and records opened event', function (): void {
    $rule = publishFeeRule(amountKobo: 50000, name: 'Standard Activation Fee');
    $agent = createEligibleAgent();

    $this->actingAs($agent)->post(route('customers.store'), [
        'attempt_reference' => (string) Str::uuid(),
        'fee_rule_version' => $rule->version,
        'name' => 'Public Activation Customer',
        'email' => 'public.act@saverapp.test',
        'phone' => '+2348011223304',
    ]);

    $invitation = Invitation::where('target_email_normalized', 'public.act@saverapp.test')->first();
    expect($invitation)->not->toBeNull();

    $plainToken = Str::random(64);
    $invitation->token_hash = hash('sha256', $plainToken);
    $invitation->save();

    Auth::logout();

    $response = $this->get(route('invitations.customer.show', $plainToken));
    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/CustomerActivation')
            ->where('status', 'ready')
            ->where('name', 'Public Activation Customer')
            ->where('fee_snapshot.formatted_amount', '₦500.00')
            ->where('fee_snapshot.is_zero', false)
        );

    $invitation->refresh();
    expect($invitation->status)->toBe(InvitationStatus::Opened);
    expect($invitation->opened_at)->not->toBeNull();

    expect(AuditEvent::query()->where('event_type', 'invitation.opened')->exists())->toBeTrue();
});

test('Customer activation requires fee acknowledgement and 15-character password', function (): void {
    $rule = publishFeeRule(amountKobo: 50000);
    $agent = createEligibleAgent();

    $this->actingAs($agent)->post(route('customers.store'), [
        'attempt_reference' => (string) Str::uuid(),
        'fee_rule_version' => $rule->version,
        'name' => 'Validation Customer',
        'email' => 'val.customer@saverapp.test',
        'phone' => '+2348011223305',
    ]);

    $invitation = Invitation::where('target_email_normalized', 'val.customer@saverapp.test')->first();
    $plainToken = Str::random(64);
    $invitation->token_hash = hash('sha256', $plainToken);
    $invitation->save();

    Auth::logout();

    // Missing fee acknowledgement
    $this->post(route('invitations.customer.activate', $plainToken), [
        'fee_acknowledged' => false,
        'password' => 'ValidPassword123456789!',
        'password_confirmation' => 'ValidPassword123456789!',
    ])->assertSessionHasErrors(['fee_acknowledged']);

    // Password too short (< 15 chars for Customer per PasswordPolicy)
    $this->post(route('invitations.customer.activate', $plainToken), [
        'fee_acknowledged' => true,
        'password' => 'Short1234!',
        'password_confirmation' => 'Short1234!',
    ])->assertSessionHasErrors(['password']);
});

test('Customer activation completes account setup, sets password, marks active, and logs in', function (): void {
    $rule = publishFeeRule(amountKobo: 50000);
    $agent = createEligibleAgent();

    $this->actingAs($agent)->post(route('customers.store'), [
        'attempt_reference' => (string) Str::uuid(),
        'fee_rule_version' => $rule->version,
        'name' => 'Active Customer',
        'email' => 'active.customer@saverapp.test',
        'phone' => '+2348011223306',
    ]);

    $user = User::where('email_normalized', 'active.customer@saverapp.test')->first();
    $customer = CustomerProfile::where('user_id', $user->id)->first();
    $invitation = Invitation::where('user_id', $user->id)->first();
    $snapshot = FeeSnapshot::where('customer_profile_id', $customer->id)->first();

    $plainToken = Str::random(64);
    $invitation->token_hash = hash('sha256', $plainToken);
    $invitation->save();

    Auth::logout();

    $password = 'SecurePasswordForCustomer2026!';

    $response = $this->post(route('invitations.customer.activate', $plainToken), [
        'fee_acknowledged' => true,
        'password' => $password,
        'password_confirmation' => $password,
    ]);

    $response->assertRedirect(route('customer.dashboard'));

    $user->refresh();
    expect($user->account_state)->toBe(AccountState::Active);
    expect($user->email_verified_at)->not->toBeNull();
    expect(Hash::check($password, $user->password))->toBeTrue();

    $invitation->refresh();
    expect($invitation->status)->toBe(InvitationStatus::Activated);
    expect($invitation->activated_at)->not->toBeNull();

    $snapshot->refresh();
    expect($snapshot->acknowledged_at)->not->toBeNull();

    // Customer is logged in to web guard
    expect(Auth::check())->toBeTrue();
    expect(Auth::id())->toBe($user->id);

    expect(AuditEvent::query()->where('event_type', 'customer.activated')->exists())->toBeTrue();
});
