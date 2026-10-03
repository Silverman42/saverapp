<?php

use App\Enums\AdminPermission;
use App\Models\AgentProfile;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\FeeRule;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\CustomerReassignmentService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\PlanFinancialActivityReadService;
use App\Services\PlanSavingsReadService;
use App\Services\ThriftPlanService;
use App\Services\WithdrawalBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\CreatesLifecycleCustomers;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../WithdrawalFixtures.php';

uses(CreatesLifecycleCustomers::class);

function savingsDirectoryPlan(User $admin, CustomerProfile $customer, User $agent): ThriftPlan
{
    config()->set('collections.enabled', true);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $month = now('Africa/Lagos')->startOfMonth()->toDateString();
    if (! FinancialPeriod::query()->where('business_profile_id', BusinessProfile::current()->id)
        ->where('timezone', 'Africa/Lagos')->whereDate('month', $month)->exists()) {
        FinancialPeriod::factory()->create(['month' => $month]);
    }
    $rule = FeeRule::create(['version' => (int) FeeRule::query()->where('kind', 'plan')->max('version') + 1,
        'name' => 'One hundred naira cycle fee', 'kind' => 'plan', 'rule_key' => 'directory-savings', 'model' => 'fixed',
        'timing' => 'first_contribution', 'basis' => 'none', 'settlement_source' => 'savings_application', 'currency' => 'NGN',
        'amount_kobo' => 10000, 'customer_description' => 'One hundred naira agreed cycle fee.',
        'effective_at' => now()->subDay(), 'published_by_user_id' => $admin->id, 'publication_reason' => 'Isolated savings fixture.']);
    $data = ['name' => 'Directory savings cycle', 'amount_ngn' => '1000.00', 'start_date' => now('Africa/Lagos')->toDateString(),
        'contribution_days' => 2, 'customer_visible_notes' => '', 'fee_rule_id' => $rule->id, 'fee_rule_version' => $rule->version,
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'business_version' => BusinessProfile::current()->version, 'customer_agreement_attested' => true];
    $plans = app(ThriftPlanService::class);
    $data['preview_fingerprint'] = $plans->preview($agent, $customer, $data)['preview_fingerprint'];
    $plan = $plans->create($agent, $customer, (string) Str::uuid(), $data)['plan'];
    $payload = collectionPayload($customer, $customer->currentAssignment, $plan, now('Africa/Lagos')->toDateString(), '1000.00');
    $collections = app(CollectionService::class);
    $payload['preview_fingerprint'] = $collections->preview($agent, $customer, $payload)['preview_fingerprint'];
    $collections->record($agent, $customer, $payload);
    enableFixtureMethod();
    submittedWithdrawal($agent, $customer, $customer->currentAssignment, $plan->fresh());

    return $plan->fresh();
}

function savingsDirectoryRows(): array
{
    $rows = [];
    foreach (['thrift_plans', 'plan_terms_revisions', 'plan_lifecycle_events', 'fee_snapshots', 'fee_obligations', 'fee_obligation_entries',
        'ledger_posting_groups', 'ledger_entries', 'ledger_projection_state', 'ledger_transaction_projections',
        'withdrawal_requests', 'withdrawal_reservations', 'collection_receipts', 'collection_allocations', 'customer_assignments'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

test('cycle savings directory batches retain modern fee attribution and bounded query growth across equal-time pagination', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    $plans = [];
    for ($index = 0; $index < 26; $index++) {
        [$admin, $customer, $agent] = $this->createLifecycleFixture();
        $plans[] = savingsDirectoryPlan($admin, $customer, $agent->user);
    }
    app(LedgerTransactionProjectionService::class)->rebuild();
    $reader = app(PlanSavingsReadService::class);
    $reader->readMany($admin, [$plans[0]]);
    $before = savingsDirectoryRows();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $reader->readMany($admin, [$plans[0]]);
    $singleCount = count(DB::getQueryLog());
    DB::flushQueryLog();
    $batch = $reader->readMany($admin, $plans);
    $batchCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($batchCount)->toBeLessThanOrEqual($singleCount + 2)->and($batch)->toHaveCount(26);
    $activityReader = app(PlanFinancialActivityReadService::class);
    $activityReader->readMany($admin, [$plans[0]]);
    expect($activityReader->history($admin, $plans[0])['history']['total'])->toBe(0);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $activityReader->readMany($admin, [$plans[0]]);
    $singleActivityCount = count(DB::getQueryLog());
    DB::flushQueryLog();
    $activities = $activityReader->readMany($admin, $plans);
    $batchActivityCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($batchActivityCount)->toBeLessThanOrEqual($singleActivityCount + 2);
    foreach ($plans as $plan) {
        expect($batch[$plan->plan_id])->toEqual($reader->read($admin, $plan))
            ->and($batch[$plan->plan_id]['cycle']['liability'])->toBe('₦900.00')
            ->and($batch[$plan->plan_id]['cycle']['reserved'])->toBe('₦300.00')
            ->and($batch[$plan->plan_id]['cycle']['available'])->toBe('₦600.00');
        expect($activities[$plan->plan_id])->toEqual($activityReader->read($admin, $plan))
            ->and(collect($activities[$plan->plan_id]['metrics'])->every(fn (array $metric): bool => $metric['value'] === 0))->toBeTrue();
    }
    $this->actingAs($admin)->get(route('plans.index', ['search' => 'Directory savings cycle', 'per_page' => 25]))
        ->assertInertia(fn (Assert $page) => $page->where('plans.total', 26)->has('plans.data', 25)
            ->where('plans.data.0.id', $plans[25]->plan_id)->where('plans.data.0.savings_summary.cycle.available', '₦600.00')
            ->where('plans.data.0.posted_activity', $activities[$plans[25]->plan_id]));
    $this->get(route('plans.index', ['search' => 'Directory savings cycle', 'per_page' => 25, 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page->where('plans.total', 26)->has('plans.data', 1)
            ->where('plans.data.0.id', $plans[0]->plan_id)->where('plans.data.0.savings_summary.customer.available', '₦600.00'));
    expect(savingsDirectoryRows())->toEqual($before);
});

test('damaged cycle sources preserve independently verified Customer savings and other cycle summaries', function (string $damage): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $firstCustomer, $firstAgent] = $this->createLifecycleFixture();
    $first = savingsDirectoryPlan($admin, $firstCustomer, $firstAgent->user);
    [, $secondCustomer, $secondAgent] = $this->createLifecycleFixture();
    $second = savingsDirectoryPlan($admin, $secondCustomer, $secondAgent->user);
    app(LedgerTransactionProjectionService::class)->rebuild();
    match ($damage) {
        'fee origin' => DB::table('fee_snapshots')->where('id', $first->currentTermsRevision()->fee_snapshot_id)->update(['source_id' => 'PLN-UNRELATED-R1']),
        'fee enum' => DB::table('fee_snapshots')->where('id', $first->currentTermsRevision()->fee_snapshot_id)->update(['kind' => 'unknown_kind']),
        'receipt owner' => DB::table('collection_receipts')->where('thrift_plan_id', $first->id)->update(['customer_profile_id' => $secondCustomer->id]),
        'reservation cycle' => DB::table('withdrawal_reservations')->where('customer_profile_id', $firstCustomer->id)->update(['thrift_plan_id' => null]),
    };
    $before = savingsDirectoryRows();
    $reader = app(PlanSavingsReadService::class);
    $summary = $reader->readMany($admin, [$first, $second]);
    expect($summary[$first->plan_id]['customer']['available'])->toBe('₦600.00')
        ->and($summary[$first->plan_id]['cycle']['available'])->toBeNull()
        ->and($summary[$second->plan_id]['cycle']['available'])->toBe('₦600.00')
        ->and($reader->read($admin, $first)['cycle']['available'])->toBeNull()
        ->and(savingsDirectoryRows())->toEqual($before);
})->with(['fee origin', 'fee enum', 'receipt owner', 'reservation cycle']);

test('current handover retains cycle savings and immediately removes former Agent batch access', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = savingsDirectoryPlan($admin, $customer, $agent->user);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $admin->givePermissionTo(AdminPermission::CustomersReassign);
    $owner = app(CustomerReassignmentService::class);
    $preview = $owner->preview($admin, $customer->fresh(), $replacement->id);
    $owner->execute($admin, $customer->fresh(), ['attempt_reference' => (string) Str::uuid(), 'version' => $preview['version'],
        'assignment_version' => $preview['assignment_version'], 'preview_token' => $preview['preview_token'],
        'target_agent_id' => $replacement->id, 'confirmed' => true, 'reason' => 'Reviewed transfer.', 'customer_explanation' => 'Your service contact changed.']);
    $before = savingsDirectoryRows();
    $reader = app(PlanSavingsReadService::class);
    expect($reader->readMany($agent->user, [$plan])[$plan->plan_id]['customer']['available'])->toBeNull()
        ->and($reader->readMany($replacement->user, [$plan])[$plan->plan_id])->toEqual($reader->read($customer->user, $plan));
    $activityReader = app(PlanFinancialActivityReadService::class);
    expect($activityReader->readMany($agent->user, [$plan])[$plan->plan_id]['status'])->toBe('unavailable')
        ->and($activityReader->readMany($replacement->user, [$plan])[$plan->plan_id])->toEqual($activityReader->read($customer->user, $plan));
    $this->actingAs($agent->user)->get(route('plans.index', ['search' => $plan->plan_id]))
        ->assertInertia(fn (Assert $page) => $page->where('plans.total', 0));
    $this->actingAs($replacement->user)->get(route('plans.index', ['search' => $plan->plan_id]))
        ->assertInertia(fn (Assert $page) => $page->where('plans.total', 1)->where('plans.data.0.savings_summary.cycle.available', '₦600.00'));
    expect(fn () => $activityReader->history($agent->user, $plan))->toThrow(NotFoundHttpException::class)
        ->and($activityReader->history($replacement->user, $plan)['history']['total'])->toBe(0);
    expect(savingsDirectoryRows())->toEqual($before);
});

test('empty duplicate and oversized cycle savings batches do not query financial sources', function (): void {
    $plan = new ThriftPlan(['plan_id' => 'PLN-INPUT', 'customer_profile_id' => 1]);
    $plan->id = 1;
    $viewer = User::factory()->customer()->make();
    $reader = app(PlanSavingsReadService::class);
    $owner = app(WithdrawalBalanceService::class);
    $activityReader = app(PlanFinancialActivityReadService::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($reader->readMany($viewer, []))->toBe([])->and($owner->positions([]))->toBe([]);
    expect($activityReader->readMany($viewer, []))->toBe([]);
    expect(fn () => $activityReader->readMany($viewer, [$plan, $plan]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $activityReader->readMany($viewer, array_fill(0, 101, $plan)))->toThrow(InvalidArgumentException::class);
    expect(fn () => $reader->readMany($viewer, [$plan, $plan]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $reader->readMany($viewer, array_fill(0, 101, $plan)))->toThrow(InvalidArgumentException::class);
    expect(fn () => $owner->positions([$plan, $plan]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $owner->positions(array_fill(0, 101, $plan)))->toThrow(InvalidArgumentException::class);
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});
