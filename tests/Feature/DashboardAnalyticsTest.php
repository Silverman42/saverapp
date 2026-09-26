<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\CustomerAssignmentStatus;
use App\Enums\FeeRuleBasis;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Enums\ThriftPlanStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AgentProfile;
use App\Models\BusinessProfile;
use App\Models\CollectionReceipt;
use App\Models\ContributionSlot;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\FeeRule;
use App\Models\FeeSnapshot;
use App\Models\PlanTermsRevision;
use App\Models\ReversalRequest;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\DashboardReadService;
use App\Services\LedgerTransactionProjectionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function dashboardFixture(): array
{
    $agent = User::factory()->agent()->create([
        'two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now(),
    ]);
    $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agent->id]);
    $customer = CustomerProfile::factory()->create();
    $assignment = CustomerAssignment::factory()->create([
        'customer_profile_id' => $customer->id, 'agent_profile_id' => $agentProfile->id,
        'assigned_by_user_id' => $agent->id, 'status' => CustomerAssignmentStatus::Current,
    ]);
    $rule = FeeRule::create([
        'version' => 1, 'name' => 'No plan fee', 'kind' => FeeRuleKind::Plan,
        'rule_key' => 'test-plan', 'model' => FeeRuleModel::NoFee,
        'timing' => FeeRuleTiming::FirstContribution, 'basis' => FeeRuleBasis::None,
        'settlement_source' => FeeSettlementSource::ExternalReceipt,
        'currency' => 'NGN', 'amount_kobo' => 0, 'customer_description' => 'No fee',
        'effective_at' => now()->subDay(), 'published_by_user_id' => $agent->id,
        'publication_reason' => 'Test plan rule.',
    ]);
    $plan = ThriftPlan::create([
        'plan_id' => 'PLN-TEST-001', 'customer_profile_id' => $customer->id,
        'created_by_user_id' => $agent->id, 'open_customer_profile_id' => $customer->id,
        'status' => ThriftPlanStatus::Active, 'current_terms_revision' => 1, 'version' => 1,
    ]);
    $snapshot = FeeSnapshot::create([
        'customer_profile_id' => $customer->id, 'source_type' => 'plan', 'source_id' => $plan->plan_id,
        'fee_rule_id' => $rule->id, 'fee_rule_version' => 1, 'name' => 'No plan fee',
        'kind' => FeeRuleKind::Plan, 'model' => FeeRuleModel::NoFee,
        'timing' => FeeRuleTiming::FirstContribution, 'basis' => FeeRuleBasis::None,
        'settlement_source' => FeeSettlementSource::ExternalReceipt,
        'currency' => 'NGN', 'amount_kobo' => 0, 'basis_amount_kobo' => 0,
        'customer_description' => 'No fee', 'acknowledged_at' => now(),
    ]);
    $today = CarbonImmutable::now('Africa/Lagos')->toDateString();
    $terms = PlanTermsRevision::create([
        'thrift_plan_id' => $plan->id, 'revision' => 1, 'name' => 'Daily plan',
        'contribution_amount_kobo' => 200000, 'currency' => 'NGN', 'start_date' => $today,
        'contribution_days' => 2, 'frequency' => 'daily', 'timezone' => 'Africa/Lagos',
        'business_version' => 1, 'expected_gross_kobo' => 400000,
        'fee_snapshot_id' => $snapshot->id, 'attested_by_user_id' => $agent->id,
        'attested_at' => now(),
    ]);
    foreach ([0, 1] as $index) {
        ContributionSlot::create([
            'thrift_plan_id' => $plan->id, 'plan_terms_revision_id' => $terms->id,
            'ordinal' => $index + 1, 'active_ordinal' => $index + 1,
            'due_date' => CarbonImmutable::parse($today, 'Africa/Lagos')->addDays($index)->toDateString(),
            'expected_amount_kobo' => 200000,
        ]);
    }

    return [$agent, $customer, $assignment, $plan, $today];
}

function dashboardReceiptPayload(CustomerProfile $customer, CustomerAssignment $assignment, ThriftPlan $plan, string $date, string $amount): array
{
    return [
        'attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $assignment->version, 'business_version' => 1,
        'plan_id' => $plan->plan_id, 'plan_version' => $plan->version,
        'received_date' => $date, 'savings_ngn' => $amount,
        'fees' => [], 'allocations' => [], 'late_reason' => '', 'notes' => '', 'confirmed' => true,
    ];
}

function dashboardPostReceipt(array $fixture, string $amount = '2000.00', ?string $receivedDate = null): void
{
    [$agent, $customer, $assignment, $plan, $today] = $fixture;
    config()->set('collections.enabled', true);
    $payload = dashboardReceiptPayload($customer, $assignment, $plan, $receivedDate ?? $today, $amount);
    if ($receivedDate !== null && $receivedDate !== $today) {
        $payload['late_reason'] = 'Received on the recorded business date.';
    }
    $preview = test()->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    test()->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    app(LedgerTransactionProjectionService::class)->rebuild();
}

function dashboardMetric(array $data, string $section, string $code): int
{
    return collect($data['sections'][$section]['metrics'])->firstWhere('code', $code)['value'];
}

test('dashboards deny direct cross role reads', function (string $role): void {
    $customer = CustomerProfile::factory()->create();

    $this->actingAs($customer->user)->get(route($role.'.dashboard'))->assertForbidden();
})->with(['agent', 'admin']);

test('unverified money is unavailable while current portfolio remains readable', function (): void {
    $customer = CustomerProfile::factory()->create();

    $this->actingAs($customer->user)->get(route('customer.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')->where('dashboard.role', 'customer')
        ->where('dashboard.sections.portfolio.status', 'Current')
        ->where('dashboard.sections.savings.status', 'Unavailable')
        ->where('dashboard.sections.savings.metrics', [])
        ->where('dashboard.sections.collections.status', 'Unavailable'));
});

test('a verified empty ledger shows real zeros rather than unavailable', function (): void {
    $customer = CustomerProfile::factory()->create();
    app(LedgerTransactionProjectionService::class)->rebuild();

    $this->actingAs($customer->user)->get(route('customer.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.sections.savings.status', 'Current')
        ->where('dashboard.sections.savings.metrics.0.value', 0)
        ->where('dashboard.sections.collections.metrics.0.value', 0));
});

test('customer dashboards isolate balances and subtract live gross reservations once', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00', 'Africa/Lagos'));
    $fixture = dashboardFixture();
    dashboardPostReceipt($fixture, '3000.00');
    $customer = $fixture[1];
    $other = CustomerProfile::factory()->create();
    DB::table('withdrawal_reservations')->insert([
        'customer_profile_id' => $customer->id, 'owner_reference' => 'DSH-RESERVATION-1',
        'gross_amount_kobo' => 50000, 'status' => 'live', 'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($customer->user)->get(route('customer.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.sections.savings.metrics.0.value', 300000)
        ->where('dashboard.sections.savings.metrics.1.value', 50000)
        ->where('dashboard.sections.savings.metrics.2.value', 250000)
        ->where('dashboard.sections.collections.metrics.0.value', 300000)
        ->where('dashboard.sections.activity.total', 1));
    $this->actingAs($other->user)->get(route('customer.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.sections.savings.metrics.0.value', 0)->where('dashboard.sections.activity.total', 0));
});

test('admin headline liability retains archived balances when operational filters change', function (): void {
    $fixture = dashboardFixture();
    dashboardPostReceipt($fixture);
    $fixture[1]->update(['operational_status' => 'archived']);
    $admin = User::factory()->admin()->create();
    $data = app(DashboardReadService::class)->read($admin, [
        'period' => 'today', 'from' => $fixture[4], 'to' => $fixture[4], 'page_size' => 25, 'customer_status' => 'active',
    ]);

    expect(dashboardMetric($data, 'portfolio', 'customers_total'))->toBe(0)
        ->and(dashboardMetric($data, 'savings', 'customer_liability'))->toBe(200000);
    $this->actingAs($fixture[1]->user)->get(route('customer.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.sections.savings.metrics.0.value', 200000)->where('dashboard.sections.activity.total', 1));
});

test('reassignment removes portfolio while preserving own recorded totals and custody without former customer detail', function (): void {
    $fixture = dashboardFixture();
    dashboardPostReceipt($fixture);
    [$agent, $customer, $assignment] = $fixture;
    $newAgent = User::factory()->agent()->create(['two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now()]);
    $newProfile = AgentProfile::factory()->active()->create(['user_id' => $newAgent->id]);
    $service = app(DashboardReadService::class);
    $before = $service->scopeSummary($agent);
    $assignment->update(['status' => CustomerAssignmentStatus::Ended, 'is_current' => null, 'ended_at' => now()]);
    CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $newProfile->id, 'version' => 2]);

    $this->actingAs($agent)->get(route('agent.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.sections.portfolio.metrics.0.value', 0)
        ->where('dashboard.sections.collections.metrics.0.value', 200000)
        ->where('dashboard.sections.custody.metrics.0.value', 200000)
        ->where('dashboard.sections.activity.total', 0)
        ->where('dashboard.sections.schedule.rows', []));
    $this->actingAs($newAgent)->get(route('agent.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.sections.portfolio.metrics.0.value', 1)
        ->where('dashboard.sections.collections.metrics.0.value', 0)
        ->where('dashboard.sections.custody.metrics.0.value', 0));
    expect($service->scopeSummary($agent)['fingerprint'])->not->toBe($before['fingerprint']);
});

test('advance funding and today received money use different date bases', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00:00', 'Africa/Lagos'));
    $fixture = dashboardFixture();
    dashboardPostReceipt($fixture, '4000.00');
    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00', 'Africa/Lagos'));

    $this->actingAs($fixture[1]->user)->get(route('customer.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.sections.collections.metrics.0.value', 0)
        ->where('dashboard.sections.schedule.metrics.0.value', 200000)
        ->where('dashboard.sections.schedule.metrics.1.value', 200000)
        ->where('dashboard.sections.schedule.metrics.2.value', 2)
        ->where('dashboard.sections.schedule.metrics.3.value', 0)
        ->where('dashboard.sections.schedule.status', 'Partial'));
});

test('an inactive usable agent keeps permitted reads and loses collection eligibility', function (): void {
    $fixture = dashboardFixture();
    dashboardPostReceipt($fixture);
    $fixture[0]->agentProfile->update(['operational_status' => 'inactive']);

    $this->actingAs($fixture[0])->get(route('agent.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('scopeSummary.can_collect', false)
        ->where('dashboard.sections.portfolio.metrics.0.value', 1));
});

test('reservation inconsistency fails savings closed without hiding independent receipt activity', function (): void {
    $fixture = dashboardFixture();
    dashboardPostReceipt($fixture);
    DB::table('withdrawal_reservations')->insert([
        'customer_profile_id' => $fixture[1]->id, 'owner_reference' => 'DSH-INVALID-RESERVATION',
        'gross_amount_kobo' => 200001, 'status' => 'live', 'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($fixture[1]->user)->get(route('customer.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.sections.savings.status', 'Partial')
        ->where('dashboard.sections.savings.metrics.0.value', 200000)
        ->where('dashboard.sections.savings.metrics.2.value', null)
        ->where('dashboard.sections.collections.status', 'Current')
        ->where('dashboard.sections.collections.metrics.0.value', 200000));
});

test('a detected ledger incident suppresses financial sections immediately', function (): void {
    $fixture = dashboardFixture();
    dashboardPostReceipt($fixture);
    DB::table('ledger_projection_state')->where('id', 1)->update(['status' => 'unavailable']);

    $this->actingAs($fixture[1]->user)->get(route('customer.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.sections.savings.status', 'Unavailable')
        ->where('dashboard.sections.schedule.status', 'Unavailable')
        ->where('dashboard.sections.activity.status', 'Unavailable')
        ->where('dashboard.sections.requests.status', 'Current'));
});

test('dashboard filters reject invalid date ranges and cross agent inputs', function (array $filters, string $error): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00', 'Africa/Lagos'));
    $customer = CustomerProfile::factory()->create();

    $this->actingAs($customer->user)->getJson(route('customer.dashboard', $filters))->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    'too long' => [['period' => 'custom', 'from' => '2025-09-25', 'to' => '2026-09-26'], 'to'],
    'reversed' => [['period' => 'custom', 'from' => '2026-09-26', 'to' => '2026-09-25'], 'to'],
    'future' => [['period' => 'custom', 'from' => '2026-09-26', 'to' => '2026-09-27'], 'to'],
    'invalid date' => [['period' => 'custom', 'from' => 'wrong', 'to' => '2026-09-26'], 'from'],
    'missing range' => [['period' => 'custom'], 'from'],
    'agent filter' => [['agent' => 'AGT-001', 'agent_basis' => 'recording'], 'agent'],
    'forged field' => [['balance_kobo' => 123], 'balance_kobo'],
    'invalid page size' => [['page_size' => 500], 'page_size'],
]);

test('period normalization uses business local dates including Monday and month boundaries', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-27 23:30:00', 'UTC'));
    $customer = CustomerProfile::factory()->create();

    $this->actingAs($customer->user)->get(route('customer.dashboard', ['period' => 'week']))->assertInertia(fn (Assert $page) => $page
        ->where('filters.from', '2026-09-28')->where('filters.to', '2026-09-28'));
    $this->get(route('customer.dashboard', ['period' => 'month']))->assertInertia(fn (Assert $page) => $page
        ->where('filters.from', '2026-09-01')->where('filters.to', '2026-09-28'));
});

test('366 inclusive dates are accepted and partial reloads reauthorize scope without financial reads', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00', 'Africa/Lagos'));
    $customer = CustomerProfile::factory()->create();

    $this->actingAs($customer->user)->get(route('customer.dashboard', ['period' => 'custom', 'from' => '2025-09-26', 'to' => '2026-09-26']))->assertInertia(fn (Assert $page) => $page
        ->where('filters.from', '2025-09-26')
        ->reloadOnly('scopeSummary', fn (Assert $reload) => $reload->has('scopeSummary.fingerprint')->missing('dashboard')));
});

test('review permission revocation hides protected task links while preserving baseline counts', function (): void {
    $fixture = dashboardFixture();
    dashboardPostReceipt($fixture);
    [$agent, $customer, $assignment, $plan] = $fixture;
    $group = CollectionReceipt::query()->firstOrFail()->savings_posting_group_id;
    $reversal = ReversalRequest::create([
        'reversal_id' => 'REV-DSH-001', 'customer_profile_id' => $customer->id,
        'original_posting_group_id' => $group, 'live_original_posting_group_id' => $group,
        'requested_by_user_id' => $agent->id, 'initiating_agent_profile_id' => $agent->agentProfile->id,
        'assignment_id' => $assignment->id, 'state' => 'pending_review', 'version' => 1,
        'reason_category' => 'duplicate_posting', 'internal_reason' => 'Private reason',
        'customer_explanation' => 'A receipt is being reviewed.', 'evidence_text' => 'Private evidence',
        'dependency_fingerprint' => str_repeat('a', 64), 'dependency_snapshot' => [],
        'original_amount_kobo' => 200000, 'currency' => 'NGN',
    ]);
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::ReversalsReview->value);
    $service = app(DashboardReadService::class);
    $fingerprint = $service->scopeSummary($admin)['fingerprint'];
    $this->actingAs($admin)->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.sections.requests.rows.0.reference', $reversal->reversal_id)
        ->missing('dashboard.sections.requests.rows.0.evidence_text'));
    $admin->revokePermissionTo(AdminPermission::ReversalsReview->value);

    $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.sections.requests.metrics.1.value', 1)
        ->where('dashboard.sections.requests.rows', []));
    expect($service->scopeSummary($admin)['fingerprint'])->not->toBe($fingerprint);
    $reversal->update(['state' => 'rejected']);
    $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page->where('dashboard.sections.requests.metrics.1.value', 0));
});

test('a suspended account loses dashboard access and dashboard routes accept no mutations', function (): void {
    $customer = CustomerProfile::factory()->create();
    $this->actingAs($customer->user)->post(route('customer.dashboard'), ['amount_kobo' => 100])->assertMethodNotAllowed();
    $this->assertDatabaseCount('collection_receipts', 0);
    $customer->user->update(['account_state' => AccountState::Suspended]);

    $this->actingAs($customer->user->fresh())->get(route('customer.dashboard'))->assertRedirect(route('login'));
});

test('admin attribution filters do not transfer recording agent receipts into the current portfolio', function (): void {
    $fixture = dashboardFixture();
    dashboardPostReceipt($fixture);
    [$agent, $customer, $assignment] = $fixture;
    $newProfile = AgentProfile::factory()->active()->create();
    $assignment->update(['status' => CustomerAssignmentStatus::Ended, 'is_current' => null, 'ended_at' => now()]);
    CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $newProfile->id, 'version' => 2]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('admin.dashboard', ['agent_basis' => 'recording', 'agent' => $agent->agentProfile->agent_id]))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.sections.collections.metrics.0.value', 200000));
    $this->get(route('admin.dashboard', ['agent_basis' => 'current', 'agent' => $agent->agentProfile->agent_id]))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.sections.collections.metrics.0.value', 0));
    $this->get(route('admin.dashboard', ['agent_basis' => 'recording', 'agent' => 'AGT-MISSING']))->assertNotFound();
});

test('open plan counts use lifecycle states even after the scheduled end', function (string $status, int $open): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 12:00:00', 'Africa/Lagos'));
    $fixture = dashboardFixture();
    $fixture[3]->update(['status' => $status]);
    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00', 'Africa/Lagos'));
    app(LedgerTransactionProjectionService::class)->rebuild();
    $data = app(DashboardReadService::class)->read($fixture[1]->user, [
        'period' => 'today', 'from' => '2026-09-26', 'to' => '2026-09-26', 'page_size' => 25,
    ]);

    expect(dashboardMetric($data, 'portfolio', 'open_plans'))->toBe($open)
        ->and(dashboardMetric($data, 'portfolio', 'plans_'.$status))->toBe(1);
})->with([['active', 1], ['paused', 1], ['completed', 1], ['closed', 0], ['cancelled', 0]]);

test('business timezone changes do not shift existing slot or receipt dates', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 00:30:00', 'Africa/Lagos'));
    $fixture = dashboardFixture();
    dashboardPostReceipt($fixture, '1000.00');
    BusinessProfile::current()->update(['timezone' => 'Pacific/Honolulu', 'version' => 2]);

    $this->actingAs($fixture[1]->user)->get(route('customer.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('filters.to', '2026-09-25')
        ->where('dashboard.manifest.timezone', 'Pacific/Honolulu')
        ->where('dashboard.sections.collections.metrics.0.value', 0)
        ->where('dashboard.sections.schedule.metrics.0.value', 200000)
        ->where('dashboard.sections.schedule.metrics.1.value', 100000));
});

test('agent scope refresh denies an account without confirmed MFA even when it has no assignments', function (): void {
    $agent = User::factory()->agent()->create();
    AgentProfile::factory()->active()->create(['user_id' => $agent->id]);

    $this->actingAs($agent)->withHeaders([
        'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Dashboard', 'X-Inertia-Partial-Data' => 'scopeSummary',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')),
    ])->get(route('agent.dashboard'))->assertForbidden();
});
