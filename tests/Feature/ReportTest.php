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
use App\Models\AgentProfile;
use App\Models\BusinessProfile;
use App\Models\ContributionSlot;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\FeeRule;
use App\Models\FeeSnapshot;
use App\Models\FinancialPeriod;
use App\Models\PlanTermsRevision;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\DashboardReadService;
use App\Services\FeeObligationService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\ReportCatalogue;
use App\Services\ReportReadService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function reportFixture(): array
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
    if (! FinancialPeriod::query()->whereDate('month', substr($today, 0, 7).'-01')->exists()) {
        FinancialPeriod::factory()->create(['month' => substr($today, 0, 7).'-01', 'changed_by_user_id' => $agent->id]);
    }
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

function reportReceiptPayload(CustomerProfile $customer, CustomerAssignment $assignment, ThriftPlan $plan, string $date, string $amount): array
{
    return [
        'attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $assignment->version, 'business_version' => 1,
        'plan_id' => $plan->plan_id, 'plan_version' => $plan->version,
        'received_date' => $date, 'savings_ngn' => $amount,
        'fees' => [], 'allocations' => [], 'late_reason' => '', 'notes' => '', 'confirmed' => true,
    ];
}

function reportPostReceipt(array $fixture, string $amount = '2000.00', ?string $receivedDate = null): void
{
    [$agent, $customer, $assignment, $plan, $today] = $fixture;
    config()->set('collections.enabled', true);
    $date = $receivedDate ?? $today;
    if (! FinancialPeriod::query()->where('business_profile_id', BusinessProfile::current()->id)
        ->where('timezone', BusinessProfile::current()->timezone)
        ->whereDate('month', substr($date, 0, 7).'-01')->exists()) {
        FinancialPeriod::factory()->create(['month' => substr($date, 0, 7).'-01', 'changed_by_user_id' => $agent->id]);
    }
    $payload = reportReceiptPayload($customer->fresh(), $assignment->fresh(), $plan->fresh(), $receivedDate ?? $today, $amount);
    if ($receivedDate !== null && $receivedDate !== $today) {
        $payload['late_reason'] = 'Received on the recorded business date.';
    }
    $preview = test()->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    test()->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    app(LedgerTransactionProjectionService::class)->rebuild();
}

function reportData(User $viewer, string $code, array $filters = []): array
{
    $today = CarbonImmutable::now(BusinessProfile::current()->timezone);
    $defaults = ['page_size' => 25, 'group' => ''];
    if (app(ReportCatalogue::class)->get($viewer, $code)['activity']) {
        $defaults += ['from' => $today->startOfMonth()->toDateString(), 'to' => $today->toDateString()];
    }

    return app(ReportReadService::class)->read($viewer, $code, [...$defaults, ...$filters]);
}

function reportValue(array $data, string $code, string $section = 'primary'): ?int
{
    return collect($data['sections'][$section]['metrics'])->firstWhere('code', $code)['value'];
}

function reportWithdrawal(array $fixture, string $state = 'pending_review'): WithdrawalRequest
{
    [$agent, $customer, $assignment, $plan] = $fixture;

    return WithdrawalRequest::create([
        'withdrawal_id' => 'WDL-RPT-001', 'customer_profile_id' => $customer->id, 'thrift_plan_id' => $plan->id,
        'initiating_agent_profile_id' => $agent->agentProfile->id, 'assignment_id' => $assignment->id,
        'submitted_by_user_id' => $agent->id, 'fee_snapshot_id' => $plan->currentTermsRevision()->fee_snapshot_id,
        'type' => 'partial', 'state' => $state, 'held' => true, 'gross_amount_kobo' => 30000, 'fee_amount_kobo' => 0,
        'net_amount_kobo' => 30000, 'currency' => 'NGN', 'method' => 'cash', 'destination_reference' => 'PRIVATE-BANK',
        'destination_mask' => 'Private destination', 'reason' => 'PRIVATE-REASON', 'internal_notes' => 'PRIVATE-NOTES',
        'customer_version' => 1, 'assignment_version' => 1, 'plan_version' => 1, 'business_version' => 1,
        'method_version' => 1, 'version' => 1, 'submitted_at' => now(), 'deadline_at' => now()->addDays(7),
    ]);
}

test('report center lists nine admin families while customer custody and peer reports are forbidden', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();

    $this->actingAs($admin)->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/Index')->has('catalogue', 9)->where('catalogue.0.export_available', false));
    $this->actingAs($customer->user)->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page->has('catalogue', 7));
    $this->get(route('reports.show', 'reconciliation'))->assertForbidden();
    $this->get(route('reports.show', 'agent-performance'))->assertForbidden();
    $this->get(route('reports.show', 'unknown'))->assertNotFound();
});

test('report reads require authentication and usable synchronized accounts', function (): void {
    $this->get(route('reports.index'))->assertRedirect(route('login'));
    $customer = CustomerProfile::factory()->create();
    $customer->user->update(['account_state' => AccountState::Suspended]);

    $this->actingAs($customer->user->fresh())->get(route('reports.index'))->assertRedirect(route('login'));
});

test('report endpoints reject mutations and export permission cannot create files', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::ReportsExport->value);

    $this->actingAs($admin)->post(route('reports.show', 'contributions'), ['amount_kobo' => 100])->assertMethodNotAllowed();
    $this->get(route('reports.show', 'fees'))->assertInertia(fn (Assert $page) => $page
        ->where('definition.export_available', false)->where('report.sections.primary.status', 'Unavailable')
        ->where('report.sections.primary.metrics', []));
    $this->assertDatabaseCount('collection_receipts', 0);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
});

test('savings reports isolate customers and subtract live gross reservations once including archived balances', function (): void {
    $fixture = reportFixture();
    reportPostReceipt($fixture, '3000.00');
    $customer = $fixture[1];
    $customer->update(['operational_status' => 'archived']);
    $other = CustomerProfile::factory()->create();
    DB::table('withdrawal_reservations')->insert([
        'customer_profile_id' => $customer->id, 'owner_reference' => 'RPT-RESERVATION', 'gross_amount_kobo' => 50000,
        'status' => 'live', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $data = reportData($customer->user, 'customer-summary');

    expect(reportValue($data, 'customer_liability'))->toBe(300000);
    expect(reportValue($data, 'live_payout_reservations'))->toBe(50000);
    expect(reportValue($data, 'available_savings'))->toBe(250000);
    expect($data['sections']['primary']['rows'])->toHaveCount(1);
    expect(reportValue(reportData($other->user, 'customer-summary'), 'customer_liability'))->toBe(0);
    $this->actingAs($other->user)->get(route('reports.show', ['report' => 'customer-summary', 'customer' => $customer->customer_id]))->assertNotFound();
});

test('reservation failure preserves verified liability and makes availability unavailable', function (): void {
    $fixture = reportFixture();
    reportPostReceipt($fixture);
    DB::table('withdrawal_reservations')->insert([
        'customer_profile_id' => $fixture[1]->id, 'owner_reference' => 'RPT-INVALID', 'gross_amount_kobo' => 200001,
        'status' => 'live', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $data = reportData($fixture[1]->user, 'customer-summary');

    expect(reportValue($data, 'customer_liability'))->toBe(200000);
    expect(reportValue($data, 'available_savings'))->toBeNull();
    expect($data['sections']['primary']['rows'][0]['available_savings'])->toBe('Not available');
});

test('verified empty sources show real zero while missing projections show unavailable', function (): void {
    $customer = CustomerProfile::factory()->create();

    expect(reportData($customer->user, 'customer-summary')['sections']['primary']['status'])->toBe('Unavailable');
    app(LedgerTransactionProjectionService::class)->rebuild();
    expect(reportValue(reportData($customer->user, 'customer-summary'), 'customer_liability'))->toBe(0);
});

test('one receipt allocated across slots is counted once and grouped totals reconcile', function (): void {
    $fixture = reportFixture();
    reportPostReceipt($fixture, '3000.00');

    $data = reportData($fixture[1]->user, 'contributions', ['group' => 'date']);

    expect($data['sections']['primary']['total'])->toBe(1);
    expect(reportValue($data, 'received_savings'))->toBe(300000);
    expect(reportValue($data, 'posted_receipt_count'))->toBe(1);
    expect($data['sections']['primary']['rows'][0]['allocation_count'])->toBe(2);
    expect($data['sections']['primary']['groups'][0]['metrics'][0]['value'])->toBe(300000);
});

test('pagination totals cover all pages and cursor returns each stable row once', function (): void {
    $this->freezeTime();
    $fixture = reportFixture();
    for ($index = 0; $index < 26; $index++) {
        reportPostReceipt($fixture, '100.00');
    }
    $first = reportData($fixture[1]->user, 'contributions', ['group' => 'date']);
    $cursor = $first['sections']['primary']['next_cursor'];

    $second = reportData($fixture[1]->user, 'contributions', ['group' => 'date', 'cursor' => $cursor]);

    expect($first['sections']['primary']['rows'])->toHaveCount(25);
    expect($second['sections']['primary']['rows'])->toHaveCount(1);
    expect(reportValue($first, 'received_savings'))->toBe(260000);
    expect(reportValue($second, 'received_savings'))->toBe(260000);
    expect($second['sections']['primary']['next_cursor'])->toBeNull();
    expect(collect([...$first['sections']['primary']['rows'], ...$second['sections']['primary']['rows']])->pluck('key')->unique())->toHaveCount(26);
});

test('cursors reject tampering changed filters another viewer and changed source data', function (): void {
    $admin = User::factory()->admin()->create();
    CustomerProfile::factory()->count(26)->create();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $cursor = reportData($admin, 'customer-summary')['sections']['primary']['next_cursor'];

    $this->actingAs($admin)->getJson(route('reports.show', ['report' => 'customer-summary', 'cursor' => 'tampered']))->assertUnprocessable();
    $this->getJson(route('reports.show', ['report' => 'customer-summary', 'cursor' => $cursor, 'page_size' => 50]))->assertUnprocessable();
    $other = User::factory()->admin()->create();
    $this->actingAs($other)->getJson(route('reports.show', ['report' => 'customer-summary', 'cursor' => $cursor]))->assertUnprocessable();
    DB::table('users')->where('id', CustomerProfile::query()->first()->user_id)->update(['name' => 'Changed name']);
    $this->actingAs($admin)->getJson(route('reports.show', ['report' => 'customer-summary', 'cursor' => $cursor]))->assertUnprocessable();
});

test('reassignment removes former customer details but preserves original agent custody and masked actor totals', function (): void {
    $fixture = reportFixture();
    reportPostReceipt($fixture);
    [$agent, $customer, $assignment] = $fixture;
    $newAgent = User::factory()->agent()->create(['two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now()]);
    $newProfile = AgentProfile::factory()->active()->create(['user_id' => $newAgent->id]);
    $assignment->update(['status' => CustomerAssignmentStatus::Ended, 'is_current' => null, 'ended_at' => now()]);
    CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $newProfile->id, 'version' => 2]);

    expect(reportData($agent, 'contributions')['sections']['primary']['rows'])->toBe([]);
    expect(reportData($newAgent, 'contributions')['sections']['primary']['rows'])->toHaveCount(1);
    expect(reportValue(reportData($agent, 'reconciliation'), 'agent_receivable'))->toBe(200000);
    expect(reportValue(reportData($newAgent, 'reconciliation'), 'agent_receivable'))->toBe(0);
    $activity = reportData($agent, 'agent-performance')['sections']['recorded_activity'];
    expect($activity['metrics'][1]['value'])->toBe(200000);
    expect($activity['rows'])->toBe([]);
    $this->actingAs($agent)->get(route('reports.show', ['report' => 'plans', 'customer' => $customer->customer_id]))->assertNotFound();
});

test('current and recording agent filters preserve receipt attribution', function (): void {
    $fixture = reportFixture();
    reportPostReceipt($fixture);
    [$agent, $customer, $assignment] = $fixture;
    $newProfile = AgentProfile::factory()->active()->create();
    $assignment->update(['status' => CustomerAssignmentStatus::Ended, 'is_current' => null, 'ended_at' => now()]);
    CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $newProfile->id, 'version' => 2]);
    $admin = User::factory()->admin()->create();

    expect(reportValue(reportData($admin, 'contributions', ['agent' => $agent->agentProfile->agent_id, 'agent_basis' => 'recording']), 'received_savings'))->toBe(200000);
    expect(reportValue(reportData($admin, 'contributions', ['agent' => $agent->agentProfile->agent_id, 'agent_basis' => 'current']), 'received_savings'))->toBe(0);
    expect(reportValue(reportData($admin, 'contributions', ['agent' => $newProfile->agent_id, 'agent_basis' => 'current']), 'received_savings'))->toBe(200000);
    $this->actingAs($admin)->get(route('reports.show', ['report' => 'contributions', 'agent' => 'AGT-MISSING', 'agent_basis' => 'recording']))->assertNotFound();
});

test('inactive agents retain authorized reads but unconfirmed MFA loses report access', function (): void {
    $fixture = reportFixture();
    $fixture[0]->agentProfile->update(['operational_status' => 'inactive']);

    $this->actingAs($fixture[0])->get(route('reports.show', 'plans'))->assertInertia(fn (Assert $page) => $page->has('report.sections.primary.rows', 1));
    $fixture[0]->update(['two_factor_confirmed_at' => null]);
    $this->actingAs($fixture[0]->fresh())->get(route('reports.index'))->assertForbidden();
});

test('report filters reject invalid ranges unknown fields invalid groups and unauthorized agent inputs with 422', function (string $report, array $filters, string $field): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00', 'Africa/Lagos'));
    $customer = CustomerProfile::factory()->create();

    $this->actingAs($customer->user)->getJson(route('reports.show', ['report' => $report, ...$filters]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'long' => ['contributions', ['from' => '2025-09-25', 'to' => '2026-09-26'], 'to'],
    'future' => ['contributions', ['from' => '2026-09-26', 'to' => '2026-09-27'], 'to'],
    'reversed' => ['contributions', ['from' => '2026-09-26', 'to' => '2026-09-25'], 'to'],
    'invalid date' => ['contributions', ['from' => 'wrong', 'to' => '2026-09-26'], 'from'],
    'missing end' => ['contributions', ['from' => '2026-09-26'], 'to'],
    'snapshot date filter' => ['plans', ['from' => '2026-09-26'], 'from'],
    'unknown' => ['plans', ['private_notes' => 'secret'], 'private_notes'],
    'invalid grouping' => ['contributions', ['group' => 'email'], 'group'],
    'page size' => ['plans', ['page_size' => 500], 'page_size'],
    'agent role' => ['plans', ['agent' => 'AGT-001', 'agent_basis' => 'current'], 'agent'],
    'inapplicable filter' => ['customer-summary', ['state' => 'posted'], 'state'],
]);

test('report default month and 366 day range use business local dates and UTC boundaries', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 23:30:00', 'UTC'));
    $customer = CustomerProfile::factory()->create();

    $this->actingAs($customer->user)->get(route('reports.show', 'contributions'))->assertInertia(fn (Assert $page) => $page
        ->where('filters.from', '2026-10-01')->where('filters.to', '2026-10-01')
        ->where('report.manifest.utc_start', '2026-09-30T23:00:00+00:00')
        ->where('report.manifest.utc_end_exclusive', '2026-09-30T23:30:00+00:00'));
    $this->get(route('reports.show', ['report' => 'contributions', 'from' => '2025-10-01', 'to' => '2026-10-01']))->assertOk();
});

test('independent workflow and plan reports survive a failed ledger source without publishing posted totals', function (): void {
    $fixture = reportFixture();
    reportWithdrawal($fixture);

    $withdrawals = reportData($fixture[1]->user, 'withdrawals');

    expect(reportValue($withdrawals, 'workflow_requests'))->toBe(1);
    expect($withdrawals['sections']['primary']['rows'][0]['state'])->toBe('pending_review');
    expect(json_encode($withdrawals))->not->toContain('PRIVATE-NOTES')->not->toContain('PRIVATE-BANK')->not->toContain('PRIVATE-REASON');
    expect(reportData($fixture[1]->user, 'plans')['sections']['primary']['rows'])->toHaveCount(1);
    expect(reportData($fixture[1]->user, 'contributions')['sections']['primary']['status'])->toBe('Unavailable');
});

test('revoking review permissions removes report owner links while retaining baseline workflow counts', function (): void {
    $fixture = reportFixture();
    reportWithdrawal($fixture);
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::WithdrawalsReview->value);
    $before = reportData($admin, 'exceptions');
    $admin->revokePermissionTo(AdminPermission::WithdrawalsReview->value);

    $after = reportData($admin, 'exceptions');

    expect($before['sections']['primary']['rows'][0]['href'])->not->toBeNull();
    expect($after['sections']['primary']['rows'][0]['href'])->toBeNull();
    expect(reportValue($after, 'supported_exceptions'))->toBe(1);
    expect($after['sections']['primary']['status'])->toBe('Partial');
});

test('report scope polling reauthorizes without executing report financial reads', function (): void {
    $customer = CustomerProfile::factory()->create();

    $this->actingAs($customer->user)->get(route('reports.show', 'customer-summary'))->assertInertia(fn (Assert $page) => $page
        ->reloadOnly('scopeSummary', fn (Assert $reload) => $reload->has('scopeSummary.fingerprint')->missing('report')));
});

test('disabled collection owners cannot be bypassed through reports', function (): void {
    $fixture = reportFixture();
    reportPostReceipt($fixture);
    config()->set('collections.enabled', false);

    expect(reportData($fixture[1]->user, 'contributions')['sections']['primary']['status'])->toBe('Unavailable');
    expect(reportData($fixture[0], 'reconciliation')['sections']['primary']['status'])->toBe('Unavailable');
});

test('mixed cash separates savings fees and tender without multiplying receipts', function (): void {
    $fixture = reportFixture();
    [$agent, $customer, $assignment, $plan, $today] = $fixture;
    config()->set('collections.enabled', true);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $rule = FeeRule::create([
        'version' => 1, 'name' => 'Registration cash fee', 'kind' => FeeRuleKind::Registration,
        'rule_key' => 'test-registration', 'model' => FeeRuleModel::Fixed,
        'timing' => FeeRuleTiming::Registration, 'basis' => FeeRuleBasis::None,
        'settlement_source' => FeeSettlementSource::ExternalReceipt,
        'currency' => 'NGN', 'amount_kobo' => 50000, 'customer_description' => 'Registration fee',
        'effective_at' => now()->subDay(), 'published_by_user_id' => $agent->id,
        'publication_reason' => 'Test registration fee.',
    ]);
    $snapshot = FeeSnapshot::create([
        'customer_profile_id' => $customer->id, 'source_type' => 'registration', 'source_id' => $customer->customer_id,
        'fee_rule_id' => $rule->id, 'fee_rule_version' => 1, 'name' => 'Registration cash fee',
        'kind' => FeeRuleKind::Registration, 'model' => FeeRuleModel::Fixed,
        'timing' => FeeRuleTiming::Registration, 'basis' => FeeRuleBasis::None,
        'settlement_source' => FeeSettlementSource::ExternalReceipt,
        'currency' => 'NGN', 'amount_kobo' => 50000, 'basis_amount_kobo' => 0,
        'customer_description' => 'Registration fee', 'acknowledged_at' => now(),
    ]);
    $obligation = app(FeeObligationService::class)->assessSnapshot($snapshot, $agent);
    $payload = reportReceiptPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['fees'] = [['obligation_id' => $obligation->id, 'amount_ngn' => '500.00']];
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)
        ->assertRedirect()->assertSessionHasNoErrors();

    $data = reportData($customer->user, 'contributions', ['group' => 'plan']);

    expect(reportValue($data, 'received_savings'))->toBe(200000);
    expect(reportValue($data, 'received_fees'))->toBe(50000);
    expect(reportValue($data, 'cash_received'))->toBe(250000);
    expect(reportValue($data, 'posted_receipt_count'))->toBe(1);
    expect(reportValue(reportData($agent, 'reconciliation'), 'agent_receivable'))->toBe(250000);
    expect($data['sections']['primary']['groups'][0]['metrics'][0]['value'])->toBe(200000);
});

test('late receipts retain occurrence dates and plan timezones after business timezone changes', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 00:30:00', 'Africa/Lagos'));
    $fixture = reportFixture();
    reportPostReceipt($fixture, '1000.00', '2026-09-25');
    BusinessProfile::current()->update(['timezone' => 'Pacific/Honolulu', 'version' => 2]);

    $data = reportData($fixture[1]->user, 'contributions', ['from' => '2026-09-25', 'to' => '2026-09-25']);
    $plans = reportData($fixture[1]->user, 'plans');

    expect($data['manifest']['timezone'])->toBe('Pacific/Honolulu');
    expect($data['sections']['primary']['rows'][0]['date'])->toBe('2026-09-25');
    expect($data['sections']['primary']['rows'][0]['receipt_timezone'])->toBe('Africa/Lagos');
    expect(reportValue($data, 'received_savings'))->toBe(100000);
    expect($plans['sections']['primary']['rows'][0]['plan_timezone'])->toBe('Africa/Lagos');
    expect($plans['sections']['primary']['rows'][0]['start_date'])->toBe('2026-09-26');
});

test('plan reports count explicit lifecycle states without inferring closure from dates', function (string $status, int $open): void {
    $fixture = reportFixture();
    $fixture[3]->update(['status' => $status]);

    $data = reportData($fixture[1]->user, 'plans', ['group' => 'state']);

    expect(reportValue($data, 'open_plans'))->toBe($open);
    expect($data['sections']['primary']['rows'][0]['state'])->toBe($status);
    expect($data['sections']['primary']['groups'][0]['count'])->toBe(1);
})->with([['active', 1], ['paused', 1], ['completed', 1], ['closed', 0], ['cancelled', 0]]);

test('corrupt receipt components do not publish financial totals despite a previously ready projection', function (): void {
    $fixture = reportFixture();
    reportPostReceipt($fixture);
    DB::table('collection_receipts')->update(['tender_amount_kobo' => 200001]);

    $data = reportData($fixture[1]->user, 'contributions');

    expect($data['sections']['primary']['status'])->toBe('Unavailable');
    expect($data['sections']['primary']['metrics'])->toBe([]);
});

test('custody failures do not hide independently verified business cash', function (): void {
    $fixture = reportFixture();
    reportPostReceipt($fixture);
    DB::table('ledger_accounts')->where('code', 'agent_receivable_ngn')->update(['mapping_status' => 'unmapped']);
    $admin = User::factory()->admin()->create();

    $data = reportData($admin, 'reconciliation');

    expect($data['sections']['primary']['status'])->toBe('Unavailable');
    expect($data['sections']['business_cash']['status'])->toBe('Current');
    expect(reportValue($data, 'business_cash', 'business_cash'))->toBe(0);
});

test('cursor invalidates on new postings permission revocation and reassignment', function (): void {
    $this->freezeTime();
    $fixture = reportFixture();
    reportPostReceipt($fixture);
    $admin = User::factory()->admin()->create();
    CustomerProfile::factory()->count(26)->create();
    $cursor = reportData($admin, 'customer-summary')['sections']['primary']['next_cursor'];
    reportPostReceipt($fixture, '100.00');

    $this->actingAs($admin)->getJson(route('reports.show', ['report' => 'customer-summary', 'cursor' => $cursor]))->assertUnprocessable();
    $cursor = reportData($admin, 'customer-summary')['sections']['primary']['next_cursor'];
    $admin->givePermissionTo(AdminPermission::WithdrawalsReview->value);
    $this->getJson(route('reports.show', ['report' => 'customer-summary', 'cursor' => $cursor]))->assertUnprocessable();
    $cursor = reportData($admin, 'customer-summary')['sections']['primary']['next_cursor'];
    $fixture[2]->update(['status' => CustomerAssignmentStatus::Ended, 'is_current' => null, 'ended_at' => now()]);
    $newAgent = AgentProfile::factory()->active()->create();
    CustomerAssignment::factory()->create(['customer_profile_id' => $fixture[1]->id, 'agent_profile_id' => $newAgent->id, 'version' => 2]);
    $this->getJson(route('reports.show', ['report' => 'customer-summary', 'cursor' => $cursor]))->assertUnprocessable();
});

test('shared dashboard and report metrics retain one definition and reconcile for the same customer scope', function (): void {
    $fixture = reportFixture();
    reportPostReceipt($fixture);
    $dashboard = app(DashboardReadService::class)->read($fixture[1]->user, [
        'period' => 'today', 'from' => $fixture[4], 'to' => $fixture[4], 'page_size' => 25,
    ]);

    $report = reportData($fixture[1]->user, 'customer-summary');

    foreach (['customer_liability', 'live_payout_reservations', 'available_savings'] as $code) {
        $dashboardMetric = collect($dashboard['sections']['savings']['metrics'])->firstWhere('code', $code);
        $reportMetric = collect($report['sections']['primary']['metrics'])->firstWhere('code', $code);
        expect($reportMetric['value'])->toBe($dashboardMetric['value']);
        expect($reportMetric['definition'])->toBe($dashboardMetric['definition']);
        expect($reportMetric['date_basis'])->toBe($dashboardMetric['date_basis']);
        expect($reportMetric['definition_version'])->toBe($dashboardMetric['definition_version']);
    }
});
