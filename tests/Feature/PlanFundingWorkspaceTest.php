<?php

use App\Enums\AdminPermission;
use App\Enums\AgentStatus;
use App\Enums\CustomerStatus;
use App\Enums\FeeRuleTiming;
use App\Models\AgentProfile;
use App\Models\BusinessProfile;
use App\Models\CollectionReceipt;
use App\Models\FeeRule;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Services\AgentEligibilityService;
use App\Services\AgentLifecycleService;
use App\Services\AgentStatusManagementService;
use App\Services\CollectionReadService;
use App\Services\CollectionReplacementService;
use App\Services\CollectionService;
use App\Services\CollectionWorkspaceService;
use App\Services\CustomerReassignmentService;
use App\Services\CustomerStatusManagementService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\PlanFundingReadService;
use App\Services\ReportReadService;
use App\Services\ReversalService;
use App\Services\ThriftPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\CreatesLifecycleAgents;
use Tests\CreatesLifecycleCustomers;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../ReversalFixtures.php';

uses(CreatesLifecycleCustomers::class, CreatesLifecycleAgents::class);

function planFundingFixture(): array
{
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(2);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $data = collectionPayload($customer, $assignment, $plan, $date, '3000.00');
    $service = app(CollectionService::class);
    $data['preview_fingerprint'] = $service->preview($agent, $customer, $data)['preview_fingerprint'];
    $receipt = $service->record($agent, $customer, $data);
    app(LedgerTransactionProjectionService::class)->rebuild();

    return [$agent, $customer, $assignment, $plan->fresh(), $date, $receipt];
}

test('Customer status-owner intervals retain funding and classify blocked dates in the captured local timezone', function (string $blockedStatus): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(4);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2500.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $payload);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $plan->refresh();
    $planAttributes = $plan->getAttributes();
    $terms = $plan->currentTermsRevision()->getAttributes();
    $slots = $plan->slots()->orderBy('id')->get()->map->getAttributes()->all();
    $history = [];
    foreach (['collection_receipts', 'collection_allocations', 'ledger_posting_groups', 'ledger_entries',
        'fee_obligations', 'fee_obligation_entries', 'withdrawal_requests', 'withdrawal_reservations',
        'plan_lifecycle_events', 'collection_annotations'] as $table) {
        $history[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::CustomersManage);
    $statuses = app(CustomerStatusManagementService::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 00:30:00', 'Africa/Lagos'));
    $customer = $statuses->transition($admin, $customer, CustomerStatus::from($blockedStatus), $customer->version,
        'Owner review pauses Customer participation.', 'Your paid contributions remain in history.');
    $card = app(CollectionReadService::class)->fundingCard($plan);
    expect(array_column($card['slots'], 'status'))->toBe(['paid', 'partial', 'blocked', 'blocked'])
        ->and(array_column($card['slots'], 'blocked'))->toBe([false, true, true, true])
        ->and(array_column($card['slots'], 'funded_kobo'))->toBe([200000, 50000, 0, 0]);
    $this->travelTo(CarbonImmutable::parse('2026-10-07 00:30:00', 'Africa/Lagos'));
    $customer = $statuses->transition($admin, $customer,
        $blockedStatus === 'inactive' ? CustomerStatus::Restricted : CustomerStatus::Inactive,
        $customer->version, 'Blocked participation continues under owner review.', 'Original dates and funding remain.');
    $this->travelTo(CarbonImmutable::parse('2026-10-08 00:30:00', 'Africa/Lagos'));
    $customer = $statuses->transition($admin, $customer, CustomerStatus::Active, $customer->version,
        'Review restores eligible participation.', 'You may contribute against your original remaining capacity.');
    $card = app(CollectionReadService::class)->fundingCard($plan);
    expect(array_column($card['slots'], 'status'))->toBe(['paid', 'partial', 'blocked', 'pending'])
        ->and(array_column($card['slots'], 'blocked'))->toBe([false, true, true, false])
        ->and($card['funded_kobo'])->toBe(250000)
        ->and($card['paid_slots'])->toBe(1);
    $workspace = app(CollectionWorkspaceService::class);
    foreach (['2026-10-06' => 50000, '2026-10-07' => 0] as $blockedDate => $fundedKobo) {
        $work = $workspace->dueWork($agent, $blockedDate, '2026-10-08', '', 'all');
        expect($work['slots']->total())->toBe(1)
            ->and($work['slots']->items()[0]['status'])->toBe('blocked')
            ->and($work['totals']['scheduled_kobo'])->toBe(200000)
            ->and($work['totals']['covered_kobo'])->toBe($fundedKobo)
            ->and($work['totals']['outstanding_kobo'])->toBe(0)
            ->and($work['totals']['blocked_target_kobo'])->toBe(200000)
            ->and($workspace->dueWork($agent, $blockedDate, '2026-10-08', '', 'blocked')['slots']->total())->toBe(1)
            ->and($workspace->dueWork($agent, $blockedDate, '2026-10-08', '', 'missed')['slots']->total())->toBe(0);
    }
    $paid = $workspace->dueWork($agent, '2026-10-05', '2026-10-08', '', 'all');
    $eligible = $workspace->dueWork($agent, '2026-10-08', '2026-10-08', '', 'all');
    expect($paid['slots']->items()[0]['status'])->toBe('paid')
        ->and($paid['totals']['covered_kobo'])->toBe(200000)
        ->and($eligible['slots']->items()[0]['status'])->toBe('pending')
        ->and($eligible['totals']['outstanding_kobo'])->toBe(200000);
    $this->actingAs($agent)->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])
        ->get(route('collections.index', ['date' => '2026-10-07']))->assertInertia(fn (Assert $page) => $page
        ->where('due_slots.data.0.status', 'blocked')->where('due_totals.outstanding_kobo', 0));
    foreach ([$agent, $customer->user] as $viewer) {
        $this->actingAs($viewer)->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])
            ->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
            ->where('plan.financial_summary.funded_principal', '₦2,500.00')
            ->where('plan.slots.0.collection_status', 'paid')->where('plan.slots.1.collection_status', 'partial')
            ->where('plan.slots.2.collection_status', 'blocked')->where('plan.slots.3.collection_status', 'pending'));
    }
    expect($plan->fresh()->getAttributes())->toBe($planAttributes)
        ->and($plan->currentTermsRevision()->getAttributes())->toBe($terms)
        ->and($plan->slots()->orderBy('id')->get()->map->getAttributes()->all())->toBe($slots);
    foreach ($history as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
})->with(['Inactive then Restricted' => 'inactive', 'Restricted then Inactive' => 'restricted']);

test('legacy restoration and resume leave earlier participation unavailable without losing posted funding', function (string $owner, bool $retainedHistory): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(4);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::CustomersManage);
    if ($retainedHistory) {
        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'Africa/Lagos'));
        if ($owner === 'customer') {
            $statuses = app(CustomerStatusManagementService::class);
            $customer = $statuses->transition($admin, $customer, CustomerStatus::Inactive, $customer->version,
                'Earlier reviewed interruption.', 'Your original schedule remains.');
            $customer = $statuses->transition($admin, $customer, CustomerStatus::Active, $customer->version,
                'Earlier reviewed restoration.', 'Your original schedule remains.');
        } else {
            foreach (['pause', 'resume'] as $action) {
                app(ThriftPlanService::class)->transition($agent, $plan, $action, (string) Str::uuid(), [
                    'customer_version' => $customer->version, 'assignment_version' => $assignment->version,
                    'plan_version' => $plan->version, 'reason' => 'Earlier reviewed participation transition.',
                    'customer_explanation' => 'Your original schedule remains.']);
                $plan->refresh();
            }
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    }
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2500.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $payload);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $financialRows = [];
    foreach (['collection_receipts', 'collection_allocations', 'ledger_posting_groups', 'ledger_entries',
        'fee_obligations', 'fee_obligation_entries', 'withdrawal_requests', 'withdrawal_reservations', 'collection_annotations'] as $table) {
        $financialRows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $terms = $plan->currentTermsRevision()->getAttributes();
    $slots = $plan->slots()->orderBy('id')->get()->map->getAttributes()->all();
    $this->assertDatabaseCount('customer_status_histories', $retainedHistory && $owner === 'customer' ? 2 : 0);
    $this->assertDatabaseCount('plan_lifecycle_events', $retainedHistory && $owner === 'plan' ? 2 : 0);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 00:30:00', 'Africa/Lagos'));
    if ($owner === 'customer') {
        DB::table('customer_profiles')->where('id', $customer->id)->update(['operational_status' => 'inactive']);
        $customer->refresh();
        $customer = app(CustomerStatusManagementService::class)->transition($admin, $customer, CustomerStatus::Active,
            $customer->version, 'Current legacy participation restored after review.', 'Original contributions remain unchanged.');
    } else {
        DB::table('thrift_plans')->where('id', $plan->id)->update(['status' => 'paused']);
        $plan->refresh();
        app(ThriftPlanService::class)->transition($agent, $plan, 'resume', (string) Str::uuid(), [
            'customer_version' => $customer->version, 'assignment_version' => $assignment->version,
            'plan_version' => $plan->version, 'reason' => 'Current legacy cycle resumes after review.',
            'customer_explanation' => 'Original dates and contributions remain unchanged.']);
    }
    $plan->refresh();
    $planAttributes = $plan->getAttributes();
    $card = app(CollectionReadService::class)->fundingCard($plan);
    expect(array_column($card['slots'], 'status'))->toBe(['paid', 'unavailable', 'unavailable', 'pending'])
        ->and(array_column($card['slots'], 'funded_kobo'))->toBe([200000, 50000, 0, 0])
        ->and($card['funded_kobo'])->toBe(250000);
    $workspace = app(CollectionWorkspaceService::class);
    foreach (['2026-10-06' => 50000, '2026-10-07' => 0] as $uncertainDate => $fundedKobo) {
        $work = $workspace->dueWork($agent, $uncertainDate, '2026-10-08', '', 'all');
        expect($work['slots']->items()[0]['status'])->toBe('unavailable')
            ->and($work['totals']['covered_kobo'])->toBe($fundedKobo)
            ->and($work['totals']['outstanding_kobo'])->toBe(0)
            ->and($work['totals']['unavailable_target_kobo'])->toBe(200000)
            ->and($workspace->dueWork($agent, $uncertainDate, '2026-10-08', '', 'missed')['slots']->total())->toBe(0);
    }
    $paid = $workspace->dueWork($agent, '2026-10-05', '2026-10-08', '', 'all');
    expect($paid['slots']->items()[0]['status'])->toBe('paid')
        ->and($paid['totals']['unavailable_target_kobo'])->toBe(0);
    $this->actingAs($agent)->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])
        ->get(route('collections.index', ['date' => '2026-10-06', 'status' => 'unavailable']))
        ->assertInertia(fn (Assert $page) => $page->where('due_slots.data.0.status', 'unavailable')
            ->where('due_totals.unavailable_target_kobo', 200000)->where('due_totals.covered_kobo', 50000)
            ->where('due_totals.outstanding_kobo', 0));
    foreach ([$agent, $customer->user] as $viewer) {
        $this->actingAs($viewer)->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])
            ->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
            ->where('plan.financial_summary.funded_principal', '₦2,500.00')
            ->where('plan.financial_summary.partially_funded_slots', '1')
            ->where('plan.slots.0.collection_status', 'paid')->where('plan.slots.1.collection_status', 'unavailable')
            ->where('plan.slots.1.formatted_funded_amount', '₦500.00')->where('plan.slots.2.collection_status', 'unavailable')
            ->where('plan.slots.3.collection_status', 'pending'));
    }
    expect($plan->fresh()->getAttributes())->toBe($planAttributes)
        ->and($plan->currentTermsRevision()->getAttributes())->toBe($terms)
        ->and($plan->slots()->orderBy('id')->get()->map->getAttributes()->all())->toBe($slots);
    foreach ($financialRows as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
})->with([
    'Missing-prefix Customer restoration' => ['customer', false],
    'Missing-prefix plan resume' => ['plan', false],
    'Contradictory Customer history' => ['customer', true],
    'Contradictory plan history' => ['plan', true],
]);

test('Admin due-work uses the assigned Agent readiness owner without changing recorded funding', function (string $readiness, bool $eligible): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(4);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2500.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $payload);
    $originalPlan = $plan->fresh()->getAttributes();
    $rows = [];
    foreach (['collection_receipts', 'collection_allocations', 'collection_batches', 'ledger_posting_groups', 'ledger_entries',
        'fee_obligations', 'fee_obligation_entries', 'withdrawal_requests', 'withdrawal_reservations',
        'plan_lifecycle_events', 'collection_annotations'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    switch ($readiness) {
        case 'missing-secret':
            $agent->forceFill(['two_factor_secret' => null])->save();
            break;
        case 'missing-confirmation':
            $agent->forceFill(['two_factor_confirmed_at' => null])->save();
            break;
        case 'missing-role':
            $agent->syncRoles([]);
            break;
        case 'wrong-role':
            $agent->syncRoles('admin');
            break;
        case 'extra-role':
            $agent->assignRole('admin');
            break;
        case 'classification-drift':
            DB::table('users')->where('id', $agent->id)->update(['user_type' => 'customer']);
            break;
        case 'temporary-lock':
            $agent->forceFill(['locked_until' => now()->addHour(), 'lock_category' => 'password'])->save();
            break;
    }
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $agent = $agent->fresh();

    expect(app(AgentEligibilityService::class)->canPerformAssignedCustomerWork($agent))->toBe($eligible);
    $workspace = app(CollectionWorkspaceService::class);
    $work = $workspace->dueWork($admin, '2026-10-06', '2026-10-06', '', 'all');
    expect($work['slots']->items()[0]['status'])->toBe($eligible ? 'partial' : 'service-interrupted')
        ->and($work['totals']['covered_kobo'])->toBe(50000)
        ->and($work['totals']['outstanding_kobo'])->toBe($eligible ? 150000 : 0)
        ->and($work['totals']['service_interrupted_target_kobo'])->toBe($eligible ? 0 : 200000);
    $paid = $workspace->dueWork($admin, '2026-10-05', '2026-10-06', '', 'all');
    expect($paid['slots']->items()[0]['status'])->toBe('paid')->and($paid['totals']['covered_kobo'])->toBe(200000)
        ->and($plan->fresh()->getAttributes())->toBe($originalPlan)
        ->and($customer->fresh()->operational_status)->toBe(CustomerStatus::Active);
    foreach ($rows as $table => $originalRows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($originalRows);
    }
})->with([
    'Normal readiness' => ['ready', true],
    'Established-session temporary lock' => ['temporary-lock', true],
    'Missing MFA secret' => ['missing-secret', false],
    'Missing MFA confirmation' => ['missing-confirmation', false],
    'Missing synchronized role' => ['missing-role', false],
    'Wrong synchronized role' => ['wrong-role', false],
    'Extra synchronized role' => ['extra-role', false],
    'Legacy classification drift' => ['classification-drift', false],
]);

test('assigned Agent interruption preserves funding and custody until explicit restoration or reassignment', function (bool $reassign, bool $suspended, bool $recoverable): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(4);
    if ($suspended && $recoverable) {
        $agent->forceFill(['recovery_codes_acknowledged_at' => now()])->save();
    }
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2500.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $payload);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $originalPlan = $plan->fresh()->getAttributes();
    $originalTerms = $plan->currentTermsRevision()->getAttributes();
    $originalSlots = $plan->slots()->orderBy('id')->get()->map->getAttributes()->all();
    $rows = [];
    foreach (['collection_receipts', 'collection_allocations', 'collection_batches', 'ledger_posting_groups', 'ledger_entries',
        'fee_obligations', 'fee_obligation_entries', 'withdrawal_requests', 'withdrawal_reservations',
        'plan_lifecycle_events', 'collection_annotations'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::AgentsManage, AdminPermission::CustomersReassign]);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'Africa/Lagos'));
    $agentProfile = $agent->agentProfile;
    if ($suspended) {
        app(AgentLifecycleService::class)->execute($admin, $agentProfile, 'suspend', $this->agentLifecyclePayload($agentProfile),
            $this->agentLifecycleRequest($admin));
        $agentProfile->refresh();
    } else {
        $agentProfile = app(AgentStatusManagementService::class)->transition($admin, $agentProfile, AgentStatus::Inactive,
            $agentProfile->version, 'Private personnel details.', 'Your service work is temporarily unavailable.');
    }
    $workspace = app(CollectionWorkspaceService::class);

    $work = $workspace->dueWork($admin, '2026-10-06', '2026-10-06', '', 'all');
    expect($work['slots']->items()[0]['status'])->toBe('service-interrupted')
        ->and($work['totals']['covered_kobo'])->toBe(50000)
        ->and($work['totals']['outstanding_kobo'])->toBe(0)
        ->and($work['totals']['service_interrupted_target_kobo'])->toBe(200000);
    $paid = $workspace->dueWork($admin, '2026-10-05', '2026-10-06', '', 'all');
    expect($paid['slots']->items()[0]['status'])->toBe('paid')
        ->and($paid['totals']['covered_kobo'])->toBe(200000);
    $this->actingAs($admin)->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])
        ->get(route('collections.index', ['date' => '2026-10-06', 'status' => 'service-interrupted']))
        ->assertInertia(fn (Assert $page) => $page->where('due_slots.data.0.status', 'service-interrupted')
            ->where('due_totals.outstanding_kobo', 0)->where('due_totals.service_interrupted_target_kobo', 200000))
        ->assertDontSee('Private personnel details.');
    if ($suspended) {
        $this->assertDatabaseHas('agent_lifecycle_histories', ['agent_profile_id' => $agentProfile->id,
            'to_account_state' => 'suspended', 'created_at' => '2026-10-06 11:00:00']);
        expect($agentProfile->operational_status)->toBe(AgentStatus::Active);
    } else {
        $this->assertDatabaseHas('agent_status_histories', ['agent_profile_id' => $agentProfile->id,
            'to_status' => 'inactive', 'created_at' => '2026-10-06 11:00:00']);
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-08 00:30:00', 'Africa/Lagos'));
    if ($reassign) {
        $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
        $handover = app(CustomerReassignmentService::class);
        $preview = $handover->preview($admin, $customer->fresh(), $replacement->id);
        $handover->execute($admin, $customer->fresh(), [
            'attempt_reference' => (string) Str::uuid(), 'version' => $preview['version'],
            'assignment_version' => $preview['assignment_version'], 'preview_token' => $preview['preview_token'],
            'target_agent_id' => $replacement->id, 'confirmed' => true, 'reason' => 'Reviewed service responsibility transfer.',
            'customer_explanation' => 'Your service contact has changed.']);
        $former = $this->actingAs($agent->fresh())->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])
            ->get(route('plans.show', $plan->plan_id));
        if ($suspended) {
            $former->assertRedirect(route('login'));
        } else {
            $former->assertNotFound();
        }
        $this->actingAs($replacement->user)->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])
            ->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page->where('plan.id', $plan->plan_id));
    } elseif ($suspended) {
        app(AgentLifecycleService::class)->execute($admin, $agentProfile, 'restore', $this->agentLifecyclePayload($agentProfile),
            $this->agentLifecycleRequest($admin));
    } else {
        app(AgentStatusManagementService::class)->transition($admin, $agentProfile, AgentStatus::Active,
            $agentProfile->version, 'Reviewed service restoration.', 'Your service work is available again.');
    }
    $restored = $workspace->dueWork($admin, '2026-10-06', '2026-10-08', '', 'all');
    expect($restored['slots']->items()[0]['status'])->toBe('service-interrupted')
        ->and($restored['totals']['covered_kobo'])->toBe(50000)
        ->and($restored['totals']['outstanding_kobo'])->toBe(0)
        ->and($restored['totals']['service_interrupted_target_kobo'])->toBe(200000)
        ->and($customer->fresh()->operational_status)->toBe(CustomerStatus::Active)
        ->and($plan->fresh()->getAttributes())->toBe($originalPlan)
        ->and($plan->currentTermsRevision()->getAttributes())->toBe($originalTerms)
        ->and($plan->slots()->orderBy('id')->get()->map->getAttributes()->all())->toBe($originalSlots);
    $todayWork = $workspace->dueWork($admin, '2026-10-08', '2026-10-08', '', 'all');
    $needsSetup = $suspended && ! $recoverable && ! $reassign;
    expect($todayWork['slots']->items()[0]['status'])->toBe($needsSetup ? 'service-interrupted' : 'pending')
        ->and($todayWork['totals']['outstanding_kobo'])->toBe($needsSetup ? 0 : 200000)
        ->and($todayWork['totals']['service_interrupted_target_kobo'])->toBe($needsSetup ? 200000 : 0);
    if ($needsSetup) {
        expect($agent->fresh()->account_state->value)->toBe('mfa_setup_required');
    }
    $card = app(CollectionReadService::class)->fundingCard($plan->fresh());
    expect(array_column($card['slots'], 'status'))->toBe(['paid', 'service-interrupted', 'service-interrupted', $needsSetup ? 'service-interrupted' : 'pending'])
        ->and(array_column($card['slots'], 'funded_kobo'))->toBe([200000, 50000, 0, 0]);
    foreach ([$admin, $customer->user] as $viewer) {
        $this->actingAs($viewer)->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])
            ->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
            ->where('plan.financial_summary.funded_principal', '₦2,500.00')
            ->where('plan.financial_summary.partially_funded_slots', '1')
            ->where('plan.slots.1.collection_status', 'service-interrupted')
            ->where('plan.slots.1.formatted_funded_amount', '₦500.00')
            ->where('plan.slots.2.collection_status', 'service-interrupted')
            ->where('plan.slots.3.collection_status', $needsSetup ? 'service-interrupted' : 'pending'))
            ->assertDontSee('Private personnel details.')->assertDontSee('Private management review.');
    }
    foreach ($rows as $table => $originalRows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($originalRows);
    }
})->with([
    'Operational restoration' => [false, false, true],
    'Operational reassignment' => [true, false, true],
    'Account restoration' => [false, true, true],
    'Suspended-Agent reassignment' => [true, true, true],
    'Restoration awaiting recovery-code acknowledgement' => [false, true, false],
]);

test('Agent restoration with a missing earlier transition leaves historical participation unavailable', function (bool $suspended, bool $reassign): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(4);
    $agent->forceFill(['recovery_codes_acknowledged_at' => now()])->save();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2500.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $payload);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::AgentsManage, AdminPermission::CustomersReassign]);
    $profile = $agent->agentProfile;
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'Africa/Lagos'));
    if ($suspended) {
        app(AgentLifecycleService::class)->execute($admin, $profile, 'suspend', $this->agentLifecyclePayload($profile),
            $this->agentLifecycleRequest($admin));
        $profile->refresh();
    } else {
        $profile = app(AgentStatusManagementService::class)->transition($admin, $profile, AgentStatus::Inactive,
            $profile->version, 'Reviewed service interruption.', 'Original schedules remain.');
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-08 00:30:00', 'Africa/Lagos'));
    if ($suspended) {
        app(AgentLifecycleService::class)->execute($admin, $profile, 'restore', $this->agentLifecyclePayload($profile),
            $this->agentLifecycleRequest($admin));
    } else {
        app(AgentStatusManagementService::class)->transition($admin, $profile, AgentStatus::Active,
            $profile->version, 'Reviewed service restoration.', 'Original schedules remain.');
    }
    $table = $suspended ? 'agent_lifecycle_histories' : 'agent_status_histories';
    $first = DB::table($table)->where('agent_profile_id', $profile->id)->orderBy('created_at')->orderBy('id')->value('id');
    DB::table($suspended ? 'agent_lifecycle_notification_intents' : 'agent_status_notification_intents')
        ->where($suspended ? 'agent_lifecycle_history_id' : 'agent_status_history_id', $first)->delete();
    DB::table($table)->where('id', $first)->delete();
    expect(DB::table($table)->where('agent_profile_id', $profile->id)->count())->toBe(1);
    if ($reassign) {
        $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
        $handover = app(CustomerReassignmentService::class);
        $preview = $handover->preview($admin, $customer->fresh(), $replacement->id);
        $handover->execute($admin, $customer->fresh(), [
            'attempt_reference' => (string) Str::uuid(), 'version' => $preview['version'],
            'assignment_version' => $preview['assignment_version'], 'preview_token' => $preview['preview_token'],
            'target_agent_id' => $replacement->id, 'confirmed' => true, 'reason' => 'Reviewed service transfer.',
            'customer_explanation' => 'Your responsible Agent has changed.']);
    }
    $before = [];
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'customer_assignments',
        'agent_status_histories', 'agent_lifecycle_histories', 'agent_status_notification_intents',
        'agent_lifecycle_notification_intents', 'plan_lifecycle_events', 'collection_receipts',
        'collection_allocations', 'collection_batches', 'ledger_posting_groups', 'ledger_entries',
        'fee_obligations', 'fee_obligation_entries', 'withdrawal_requests', 'withdrawal_reservations', 'collection_annotations'] as $ownerTable) {
        $before[$ownerTable] = DB::table($ownerTable)->orderBy('id')->get()->all();
    }
    $card = app(CollectionReadService::class)->fundingCard($plan->fresh());
    expect(array_column($card['slots'], 'status'))->toBe(['paid', 'unavailable', 'unavailable', 'pending']);
    expect(array_column($card['slots'], 'funded_kobo'))->toBe([200000, 50000, 0, 0]);
    $workspace = app(CollectionWorkspaceService::class);
    foreach (['2026-10-06' => 50000, '2026-10-07' => 0] as $due => $covered) {
        $work = $workspace->dueWork($admin, $due, '2026-10-08', '', 'all');
        expect($work['slots']->items()[0]['status'])->toBe('unavailable');
        expect($work['totals']['covered_kobo'])->toBe($covered);
        expect($work['totals']['outstanding_kobo'])->toBe(0);
        expect($work['totals']['unavailable_target_kobo'])->toBe(200000);
        expect($workspace->dueWork($admin, $due, '2026-10-08', '', 'missed')['slots']->total())->toBe(0);
    }
    $current = $workspace->dueWork($admin, '2026-10-08', '2026-10-08', '', 'all');
    expect($current['slots']->items()[0]['status'])->toBe('pending');
    expect($current['totals']['outstanding_kobo'])->toBe(200000);
    foreach ([$admin, $customer->user] as $viewer) {
        $this->actingAs($viewer)->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])
            ->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
            ->where('plan.financial_summary.funded_principal', '₦2,500.00')
            ->where('plan.financial_summary.partially_funded_slots', '1')
            ->where('plan.slots.0.collection_status', 'paid')
            ->where('plan.slots.1.collection_status', 'unavailable')
            ->where('plan.slots.1.formatted_funded_amount', '₦500.00')
            ->where('plan.slots.2.collection_status', 'unavailable')
            ->where('plan.slots.3.collection_status', 'pending'));
    }
    foreach ($before as $ownerTable => $rows) {
        expect(DB::table($ownerTable)->orderBy('id')->get()->all())->toEqual($rows);
    }
})->with(['Operational restoration' => [false, false], 'Operational restoration then reassignment' => [false, true],
    'Account restoration' => [true, false], 'Account restoration then reassignment' => [true, true]]);

test('contradictory retained Agent history leaves only uncertain assignment dates unavailable', function (bool $suspended, string $damage, bool $recordedActive, bool $reassign): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(5);
    $agent->forceFill(['recovery_codes_acknowledged_at' => now()])->save();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2500.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $payload);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::AgentsManage, AdminPermission::CustomersReassign]);
    $profile = $agent->agentProfile;
    $transition = function (bool $available, string $day) use ($admin, $profile, $suspended): void {
        $this->travelTo(CarbonImmutable::parse($day.' 12:00:00', 'Africa/Lagos'));
        $profile->refresh();
        if ($suspended) {
            app(AgentLifecycleService::class)->execute($admin, $profile, $available ? 'restore' : 'suspend',
                $this->agentLifecyclePayload($profile), $this->agentLifecycleRequest($admin));
        } else {
            app(AgentStatusManagementService::class)->transition($admin, $profile, $available ? AgentStatus::Active : AgentStatus::Inactive,
                $profile->version, 'Reviewed service availability.', 'Your original schedule remains.');
        }
    };
    $transition(false, '2026-10-06');
    $table = $suspended ? 'agent_lifecycle_histories' : 'agent_status_histories';
    if ($damage === 'middle' || $recordedActive) {
        $transition(true, '2026-10-07');
    }
    if ($damage === 'middle') {
        $missing = DB::table($table)->where('agent_profile_id', $profile->id)->orderByDesc('id')->value('id');
        DB::table($suspended ? 'agent_lifecycle_notification_intents' : 'agent_status_notification_intents')
            ->where($suspended ? 'agent_lifecycle_history_id' : 'agent_status_history_id', $missing)->delete();
        DB::table($table)->where('id', $missing)->delete();
        $transition(false, '2026-10-08');
    } elseif ($suspended) {
        DB::table('users')->where('id', $agent->id)->update(['account_state' => $recordedActive ? 'suspended' : 'active']);
    } else {
        DB::table('agent_profiles')->where('id', $profile->id)->update(['operational_status' => $recordedActive ? 'inactive' : 'active']);
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00:00', 'Africa/Lagos'));
    if ($reassign) {
        $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
        $handover = app(CustomerReassignmentService::class);
        $preview = $handover->preview($admin, $customer->fresh(), $replacement->id);
        $handover->execute($admin, $customer->fresh(), [
            'attempt_reference' => (string) Str::uuid(), 'version' => $preview['version'],
            'assignment_version' => $preview['assignment_version'], 'preview_token' => $preview['preview_token'],
            'target_agent_id' => $replacement->id, 'confirmed' => true, 'reason' => 'Reviewed service transfer.',
            'customer_explanation' => 'Your responsible Agent has changed.']);
    }
    if ($damage === 'middle') {
        $transition(true, '2026-10-09');
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-09 13:00:00', 'Africa/Lagos'));
    $before = [];
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'customer_assignments',
        'agent_profiles', 'agent_status_histories', 'agent_lifecycle_histories', 'agent_status_notification_intents',
        'agent_lifecycle_notification_intents', 'plan_lifecycle_events', 'collection_receipts', 'collection_allocations',
        'collection_batches', 'ledger_posting_groups', 'ledger_entries', 'fee_obligations', 'fee_obligation_entries',
        'withdrawal_requests', 'withdrawal_reservations', 'collection_annotations'] as $ownerTable) {
        $before[$ownerTable] = DB::table($ownerTable)->orderBy('id')->get()->all();
    }
    $expected = $damage === 'middle'
        ? ['paid', 'unavailable', 'unavailable', $reassign ? 'missed' : 'service-interrupted', 'pending']
        : ['paid', $recordedActive ? 'service-interrupted' : 'unavailable', 'unavailable',
            $reassign ? 'missed' : 'unavailable', $reassign ? 'pending' : 'unavailable'];
    $card = app(CollectionReadService::class)->fundingCard($plan->fresh());
    expect(array_column($card['slots'], 'status'))->toBe($expected);
    expect(array_column($card['slots'], 'funded_kobo'))->toBe([200000, 50000, 0, 0, 0]);
    $workspace = app(CollectionWorkspaceService::class);
    foreach (['2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'] as $index => $due) {
        $work = $workspace->dueWork($admin, $due, '2026-10-09', '', 'all');
        expect($work['slots']->items()[0]['status'])->toBe($expected[$index + 1]);
        expect($work['totals']['covered_kobo'])->toBe($index === 0 ? 50000 : 0);
        expect($work['totals']['outstanding_kobo'])->toBe(in_array($expected[$index + 1], ['pending', 'missed'], true) ? 200000 : 0);
        expect($work['totals']['unavailable_target_kobo'])->toBe($expected[$index + 1] === 'unavailable' ? 200000 : 0);
        expect($workspace->dueWork($admin, $due, '2026-10-09', '', 'missed')['slots']->total())->toBe($expected[$index + 1] === 'missed' ? 1 : 0);
    }
    foreach ([$admin, $customer->user] as $viewer) {
        $this->actingAs($viewer)->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])
            ->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
            ->where('plan.financial_summary.funded_principal', '₦2,500.00')
            ->where('plan.financial_summary.partially_funded_slots', '1')
            ->where('plan.slots.0.collection_status', 'paid')->where('plan.slots.1.collection_status', $expected[1])
            ->where('plan.slots.1.formatted_funded_amount', '₦500.00')->where('plan.slots.2.collection_status', 'unavailable')
            ->where('plan.slots.3.collection_status', $expected[3])->where('plan.slots.4.collection_status', $expected[4]));
    }
    expect($agent->fresh()->account_state->value)->toBe($suspended && $damage === 'suffix' && $recordedActive ? 'suspended' : 'active');
    foreach ($before as $ownerTable => $rows) {
        expect(DB::table($ownerTable)->orderBy('id')->get()->all())->toEqual($rows);
    }
})->with([
    'Operational missing middle' => [false, 'middle', false, false],
    'Account missing middle' => [true, 'middle', false, false],
    'Operational missing middle then reassignment' => [false, 'middle', false, true],
    'Account missing middle then reassignment' => [true, 'middle', false, true],
    'Operational recorded inactive suffix' => [false, 'suffix', false, false],
    'Operational recorded active suffix' => [false, 'suffix', true, false],
    'Account recorded suspended suffix' => [true, 'suffix', false, false],
    'Account recorded active suffix' => [true, 'suffix', true, false],
    'Operational recorded inactive suffix then reassignment' => [false, 'suffix', false, true],
    'Operational recorded active suffix then reassignment' => [false, 'suffix', true, true],
    'Account recorded suspended suffix then reassignment' => [true, 'suffix', false, true],
    'Account recorded active suffix then reassignment' => [true, 'suffix', true, true],
]);

test('historical due work uses the captured local midnight across DST changes', function (string $startDate, string $blockedAt, string $restoredAt): void {
    $this->travelTo(CarbonImmutable::parse($startDate.' 12:00:00', 'America/New_York'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    [, $foreignCustomer, $foreignAgent] = $this->createLifecycleFixture();
    $rule = FeeRule::create(['version' => 1, 'name' => 'No fee for dated participation fixture',
        'kind' => 'plan', 'rule_key' => 'daily', 'model' => 'no_fee', 'timing' => 'first_contribution',
        'basis' => 'none', 'settlement_source' => 'external_receipt', 'currency' => 'NGN', 'amount_kobo' => 0,
        'customer_description' => 'No fee', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $admin->id, 'publication_reason' => 'TEST FIXTURE agreed current terms.']);
    $data = ['name' => 'Captured New York dates', 'amount_ngn' => '2000.00', 'start_date' => $startDate,
        'contribution_days' => 3, 'customer_visible_notes' => '', 'fee_rule_id' => $rule->id, 'fee_rule_version' => $rule->version,
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'business_version' => 1, 'customer_agreement_attested' => true];
    $plans = app(ThriftPlanService::class);
    $foreignData = [...$data, 'name' => 'Retained Lagos captured fixture', 'contribution_days' => 2,
        'customer_version' => $foreignCustomer->version, 'assignment_version' => $foreignCustomer->currentAssignment->version];
    $foreignData['preview_fingerprint'] = $plans->preview($foreignAgent->user, $foreignCustomer, $foreignData)['preview_fingerprint'];
    $plans->create($foreignAgent->user, $foreignCustomer, (string) Str::uuid(), $foreignData);
    BusinessProfile::current()->update(['timezone' => 'America/New_York']);
    $data['preview_fingerprint'] = $plans->preview($agent->user, $customer, $data)['preview_fingerprint'];
    $plan = $plans->create($agent->user, $customer, (string) Str::uuid(), $data)['plan'];
    expect($plan->currentTermsRevision()->timezone)->toBe('America/New_York');
    $statuses = app(CustomerStatusManagementService::class);
    $this->travelTo(CarbonImmutable::parse($blockedAt, 'America/New_York'));
    $customer = $statuses->transition($admin, $customer, CustomerStatus::Inactive, $customer->version,
        'Participation blocked near local midnight.', 'Your original schedule remains.');
    $this->travelTo(CarbonImmutable::parse($restoredAt, 'America/New_York'));
    $customer = $statuses->transition($admin, $customer, CustomerStatus::Active, $customer->version,
        'Participation restored after local midnight.', 'Original remaining slots may be funded.');
    $blockedDate = CarbonImmutable::parse($blockedAt, 'America/New_York')->toDateString();
    $restoredDate = CarbonImmutable::parse($restoredAt, 'America/New_York')->toDateString();
    $workspace = app(CollectionWorkspaceService::class);
    $blocked = $workspace->dueWork($agent->user, $blockedDate, $restoredDate, '', 'all');
    $restored = $workspace->dueWork($agent->user, $restoredDate, $restoredDate, '', 'all');
    $mixed = $workspace->dueWork($admin, $blockedDate, $restoredDate, '', 'all');
    expect($mixed['slots']->total())->toBe(2)
        ->and($mixed['totals']['blocked_target_kobo'])->toBe(200000)
        ->and($mixed['totals']['outstanding_kobo'])->toBe(200000);
    expect($blocked['slots']->total())->toBe(1)
        ->and($blocked['slots']->items()[0]['status'])->toBe('blocked')
        ->and($blocked['totals']['outstanding_kobo'])->toBe(0)
        ->and($blocked['totals']['blocked_target_kobo'])->toBe(200000)
        ->and($restored['slots']->items()[0]['status'])->toBe('pending')
        ->and($restored['totals']['outstanding_kobo'])->toBe(200000)
        ->and(DB::table('collection_annotations')->count())->toBe(0)
        ->and(DB::table('collection_receipts')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
})->with(['23 hour local day' => ['2027-03-13', '2027-03-14 23:30:00', '2027-03-15 00:30:00'],
    '25 hour local day' => ['2027-11-06', '2027-11-07 23:30:00', '2027-11-08 00:30:00']]);

test('due work rejects unavailable captured timezone without silently dropping scoped targets', function (bool $missingRevision): void {
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(2);
    if ($missingRevision) {
        DB::table('thrift_plans')->where('id', $plan->id)->update(['current_terms_revision' => 999]);
    } else {
        DB::table('plan_terms_revisions')->where('id', $plan->currentTermsRevision()->id)->update(['timezone' => 'Invalid/Zone']);
    }
    $workspace = app(CollectionWorkspaceService::class);
    expect(fn () => $workspace->dueWork($agent, $date, $date, '', 'all'))->toThrow(ServiceUnavailableHttpException::class);
    $foreignAgent = User::factory()->agent()->withTwoFactor()->create();
    expect($workspace->dueWork($foreignAgent, $date, $date, '', 'all')['totals']['slot_count'])->toBe(0)
        ->and(DB::table('collection_receipts')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
})->with(['Invalid timezone' => false, 'Missing current revision' => true]);

test('passing the final due date leaves an unpaid or partially funded cycle active without owner mutations', function (?string $amount, int $fundedKobo): void {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 12:00', 'Africa/Lagos'));
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(3, feeAmountKobo: 10000, feeTiming: FeeRuleTiming::CycleCompletion);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    if ($amount !== null) {
        $data = collectionPayload($customer, $assignment, $plan, $date, $amount);
        $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
        app(CollectionService::class)->record($agent, $customer, $data);
    }
    app(LedgerTransactionProjectionService::class)->rebuild();
    $slots = $plan->slots()->orderBy('ordinal')->get()->map->getAttributes()->all();
    $groups = LedgerPostingGroup::query()->get()->map->getAttributes()->all();
    $receiptCount = CollectionReceipt::count();
    $this->travel(4)->days();
    foreach ([$agent, $customer->user] as $viewer) {
        $this->actingAs($viewer)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
            ->where('plan.status', 'active')->where('plan.current_terms.scheduled_end_date', '2027-02-12')
            ->where('plan.financial_summary.fully_funded_slots', '0')
            ->where('plan.financial_summary.partially_funded_slots', $fundedKobo === 0 ? '0' : '1')
            ->where('plan.slots.0.collection_status', $fundedKobo === 0 ? 'missed' : 'partial')
            ->where('plan.slots.1.collection_status', 'missed')->where('plan.slots.2.collection_status', 'missed'));
    }
    expect($plan->fresh()->status->value)->toBe('active')
        ->and($plan->fresh()->open_customer_profile_id)->toBe($customer->id)
        ->and($plan->slots()->orderBy('ordinal')->get()->map->getAttributes()->all())->toBe($slots)
        ->and(LedgerPostingGroup::query()->get()->map->getAttributes()->all())->toBe($groups)
        ->and(CollectionReceipt::count())->toBe($receiptCount)
        ->and($plan->lifecycleEvents()->count())->toBe(0)
        ->and(DB::table('fee_obligations')->count())->toBe(0)
        ->and(DB::table('withdrawal_requests')->count())->toBe(0)
        ->and(DB::table('withdrawal_reservations')->count())->toBe(0)
        ->and(app(CollectionReadService::class)->card($plan->fresh())['funded_kobo'])->toBe($fundedKobo);
})->with(['unpaid' => [null, 0], 'partial' => ['1000.00', 100000]]);

test('advance full funding completes once before the final date and repeated completion delivery adds no owner effects', function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 12:00', 'Africa/Lagos'));
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(3, feeAmountKobo: 10000, feeTiming: FeeRuleTiming::CycleCompletion);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $slots = $plan->slots()->orderBy('ordinal')->get()->map->getAttributes()->all();
    $service = app(CollectionService::class);
    $data = collectionPayload($customer, $assignment, $plan, $date, '6000.00');
    $data['preview_fingerprint'] = $service->preview($agent, $customer, $data)['preview_fingerprint'];
    $receipt = $service->record($agent, $customer, $data);
    expect($plan->fresh()->status->value)->toBe('completed')
        ->and($plan->fresh()->open_customer_profile_id)->toBe($customer->id)
        ->and($plan->lifecycleEvents()->where('event_type', 'completed')->count())->toBe(1)
        ->and(DB::table('fee_obligations')->count())->toBe(1)
        ->and(DB::table('fee_obligation_entries')->where('entry_type', 'assessment')->count())->toBe(1)
        ->and(DB::table('fee_obligation_entries')->where('entry_type', 'settlement')->sum('amount_kobo'))->toBe(10000)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(590000);
    $completion = $plan->lifecycleEvents()->where('event_type', 'completed')->firstOrFail();
    expect($completion->effective_at->timezone('Africa/Lagos')->toDateString())->toBe('2027-02-10')
        ->and($completion->actor_user_id)->toBe($agent->id)
        ->and($completion->payload['receipt_reference'])->toBe($receipt->receipt_reference);
    $baseline = [];
    foreach (['thrift_plans', 'plan_lifecycle_events', 'fee_obligations', 'fee_obligation_entries', 'ledger_posting_groups', 'ledger_entries', 'collection_receipts', 'collection_allocations', 'withdrawal_reservations', 'withdrawal_requests'] as $table) {
        $baseline[$table] = DB::table($table)->orderBy('id')->get()->toArray();
    }
    expect($service->record($agent, $customer, $data)->id)->toBe($receipt->id);
    DB::transaction(fn () => $service->updatePlanCompletionAndFee($plan->fresh(), $agent, $customer->fresh(), $receipt));
    foreach ($baseline as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->toArray())->toEqual($rows);
    }
    app(LedgerTransactionProjectionService::class)->rebuild();
    $this->actingAs($customer->user)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->where('plan.status', 'completed')->where('plan.current_terms.scheduled_end_date', '2027-02-12')
        ->where('plan.financial_summary.fully_funded_slots', '3')->where('plan.financial_summary.funded_principal', '₦6,000.00')
        ->where('plan.slots.0.advance', false)->where('plan.slots.1.advance', true)->where('plan.slots.2.advance', true));
    expect($plan->slots()->orderBy('ordinal')->get()->map->getAttributes()->all())->toBe($slots)
        ->and(DB::table('withdrawal_requests')->count())->toBe(0)
        ->and(DB::table('withdrawal_reservations')->count())->toBe(0);
});

test('a pending correction cannot remove original funded slots through an unsupported release row', function (): void {
    [$agent, $customer, $assignment, , , $receipt] = planFundingFixture();
    $reader = app(ReportReadService::class);
    $filters = ['page_size' => 100, 'group' => ''];
    expect($reader->read($agent, 'plans', $filters)['sections']['funding_progress']['status'])->toBe('Partial');
    $service = app(ReversalService::class);
    $original = LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id);
    $preview = $service->preview($agent, $original);
    $request = $service->submit($agent, $original, ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $preview['preview_fingerprint'], 'customer_version' => $customer->version,
        'assignment_version' => $assignment->version, 'reason_category' => 'wrong_amount_allocation',
        'internal_reason' => 'Original tender remains controlled.', 'customer_explanation' => 'Await independent review.',
        'evidence_text' => 'Review the original receipt and allocation.', 'confirmed' => true]);
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    DB::table('collection_allocation_releases')->insert(['collection_allocation_id' => $receipt->allocations()->orderBy('id')->value('id'),
        'reversal_request_id' => $request->id, 'created_at' => now(), 'updated_at' => now()]);

    $funding = $reader->read($agent, 'plans', $filters)['sections']['funding_progress'];

    expect($funding['status'])->toBe('Unavailable');
    expect($funding['rows'])->toBe([]);
    expect($funding['metrics'])->toBe([]);
    expect($request->fresh()->state)->toBe('pending_review');
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
});

test('an independently approved correction releases complete original slot funding without changing custody', function (): void {
    [$agent, $customer, $assignment, , , $receipt] = planFundingFixture();
    $request = approveReceiptCorrection($this, $agent, $customer, $assignment,
        LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id))->fresh();
    expect($request->state)->toBe('approved_posted');
    app(LedgerTransactionProjectionService::class)->rebuild();
    $groups = LedgerPostingGroup::query()->pluck('id')->all();

    $funding = app(ReportReadService::class)->read($agent, 'plans', ['page_size' => 100, 'group' => ''])['sections']['funding_progress'];

    expect($funding['status'])->toBe('Partial');
    expect($funding['rows'][0]['funded_principal'])->toBe('₦0.00');
    expect($funding['rows'][0]['fully_funded_slots'])->toBe(0);
    expect($funding['rows'][0]['unfunded_slots'])->toBe(2);
    expect($funding['rows'][0]['remaining_scheduled_target'])->toBe('₦4,000.00');
    $this->actingAs($customer->user)->get(route('plans.show', $receipt->plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->where('plan.slots.0.formatted_funded_amount', '₦0.00')->where('plan.slots.0.formatted_remaining_amount', '₦2,000.00')
        ->where('plan.slots.1.formatted_funded_amount', '₦0.00')->where('plan.financial_summary.fully_funded_slots', '0'));
    expect($receipt->fresh()->tender_amount_kobo)->toBe(300000);
    expect((int) $receipt->batch->receipts()->sum('tender_amount_kobo'))->toBe(300000);
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
});

test('damaged approved correction provenance cannot publish net funded slots', function (string $damage): void {
    [$agent, $customer, $assignment, , , $receipt] = planFundingFixture();
    $request = approveReceiptCorrection($this, $agent, $customer, $assignment,
        LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id))->fresh();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $reader = app(ReportReadService::class);
    $filters = ['page_size' => 100, 'group' => ''];
    expect($reader->read($agent, 'plans', $filters)['sections']['funding_progress']['status'])->toBe('Partial');
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    match ($damage) {
        'one_release' => DB::table('collection_allocation_releases')->where('id', DB::table('collection_allocation_releases')->min('id'))->delete(),
        'all_releases' => DB::table('collection_allocation_releases')->delete(),
        'self_review' => DB::table('reversal_requests')->where('id', $request->id)->update(['reviewed_by_user_id' => $agent->id]),
        'approval_event' => DB::table('reversal_events')->where('reversal_request_id', $request->id)->where('event_type', 'approved_posted')->update(['actor_user_id' => $agent->id]),
        'compensation_source' => DB::table('ledger_posting_groups')->where('id', $request->compensation_posting_group_id)->update(['source_id' => 'unrelated-source']),
        'compensation_savings_account' => DB::table('ledger_entries')->where('ledger_posting_group_id', $request->compensation_posting_group_id)
            ->where('side', 'debit')->update(['ledger_account_id' => LedgerAccount::query()->where('code', 'fee_income_ngn')->value('id')]),
        'original_source' => DB::table('ledger_posting_groups')->where('id', $receipt->savings_posting_group_id)->update(['source_id' => 'unrelated-receipt']),
    };

    $funding = $reader->read($agent, 'plans', $filters)['sections']['funding_progress'];

    expect($funding['status'])->toBe('Unavailable');
    expect($funding['rows'])->toBe([]);
    expect($funding['metrics'])->toBe([]);
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
})->with(['one_release', 'all_releases', 'self_review', 'approval_event', 'compensation_source', 'compensation_savings_account', 'original_source']);

test('plan directory and detail expose verified allocation coverage separately from agreed estimates', function (): void {
    $this->freezeTime();
    [$agent, $customer, , $plan] = planFundingFixture();
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    $admin = User::factory()->admin()->withTwoFactor()->create();

    foreach ([$agent, $customer->user, $admin] as $viewer) {
        $this->actingAs($viewer)->get(route('plans.index', ['search' => $plan->plan_id]))
            ->assertInertia(fn (Assert $page) => $page->component('plans/Index')->where('plans.total', 1)
                ->where('plans.data.0.financials.status', 'Partial')->where('plans.data.0.financials.funded_principal', '₦3,000.00')
                ->where('plans.data.0.financials.fully_funded_slots', '1')->where('plans.data.0.financials.required_slots', '2')
                ->where('plans.data.0.financials.partially_funded_slots', '1')
                ->where('plans.data.0.financials.remaining_scheduled_target', '₦1,000.00')
                ->missing('plans.data.0.financials.liability_kobo')->missing('plans.data.0.financials.receiving_location'));
        $this->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page->component('plans/Show')
            ->where('plan.current_terms.expected_gross_kobo', 400000)->where('plan.financial_summary.funded_principal', '₦3,000.00')
            ->where('plan.financial_summary.fully_funded_slots', '1')->where('plan.financial_summary.partially_funded_slots', '1')
            ->where('plan.financial_summary.as_of', now()->toIso8601String())
            ->where('plan.financial_summary.source_version', fn (string $version): bool => strlen($version) === 64)
            ->where('plan.slots.0.formatted_funded_amount', '₦2,000.00')->where('plan.slots.0.formatted_remaining_amount', '₦0.00')
            ->where('plan.slots.0.collection_status', 'paid')->where('plan.slots.1.formatted_funded_amount', '₦1,000.00')
            ->where('plan.slots.1.formatted_remaining_amount', '₦1,000.00')->where('plan.slots.1.collection_status', 'partial')
            ->where('plan.slots.1.advance', true)->missing('plan.slots.0.annotation_reason'));
    }
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
});

test('unverifiable plan funding keeps agreed terms readable without guessed financial zeroes', function (string $damage): void {
    [$agent, , , $plan, , $receipt] = planFundingFixture();
    match ($damage) {
        'projection' => DB::table('ledger_projection_state')->update(['status' => 'unavailable']),
        'allocation' => DB::table('collection_allocations')->where('id', $receipt->allocations()->orderBy('id')->value('id'))->update(['amount_kobo' => 200001]),
        'mapping' => DB::table('ledger_accounts')->where('code', 'customer_savings_liability_ngn')->update(['mapping_status' => 'unmapped']),
        'account_class' => DB::table('ledger_accounts')->where('code', 'customer_savings_liability_ngn')->update(['account_class' => 'asset']),
    };

    $this->actingAs($agent)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->where('plan.current_terms.expected_gross_kobo', 400000)->where('plan.financial_summary.status', 'Unavailable')
        ->where('plan.financial_summary.funded_principal', null)->where('plan.financial_summary.fully_funded_slots', null)
        ->where('plan.financial_summary.remaining_scheduled_target', null)->where('plan.financial_summary.source_version', null)
        ->where('plan.slots.0.formatted_expected_amount', '₦2,000.00')->where('plan.slots.0.formatted_funded_amount', null)
        ->where('plan.slots.0.formatted_remaining_amount', null)->where('plan.slots.0.collection_status', 'unavailable'));
    $this->get(route('plans.index', ['search' => $plan->plan_id]))->assertInertia(fn (Assert $page) => $page
        ->where('plans.data.0.contribution_amount', '₦2,000.00')->where('plans.data.0.financials.status', 'Unavailable')
        ->where('plans.data.0.financials.funded_principal', null));
})->with(['projection', 'allocation', 'mapping', 'account_class']);

test('controlled replacement restores only current allocation coverage while preserving one original cash tender', function (): void {
    [$agent, $customer, $assignment, $plan, $date, $receipt] = planFundingFixture();
    $request = approveReceiptCorrection($this, $agent, $customer, $assignment,
        LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id))->fresh();
    $plan->refresh();
    $data = collectionPayload($customer, $assignment, $plan, $date, '3000.00');
    $service = app(CollectionReplacementService::class);
    $quote = $service->preview($agent, $request, $data);
    $replacement = $service->record($agent, $request, [...$data, 'preview_fingerprint' => $quote['preview_fingerprint'],
        'replacement_fingerprint' => $quote['replacement_fingerprint']]);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $groups = LedgerPostingGroup::query()->pluck('id')->all();

    $this->actingAs($customer->user)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->where('plan.financial_summary.status', 'Partial')->where('plan.financial_summary.funded_principal', '₦3,000.00')
        ->where('plan.financial_summary.fully_funded_slots', '1')->where('plan.financial_summary.partially_funded_slots', '1')
        ->where('plan.slots.0.formatted_funded_amount', '₦2,000.00')->where('plan.slots.1.formatted_funded_amount', '₦1,000.00'));
    expect((int) $receipt->batch->receipts()->sum('tender_amount_kobo'))->toBe(300000);
    expect($replacement->replacement_reversal_id)->toBe($request->id);
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
});

test('per-day funding completes only the original remaining capacity and reads without another journal', function (): void {
    [$agent, $customer, $assignment, $plan, $date] = planFundingFixture();
    $data = collectionPayload($customer, $assignment, $plan, $date, '1000.00');
    $service = app(CollectionService::class);
    $data['preview_fingerprint'] = $service->preview($agent, $customer, $data)['preview_fingerprint'];
    $service->record($agent, $customer, $data);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    $this->actingAs($customer->user)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->where('plan.financial_summary.fully_funded_slots', '2')->where('plan.financial_summary.funded_principal', '₦4,000.00')
        ->where('plan.slots.1.collection_status', 'paid')->where('plan.slots.1.formatted_remaining_amount', '₦0.00'));
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
});

test('a changed plan epoch cannot combine old terms with a newer funding snapshot', function (): void {
    [, $customer, , $plan] = planFundingFixture();
    DB::table('thrift_plans')->where('id', $plan->id)->increment('version');
    $read = app(PlanFundingReadService::class)->readDetail($customer->user, $plan);
    expect($read['summary']['status'])->toBe('Unavailable')->and($read['slots'])->toBeNull();
});

test('unavailable wallet position does not hide independently verified dated allocations', function (): void {
    [, $customer, , $plan] = planFundingFixture();
    $this->partialMock(CollectionReadService::class, fn ($mock) => $mock
        ->shouldReceive('position')->andThrow(new RuntimeException('Wallet owner unavailable.')));
    $read = app(PlanFundingReadService::class)->readDetail($customer->user, $plan);
    expect($read['summary']['funded_principal'])->toBe('₦3,000.00')
        ->and($read['slots'][0]['formatted_funded_amount'])->toBe('₦2,000.00')
        ->and($read['slots'][1]['formatted_remaining_amount'])->toBe('₦1,000.00');
});

test('per-day funding rejects owner disagreement and does not disclose foreign allocations', function (): void {
    [, $customer, , $plan] = planFundingFixture();
    $foreign = User::factory()->customer()->create();
    expect(app(PlanFundingReadService::class)->readDetail($foreign, $plan)['slots'])->toBeNull();
    $card = app(CollectionReadService::class)->fundingCard($plan);
    $card['funded_kobo'] = 100000;
    $this->mock(CollectionReadService::class, fn ($mock) => $mock
        ->shouldReceive('fundingCard')->once()->andReturn($card));
    $read = app(PlanFundingReadService::class)->readDetail($customer->user, $plan);
    expect($read['summary']['status'])->toBe('Unavailable')->and($read['slots'])->toBeNull();
});

test('funded days count original slot capacity rather than receipt count', function (int $receiptCount, string $amount, int $fundedDays, string $fundedTotal, string $remaining): void {
    $this->freezeTime();
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(3);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $originalSlots = $plan->slots()->orderBy('active_ordinal')->get(['id', 'due_date', 'expected_amount_kobo'])->toArray();
    $service = app(CollectionService::class);
    for ($index = 0; $index < $receiptCount; $index++) {
        $data = collectionPayload($customer->fresh(), $assignment->fresh(), $plan->fresh(), $date, $amount);
        $data['preview_fingerprint'] = $service->preview($agent, $customer->fresh(), $data)['preview_fingerprint'];
        $service->record($agent, $customer->fresh(), $data);
    }
    app(LedgerTransactionProjectionService::class)->rebuild();
    expect(CollectionReceipt::query()->count())->toBe($receiptCount)
        ->and($plan->slots()->orderBy('active_ordinal')->get(['id', 'due_date', 'expected_amount_kobo'])->toArray())->toBe($originalSlots);
    $this->actingAs($customer->user)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->where('plan.financial_summary.required_slots', '3')->where('plan.financial_summary.fully_funded_slots', (string) $fundedDays)
        ->where('plan.financial_summary.partially_funded_slots', '0')->where('plan.financial_summary.funded_principal', $fundedTotal)
        ->where('plan.financial_summary.remaining_scheduled_target', $remaining)->where('plan.slots.0.collection_status', 'paid')
        ->where('plan.slots.0.formatted_funded_amount', '₦2,000.00'));
    $this->get(route('plans.index', ['search' => $plan->plan_id]))->assertInertia(fn (Assert $page) => $page
        ->where('plans.data.0.financials.fully_funded_slots', (string) $fundedDays)
        ->where('plans.data.0.financials.funded_principal', $fundedTotal));
    $allocations = DB::table('collection_allocations')->whereIn('contribution_slot_id', array_column($originalSlots, 'id'))
        ->selectRaw('contribution_slot_id, COUNT(*) AS receipts, SUM(amount_kobo) AS funded')->groupBy('contribution_slot_id')->get();
    expect($allocations)->toHaveCount($fundedDays);
    foreach ($allocations as $allocation) {
        expect((int) $allocation->funded)->toBe(200000);
    }
    if ($receiptCount === 10) {
        expect((int) $allocations->sole()->receipts)->toBe(10)->and($plan->fresh()->status->value)->toBe('active');
    } else {
        expect($plan->fresh()->status->value)->toBe('completed');
    }
})->with([
    'ten partial receipts fund one day' => [10, '200.00', 1, '₦2,000.00', '₦4,000.00'],
    'one receipt funds three days' => [1, '6000.00', 3, '₦6,000.00', '₦0.00'],
]);

test('participation history suffix disagreement preserves money and makes uncertain residuals unavailable', function (string $owner, bool $recordedActive): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(4);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2500.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $payload);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'Africa/Lagos'));
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::CustomersManage);
    if ($owner === 'customer') {
        $service = app(CustomerStatusManagementService::class);
        $customer = $service->transition($admin, $customer, CustomerStatus::Inactive, $customer->version,
            'Reviewed interruption.', 'Your dated agreement remains.');
        if ($recordedActive) {
            $customer = $service->transition($admin, $customer, CustomerStatus::Active, $customer->version,
                'Reviewed restoration.', 'Your dated agreement remains.');
        }
        DB::table('customer_profiles')->where('id', $customer->id)->update([
            'operational_status' => $recordedActive ? 'inactive' : 'active',
        ]);
    } else {
        foreach ($recordedActive ? ['pause', 'resume'] : ['pause'] as $action) {
            $plan->refresh();
            app(ThriftPlanService::class)->transition($agent, $plan, $action, (string) Str::uuid(), [
                'customer_version' => $customer->version, 'assignment_version' => $assignment->version,
                'plan_version' => $plan->version, 'reason' => 'Reviewed participation change.',
                'customer_explanation' => 'Your dated agreement remains.']);
        }
        DB::table('thrift_plans')->where('id', $plan->id)->update(['status' => $recordedActive ? 'paused' : 'active']);
    }
    $before = [];
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'customer_status_histories',
        'plan_lifecycle_events', 'collection_receipts', 'collection_allocations', 'ledger_posting_groups',
        'ledger_entries', 'fee_obligations', 'fee_obligation_entries', 'withdrawal_requests',
        'withdrawal_reservations', 'collection_annotations'] as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'Africa/Lagos'));
    $card = app(CollectionReadService::class)->fundingCard($plan->fresh());
    expect(array_column($card['slots'], 'status'))->toBe(['paid', 'unavailable', 'unavailable', 'unavailable']);
    expect(array_column($card['slots'], 'funded_kobo'))->toBe([200000, 50000, 0, 0]);
    $workspace = app(CollectionWorkspaceService::class);
    foreach (['2026-10-06' => 50000, '2026-10-07' => 0, '2026-10-08' => 0] as $due => $covered) {
        $work = $workspace->dueWork($agent, $due, '2026-10-08', '', 'all');
        expect($work['slots']->items()[0]['status'])->toBe('unavailable');
        expect($work['totals']['covered_kobo'])->toBe($covered);
        expect($work['totals']['outstanding_kobo'])->toBe(0);
        expect($work['totals']['unavailable_target_kobo'])->toBe(200000);
        expect($workspace->dueWork($agent, $due, '2026-10-08', '', 'missed')['slots']->total())->toBe(0);
    }
    foreach ([$agent, $customer->user] as $viewer) {
        $this->actingAs($viewer)->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])
            ->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
            ->where('plan.financial_summary.funded_principal', '₦2,500.00')
            ->where('plan.financial_summary.partially_funded_slots', '1')
            ->where('plan.slots.0.collection_status', 'paid')
            ->where('plan.slots.1.collection_status', 'unavailable')
            ->where('plan.slots.1.formatted_funded_amount', '₦500.00'));
    }
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
})->with(['Customer recorded blocked' => ['customer', false], 'Customer recorded restored' => ['customer', true],
    'plan recorded paused' => ['plan', false], 'plan recorded resumed' => ['plan', true]]);
