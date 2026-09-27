<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\CustomerActivity;
use App\Enums\CustomerAssignmentStatus;
use App\Enums\CustomerStatus;
use App\Enums\UserType;
use App\Jobs\DeliverCustomerStatusNotificationIntent;
use App\Models\AgentProfile;
use App\Models\AuditEvent;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\CustomerStatusHistory;
use App\Models\CustomerStatusNotificationIntent;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\CustomerStatusNotification;
use App\Services\AgentEligibilityService;
use App\Services\CustomerActivityGate;
use App\Services\CustomerStatusManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach ([UserType::Admin, UserType::Agent, UserType::Customer] as $userType) {
        Role::firstOrCreate(['name' => $userType->value, 'guard_name' => 'web']);
    }

    Permission::firstOrCreate(
        ['name' => AdminPermission::CustomersManage->value, 'guard_name' => 'web'],
        ['status' => 'active'],
    );
});

function makeStatusManager(bool $withPermission = true): User
{
    $admin = User::factory()->admin()->create();
    $admin->assignRole(UserType::Admin->value);

    if ($withPermission) {
        $admin->givePermissionTo(AdminPermission::CustomersManage->value);
    }

    return $admin;
}

function assignEligibleStatusAgent(CustomerProfile $customer, User $admin): AgentProfile
{
    $agentUser = User::factory()->agent()->withTwoFactor()->create();
    $agentUser->assignRole(UserType::Agent->value);
    $agent = AgentProfile::factory()->active()->create(['user_id' => $agentUser->id]);

    CustomerAssignment::create([
        'customer_profile_id' => $customer->id,
        'agent_profile_id' => $agent->id,
        'assigned_by_user_id' => $admin->id,
        'reason' => 'Current service assignment',
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => 1,
        'effective_at' => now(),
        'version' => 1,
    ]);

    return $agent;
}

function customerStatusPayload(CustomerProfile $customer, string $status, array $overrides = []): array
{
    return array_merge([
        'target_status' => $status,
        'version' => $customer->version,
        'confirmed' => true,
        'reason' => 'Approved status review.',
        'customer_explanation' => 'We reviewed your account status and updated it.',
    ], $overrides);
}

test('Admin with customers.manage can perform every supported Customer status transition', function (): void {
    $admin = makeStatusManager();

    $transitions = [
        [CustomerStatus::Active, CustomerStatus::Inactive],
        [CustomerStatus::Active, CustomerStatus::Restricted],
        [CustomerStatus::Inactive, CustomerStatus::Restricted],
        [CustomerStatus::Inactive, CustomerStatus::Active],
        [CustomerStatus::Restricted, CustomerStatus::Active],
        [CustomerStatus::Restricted, CustomerStatus::Inactive],
    ];

    foreach ($transitions as [$from, $to]) {
        $customer = CustomerProfile::factory()->create(['operational_status' => $from]);
        if ($to === CustomerStatus::Active) {
            assignEligibleStatusAgent($customer, $admin);
        }

        $this->actingAs($admin)
            ->patch(route('customers.status.update', $customer->customer_id), customerStatusPayload($customer, $to->value))
            ->assertRedirect(route('customers.status.edit', $customer->customer_id));

        $customer->refresh();
        expect($customer->operational_status)->toBe($to)
            ->and($customer->version)->toBe(2)
            ->and($customer->statusHistories()->count())->toBe(1);
    }
});

test('status changes keep access and assignment separate and atomically record redacted audit and notifications', function (): void {
    Notification::fake();
    $admin = makeStatusManager();
    $customer = CustomerProfile::factory()->create(['operational_status' => CustomerStatus::Active]);
    $agent = assignEligibleStatusAgent($customer, $admin);
    $customerUser = $customer->user;
    $originalPassword = $customerUser->password;
    $assignment = $customer->currentAssignment;

    $this->actingAs($admin)
        ->patch(route('customers.status.update', $customer->customer_id), customerStatusPayload($customer, 'restricted', [
            'reason' => 'Private investigation reference 481.',
            'customer_explanation' => 'A temporary review is in progress.',
        ]))
        ->assertRedirect(route('customers.status.edit', $customer->customer_id));

    $customer->refresh();
    $customerUser->refresh();
    $history = $customer->statusHistories()->firstOrFail();
    $audit = AuditEvent::query()->where('event_type', 'customer.status_changed')->firstOrFail();

    expect($customer->operational_status)->toBe(CustomerStatus::Restricted)
        ->and($customer->version)->toBe(2)
        ->and($customerUser->account_state)->toBe(AccountState::Active)
        ->and($customerUser->password)->toBe($originalPassword)
        ->and($customer->currentAssignment->id)->toBe($assignment->id)
        ->and($customer->currentAssignment->agent_profile_id)->toBe($agent->id)
        ->and($history->reason)->toBe('Private investigation reference 481.')
        ->and($history->customer_facing_explanation)->toBe('A temporary review is in progress.')
        ->and($history->audit_event_id)->toBe($audit->id)
        ->and($audit->payload)->toMatchArray([
            'from_status' => 'active',
            'to_status' => 'restricted',
            'from_version' => 1,
            'to_version' => 2,
        ])
        ->and(json_encode($audit->payload))->not->toContain('481')
        ->and(CustomerStatusNotificationIntent::query()->where('customer_status_history_id', $history->id)->count())->toBe(3);

    $mailIntent = CustomerStatusNotificationIntent::query()
        ->where('customer_status_history_id', $history->id)
        ->where('channel', 'mail')
        ->firstOrFail();
    $mailJob = new DeliverCustomerStatusNotificationIntent($mailIntent->id);
    $mailJob->handle(app(AgentEligibilityService::class));
    $mailJob->handle(app(AgentEligibilityService::class));

    $databaseIntent = CustomerStatusNotificationIntent::query()
        ->where('customer_status_history_id', $history->id)
        ->where('audience_type', 'subject_customer')
        ->where('channel', 'database')
        ->firstOrFail();
    (new DeliverCustomerStatusNotificationIntent($databaseIntent->id))
        ->handle(app(AgentEligibilityService::class));

    expect($mailIntent->fresh()->status)->toBe('delivered')
        ->and($databaseIntent->fresh()->status)->toBe('delivered');
    Notification::assertSentToTimes($customerUser, CustomerStatusNotification::class, 1);
    expect($customerUser->notifications()->whereKey($databaseIntent->notification_id)->exists())->toBeTrue();
});

test('invited Customer retains an inaccessible in-app notice alongside status email', function (): void {
    $admin = makeStatusManager();
    $customer = CustomerProfile::factory()->create();
    $customer->user->forceFill(['account_state' => AccountState::Invited])->save();

    $this->actingAs($admin)
        ->patch(route('customers.status.update', $customer->customer_id), customerStatusPayload($customer, 'restricted'))
        ->assertRedirect(route('customers.status.edit', $customer->customer_id));

    $history = $customer->statusHistories()->firstOrFail();
    expect($history->notificationIntents()->pluck('channel')->sort()->values()->all())->toBe(['database', 'mail']);
});

test('current eligible Agent is required to move a Customer into Active', function (): void {
    $admin = makeStatusManager();
    $customer = CustomerProfile::factory()->inactive()->create();

    $this->actingAs($admin)
        ->patch(route('customers.status.update', $customer->customer_id), customerStatusPayload($customer, 'active'))
        ->assertSessionHasErrors('target_status');

    $inactiveAgentUser = User::factory()->agent()->withTwoFactor()->create();
    $inactiveAgentUser->assignRole(UserType::Agent->value);
    $inactiveAgent = AgentProfile::factory()->inactive()->create(['user_id' => $inactiveAgentUser->id]);
    CustomerAssignment::create([
        'customer_profile_id' => $customer->id,
        'agent_profile_id' => $inactiveAgent->id,
        'assigned_by_user_id' => $admin->id,
        'reason' => 'Current service assignment',
        'status' => CustomerAssignmentStatus::Current,
        'is_current' => 1,
        'effective_at' => now(),
        'version' => 1,
    ]);

    $this->actingAs($admin)
        ->patch(route('customers.status.update', $customer->customer_id), customerStatusPayload($customer, 'active'))
        ->assertSessionHasErrors('target_status');

    expect($customer->fresh()->operational_status)->toBe(CustomerStatus::Inactive)
        ->and($customer->statusHistories()->count())->toBe(0);
});

test('status endpoints deny unauthorized actors and do not accept archival through this workflow', function (): void {
    $adminWithoutPermission = makeStatusManager(false);
    $customer = CustomerProfile::factory()->create();

    $this->actingAs($adminWithoutPermission)
        ->get(route('customers.status.edit', $customer->customer_id))
        ->assertForbidden();
    $this->actingAs($adminWithoutPermission)
        ->patch(route('customers.status.update', $customer->customer_id), customerStatusPayload($customer, 'inactive'))
        ->assertForbidden();

    $customerUser = $customer->user;
    $customerUser->assignRole(UserType::Customer->value);
    $this->actingAs($customerUser)
        ->patch(route('customers.status.update', $customer->customer_id), customerStatusPayload($customer, 'inactive'))
        ->assertForbidden();

    $agentAdmin = makeStatusManager();
    $assignedAgent = assignEligibleStatusAgent($customer, $agentAdmin);
    $this->actingAs($assignedAgent->user)
        ->patch(route('customers.status.update', $customer->customer_id), customerStatusPayload($customer, 'inactive'))
        ->assertForbidden();

    $admin = makeStatusManager();
    $this->actingAs($admin)
        ->patch(route('customers.status.update', $customer->customer_id), customerStatusPayload($customer, 'archived'))
        ->assertSessionHasErrors('target_status');

    $archived = CustomerProfile::factory()->archived()->create();
    $this->actingAs($admin)
        ->get(route('customers.status.edit', $archived->customer_id))
        ->assertOk();

    expect($customer->fresh()->operational_status)->toBe(CustomerStatus::Active)
        ->and($customer->statusHistories()->count())->toBe(0);
});

test('permission is rechecked under the status transition lock and rollback leaves no partial change', function (): void {
    $admin = makeStatusManager();
    $customer = CustomerProfile::factory()->create();
    $admin->revokePermissionTo(AdminPermission::CustomersManage->value);

    expect(fn () => app(CustomerStatusManagementService::class)->transition(
        actor: $admin,
        customer: $customer,
        targetStatus: CustomerStatus::Inactive,
        expectedVersion: $customer->version,
        reason: 'Approved review.',
        customerExplanation: 'Participation is paused.',
    ))->toThrow(AuthorizationException::class);

    expect($customer->fresh()->operational_status)->toBe(CustomerStatus::Active)
        ->and($customer->fresh()->version)->toBe(1)
        ->and($customer->statusHistories()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', 'customer.status_changed')->count())->toBe(0);

    $authorizedAdmin = makeStatusManager();
    CustomerStatusNotificationIntent::creating(static function (): void {
        throw new RuntimeException('Simulated intent persistence failure.');
    });

    try {
        expect(fn () => app(CustomerStatusManagementService::class)->transition(
            actor: $authorizedAdmin,
            customer: $customer,
            targetStatus: CustomerStatus::Restricted,
            expectedVersion: $customer->version,
            reason: 'Approved review.',
            customerExplanation: 'A temporary review is in progress.',
        ))->toThrow(RuntimeException::class, 'Simulated intent persistence failure.');
    } finally {
        CustomerStatusNotificationIntent::flushEventListeners();
    }

    expect($customer->fresh()->operational_status)->toBe(CustomerStatus::Active)
        ->and($customer->fresh()->version)->toBe(1)
        ->and($customer->statusHistories()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', 'customer.status_changed')->count())->toBe(0)
        ->and(CustomerStatusNotificationIntent::query()->count())->toBe(0);
});

test('stale versions fail and unchanged status is a no-op', function (): void {
    $admin = makeStatusManager();
    $customer = CustomerProfile::factory()->create();

    $this->actingAs($admin)
        ->patch(route('customers.status.update', $customer->customer_id), customerStatusPayload($customer, 'inactive', ['version' => 99]))
        ->assertSessionHasErrors('version');

    $this->actingAs($admin)
        ->patch(route('customers.status.update', $customer->customer_id), customerStatusPayload($customer, 'active'))
        ->assertRedirect(route('customers.status.edit', $customer->customer_id));

    expect($customer->fresh()->version)->toBe(1)
        ->and($customer->statusHistories()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', 'customer.status_changed')->count())->toBe(0)
        ->and(CustomerStatusNotificationIntent::query()->count())->toBe(0);
});

test('status page shows separate account state and retained financial history while profile viewers see no internal reason', function (): void {
    $admin = makeStatusManager();
    $customer = CustomerProfile::factory()->create();
    $this->actingAs($admin)
        ->patch(route('customers.status.update', $customer->customer_id), customerStatusPayload($customer, 'restricted', [
            'reason' => 'Admin-only internal note.',
            'customer_explanation' => 'A temporary review is in progress.',
        ]));

    $this->actingAs($admin)
        ->get(route('customers.status.edit', $customer->customer_id))
        ->assertInertia(fn (Assert $page) => $page
            ->component('customers/Status')
            ->where('customer.account_state', 'active')
            ->where('customer.operational_status', 'restricted')
            ->where('financial_sections.plans', 'Plan schedules and contribution progress remain available from the Customer profile.')
            ->where('financial_sections.withdrawals', 'Withdrawal request history is available; payout execution awaits an approved method.')
            ->where('history.0.reason', 'Admin-only internal note.')
            ->where('history.0.customer_explanation', 'A temporary review is in progress.'));

    $customerUser = $customer->user;
    $customerUser->assignRole(UserType::Customer->value);
    $this->actingAs($customerUser)
        ->get(route('customers.show', $customer->customer_id))
        ->assertInertia(fn (Assert $page) => $page
            ->component('customers/Show')
            ->where('customer.status_explanation.explanation', 'A temporary review is in progress.')
            ->missing('customer.status_history')
            ->missing('customer.internal_reason'));
});

test('Customer activity rules block and allow the Section 5 operations by current status', function (): void {
    $gate = app(CustomerActivityGate::class);

    expect($gate->allows(CustomerStatus::Active, CustomerActivity::CreatePlan))->toBeTrue()
        ->and($gate->allows(CustomerStatus::Inactive, CustomerActivity::CreatePlan))->toBeFalse()
        ->and($gate->allows(CustomerStatus::Inactive, CustomerActivity::InitiateWithdrawal))->toBeTrue()
        ->and($gate->allows(CustomerStatus::Inactive, CustomerActivity::AssessDiscretionaryFee))->toBeFalse()
        ->and($gate->allows(CustomerStatus::Restricted, CustomerActivity::PostPayout))->toBeFalse()
        ->and($gate->allows(CustomerStatus::Restricted, CustomerActivity::PostCorrectiveReversal))->toBeTrue()
        ->and($gate->allows(CustomerStatus::Restricted, CustomerActivity::ReconcileHistoricalCollections))->toBeTrue()
        ->and($gate->allows(CustomerStatus::Archived, CustomerActivity::PostCorrectiveReversal))->toBeFalse();

    $inactiveCustomer = CustomerProfile::factory()->inactive()->create();
    expect(fn () => DB::transaction(fn () => $gate->assertAllowed($inactiveCustomer, CustomerActivity::CreatePlan)))
        ->toThrow(ValidationException::class);

    $lockedCustomer = DB::transaction(fn () => $gate->assertAllowed($inactiveCustomer, CustomerActivity::InitiateWithdrawal));
    expect($lockedCustomer->id)->toBe($inactiveCustomer->id)
        ->and($lockedCustomer->operational_status)->toBe(CustomerStatus::Inactive);
});

test('dated activity queries use ordered status history and return null without history', function (): void {
    $customer = CustomerProfile::factory()->create();
    $actor = User::factory()->admin()->create();
    $firstTime = Carbon::parse('2026-09-20 10:00:00', 'UTC');
    $secondTime = Carbon::parse('2026-09-21 10:00:00', 'UTC');

    CustomerStatusHistory::create([
        'customer_profile_id' => $customer->id,
        'from_status' => null,
        'to_status' => CustomerStatus::Active,
        'reason' => 'Initial status',
        'changed_by_user_id' => $actor->id,
        'created_at' => $firstTime,
    ]);
    CustomerStatusHistory::create([
        'customer_profile_id' => $customer->id,
        'from_status' => CustomerStatus::Active,
        'to_status' => CustomerStatus::Inactive,
        'reason' => 'Participation pause',
        'changed_by_user_id' => $actor->id,
        'created_at' => $secondTime,
    ]);

    $gate = app(CustomerActivityGate::class);
    expect($gate->statusAt($customer, $firstTime->copy()->addHours(1)))->toBe(CustomerStatus::Active)
        ->and($gate->statusAt($customer, $secondTime->copy()->addHours(1)))->toBe(CustomerStatus::Inactive);

    $untrackedCustomer = CustomerProfile::factory()->create();
    expect($gate->statusAt($untrackedCustomer, Carbon::now()))->toBeNull();
});

test('notification delivery suppresses a former Agent after assignment scope is lost', function (): void {
    Notification::fake();
    $admin = makeStatusManager();
    $customer = CustomerProfile::factory()->create();
    $oldAgentUser = User::factory()->agent()->withTwoFactor()->create();
    $oldAgentUser->assignRole(UserType::Agent->value);
    AgentProfile::factory()->active()->create(['user_id' => $oldAgentUser->id]);
    $history = CustomerStatusHistory::create([
        'customer_profile_id' => $customer->id,
        'from_status' => CustomerStatus::Active,
        'to_status' => CustomerStatus::Restricted,
        'reason' => 'Internal reason.',
        'customer_facing_explanation' => 'A temporary review is in progress.',
        'changed_by_user_id' => $admin->id,
    ]);
    $intent = CustomerStatusNotificationIntent::create([
        'notification_id' => (string) Str::uuid(),
        'customer_status_history_id' => $history->id,
        'recipient_user_id' => $oldAgentUser->id,
        'audience_type' => 'current_agent',
        'channel' => 'database',
        'purpose' => 'customer_status_changed',
        'customer_profile_id' => $customer->id,
        'payload' => [
            'title' => 'Status changed',
            'message' => 'A permitted status notice.',
            'status' => 'Restricted',
            'effective_at' => now()->utc()->toIso8601String(),
            'customer_id' => $customer->customer_id,
        ],
        'status' => 'pending',
    ]);
    app(DeliverCustomerStatusNotificationIntent::class, ['intentId' => $intent->id])
        ->handle(app(AgentEligibilityService::class));

    expect($intent->fresh()->status)->toBe('suppressed')
        ->and($oldAgentUser->notifications()->count())->toBe(0);
    Notification::assertNothingSent();
});
