<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\CustomerAssignmentStatus;
use App\Enums\FeeObligationEntryType;
use App\Enums\FeeRuleBasis;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Enums\ThriftPlanStatus;
use App\Models\AgentProfile;
use App\Models\BusinessProfile;
use App\Models\CollectionBatch;
use App\Models\CollectionException;
use App\Models\ContributionSlot;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeObligationEntry;
use App\Models\FeeRule;
use App\Models\FeeSnapshot;
use App\Models\FinancialArtifact;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\PlanTermsRevision;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\DashboardReadService;
use App\Services\FeeObligationService;
use App\Services\FinancialArtifactService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\ReportCatalogue;
use App\Services\ReportReadService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../FeeFixtures.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'Africa/Lagos'));
});

function reportFixture(string $planId = 'PLN-TEST-001'): array
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
    $rule = FeeRule::query()->where('rule_key', 'test-plan')->where('version', 1)->first() ?? FeeRule::create([
        'version' => 1, 'name' => 'No plan fee', 'kind' => FeeRuleKind::Plan,
        'rule_key' => 'test-plan', 'model' => FeeRuleModel::NoFee,
        'timing' => FeeRuleTiming::FirstContribution, 'basis' => FeeRuleBasis::None,
        'settlement_source' => FeeSettlementSource::ExternalReceipt,
        'currency' => 'NGN', 'amount_kobo' => 0, 'customer_description' => 'No fee',
        'effective_at' => now()->subDay(), 'published_by_user_id' => $agent->id,
        'publication_reason' => 'Test plan rule.',
    ]);
    $plan = ThriftPlan::create([
        'plan_id' => $planId, 'customer_profile_id' => $customer->id,
        'created_by_user_id' => $agent->id, 'open_customer_profile_id' => $customer->id,
        'status' => ThriftPlanStatus::Active, 'current_terms_revision' => 1, 'version' => 1,
    ]);
    $snapshot = FeeSnapshot::create([
        'customer_profile_id' => $customer->id, 'source_type' => 'plan', 'source_id' => $planId,
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
    if ($date !== CarbonImmutable::now(BusinessProfile::current()->timezone)->toDateString()) {
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

test('report endpoints reject mutations and unavailable sections cannot be exported', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::ReportsExport->value);

    $this->actingAs($admin)->post(route('reports.show', 'contributions'), ['amount_kobo' => 100])->assertMethodNotAllowed();
    $this->get(route('reports.show', 'fees'))->assertInertia(fn (Assert $page) => $page
        ->where('definition.export_available', true)->where('report.sections.primary.status', 'Unavailable')
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
    $newAssignment = CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $newProfile->id, 'version' => 2]);

    expect(reportData($agent, 'contributions')['sections']['primary']['rows'])->toBe([]);
    expect(reportData($newAgent, 'contributions')['sections']['primary']['rows'])->toHaveCount(1);
    expect(reportValue(reportData($agent, 'reconciliation'), 'agent_receivable'))->toBe(200000);
    expect(reportValue(reportData($newAgent, 'reconciliation'), 'agent_receivable'))->toBe(0);
    expect(reportData($agent, 'reconciliation')['sections']['batch_reconciliation']['rows'])->toHaveCount(1);
    expect(reportData($newAgent, 'reconciliation')['sections']['batch_reconciliation']['rows'])->toBe([]);
    reportPostReceipt([$newAgent, $customer, $newAssignment, $fixture[3], $fixture[4]], '1000.00');
    $admin = User::factory()->admin()->create();
    $originalBatch = CollectionBatch::query()->where('agent_profile_id', $agent->agentProfile->id)->sole();
    $currentBatch = CollectionBatch::query()->where('agent_profile_id', $newProfile->id)->sole();
    $oldScope = reportData($admin, 'reconciliation', ['agent' => $agent->agentProfile->agent_id, 'agent_basis' => 'custody']);
    $newScope = reportData($admin, 'reconciliation', ['agent' => $newProfile->agent_id, 'agent_basis' => 'custody']);
    expect($oldScope['sections']['batch_reconciliation']['rows'][0]['reference'])->toBe($originalBatch->id);
    expect($newScope['sections']['batch_reconciliation']['rows'][0]['reference'])->toBe($currentBatch->id);
    expect(reportValue($oldScope, 'gross_tender', 'batch_reconciliation'))->toBe(200000);
    expect(reportValue($newScope, 'gross_tender', 'batch_reconciliation'))->toBe(100000);
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

test('outstanding fee exceptions follow current Customer scope and disappear after waiver', function (): void {
    $fixture = reportFixture();
    [$agent, $customer, $assignment] = $fixture;
    $obligation = reportFeeObligation($agent, $customer);
    $admin = User::factory()->admin()->create();

    foreach ([$customer->user, $agent, $admin] as $viewer) {
        $section = reportData($viewer, 'exceptions')['sections']['fee_obligations'];
        expect($section['status'])->toBe('Partial');
        expect(reportValue(reportData($viewer, 'exceptions'), 'outstanding_fee_obligations', 'fee_obligations'))->toBe(1);
        expect(reportValue(reportData($viewer, 'exceptions'), 'outstanding_fees', 'fee_obligations'))->toBe(50000);
        expect($section['rows'][0]['href'])->toBe(route('customers.show', $customer->customer_id));
    }
    expect(reportData(CustomerProfile::factory()->create()->user, 'exceptions')['sections']['fee_obligations']['rows'])->toBe([]);
    expect(reportData($customer->user, 'exceptions')['sections'])->not->toHaveKey('custody_batches');
    expect(reportData($admin, 'exceptions')['sections']['refund_payables']['status'])->toBe('Unavailable');

    $newAgent = User::factory()->agent()->create(['two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now()]);
    $newProfile = AgentProfile::factory()->active()->create(['user_id' => $newAgent->id]);
    $assignment->update(['status' => CustomerAssignmentStatus::Ended, 'is_current' => null, 'ended_at' => now()]);
    CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $newProfile->id, 'version' => 2]);
    expect(reportData($agent, 'exceptions')['sections']['fee_obligations']['rows'])->toBe([]);
    expect(reportData($newAgent, 'exceptions')['sections']['fee_obligations']['rows'])->toHaveCount(1);

    FeeObligationEntry::create([
        'fee_obligation_id' => $obligation->id, 'entry_type' => FeeObligationEntryType::Waiver,
        'amount_kobo' => 50000, 'currency' => 'NGN', 'source_type' => 'report_test',
        'source_id' => 'full-waiver', 'idempotency_key' => 'full-waiver', 'actor_user_id' => $admin->id,
        'customer_description' => 'Fee waived.',
    ]);
    $after = reportData($admin, 'exceptions');
    expect($after['sections']['fee_obligations']['rows'])->toBe([]);
    expect(reportValue($after, 'outstanding_fee_obligations', 'fee_obligations'))->toBe(0);
    expect(reportValue($after, 'outstanding_fees', 'fee_obligations'))->toBe(0);
});

test('incomplete fee assessment makes its exception section unavailable without hiding workflow', function (): void {
    $fixture = reportFixture();
    $obligation = reportFeeObligation($fixture[0], $fixture[1]);
    reportWithdrawal($fixture);
    DB::table('fee_obligation_entries')->where('fee_obligation_id', $obligation->id)
        ->where('entry_type', FeeObligationEntryType::Assessment->value)->delete();

    $data = reportData($fixture[1]->user, 'exceptions');

    expect($data['sections']['fee_obligations']['status'])->toBe('Unavailable');
    expect($data['sections']['fee_obligations']['metrics'])->toBe([]);
    expect(reportValue($data, 'supported_exceptions'))->toBe(1);
});

test('fee exception totals span pages and continuation rejects changed obligation entries', function (): void {
    $fixture = reportFixture();
    $agent = $fixture[0];
    $firstObligation = reportFeeObligation($agent, $fixture[1]);
    $template = $firstObligation->feeSnapshot;
    foreach (range(1, 25) as $index) {
        $customer = CustomerProfile::factory()->create();
        $snapshot = FeeSnapshot::create([
            'customer_profile_id' => $customer->id, 'source_type' => 'registration', 'source_id' => $customer->customer_id,
            'fee_rule_id' => $template->fee_rule_id, 'fee_rule_version' => $template->fee_rule_version,
            'name' => $template->name, 'kind' => $template->kind, 'model' => $template->model,
            'timing' => $template->timing, 'basis' => $template->basis,
            'settlement_source' => $template->settlement_source, 'currency' => 'NGN',
            'amount_kobo' => 50000, 'basis_amount_kobo' => 0,
            'customer_description' => 'Registration fee', 'acknowledged_at' => now(),
        ]);
        app(FeeObligationService::class)->assessSnapshot($snapshot, $agent);
    }
    $admin = User::factory()->admin()->create();

    $firstPage = reportData($admin, 'exceptions');
    $cursor = $firstPage['sections']['fee_obligations']['next_cursor'];
    $secondPage = reportData($admin, 'exceptions', ['cursor' => $cursor]);
    expect($firstPage['sections']['fee_obligations']['rows'])->toHaveCount(25);
    expect($secondPage['sections']['fee_obligations']['rows'])->toHaveCount(1);
    expect(reportValue($firstPage, 'outstanding_fee_obligations', 'fee_obligations'))->toBe(26);
    expect(reportValue($secondPage, 'outstanding_fees', 'fee_obligations'))->toBe(1300000);

    FeeObligationEntry::create([
        'fee_obligation_id' => $firstObligation->id, 'entry_type' => FeeObligationEntryType::Waiver,
        'amount_kobo' => 1, 'currency' => 'NGN', 'source_type' => 'report_test',
        'source_id' => 'cursor-waiver', 'idempotency_key' => 'cursor-waiver',
        'actor_user_id' => $admin->id, 'customer_description' => 'One kobo waived.',
    ]);
    $this->actingAs($admin)->getJson(route('reports.show', ['report' => 'exceptions', 'cursor' => $cursor]))->assertUnprocessable();
});

test('custody exceptions use original Agent scope and fail closed on filters and owner gates', function (): void {
    $fixture = reportFixture();
    reportPostReceipt($fixture);
    [$agent, $customer, $assignment] = $fixture;
    $batch = CollectionBatch::query()->sole();
    $admin = User::factory()->admin()->create();
    CollectionException::create(['collection_batch_id' => $batch->id, 'opened_by_user_id' => $admin->id,
        'kind' => 'cash_shortage', 'status' => 'open', 'amount_kobo' => 1000, 'reason' => 'PRIVATE-SHORTAGE-REASON']);

    $data = reportData($admin, 'exceptions');
    expect(reportValue($data, 'unreconciled_batches', 'custody_batches'))->toBe(1);
    expect(reportValue($data, 'unremitted', 'custody_batches'))->toBe(200000);
    expect(reportValue($data, 'open_exceptions', 'custody_batches'))->toBe(1);
    expect($data['sections']['custody_batches']['rows'][0]['href'])->toBe(route('collection-batches.show', $batch));
    expect(json_encode($data['sections']['custody_batches']))->not->toContain('PRIVATE-SHORTAGE-REASON')->not->toContain($customer->customer_id);
    expect(reportData($agent, 'exceptions')['sections']['custody_batches']['rows'])->toHaveCount(1);
    expect(reportData($customer->user, 'exceptions')['sections'])->not->toHaveKey('custody_batches');
    expect(reportData($admin, 'exceptions', ['customer' => $customer->customer_id])['sections']['custody_batches']['status'])->toBe('Unavailable');

    $newAgent = User::factory()->agent()->create(['two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now()]);
    $newProfile = AgentProfile::factory()->active()->create(['user_id' => $newAgent->id]);
    $assignment->update(['status' => CustomerAssignmentStatus::Ended, 'is_current' => null, 'ended_at' => now()]);
    CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $newProfile->id, 'version' => 2]);
    expect(reportData($agent, 'exceptions')['sections']['custody_batches']['rows'])->toHaveCount(1);
    expect(reportData($newAgent, 'exceptions')['sections']['custody_batches']['rows'])->toBe([]);

    config()->set('collections.enabled', false);
    $disabled = reportData($admin, 'exceptions');
    expect($disabled['sections']['custody_batches']['status'])->toBe('Unavailable');
    expect($disabled['sections']['custody_batches']['reason'])->toContain('collection owner is disabled');
    expect($disabled['sections']['fee_obligations']['status'])->toBe('Partial');
    config()->set('collections.enabled', true);
    DB::table('ledger_accounts')->where('code', 'agent_receivable_ngn')->update(['mapping_status' => 'unmapped']);
    expect(reportData($admin, 'exceptions')['sections']['custody_batches']['status'])->toBe('Unavailable');
});

test('report scope polling reauthorizes without executing report financial reads', function (): void {
    $customer = CustomerProfile::factory()->create();

    $this->actingAs($customer->user)->get(route('reports.show', 'customer-summary'))->assertInertia(fn (Assert $page) => $page
        ->reloadOnly('scopeSummary', fn (Assert $reload) => $reload->has('scopeSummary.fingerprint')->missing('report')));
});

test('disabled collection owners cannot be bypassed through reports', function (): void {
    $fixture = reportFixture();
    reportPostReceipt($fixture);
    reportFeeObligation($fixture[0], $fixture[1]);
    config()->set('collections.enabled', false);

    expect(reportData($fixture[1]->user, 'contributions')['sections']['primary']['status'])->toBe('Unavailable');
    $reconciliation = reportData($fixture[0], 'reconciliation');
    expect($reconciliation['sections']['primary']['status'])->toBe('Unavailable');
    expect($reconciliation['sections']['batch_reconciliation']['status'])->toBe('Unavailable');
    $fees = reportData($fixture[1]->user, 'fees');
    expect($fees['sections']['primary']['status'])->toBe('Unavailable');
    expect($fees['sections']['external_receipts']['status'])->toBe('Unavailable');
});

test('mixed cash separates savings fees and tender without multiplying receipts', function (): void {
    $fixture = reportFixture();
    [$agent, $customer, $assignment, $plan, $today] = $fixture;
    config()->set('collections.enabled', true);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $obligation = reportFeeObligation($agent, $customer);
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
    expect(reportValue($data, 'total_received'))->toBe(250000);
    expect($data['sections']['primary']['rows'][0]['method'])->toBe('Cash');
    expect(reportValue($data, 'posted_receipt_count'))->toBe(1);
    expect(reportValue(reportData($agent, 'reconciliation'), 'agent_receivable'))->toBe(250000);
    $batchData = reportData($agent, 'reconciliation');
    $batchSection = $batchData['sections']['batch_reconciliation'];
    expect(reportValue($batchData, 'gross_tender', 'batch_reconciliation'))->toBe(250000);
    expect(reportValue($batchData, 'savings_component', 'batch_reconciliation'))->toBe(200000);
    expect(reportValue($batchData, 'external_fee_component', 'batch_reconciliation'))->toBe(50000);
    expect($batchSection['rows'][0]['href'])->toBeNull();
    foreach (['customer', 'handoff', 'location', 'attestation', 'reason'] as $privateField) {
        expect(implode(' ', array_keys($batchSection['rows'][0])))->not->toContain($privateField);
    }
    expect($data['sections']['primary']['groups'][0]['metrics'][0]['value'])->toBe(200000);
    $fees = reportData($customer->user, 'fees');
    expect(reportValue($fees, 'gross_assessed'))->toBe(50000);
    expect(reportValue($fees, 'external_fees_received', 'external_receipts'))->toBe(50000);
    expect($fees['sections']['external_receipts']['rows'])->toHaveCount(1);
    expect($fees['sections']['external_receipts']['rows'][0]['external_fees_received'])->toBe('₦500.00');
    expect(reportValue(reportData($customer->user, 'exceptions'), 'outstanding_fee_obligations', 'fee_obligations'))->toBe(0);
});

test('fee obligation activity keeps assessment corrections and waivers separate from external cash', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00:00', 'Africa/Lagos'));
    $fixture = reportFixture();
    [$agent, $customer] = $fixture;
    config()->set('collections.enabled', true);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $obligation = reportFeeObligation($agent, $customer);
    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00', 'Africa/Lagos'));
    foreach ([
        FeeObligationEntryType::AssessmentCorrectionIncrease->value => 10000,
        FeeObligationEntryType::AssessmentCorrection->value => 5000,
        FeeObligationEntryType::Waiver->value => 2000,
    ] as $type => $amount) {
        $source = (string) Str::uuid();
        FeeObligationEntry::create([
            'fee_obligation_id' => $obligation->id, 'entry_type' => $type,
            'amount_kobo' => $amount, 'currency' => 'NGN', 'source_type' => 'report_test',
            'source_id' => $source, 'idempotency_key' => $source, 'actor_user_id' => $agent->id,
            'customer_description' => 'Test fee activity',
        ]);
    }

    $firstDay = reportData($customer->user, 'fees', ['from' => '2026-09-25', 'to' => '2026-09-25']);
    $secondDay = reportData($customer->user, 'fees', ['from' => '2026-09-26', 'to' => '2026-09-26']);

    expect(reportValue($firstDay, 'gross_assessed'))->toBe(50000);
    expect(reportValue($firstDay, 'assessment_increase'))->toBe(0);
    expect(reportValue($secondDay, 'gross_assessed'))->toBe(0);
    expect(reportValue($secondDay, 'assessment_increase'))->toBe(10000);
    expect(reportValue($secondDay, 'assessment_reduction'))->toBe(5000);
    expect(reportValue($secondDay, 'waived_fees'))->toBe(2000);
    expect(reportValue($secondDay, 'external_fees_received', 'external_receipts'))->toBe(0);
    expect($secondDay['sections']['primary']['total'])->toBe(3);
});

test('positive fee obligation without assessment history makes both fee sections unavailable', function (): void {
    $fixture = reportFixture();
    [$agent, $customer] = $fixture;
    config()->set('collections.enabled', true);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $obligation = reportFeeObligation($agent, $customer);
    DB::table('fee_obligation_entries')->where('fee_obligation_id', $obligation->id)
        ->where('entry_type', FeeObligationEntryType::Assessment->value)->delete();

    $fees = reportData($customer->user, 'fees');

    expect($fees['sections']['primary']['status'])->toBe('Unavailable');
    expect($fees['sections']['external_receipts']['status'])->toBe('Unavailable');
});

test('fee-only receipt is external cash while a former Agent loses Customer fee detail', function (): void {
    $fixture = reportFixture();
    [$agent, $customer, $assignment, $plan, $today] = $fixture;
    config()->set('collections.enabled', true);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $obligation = reportFeeObligation($agent, $customer);
    $payload = reportReceiptPayload($customer, $assignment, $plan, $today, '0');
    unset($payload['plan_id'], $payload['plan_version']);
    $payload['fees'] = [['obligation_id' => $obligation->id, 'amount_ngn' => '500.00']];
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect()->assertSessionHasNoErrors();

    expect(reportValue(reportData($customer->user, 'fees'), 'external_fees_received', 'external_receipts'))->toBe(50000);
    expect(reportValue(reportData($customer->user, 'contributions'), 'received_savings'))->toBe(0);
    $newAgent = User::factory()->agent()->create(['two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now()]);
    $newProfile = AgentProfile::factory()->active()->create(['user_id' => $newAgent->id]);
    $assignment->update(['status' => CustomerAssignmentStatus::Ended, 'is_current' => null, 'ended_at' => now()]);
    CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $newProfile->id, 'version' => 2]);

    expect(reportData($agent, 'fees')['sections']['primary']['rows'])->toBe([]);
    expect(reportData($agent, 'fees')['sections']['external_receipts']['rows'])->toBe([]);
    expect(reportValue(reportData($newAgent, 'fees'), 'external_fees_received', 'external_receipts'))->toBe(50000);
    $this->actingAs($agent)->get(route('reports.show', ['report' => 'fees', 'customer' => $customer->customer_id]))->assertNotFound();
});

test('fee report fails closed when a receipt component or its projection is inconsistent', function (): void {
    $fixture = reportFixture();
    [$agent, $customer, $assignment, $plan, $today] = $fixture;
    config()->set('collections.enabled', true);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $obligation = reportFeeObligation($agent, $customer);
    $payload = reportReceiptPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['fees'] = [['obligation_id' => $obligation->id, 'amount_ngn' => '500.00']];
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $componentId = DB::table('collection_fee_components')->value('id');
    DB::table('collection_fee_components')->where('id', $componentId)->update(['amount_kobo' => 49000]);

    expect(reportData($customer->user, 'fees')['sections']['external_receipts']['status'])->toBe('Unavailable');
    DB::table('collection_fee_components')->where('id', $componentId)->update(['amount_kobo' => 50000]);
    DB::table('ledger_entries')->where('fee_obligation_id', $obligation->id)->where('side', 'credit')->update(['amount_kobo' => 49000]);

    expect(reportData($customer->user, 'fees')['sections']['external_receipts']['status'])->toBe('Unavailable');
    DB::table('ledger_entries')->where('fee_obligation_id', $obligation->id)->where('side', 'credit')->update(['amount_kobo' => 50000]);
    DB::table('ledger_transaction_projections')->delete();

    expect(reportData($customer->user, 'fees')['sections']['external_receipts']['status'])->toBe('Unavailable');
});

test('fee obligation pagination retains full totals and rejects changed activity', function (): void {
    $fixture = reportFixture();
    [$agent, $customer] = $fixture;
    config()->set('collections.enabled', true);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $obligation = reportFeeObligation($agent, $customer);
    foreach (range(1, 25) as $index) {
        FeeObligationEntry::create([
            'fee_obligation_id' => $obligation->id, 'entry_type' => FeeObligationEntryType::Waiver,
            'amount_kobo' => 1, 'currency' => 'NGN', 'source_type' => 'report_test',
            'source_id' => 'waiver-'.$index, 'idempotency_key' => 'report-waiver-'.$index,
            'actor_user_id' => $agent->id, 'customer_description' => 'Test waiver',
        ]);
    }

    $first = reportData($customer->user, 'fees');
    $cursor = $first['sections']['primary']['next_cursor'];
    $second = reportData($customer->user, 'fees', ['cursor' => $cursor]);

    expect($first['sections']['primary']['total'])->toBe(26);
    expect($first['sections']['primary']['rows'])->toHaveCount(25);
    expect($second['sections']['primary']['rows'])->toHaveCount(1);
    expect($second['sections']['external_receipts']['total'])->toBe(0);
    FeeObligationEntry::create([
        'fee_obligation_id' => $obligation->id, 'entry_type' => FeeObligationEntryType::Waiver,
        'amount_kobo' => 1, 'currency' => 'NGN', 'source_type' => 'report_test',
        'source_id' => 'later-waiver', 'idempotency_key' => 'report-later-waiver',
        'actor_user_id' => $agent->id, 'customer_description' => 'Later waiver',
    ]);

    $this->actingAs($customer->user)->getJson(route('reports.show', ['report' => 'fees', 'cursor' => $cursor]))->assertUnprocessable();
});

test('external fee receipts paginate independently and reject a newly posted receipt', function (): void {
    $fixture = reportFixture();
    [$agent, $customer, $assignment, $plan, $today] = $fixture;
    config()->set('collections.enabled', true);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $obligation = reportFeeObligation($agent, $customer);
    $postFee = function () use ($agent, $customer, $assignment, $plan, $today, $obligation): void {
        $payload = reportReceiptPayload($customer->fresh(), $assignment->fresh(), $plan->fresh(), $today, '0');
        unset($payload['plan_id'], $payload['plan_version']);
        $payload['fees'] = [['obligation_id' => $obligation->id, 'amount_ngn' => '0.01']];
        $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk()->json();
        $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
        $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect()->assertSessionHasNoErrors();
    };
    foreach (range(1, 26) as $index) {
        $postFee();
    }

    $first = reportData($customer->user, 'fees');
    $cursor = $first['sections']['external_receipts']['next_cursor'];
    $second = reportData($customer->user, 'fees', ['cursor' => $cursor]);

    expect($first['sections']['external_receipts']['total'])->toBe(26);
    expect(reportValue($first, 'external_fees_received', 'external_receipts'))->toBe(26);
    expect($first['sections']['external_receipts']['rows'])->toHaveCount(25);
    expect($second['sections']['external_receipts']['rows'])->toHaveCount(1);
    expect($second['sections']['primary']['rows'])->toHaveCount(1);
    $postFee();

    $this->actingAs($customer->user)->getJson(route('reports.show', ['report' => 'fees', 'cursor' => $cursor]))->assertUnprocessable();
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

test('plan funding reports verified slot coverage without treating an unfunded target as due', function (): void {
    $fixture = reportFixture();
    [$agent, $customer, , $plan] = $fixture;
    config()->set('collections.enabled', true);
    app(LedgerTransactionProjectionService::class)->rebuild();

    $empty = reportData($customer->user, 'plans')['sections']['funding_progress'];
    expect($empty['status'])->toBe('Partial');
    expect(reportValue(reportData($customer->user, 'plans'), 'required_slots', 'funding_progress'))->toBe(2);
    expect(reportValue(reportData($customer->user, 'plans'), 'unfunded_slots', 'funding_progress'))->toBe(2);
    expect(reportValue(reportData($customer->user, 'plans'), 'remaining_scheduled_target', 'funding_progress'))->toBe(400000);
    expect($empty['rows'][0]['href'])->toBe(route('plans.show', $plan->plan_id));
    $plan->update(['status' => ThriftPlanStatus::Cancelled]);
    $cancelled = reportData($customer->user, 'plans')['sections']['funding_progress'];
    expect($cancelled['rows'][0]['state'])->toBe('cancelled');
    expect($cancelled['reason'])->toContain('not an amount due');
    $plan->update(['status' => ThriftPlanStatus::Active]);

    reportPostReceipt($fixture, '3000.00');
    expect(DB::table('collection_allocations')->count())->toBe(2);
    $funding = reportData($agent, 'plans')['sections']['funding_progress'];
    expect($funding['status'])->toBe('Partial');
    expect(reportValue(reportData($agent, 'plans'), 'fully_funded_slots', 'funding_progress'))->toBe(1);
    expect(reportValue(reportData($agent, 'plans'), 'partially_funded_slots', 'funding_progress'))->toBe(1);
    expect(reportValue(reportData($agent, 'plans'), 'unfunded_slots', 'funding_progress'))->toBe(0);
    expect(reportValue(reportData($agent, 'plans'), 'funded_principal', 'funding_progress'))->toBe(300000);
    expect(reportValue(reportData($agent, 'plans'), 'remaining_scheduled_target', 'funding_progress'))->toBe(100000);
    expect($funding['rows'][0]['remaining_scheduled_target'])->toBe('₦1,000.00');
});

test('plan funding accepts retained slots after a name-only terms revision', function (): void {
    $fixture = reportFixture();
    $plan = $fixture[3];
    reportPostReceipt($fixture, '3000.00');

    $revisedTerms = $plan->currentTermsRevision()->replicate();
    $revisedTerms->revision = 2;
    $revisedTerms->name = 'Corrected plan name';
    $revisedTerms->save();
    $plan->update(['current_terms_revision' => 2, 'version' => 2]);

    $funding = reportData($fixture[1]->user, 'plans')['sections']['funding_progress'];

    expect($funding['status'])->toBe('Partial');
    expect($funding['rows'])->toHaveCount(1);
    expect(reportValue(reportData($fixture[1]->user, 'plans'), 'fully_funded_slots', 'funding_progress'))->toBe(1);
    expect(reportValue(reportData($fixture[1]->user, 'plans'), 'partially_funded_slots', 'funding_progress'))->toBe(1);
    expect(reportValue(reportData($fixture[1]->user, 'plans'), 'funded_principal', 'funding_progress'))->toBe(300000);
});

test('plan funding rejects a mismatched slot date or another plans terms ownership', function (): void {
    $fixture = reportFixture();
    $other = reportFixture('PLN-TEST-002');
    config()->set('collections.enabled', true);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $slot = ContributionSlot::query()->where('thrift_plan_id', $fixture[3]->id)->where('active_ordinal', 2)->sole();
    $correctDate = $slot->due_date;

    DB::table('contribution_slots')->where('id', $slot->id)
        ->update(['due_date' => CarbonImmutable::parse($correctDate)->addDay()->toDateString()]);
    $wrongDate = reportData($fixture[1]->user, 'plans');
    expect($wrongDate['sections']['primary']['status'])->toBe('Partial');
    expect($wrongDate['sections']['funding_progress']['status'])->toBe('Unavailable');

    $foreignTerms = $other[3]->currentTermsRevision()->replicate();
    $foreignTerms->revision = 2;
    $foreignTerms->save();
    DB::table('contribution_slots')->where('id', $slot->id)
        ->update(['due_date' => $correctDate, 'plan_terms_revision_id' => $foreignTerms->id]);
    $wrongOwner = reportData($fixture[1]->user, 'plans');
    expect($wrongOwner['sections']['primary']['status'])->toBe('Partial');
    expect($wrongOwner['sections']['funding_progress']['status'])->toBe('Unavailable');
});

test('plan funding totals and continuation follow current scope and source changes', function (): void {
    $first = reportFixture();
    $second = reportFixture('PLN-TEST-002');
    $admin = User::factory()->admin()->create();
    config()->set('collections.enabled', true);
    app(LedgerTransactionProjectionService::class)->rebuild();

    $page = reportData($admin, 'plans', ['page_size' => 1]);
    expect($page['sections']['funding_progress']['total'])->toBe(2);
    expect(reportValue($page, 'required_slots', 'funding_progress'))->toBe(4);
    $cursor = $page['sections']['funding_progress']['next_cursor'];
    expect($cursor)->not->toBeNull();
    expect(reportData($admin, 'plans', ['page_size' => 1, 'cursor' => $cursor])['sections']['funding_progress']['rows'])->toHaveCount(1);
    expect(reportData($admin, 'plans', ['plan' => $first[3]->plan_id])['sections']['funding_progress']['total'])->toBe(1);
    expect(reportData($admin, 'plans', ['plan_status' => 'active'])['sections']['funding_progress']['total'])->toBe(2);
    expect(reportData($first[0], 'plans')['sections']['funding_progress']['total'])->toBe(1);
    expect(reportData($second[1]->user, 'plans')['sections']['funding_progress']['total'])->toBe(1);

    reportPostReceipt($second);
    expect(fn () => reportData($admin, 'plans', ['page_size' => 1, 'cursor' => $cursor]))
        ->toThrow(HttpException::class);

    $newAgent = User::factory()->agent()->create(['two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now()]);
    $newProfile = AgentProfile::factory()->active()->create(['user_id' => $newAgent->id]);
    $first[2]->update(['status' => CustomerAssignmentStatus::Ended, 'is_current' => null, 'ended_at' => now()]);
    CustomerAssignment::factory()->create(['customer_profile_id' => $first[1]->id, 'agent_profile_id' => $newProfile->id, 'version' => 2]);
    expect(reportData($first[0], 'plans')['sections']['funding_progress']['rows'])->toBe([]);
    expect(reportData($newAgent, 'plans')['sections']['funding_progress']['rows'])->toHaveCount(1);
});

test('plan funding fails closed independently of plan terms on disabled or inconsistent owners', function (): void {
    $fixture = reportFixture();
    $customer = $fixture[1];
    $disabled = reportData($customer->user, 'plans');
    expect($disabled['sections']['primary']['status'])->toBe('Partial');
    expect($disabled['sections']['funding_progress']['status'])->toBe('Unavailable');

    reportPostReceipt($fixture);
    $otherCustomer = CustomerProfile::factory()->create();
    DB::table('collection_receipts')->update(['customer_profile_id' => $otherCustomer->id]);
    expect(reportData($customer->user, 'plans')['sections']['funding_progress']['status'])->toBe('Unavailable');
    DB::table('collection_receipts')->update(['customer_profile_id' => $customer->id]);
    DB::table('collection_allocations')->update(['amount_kobo' => 300000]);
    $corrupt = reportData($customer->user, 'plans');
    expect($corrupt['sections']['primary']['status'])->toBe('Partial');
    expect($corrupt['sections']['funding_progress']['status'])->toBe('Unavailable');
    DB::table('collection_allocations')->update(['amount_kobo' => 200000]);
    DB::table('ledger_transaction_projections')->delete();
    expect(reportData($customer->user, 'plans')['sections']['funding_progress']['status'])->toBe('Unavailable');
    DB::table('ledger_projection_state')->where('id', 1)->update(['status' => 'unavailable']);
    expect(reportData($customer->user, 'plans')['sections']['funding_progress']['status'])->toBe('Unavailable');
});

test('plan funding rejects incomplete active slots while terms remain readable', function (): void {
    $fixture = reportFixture();
    config()->set('collections.enabled', true);
    app(LedgerTransactionProjectionService::class)->rebuild();
    DB::table('contribution_slots')->where('active_ordinal', 2)->update(['active_ordinal' => null]);

    $data = reportData($fixture[1]->user, 'plans');
    expect($data['sections']['primary']['status'])->toBe('Partial');
    expect($data['sections']['funding_progress']['status'])->toBe('Unavailable');
    expect($data['sections']['funding_progress']['metrics'])->toBe([]);
});

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
    expect($data['sections']['batch_reconciliation']['status'])->toBe('Unavailable');
    expect(reportValue($data, 'business_cash', 'business_cash'))->toBe(0);
});

test('cash batch report shows scoped partial remittance and reviewed shortage without changing custody totals', function (): void {
    $fixture = reportFixture();
    reportPostReceipt($fixture);
    $batch = CollectionBatch::query()->sole();
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::ReconciliationManage->value);
    $this->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp]);
    $this->actingAs($admin)->post(route('collection-batches.remittances.store', $batch), [
        'handoff_reference' => 'REPORT-BATCH-HANDOFF', 'amount_ngn' => '1500.00',
        'handoff_date' => CarbonImmutable::now('Africa/Lagos')->toDateString(),
        'receiving_location' => 'Lagos office', 'source_attestation' => 'Counted cash.',
        'batch_version' => $batch->fresh()->version, 'confirmed' => true,
    ])->assertRedirect(route('collection-batches.show', $batch))->assertSessionHasNoErrors();
    $this->post(route('collection-batches.review', $batch), [
        'batch_version' => $batch->fresh()->version, 'reason' => 'Shortage under investigation.',
        'confirmed' => true,
    ])->assertRedirect(route('collection-batches.show', $batch))->assertSessionHasNoErrors();

    $data = reportData($admin, 'reconciliation', ['agent' => $fixture[0]->agentProfile->agent_id, 'agent_basis' => 'custody']);
    $section = $data['sections']['batch_reconciliation'];

    expect($section['status'])->toBe('Partial');
    expect(reportValue($data, 'batch_count', 'batch_reconciliation'))->toBe(1);
    expect(reportValue($data, 'gross_tender', 'batch_reconciliation'))->toBe(200000);
    expect(reportValue($data, 'confirmed_remittances', 'batch_reconciliation'))->toBe(150000);
    expect(reportValue($data, 'unremitted', 'batch_reconciliation'))->toBe(50000);
    expect(reportValue($data, 'open_exceptions', 'batch_reconciliation'))->toBe(1);
    expect($section['rows'][0]['state'])->toBe('exception');
    expect($section['rows'][0]['latest_review'])->toBe('exception');
    expect($section['rows'][0]['href'])->toBe(route('collection-batches.show', $batch));
    expect(reportValue($data, 'agent_receivable'))->toBe(50000);
    $postingId = DB::table('cash_remittances')->value('ledger_posting_group_id');
    DB::table('ledger_entries')->where('ledger_posting_group_id', $postingId)
        ->where('side', 'debit')->update(['amount_kobo' => 149999]);
    expect(reportData($admin, 'reconciliation')['sections']['batch_reconciliation']['status'])->toBe('Unavailable');
    expect(reportData($admin, 'exceptions')['sections']['custody_batches']['status'])->toBe('Unavailable');
    DB::table('ledger_entries')->where('ledger_posting_group_id', $postingId)
        ->where('side', 'debit')->update(['amount_kobo' => 150000]);
    DB::table('collection_batch_reviews')->update(['outstanding_kobo' => 0]);
    expect(reportData($admin, 'reconciliation')['sections']['batch_reconciliation']['status'])->toBe('Unavailable');
});

test('cash batch report retains frozen original and linked late supplement', function (): void {
    $fixture = reportFixture();
    reportPostReceipt($fixture);
    $original = CollectionBatch::query()->sole();
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp]);
    reportPostReceipt($fixture, '1000.00', $fixture[4]);
    $admin = User::factory()->admin()->create();

    $data = reportData($admin, 'reconciliation');
    $rows = $data['sections']['batch_reconciliation']['rows'];

    expect($rows)->toHaveCount(2);
    expect(reportValue($data, 'batch_count', 'batch_reconciliation'))->toBe(2);
    expect(reportValue($data, 'gross_tender', 'batch_reconciliation'))->toBe(300000);
    expect($rows[0]['revision'])->toBe(2);
    expect($rows[0]['predecessor'])->toBe($original->id);
    expect($rows[1]['revision'])->toBe(1);
    expect($rows[1]['state'])->toBe('ready_for_review');
    $firstPage = reportData($admin, 'reconciliation', ['page_size' => 1]);
    $cursor = $firstPage['sections']['batch_reconciliation']['next_cursor'];

    expect($cursor)->not->toBeNull();
    CollectionBatch::query()->whereKey($original->id)->update(['version' => $original->fresh()->version + 1]);
    expect(fn () => reportData($admin, 'reconciliation', ['page_size' => 1, 'cursor' => $cursor]))
        ->toThrow(HttpException::class);
    DB::table('collection_receipts')->where('collection_batch_id', $original->id)->update(['tender_amount_kobo' => 200001]);

    expect(reportData($admin, 'reconciliation')['sections']['batch_reconciliation']['status'])->toBe('Unavailable');
    expect(fn () => reportData($admin, 'reconciliation', ['page_size' => 1, 'cursor' => $cursor]))
        ->toThrow(HttpException::class);
});

test('cash exception continuation rejects reconciliation changes and keeps an open supplement', function (): void {
    $fixture = reportFixture();
    reportPostReceipt($fixture);
    $original = CollectionBatch::query()->sole();
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp]);
    reportPostReceipt($fixture, '1000.00', $fixture[4]);
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::ReconciliationManage->value);

    $before = reportData($admin, 'exceptions', ['page_size' => 1]);
    $cursor = $before['sections']['custody_batches']['next_cursor'];
    expect($before['sections']['custody_batches']['total'])->toBe(2);
    expect($cursor)->not->toBeNull();

    $this->actingAs($admin)->post(route('collection-batches.remittances.store', $original), [
        'handoff_reference' => 'EXCEPTION-REPORT-HANDOFF', 'amount_ngn' => '2000.00',
        'handoff_date' => CarbonImmutable::now('Africa/Lagos')->toDateString(),
        'receiving_location' => 'Lagos office', 'source_attestation' => 'Counted cash.',
        'batch_version' => $original->fresh()->version, 'confirmed' => true,
    ])->assertRedirect(route('collection-batches.show', $original))->assertSessionHasNoErrors();
    $this->post(route('collection-batches.review', $original), [
        'batch_version' => $original->fresh()->version, 'reason' => 'Exact cash remittance confirmed.',
        'confirmed' => true,
    ])->assertRedirect(route('collection-batches.show', $original))->assertSessionHasNoErrors();

    expect(fn () => reportData($admin, 'exceptions', ['page_size' => 1, 'cursor' => $cursor]))
        ->toThrow(HttpException::class);
    $after = reportData($admin, 'exceptions');
    expect($after['sections']['custody_batches']['total'])->toBe(1);
    expect($after['sections']['custody_batches']['rows'][0]['revision'])->toBe(2);
    expect($after['sections']['custody_batches']['rows'][0]['predecessor'])->toBe($original->id);
    expect(reportValue($after, 'unremitted', 'custody_batches'))->toBe(100000);
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

test('FEE-AC-043: actual filtered fee CSV retains its ledger snapshot and denies private or unauthorized downloads', function (): void {
    Queue::fake();
    Storage::fake('local');
    config()->set('collections.enabled', true);
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'Africa/Lagos'));
    [$agent, $customer, $assignment, $plan, $date] = reportFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 100000);
    $postFee = function (array $fixture, FeeObligation $obligation, string $amount): void {
        [$recorder, $profile, $currentAssignment, $currentPlan] = $fixture;
        $payload = [...reportReceiptPayload($profile->fresh(), $currentAssignment->fresh(), $currentPlan->fresh(),
            now('Africa/Lagos')->toDateString(), '0'), 'plan_id' => null, 'plan_version' => null,
            'fees' => [['obligation_id' => $obligation->id, 'amount_ngn' => $amount]], 'notes' => 'PRIVATE fee receipt investigation note'];
        $payload['preview_fingerprint'] = $this->actingAs($recorder)->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])->postJson(route('customers.collections.preview', $profile->customer_id), $payload)
            ->assertOk()->json('preview_fingerprint');
        $this->post(route('customers.collections.store', $profile->customer_id), $payload)->assertRedirect()->assertSessionHasNoErrors();
        app(LedgerTransactionProjectionService::class)->rebuild();
    };
    $fixture = [$agent, $customer, $assignment, $plan, $date];
    $postFee($fixture, $fee, '100.00');
    $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'Africa/Lagos'));
    $postFee($fixture, $fee, '200.00');
    $postFee($fixture, $fee, '300.00');
    $foreign = reportFixture('PLN-FOREIGN-FEE');
    $foreignFee = reportFeeObligation($foreign[0], $foreign[1], 70000, 2);
    $postFee($foreign, $foreignFee, '700.00');
    $admin = User::factory()->admin()->create();
    $filters = ['customer' => $customer->customer_id, 'from' => '2026-10-02', 'to' => '2026-10-02'];
    $payload = [...$filters, 'operation_reference' => (string) Str::uuid(), 'format' => 'csv', 'confirmed' => true];
    $this->actingAs($admin)->postJson(route('reports.export', 'fees'), $payload)->assertForbidden();
    $this->assertDatabaseCount('financial_artifacts', 0);
    $admin->givePermissionTo(AdminPermission::ReportsExport);
    $this->actingAs($admin->fresh())->postJson(route('reports.export', 'fees'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $artifact = FinancialArtifact::query()->sole();
    $snapshot = $artifact->snapshot;
    $source = reportData($admin, 'fees', [...$filters, 'page_size' => 100]);
    expect($snapshot['sections'])->toEqual($source['sections'])
        ->and($snapshot['sections']['external_receipts']['rows'])->toHaveCount(2)
        ->and(reportValue($snapshot, 'external_fees_received', 'external_receipts'))->toBe(50000)
        ->and(reportValue($snapshot, 'gross_assessed'))->toBe(0)
        ->and($snapshot['manifest']['utc_start'])->toBe('2026-10-01T23:00:00+00:00')
        ->and($snapshot['manifest']['utc_end_exclusive'])->toBe('2026-10-02T11:00:00+00:00');
    $receiptIds = DB::table('collection_receipts')->where('customer_profile_id', $customer->id)
        ->whereDate('received_date', '2026-10-02')->pluck('id');
    $groups = DB::table('collection_fee_components')->whereIn('collection_receipt_id', $receiptIds)->pluck('ledger_posting_group_id');
    expect((int) DB::table('collection_fee_components')->whereIn('collection_receipt_id', $receiptIds)->sum('amount_kobo'))->toBe(50000)
        ->and((int) DB::table('ledger_entries')->whereIn('ledger_posting_group_id', $groups)->where('side', 'credit')->sum('amount_kobo'))->toBe(50000)
        ->and((int) $fee->entries()->where('entry_type', 'settlement')->whereDate('created_at', '2026-10-02')->sum('amount_kobo'))->toBe(50000);
    $ownerRows = [];
    foreach (['collection_receipts', 'collection_fee_components', 'fee_snapshots', 'fee_obligations', 'fee_obligation_entries',
        'collection_batches', 'ledger_posting_groups', 'ledger_entries'] as $table) {
        $ownerRows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $service = app(FinancialArtifactService::class);
    $service->render($artifact->id);
    $artifact->refresh();
    $manifest = $artifact->manifest;
    $url = URL::temporarySignedRoute('financial-artifacts.download', now()->addMinutes(15), ['artifact' => $artifact, 'viewer' => $admin->id]);
    $bytes = $this->actingAs($admin)->get($url)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
    expect($bytes)->toBe($service->csv($snapshot, $manifest))->toContain('₦500.00')
        ->not->toContain('PRIVATE fee receipt investigation note')->not->toContain($foreign[1]->customer_id)
        ->and(hash('sha256', $bytes))->toBe($artifact->artifact_hash);
    foreach ($ownerRows as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
    $otherAdmin = User::factory()->admin()->create();
    $otherAdmin->givePermissionTo(AdminPermission::ReportsExport);
    foreach ([$otherAdmin, $agent, $customer->user] as $denied) {
        $deniedUrl = URL::temporarySignedRoute('financial-artifacts.download', now()->addMinutes(15), ['artifact' => $artifact, 'viewer' => $denied->id]);
        $this->actingAs($denied)->get($deniedUrl)->assertNotFound();
    }
    $admin->revokePermissionTo(AdminPermission::ReportsExport);
    $this->actingAs($admin->fresh())->get($url)->assertNotFound();
    foreach ($ownerRows as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
    $admin->givePermissionTo(AdminPermission::ReportsExport);
    $this->travel(1)->minutes();
    $postFee($fixture, $fee, '400.00');
    expect(reportValue(reportData($admin, 'fees', $filters), 'external_fees_received', 'external_receipts'))->toBe(90000)
        ->and($artifact->fresh()->snapshot)->toBe($snapshot)->and($artifact->fresh()->manifest)->toBe($manifest);
    $this->actingAs($admin->fresh())->get($url)->assertOk()->assertContent($bytes);
    $this->post(route('reports.export', 'fees'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseCount('financial_artifacts', 1);
    expect($artifact->fresh()->snapshot)->toBe($snapshot)->and($artifact->fresh()->artifact_hash)->toBe(hash('sha256', $bytes));
});

test('every report accepts exactly its catalogued filters for each role', function (string $role): void {
    $fixture = reportFixture();
    $viewer = match ($role) {
        'admin' => User::factory()->admin()->create(),
        'agent' => $fixture[0],
        default => $fixture[1]->user,
    };
    $values = ['customer' => $fixture[1]->customer_id, 'plan' => $fixture[3]->plan_id, 'customer_status' => 'active',
        'plan_status' => 'active', 'state' => 'posted'];
    $all = ['customer', 'plan', 'customer_status', 'plan_status', 'state', 'agent'];

    foreach (app(ReportCatalogue::class)->forViewer($viewer) as $report) {
        $allowed = array_values(array_diff($report['filters'], $role === 'admin' ? ['agent_basis', 'agent'] : ['agent', 'agent_basis']));
        foreach ($allowed as $filter) {
            $this->actingAs($viewer)->get(route('reports.show', ['report' => $report['code'], $filter => $values[$filter]]))
                ->assertSessionDoesntHaveErrors();
        }
        foreach (array_diff($all, $report['filters']) as $filter) {
            $this->actingAs($viewer)->get(route('reports.show', ['report' => $report['code'], $filter => $values[$filter] ?? 'X']))
                ->assertSessionHasErrors($filter);
        }
        if ($role !== 'admin' && in_array('agent', $report['filters'], true)) {
            $this->actingAs($viewer)->get(route('reports.show', ['report' => $report['code'], 'agent' => 'AGT-1', 'agent_basis' => 'current']))
                ->assertSessionHasErrors('agent');
        }
    }
})->with(['admin', 'agent', 'customer']);
