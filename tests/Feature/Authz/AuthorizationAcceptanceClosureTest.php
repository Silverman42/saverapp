<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\AuthorizationRestrictionType;
use App\Enums\CustomerAssignmentStatus;
use App\Enums\ThriftPlanStatus;
use App\Jobs\ProjectAuditEvent;
use App\Models\AgentProfile;
use App\Models\AuditEvent;
use App\Models\CollectionBatch;
use App\Models\CollectionReceipt;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\CustomerRecovery;
use App\Models\FinancialArtifact;
use App\Models\LedgerAccount;
use App\Models\PermissionGrantHistory;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\AdminStatusService;
use App\Services\AuthorizationRestrictionService;
use App\Services\AuthorizationService;
use App\Services\CollectionService;
use App\Services\FinancialArtifactService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\WithdrawalService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\CreatesLifecycleCustomers;

require_once __DIR__.'/../../CollectionFixtures.php';
require_once __DIR__.'/../../WithdrawalFixtures.php';

uses(CreatesLifecycleCustomers::class);

beforeEach(function (): void {
    config()->set('collections.enabled', true);
    Notification::fake();
});

/** @return array<string, int> */
function authzFreshSession(): array
{
    return ['auth.fresh_until' => now()->timestamp + 600, 'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
}

/** @param list<AdminPermission> $permissions */
function authzAdmin(array $permissions = []): User
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    if ($permissions !== []) {
        $admin->givePermissionTo(array_map(fn (AdminPermission $permission): string => $permission->value, $permissions));
    }

    return $admin->fresh();
}

function authzReplacementAgent(): AgentProfile
{
    return AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
}

/** @return array<string, mixed> */
function authzStatusPayload(User $target, string $action, string $reason = 'Private management reason'): array
{
    return ['action' => $action, 'reason' => $reason, 'expected_version' => (int) $target->fresh()->lifecycle_access_version, 'confirmed' => true];
}

/** @return array<string, mixed> */
function authzPermissionPayload(User $target, array $permissions): array
{
    return ['permissions' => $permissions, 'reason' => 'Access review', 'expected_permission_version' => $target->fresh()->permission_version, 'confirmed' => true];
}

test('AUTHZ-AC-006: an assigned Agent cannot decide withdrawals, reassign, export or open business administration', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $before = $withdrawal->fresh()->getAttributes();
    $this->actingAs($agent)->withSession(authzFreshSession());

    $this->get(route('admin.fees.index'))->assertForbidden();
    $this->get(route('admin.financial-periods.index'))->assertForbidden();
    $this->get(route('customers.reassignment.edit', $customer->customer_id))->assertForbidden();
    $this->postJson(route('customers.reassignment.preview', $customer->customer_id), ['target_agent_id' => authzReplacementAgent()->id])->assertForbidden();
    $this->postJson(route('withdrawals.reject', $withdrawal), ['attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->version,
        'internal_reason' => 'No', 'customer_explanation' => 'Declined', 'confirmed' => true])->assertForbidden();
    $this->postJson(route('reports.export', 'withdrawals'), ['operation_reference' => (string) Str::uuid(), 'format' => 'csv', 'confirmed' => true])->assertForbidden();

    expect($withdrawal->fresh()->getAttributes())->toBe($before)
        ->and(FinancialArtifact::query()->count())->toBe(0)
        ->and(DB::table('customer_handover_events')->count())->toBe(0);
});

test('AUTHZ-AC-007/019: an Admin holding every permission or customers.manage cannot record a collection', function (array $permissions): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $admin = authzAdmin($permissions);

    $this->actingAs($admin)->withSession(authzFreshSession());
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertForbidden();
    $this->postJson(route('customers.collections.store', $customer->customer_id), $payload)->assertForbidden();

    expect(CollectionReceipt::query()->count())->toBe(0)
        ->and(DB::table('collection_allocations')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
})->with([
    'every permission' => [AdminPermission::cases()],
    'customers.manage only' => [[AdminPermission::CustomersManage]],
]);

test('AUTHZ-AC-009/010: a grant and a revocation made through the access page reach the target Admin on their next request', function (): void {
    $manager = authzAdmin([AdminPermission::AdminsManage]);
    $target = authzAdmin();

    $this->actingAs($target)->get(route('admin.audit.index'))->assertForbidden();
    $this->actingAs($manager)->put(route('admin.access.permissions.update', $target), authzPermissionPayload($target, [AdminPermission::AuditView->value]))->assertRedirect();
    $this->actingAs($target->fresh())->get(route('admin.audit.index'))->assertOk();

    $this->actingAs($manager)->put(route('admin.access.permissions.update', $target), authzPermissionPayload($target, []))->assertRedirect();
    $this->actingAs($target->fresh())->get(route('admin.audit.index'))->assertForbidden();

    $revocation = DB::table('canonical_audit_events')->where('event_type', 'authorization.permissions_changed')->orderByDesc('id')->first();
    expect(json_decode($revocation->content, true)['safe_changes']['revocations'])->toBe([AdminPermission::AuditView->value])
        ->and(PermissionGrantHistory::query()->where('user_id', $target->id)->pluck('action')->all())->toBe(['grant', 'revoke']);
});

test('AUTHZ-AC-010: a queued export stops rendering once its requester loses reports.export', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'Africa/Lagos'));
    Queue::fake();
    Storage::fake('local');
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $exporter = authzAdmin([AdminPermission::ReportsExport]);
    $this->actingAs($exporter)->post(route('reports.export', 'withdrawals'), ['operation_reference' => (string) Str::uuid(), 'format' => 'csv', 'confirmed' => true])->assertRedirect();
    $artifact = FinancialArtifact::query()->sole();

    $exporter->revokePermissionTo(AdminPermission::ReportsExport->value);

    expect(fn () => app(FinancialArtifactService::class)->render($artifact->id))->toThrow(HttpException::class);
    expect($artifact->fresh()->status)->toBe('queued')
        ->and($artifact->fresh()->storage_path)->toBeNull();
});

test('AUTHZ-AC-012: an invited or MFA-incomplete Admin cannot use a selected permission', function (AccountState $state, string $redirect): void {
    $admin = authzAdmin([AdminPermission::AuditView]);
    $admin->forceFill(['account_state' => $state])->save();

    $this->actingAs($admin->fresh())->get(route('admin.audit.index'))->assertRedirect(route($redirect));
})->with([
    'invited' => [AccountState::Invited, 'login'],
    'mfa setup required' => [AccountState::MfaSetupRequired, 'two-factor.enrolment'],
]);

test('AUTHZ-AC-014: an admins.manage holder suspends, restores and deactivates another Admin', function (): void {
    $manager = authzAdmin([AdminPermission::AdminsManage]);
    $otherManager = authzAdmin([AdminPermission::AdminsManage]);
    $target = authzAdmin([AdminPermission::AuditView]);
    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $target->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'Test', 'payload' => '', 'last_activity' => now()->timestamp]);

    $this->actingAs($manager)->get(route('admin.access.show', $target))
        ->assertInertia(fn (Assert $page) => $page->where('status.actions', ['suspend', 'deactivate']));
    assertToast($this->patch(route('admin.access.status.update', $target), authzStatusPayload($target, 'suspend'))
        ->assertRedirect(route('admin.access.show', $target)), 'success', 'Account status updated');

    $suspended = $target->fresh();
    expect($suspended->account_state)->toBe(AccountState::Suspended)
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0)
        ->and($suspended->lifecycle_access_version)->toBe($target->lifecycle_access_version + 1)
        ->and($suspended->hasDirectPermission(AdminPermission::AuditView->value))->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', 'admin.suspended')->where('target_id', $target->id)->sole()->payload)
        ->toBe(['account_state' => 'suspended', 'from_account_state' => 'active'])
        ->and(DB::table('audit_events')->where('payload', 'like', '%Private management reason%')->exists())->toBeFalse()
        ->and(DB::table('audit_notification_intents')->where('recipient_user_id', $otherManager->id)->exists())->toBeTrue()
        ->and(DB::table('audit_notification_intents')->where('recipient_user_id', $manager->id)->exists())->toBeFalse();

    $this->actingAs($suspended)->withSession(['auth.lifecycle_access_version' => $suspended->lifecycle_access_version])
        ->get(route('admin.access.index'))->assertRedirect(route('login'));

    $this->actingAs($manager)->patch(route('admin.access.status.update', $target), authzStatusPayload($target, 'reactivate'))->assertRedirect();
    expect($target->fresh()->account_state)->toBe(AccountState::Active);

    $this->patch(route('admin.access.status.update', $target), authzStatusPayload($target, 'deactivate'))->assertRedirect();
    expect($target->fresh()->account_state)->toBe(AccountState::Deactivated);
    $this->patchJson(route('admin.access.status.update', $target), authzStatusPayload($target, 'reactivate'))->assertUnprocessable()->assertJsonValidationErrors('action');
    expect($target->fresh()->account_state)->toBe(AccountState::Deactivated);
});

test('AUTHZ-AC-013: an Admin cannot change their own permissions or account status and the attempt is audited', function (): void {
    $manager = authzAdmin([AdminPermission::AdminsManage]);
    authzAdmin([AdminPermission::AdminsManage]);

    $this->actingAs($manager)->patchJson(route('admin.access.status.update', $manager), authzStatusPayload($manager, 'suspend'))->assertForbidden();
    $this->putJson(route('admin.access.permissions.update', $manager), authzPermissionPayload($manager, AdminPermission::values()))->assertForbidden();

    expect($manager->fresh()->account_state)->toBe(AccountState::Active)
        ->and($manager->fresh()->permission_version)->toBe($manager->permission_version)
        ->and(AuditEvent::query()->where('event_type', 'admin.status_denied')->sole()->payload['denial_code'])->toBe('self_management')
        ->and(AuditEvent::query()->where('event_type', 'authorization.denied')->sole()->payload['denial_code'])->toBe('self_management');
});

test('AUTHZ-AC-014: an Admin without admins.manage changes neither status nor permissions of another Admin', function (): void {
    $actor = authzAdmin([AdminPermission::AgentsManage, AdminPermission::SecurityOperationsManage]);
    $target = authzAdmin();

    $this->actingAs($actor)->patchJson(route('admin.access.status.update', $target), authzStatusPayload($target, 'suspend'))->assertForbidden();
    $this->putJson(route('admin.access.permissions.update', $target), authzPermissionPayload($target, [AdminPermission::AuditView->value]))->assertForbidden();

    expect($target->fresh()->account_state)->toBe(AccountState::Active)
        ->and($target->fresh()->permission_version)->toBe($target->permission_version)
        ->and(PermissionGrantHistory::query()->where('user_id', $target->id)->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', 'admin.status_denied')->sole()->payload['denial_code'])->toBe('missing_authority')
        ->and(AuditEvent::query()->where('event_type', 'authorization.denied')->sole()->payload['denial_code'])->toBe('missing_authority');
    $this->get(route('admin.access.show', $target))->assertForbidden();
});

test('AUTHZ-AC-014: a stale status form and an invited target change nothing', function (): void {
    $manager = authzAdmin([AdminPermission::AdminsManage]);
    $target = authzAdmin();
    $stale = authzStatusPayload($target, 'suspend');
    $target->forceFill(['lifecycle_access_version' => $target->lifecycle_access_version + 1])->save();

    $this->actingAs($manager)->patchJson(route('admin.access.status.update', $target), $stale)->assertConflict();
    expect($target->fresh()->account_state)->toBe(AccountState::Active);

    $invited = User::factory()->admin()->invited()->create();
    $this->get(route('admin.access.show', $invited))->assertInertia(fn (Assert $page) => $page->where('status.actions', []));
    $this->patchJson(route('admin.access.status.update', $invited), authzStatusPayload($invited, 'suspend'))->assertUnprocessable();
    expect($invited->fresh()->account_state)->toBe(AccountState::Invited);
});

test('AUTHZ-AC-015/016: managers suspending each other always leave one active capable Admin', function (): void {
    $first = authzAdmin([AdminPermission::AdminsManage]);
    $second = authzAdmin([AdminPermission::AdminsManage]);

    $this->actingAs($first)->patch(route('admin.access.status.update', $second), authzStatusPayload($second, 'suspend'))->assertRedirect();
    $this->actingAs($second->fresh())->patch(route('admin.access.status.update', $first), authzStatusPayload($first, 'suspend'))->assertRedirect(route('login'));

    expect(fn () => app(AdminStatusService::class)->change($second->fresh(), $first->fresh(), 'suspend', (int) $first->fresh()->lifecycle_access_version, 'Stale tab'))
        ->toThrow(AuthorizationException::class);
    expect($first->fresh()->account_state)->toBe(AccountState::Active)
        ->and(app(AuthorizationService::class)->allows($first->fresh(), AdminPermission::AdminsManage))->toBeTrue()
        ->and(fn () => $first->fresh()->forceFill(['account_state' => AccountState::Suspended])->save())->toThrow(RuntimeException::class);
});

test('AUTHZ-AC-017: the post-recovery restriction blocks Admin management until it expires', function (): void {
    $restricted = authzAdmin([AdminPermission::AdminsManage]);
    authzAdmin([AdminPermission::AdminsManage]);
    $target = authzAdmin();
    app(AuthorizationRestrictionService::class)->apply(target: $restricted, type: AuthorizationRestrictionType::PostRecoveryAdminManagement, source: 'staff_recovery');

    $this->actingAs($restricted->fresh())->withSession(authzFreshSession());
    $this->patchJson(route('admin.access.status.update', $target), authzStatusPayload($target, 'suspend'))->assertForbidden();
    $this->putJson(route('admin.access.permissions.update', $target), authzPermissionPayload($target, [AdminPermission::AuditView->value]))->assertForbidden();
    $this->postJson(route('admin.access.invitations.store'), ['name' => 'New Admin', 'email' => 'new-admin@example.test', 'permissions' => [],
        'reason' => 'Coverage', 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true])->assertForbidden();
    expect($target->fresh()->account_state)->toBe(AccountState::Active)
        ->and(User::query()->where('email', 'new-admin@example.test')->exists())->toBeFalse()
        ->and(DB::table('canonical_audit_events')->where('event_type', 'authorization.restriction_applied')->exists())->toBeTrue();

    $this->travel(25)->hours();
    $this->flushSession();
    $this->actingAs($restricted->fresh())->patch(route('admin.access.status.update', $target), authzStatusPayload($target, 'suspend'))->assertRedirect();
    expect($target->fresh()->account_state)->toBe(AccountState::Suspended);
});

test('AUTHZ-AC-018: agents.manage alone cannot reassign a Customer', function (): void {
    [, $customer] = collectionFixture();
    $admin = authzAdmin([AdminPermission::AgentsManage]);

    $this->actingAs($admin)->withSession(authzFreshSession());
    $target = authzReplacementAgent();
    $this->get(route('customers.reassignment.edit', $customer->customer_id))->assertForbidden();
    $this->postJson(route('customers.reassignment.preview', $customer->customer_id), ['target_agent_id' => $target->id])->assertForbidden();
    $this->postJson(route('customers.reassignment.store', $customer->customer_id), ['attempt_reference' => (string) Str::uuid(), 'version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'target_agent_id' => $target->id, 'preview_token' => str_repeat('a', 64),
        'confirmed' => true, 'reason' => 'Coverage', 'customer_explanation' => 'Your contact changed.'])->assertForbidden();

    expect(DB::table('customer_handover_events')->count())->toBe(0)
        ->and(CustomerAssignment::query()->where('customer_profile_id', $customer->id)->where('is_current', 1)->count())->toBe(1);
});

test('AUTHZ-AC-022: fee, deduction and reconciliation permissions do not stand in for each other', function (AdminPermission $permission, bool $fees, bool $reconciliation): void {
    $agentProfile = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $batch = CollectionBatch::create(['business_version' => 1, 'agent_profile_id' => $agentProfile->id, 'received_date' => now()->toDateString(),
        'timezone' => 'Africa/Lagos', 'revision' => 1, 'status' => 'open', 'version' => 1]);
    $admin = authzAdmin([$permission]);

    $this->actingAs($admin)->withSession(authzFreshSession());
    expect($this->get(route('admin.fees.index'))->status() === 403)->toBe(! $fees);
    if (! $reconciliation) {
        $this->postJson(route('collection-batches.review', $batch), ['batch_version' => 1, 'reason' => 'Review', 'confirmed' => true])->assertForbidden();
    }
    expect($batch->fresh()->version)->toBe(1);
})->with([
    'fees.manage' => [AdminPermission::FeesManage, true, false],
    'deductions.manage' => [AdminPermission::DeductionsManage, false, false],
    'reconciliation.manage' => [AdminPermission::ReconciliationManage, false, true],
]);

test('AUTHZ-AC-023: only security.operations.manage approves a Customer assisted recovery', function (): void {
    Queue::fake();
    [, $customer, $agent] = $this->createLifecycleFixture();
    $this->actingAs($agent->user)->postJson(route('customers.recovery.store', $customer->customer_id), [
        'attempt_reference' => (string) Str::uuid(), 'confirmed' => true, 'version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'email' => 'recovered@example.test', 'in_person' => true,
        'record_compared' => true, 'verified_at' => now()->toIso8601String(), 'procedure_reference' => 'KYC-1', 'notes' => 'Private evidence',
    ])->assertOk();
    $recovery = CustomerRecovery::query()->sole();
    $original = $customer->user->fresh()->getAttributes();
    $decision = ['attempt_reference' => (string) Str::uuid(), 'confirmed' => true, 'recovery_version' => $recovery->version, 'reason' => 'Reviewed'];
    $url = route('customers.recovery.update', [$customer->customer_id, $recovery->reference, 'approve']);

    $this->actingAs(authzAdmin([AdminPermission::CustomersManage, AdminPermission::AdminsManage]))->withSession(authzFreshSession())
        ->postJson($url, $decision)->assertForbidden();
    expect($customer->user->fresh()->getAttributes())->toBe($original)
        ->and($recovery->fresh()->state)->toBe('awaiting_approval');

    $this->actingAs(authzAdmin([AdminPermission::SecurityOperationsManage]))->withSession(authzFreshSession())
        ->postJson($url, $decision)->assertOk()->assertJsonPath('state', 'awaiting_activation');
});

test('AUTHZ-AC-025: Agents and Customers cannot export business reports', function (): void {
    $agent = User::factory()->agent()->withTwoFactor()->create();
    AgentProfile::factory()->active()->create(['user_id' => $agent->id]);
    $customer = CustomerProfile::factory()->create()->user;
    $payload = ['operation_reference' => (string) Str::uuid(), 'format' => 'csv', 'confirmed' => true];

    foreach ([$agent, $customer] as $viewer) {
        foreach (['withdrawals', 'contributions', 'customer-summary'] as $report) {
            $this->actingAs($viewer)->postJson(route('reports.export', $report), $payload)->assertForbidden();
        }
    }
    expect(FinancialArtifact::query()->count())->toBe(0);
});

test('AUTHZ-AC-026: an Agent directory search never finds an out-of-scope Customer', function (): void {
    [$agent, $customer] = collectionFixture();
    $foreign = CustomerProfile::factory()->create();
    $otherAgent = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    CustomerAssignment::factory()->create(['customer_profile_id' => $foreign->id, 'agent_profile_id' => $otherAgent->id, 'status' => CustomerAssignmentStatus::Current]);

    foreach ([$foreign->user->name, $foreign->customer_id, $foreign->phone] as $term) {
        $this->actingAs($agent)->get(route('customers.index', ['search' => $term]))
            ->assertInertia(fn (Assert $page) => $page->where('customers.total', 0));
    }
    $this->get(route('customers.index', ['search' => $customer->customer_id]))
        ->assertInertia(fn (Assert $page) => $page->where('customers.total', 1));
});

test('AUTHZ-AC-027: an accessible Customer paired with another Customer\'s plan is rejected without effect', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $otherCustomer = CustomerProfile::factory()->create();
    $otherPlan = ThriftPlan::create(['plan_id' => 'PLN-OTHER-001', 'customer_profile_id' => $otherCustomer->id, 'created_by_user_id' => $agent->id,
        'open_customer_profile_id' => $otherCustomer->id, 'status' => ThriftPlanStatus::Active, 'current_terms_revision' => 1, 'version' => 1]);
    $before = ['receipts' => CollectionReceipt::query()->count(), 'groups' => DB::table('ledger_posting_groups')->count(), 'withdrawals' => WithdrawalRequest::query()->count()];

    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['plan_id'] = $otherPlan->plan_id;
    $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertUnprocessable()->assertJsonValidationErrors('plan_id');
    $this->postJson(route('customers.collections.store', $customer->customer_id), $payload)->assertUnprocessable();

    enableFixtureMethod();
    $this->postJson(route('customers.withdrawals.preview', $customer->customer_id), withdrawalPayload($customer, $assignment, $otherPlan))->assertNotFound();

    expect(['receipts' => CollectionReceipt::query()->count(), 'groups' => DB::table('ledger_posting_groups')->count(), 'withdrawals' => WithdrawalRequest::query()->count()])->toBe($before)
        ->and($otherPlan->fresh()->customer_profile_id)->toBe($otherCustomer->id);
});

test('AUTHZ-AC-028: denied mutations queue no background work beyond projecting their denial audit', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $quote = app(WithdrawalService::class)->preview($agent, $customer->fresh(), withdrawalPayload($customer, $assignment, $plan));
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    Queue::fake();
    $outsider = User::factory()->agent()->withTwoFactor()->create();
    AgentProfile::factory()->active()->create(['user_id' => $outsider->id]);
    $baselineAdmin = authzAdmin();

    $this->actingAs($agent)->withSession(authzFreshSession())->postJson(route('withdrawals.approve', $withdrawal), ['attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->version,
        'decision_note' => 'Self approval', 'confirmed' => true])->assertForbidden();
    $this->actingAs($outsider)->postJson(route('customers.withdrawals.store', $customer->customer_id), [...withdrawalPayload($customer, $assignment, $plan),
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'], 'plan_version' => $quote['plan_version'],
        'business_version' => $quote['business_version'], 'instruction_attested' => true, 'confirmed' => true])->assertForbidden();
    $this->actingAs($baselineAdmin)->postJson(route('reports.export', 'withdrawals'), ['operation_reference' => (string) Str::uuid(), 'format' => 'csv', 'confirmed' => true])->assertForbidden();
    $this->putJson(route('admin.access.permissions.update', authzAdmin()), ['permissions' => [], 'reason' => 'x', 'expected_permission_version' => 0, 'confirmed' => true])->assertForbidden();

    expect(array_keys(Queue::pushedJobs()))->toBe([ProjectAuditEvent::class])
        ->and(WithdrawalRequest::query()->count())->toBe(1)
        ->and($withdrawal->fresh()->state)->toBe($withdrawal->state);
});

test('AUTHZ-AC-030: applying, clearing and expiring a restriction are audited without the clearing reason', function (): void {
    $admin = authzAdmin([AdminPermission::AdminsManage]);
    $clearer = authzAdmin([AdminPermission::AdminsManage]);
    $service = app(AuthorizationRestrictionService::class);

    $cleared = $service->apply(target: $admin, type: AuthorizationRestrictionType::PostRecoveryAdminManagement, source: 'staff_recovery');
    $service->clear($cleared, 'Private clearing reason', $clearer);
    $service->apply(target: $admin->fresh(), type: AuthorizationRestrictionType::PostRecoveryAdminManagement, source: 'staff_recovery');
    $this->travel(25)->hours();

    expect($service->expireElapsedRestrictions())->toBe(1)
        ->and(DB::table('canonical_audit_events')->whereIn('event_type', ['authorization.restriction_applied', 'authorization.restriction_cleared', 'authorization.restriction_expired'])
            ->orderBy('id')->pluck('event_type')->all())
        ->toBe(['authorization.restriction_applied', 'authorization.restriction_cleared', 'authorization.restriction_applied', 'authorization.restriction_expired'])
        ->and(DB::table('canonical_audit_events')->where('content', 'like', '%Private clearing reason%')->exists())->toBeFalse()
        ->and(DB::table('audit_events')->where('payload', 'like', '%Private clearing reason%')->exists())->toBeFalse();
});
