<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Jobs\DeliverPlanNotificationIntent;
use App\Models\BusinessProfile;
use App\Models\ChargeCategoryVersion;
use App\Models\ContributionSlot;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeRule;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\PlanLifecycleEvent;
use App\Models\PlanNotificationIntent;
use App\Models\PlanOperationAttempt;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\AgentEligibilityService;
use App\Services\BusinessSettings;
use App\Services\CollectionReadService;
use App\Services\CollectionService;
use App\Services\CollectionWorkspaceService;
use App\Services\CustomerLifecycleService;
use App\Services\CustomerStatusManagementService;
use App\Services\FinancialReleaseEvidenceService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\PlanEstimateService;
use App\Services\PlanFinancialActivityReadService;
use App\Services\PlanSavingsReadService;
use App\Services\ThriftPlanService;
use App\Services\WithdrawalService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\CreatesLifecycleCustomers;

require_once __DIR__.'/../ManualChargeFixtures.php';
require_once __DIR__.'/../WithdrawalFixtures.php';

uses(CreatesLifecycleCustomers::class);

test('FEE-AC-013: actual receipts and replay preserve one fixed or contractual daily cycle fee across descriptive revision', function (string $damage, string $model, string $source, string $timing): void {
    $this->freezeTime();
    config()->set('collections.enabled', true);
    [, $customer, $agent] = $this->createLifecycleFixture();
    $actor = $agent->user;
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    $rule = FeeRule::create(['version' => 1, 'name' => 'Once per cycle fee', 'kind' => 'plan', 'rule_key' => 'once-per-cycle',
        'model' => $model, 'timing' => $timing, 'basis' => $model === 'one_day' ? 'contractual_daily_contribution' : 'none', 'settlement_source' => $source,
        'currency' => 'NGN', 'amount_kobo' => $model === 'fixed' ? 10000 : 0, 'customer_description' => 'Agreed fee once per cycle.',
        'effective_at' => now()->subDay(), 'published_by_user_id' => $actor->id, 'publication_reason' => 'Isolated fixture.']);
    $confirmed = httpPlanConfirmed($customer, $actor, httpPlanData($customer, $rule));
    $service = app(ThriftPlanService::class);
    $plan = $service->create($actor, $customer, $confirmed['attempt_reference'], $confirmed)['plan'];
    $collection = [...$this->lifecycleCollectionPayload($customer, $plan), 'savings_ngn' => '4000.00'];
    $collection['preview_fingerprint'] = app(CollectionService::class)->preview($actor, $customer, $collection)['preview_fingerprint'];
    app(CollectionService::class)->record($actor, $customer, $collection);
    $firstRows = httpPlanOwnerRows();
    app(CollectionService::class)->record($actor, $customer, $collection);
    expect(httpPlanOwnerRows())->toEqual($firstRows);
    expect(DB::table('fee_obligations')->count())->toBe($timing === 'first_contribution' ? 1 : 0);
    $plan->refresh();
    $originalSnapshotId = $plan->currentTermsRevision()->fee_snapshot_id;
    $financialTables = ['contribution_slots', 'fee_snapshots', 'collection_receipts', 'fee_obligations', 'fee_obligation_entries', 'ledger_posting_groups', 'ledger_entries', 'withdrawal_requests', 'withdrawal_reservations'];
    $revisionRows = array_intersect_key(httpPlanOwnerRows(), array_flip($financialTables));
    $revision = [...$confirmed, 'name' => 'Clearer agreed name', 'plan_version' => $plan->version,
        'terms_revision' => $plan->current_terms_revision, 'reason' => 'Customer confirms the clearer name.',
        'customer_explanation' => 'All financial terms remain the same.'];
    $revision['preview_fingerprint'] = $service->previewRevision($actor, $plan, $revision)['preview_fingerprint'];
    $revised = $service->revise($actor, $plan, (string) Str::uuid(), $revision);
    expect(array_intersect_key(httpPlanOwnerRows(), array_flip($financialTables)))->toEqual($revisionRows)
        ->and($revised->currentTermsRevision()->fee_snapshot_id)->toBe($originalSnapshotId);
    $next = [...$this->lifecycleCollectionPayload($customer, $revised), 'savings_ngn' => '2000.00'];
    $next['preview_fingerprint'] = app(CollectionService::class)->preview($actor, $customer, $next)['preview_fingerprint'];
    app(CollectionService::class)->record($actor, $customer, $next);
    $completionRows = httpPlanOwnerRows();
    app(CollectionService::class)->record($actor, $customer, $next);
    expect(httpPlanOwnerRows())->toEqual($completionRows);
    $expectedFee = $model === 'fixed' ? 10000 : 200000;
    $expectedSettlement = $source === 'savings_application' ? $expectedFee : 0;
    expect(DB::table('fee_obligations')->count())->toBe(1)
        ->and((int) DB::table('fee_obligation_entries')->where('entry_type', 'assessment')->sum('amount_kobo'))->toBe($expectedFee)
        ->and(DB::table('fee_obligation_entries')->where('entry_type', 'assessment')->count())->toBe(1)
        ->and((int) DB::table('fee_obligation_entries')->where('entry_type', 'settlement')->sum('amount_kobo'))->toBe($expectedSettlement)
        ->and(FeeObligation::query()->sole()->outstandingAmountKobo())->toBe($source === 'external_receipt' ? $expectedFee : 0)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(600000 - $expectedSettlement);
    $revised->refresh();
    $terms = $revised->currentTermsRevision();
    match ($damage) {
        'later source' => DB::table('fee_snapshots')->where('id', $originalSnapshotId)->update(['source_id' => $revised->plan_id.'-R2']),
        'foreign source' => DB::table('fee_snapshots')->where('id', $originalSnapshotId)->update(['source_id' => 'PLN-OTHER-R1']),
        'changed amount' => DB::table('plan_terms_revisions')->where('id', $terms->id)->update(['contribution_amount_kobo' => 300000, 'expected_gross_kobo' => 900000]),
        'changed schedule' => DB::table('plan_terms_revisions')->where('id', $terms->id)->update(['start_date' => CarbonImmutable::parse($terms->start_date)->addDay()->toDateString()]),
        default => null,
    };
    $terms = $terms->fresh();
    app(LedgerTransactionProjectionService::class)->rebuild();
    enableFixtureMethod();
    $before = httpPlanOwnerRows();
    $estimate = app(PlanEstimateService::class)->forSnapshot($revised, $terms);
    $payload = withdrawalPayload($customer, $customer->currentAssignment, $revised);
    if ($damage === 'none' && $source === 'savings_application') {
        $quote = app(WithdrawalService::class)->preview($actor, $customer->fresh(), $payload);
        expect($estimate['status'])->toBe('available')->and($estimate['estimated_fee'])->toBe($model === 'fixed' ? '₦100.00' : '₦2,000.00')
            ->and($quote['fee_snapshot_id'])->toBe($originalSnapshotId)->and($quote['fee_kobo'])->toBe(0);
    } elseif ($damage === 'none') {
        expect($estimate['status'])->toBe('available');
        if ($timing === 'cycle_completion') {
            expect(fn () => app(WithdrawalService::class)->preview($actor, $customer->fresh(), $payload))->toThrow(ConflictHttpException::class);
        } else {
            $quote = app(WithdrawalService::class)->preview($actor, $customer->fresh(), $payload);
            expect($quote['fee_kobo'])->toBe(0);
            expect(FeeObligation::query()->sole()->outstandingAmountKobo())->toBe($expectedFee);
        }
    } else {
        expect($estimate['status'])->toBe('unavailable');
        expect(fn () => app(WithdrawalService::class)->preview($actor, $customer->fresh(), $payload))
            ->toThrow(ConflictHttpException::class);
    }
    expect(httpPlanOwnerRows())->toEqual($before);
})->with([
    'fixed savings' => ['none', 'fixed', 'savings_application'],
    'later source' => ['later source', 'fixed', 'savings_application'],
    'foreign source' => ['foreign source', 'fixed', 'savings_application'],
    'changed amount' => ['changed amount', 'fixed', 'savings_application'],
    'changed schedule' => ['changed schedule', 'fixed', 'savings_application'],
    'one day savings' => ['none', 'one_day', 'savings_application'],
    'fixed external' => ['none', 'fixed', 'external_receipt'],
    'one day external' => ['none', 'one_day', 'external_receipt'],
])->with(['first_contribution', 'cycle_completion']);

test('plan savings detail reads actual reservations without changing owner rows or granting payout eligibility', function (string $damage): void {
    $this->freezeTime();
    config()->set('collections.enabled', true);
    [, $customer, $agent] = $this->createLifecycleFixture();
    $actor = $agent->user;
    $confirmed = httpPlanConfirmed($customer, $actor, httpPlanData($customer, httpPlanRule($actor)));
    $plan = app(ThriftPlanService::class)->create($actor, $customer, $confirmed['attempt_reference'], $confirmed)['plan'];
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    $collection = [...$this->lifecycleCollectionPayload($customer, $plan), 'savings_ngn' => '1000.00'];
    $collection['preview_fingerprint'] = app(CollectionService::class)->preview($actor, $customer, $collection)['preview_fingerprint'];
    app(CollectionService::class)->record($actor, $customer, $collection);
    enableFixtureMethod();
    submittedWithdrawal($actor, $customer, $customer->currentAssignment, $plan->fresh());
    app(LedgerTransactionProjectionService::class)->rebuild();
    match ($damage) {
        'projection' => DB::table('ledger_projection_state')->update(['status' => 'unavailable']),
        'mapping' => DB::table('ledger_accounts')->where('code', 'customer_savings_liability_ngn')->update(['mapping_status' => 'unmapped']),
        'attribution' => DB::table('withdrawal_reservations')->where('customer_profile_id', $customer->id)->update(['thrift_plan_id' => null]),
        default => null,
    };
    $before = httpPlanOwnerRows();
    $customerReady = in_array($damage, ['none', 'attribution'], true);
    foreach ([$actor, $customer->user] as $viewer) {
        $summary = app(PlanSavingsReadService::class)->readMany($viewer, [$plan->fresh()])[$plan->plan_id];
        $activitySummary = app(PlanFinancialActivityReadService::class)->readMany($viewer, [$plan->fresh()])[$plan->plan_id];
        expect($activitySummary['status'])->toBe($customerReady ? 'available' : 'unavailable');
        expect($summary['customer']['available'])->toBe($customerReady ? '₦700.00' : null)
            ->and($summary['cycle']['available'])->toBe($damage === 'none' ? '₦700.00' : null);
        $this->actingAs($viewer)->get(route('plans.index', ['search' => $plan->plan_id]))->assertInertia(fn (Assert $page) => $page
            ->where('plans.total', 1)->where('plans.data.0.id', $plan->plan_id)
            ->where('plans.data.0.savings_summary', $summary)->where('plans.data.0.posted_activity', $activitySummary));
        $this->actingAs($viewer)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
            ->where('plan.savings_summary.customer.liability', $customerReady ? '₦1,000.00' : null)
            ->where('plan.savings_summary.customer.reserved', $customerReady ? '₦300.00' : null)
            ->where('plan.savings_summary.customer.available', $customerReady ? '₦700.00' : null)
            ->where('plan.savings_summary.cycle.liability', $damage === 'none' ? '₦1,000.00' : null)
            ->where('plan.savings_summary.cycle.reserved', $damage === 'none' ? '₦300.00' : null)
            ->where('plan.savings_summary.cycle.available', $damage === 'none' ? '₦700.00' : null)
            ->where('plan.posted_activity.status', $customerReady ? 'available' : 'unavailable')
            ->where('plan.posted_activity.metrics', fn ($metrics): bool => $customerReady
                ? count($metrics) === 10 && collect($metrics)->every(fn ($metric): bool => $metric['value'] === 0)
                : count($metrics) === 0)
            ->where('plan.estimate.estimated_payout', '₦6,000.00'));
    }
    expect(httpPlanOwnerRows())->toEqual($before);
    $foreign = User::factory()->agent()->withTwoFactor()->create();
    $this->actingAs($foreign)->get(route('plans.show', $plan->plan_id))->assertNotFound();
    expect(fn () => app(PlanSavingsReadService::class)->read($foreign, $plan))
        ->toThrow(NotFoundHttpException::class);
    expect(app(PlanSavingsReadService::class)->readMany($foreign, [$plan])[$plan->plan_id]['customer']['available'])->toBeNull();
    expect(fn () => app(PlanFinancialActivityReadService::class)->read($foreign, $plan))
        ->toThrow(NotFoundHttpException::class);
    expect(app(PlanFinancialActivityReadService::class)->readMany($foreign, [$plan])[$plan->plan_id]['status'])->toBe('unavailable');
    $oldVersion = clone $plan;
    $oldVersion->version = $plan->version - 1;
    expect(app(PlanSavingsReadService::class)->read($actor, $oldVersion)['customer']['liability'])->toBeNull();
    expect(app(PlanSavingsReadService::class)->readMany($actor, [$oldVersion])[$plan->plan_id]['customer']['available'])->toBeNull();
    expect(app(PlanFinancialActivityReadService::class)->read($actor, $oldVersion)['metrics'])->toBe([]);
    expect(app(PlanFinancialActivityReadService::class)->readMany($actor, [$oldVersion])[$plan->plan_id]['metrics'])->toBe([]);
    $plan->refresh();
    $foreignCustomer = CustomerProfile::factory()->create();
    DB::table('thrift_plans')->where('id', $plan->id)->update(['customer_profile_id' => $foreignCustomer->id]);
    $changedOwner = app(PlanSavingsReadService::class)->read($actor, $plan);
    expect(app(PlanSavingsReadService::class)->readMany($actor, [$plan])[$plan->plan_id]['customer']['available'])->toBeNull();
    expect($changedOwner['customer']['liability'])->toBeNull()
        ->and($changedOwner['cycle']['liability'])->toBeNull()->and($changedOwner['source_version'])->toBeNull();
    expect(app(PlanFinancialActivityReadService::class)->read($actor, $plan)['metrics'])->toBe([]);
    expect(app(PlanFinancialActivityReadService::class)->readMany($actor, [$plan])[$plan->plan_id]['metrics'])->toBe([]);
})->with(['none', 'projection', 'mapping', 'attribution']);

test('contractual estimates use captured plan fees without creating actual financial values', function (string $model, string $timing, string $basis, string $source, int $amount, ?int $points, ?string $fee, ?string $payout): void {
    $this->freezeTime();
    config()->set('collections.enabled', true);
    [, $customer, $agent] = $this->createLifecycleFixture();
    $actor = $agent->user;
    $rule = FeeRule::create([
        'version' => 1, 'name' => 'Agreed plan estimate', 'kind' => 'plan', 'rule_key' => 'estimate',
        'model' => $model, 'timing' => $timing, 'basis' => $basis, 'settlement_source' => $source,
        'currency' => 'NGN', 'amount_kobo' => $amount, 'basis_points' => $points,
        'customer_description' => 'Customer reviewed the fee.', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $actor->id, 'publication_reason' => 'Fixture',
    ]);
    $data = [...httpPlanData($customer, $rule), 'contribution_days' => 31];
    $before = httpPlanOwnerRows();
    $this->actingAs($actor)->get(route('customers.plans.create', [
        'customer' => $customer->customer_id, 'preview' => 1, ...httpPlanPreviewData($data),
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('preview.estimate.expected_gross', '₦62,000.00')
        ->where('preview.estimate.estimated_fee', $fee)
        ->where('preview.estimate.estimated_payout', $payout));
    expect(httpPlanOwnerRows())->toEqual($before);
    $confirmed = httpPlanConfirmed($customer, $actor, $data);
    $plan = app(ThriftPlanService::class)->create($actor, $customer, $confirmed['attempt_reference'], $confirmed)['plan'];
    $financialTables = array_flip(['collection_receipts', 'fee_obligations', 'fee_obligation_entries',
        'ledger_posting_groups', 'ledger_entries', 'withdrawal_requests', 'withdrawal_reservations']);
    expect(array_intersect_key(httpPlanOwnerRows(), $financialTables))->toEqual(array_intersect_key($before, $financialTables));
    app(LedgerTransactionProjectionService::class)->rebuild();
    $beforeDetail = httpPlanOwnerRows();
    $this->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->where('plan.estimate.expected_gross', '₦62,000.00')
        ->where('plan.estimate.estimated_fee', $fee)
        ->where('plan.estimate.estimated_payout', $payout)
        ->where('plan.financial_summary.funded_principal', '₦0.00')
        ->where('plan.financial_summary.fully_funded_slots', '0')
        ->where('plan.financial_summary.remaining_scheduled_target', '₦62,000.00'));
    expect(httpPlanOwnerRows())->toEqual($beforeDetail);
    if ($model === 'one_day' && $timing === 'cycle_completion' && $source === 'savings_application') {
        LedgerAccount::query()->update(['mapping_status' => 'mapped']);
        FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
        $collection = [...$this->lifecycleCollectionPayload($customer, $plan), 'savings_ngn' => '2500.00'];
        $collection['preview_fingerprint'] = app(CollectionService::class)->preview($actor, $customer, $collection)['preview_fingerprint'];
        app(CollectionService::class)->record($actor, $customer, $collection);
        app(LedgerTransactionProjectionService::class)->rebuild();
        $beforeFundedRead = httpPlanOwnerRows();
        $this->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
            ->where('plan.estimate.expected_gross', '₦62,000.00')->where('plan.estimate.estimated_fee', '₦2,000.00')
            ->where('plan.estimate.estimated_payout', '₦60,000.00')
            ->where('plan.financial_summary.funded_principal', '₦2,500.00')
            ->where('plan.financial_summary.fully_funded_slots', '1')
            ->where('plan.financial_summary.partially_funded_slots', '1')
            ->where('plan.financial_summary.remaining_scheduled_target', '₦59,500.00'));
        expect(httpPlanOwnerRows())->toEqual($beforeFundedRead);
    }
})->with([
    'one day from savings' => ['one_day', 'cycle_completion', 'contractual_daily_contribution', 'savings_application', 0, null, '₦2,000.00', '₦60,000.00'],
    'one day once at withdrawal' => ['one_day', 'withdrawal', 'contractual_daily_contribution', 'withdrawal_payout', 0, null, '₦2,000.00', '₦60,000.00'],
    'fixed once at withdrawal' => ['fixed', 'withdrawal', 'none', 'withdrawal_payout', 10000, null, '₦100.00', '₦61,900.00'],
    'one day external receipt' => ['one_day', 'first_contribution', 'contractual_daily_contribution', 'external_receipt', 0, null, '₦2,000.00', '₦62,000.00'],
    'completion percentage' => ['percentage', 'cycle_completion', 'net_cycle_contributions', 'savings_application', 0, 500, '₦3,100.00', '₦58,900.00'],
    'withdrawal percentage' => ['percentage', 'withdrawal', 'gross_withdrawal_debit', 'withdrawal_payout', 0, 500, null, null],
    'no fee' => ['no_fee', 'first_contribution', 'none', 'savings_application', 0, null, '₦0.00', '₦62,000.00'],
    'fee equals gross' => ['fixed', 'cycle_completion', 'none', 'savings_application', 6200000, null, '₦62,000.00', null],
    'fee exceeds gross' => ['fixed', 'cycle_completion', 'none', 'savings_application', 6300000, null, '₦63,000.00', null],
]);

test('amendment estimates follow the new immutable terms while preserving prior snapshots', function (): void {
    $this->freezeTime();
    [, $customer, $agent] = $this->createLifecycleFixture();
    $actor = $agent->user;
    $rule = httpPlanRule($actor);
    $confirmed = httpPlanConfirmed($customer, $actor, httpPlanData($customer, $rule));
    $service = app(ThriftPlanService::class);
    $plan = $service->create($actor, $customer, $confirmed['attempt_reference'], $confirmed)['plan'];
    $oldTerms = $plan->currentTermsRevision();
    $oldSnapshot = $oldTerms->feeSnapshot->getAttributes();
    $revision = [...$confirmed, 'amount_ngn' => '2500.00', 'contribution_days' => 31,
        'plan_version' => $plan->version, 'terms_revision' => 1, 'reason' => 'Customer changes the agreed schedule.',
        'customer_explanation' => 'The new schedule is 31 days at 2500 naira.'];
    $before = httpPlanOwnerRows();
    $this->actingAs($actor)->get(route('plans.edit', ['plan' => $plan->plan_id, 'preview' => 1, ...httpPlanPreviewData($revision)]))
        ->assertInertia(fn (Assert $page) => $page->where('preview.estimate.expected_gross', '₦77,500.00')
            ->where('preview.estimate.estimated_fee', '₦0.00')->where('preview.estimate.estimated_payout', '₦77,500.00'));
    expect(httpPlanOwnerRows())->toEqual($before);
    $revision['preview_fingerprint'] = $service->previewRevision($actor, $plan, $revision)['preview_fingerprint'];
    $service->revise($actor, $plan, (string) Str::uuid(), $revision);
    $this->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->where('plan.estimate.expected_gross', '₦77,500.00')->where('plan.estimate.estimated_payout', '₦77,500.00'));
    expect($oldTerms->feeSnapshot->fresh()->getAttributes())->toEqual($oldSnapshot);
});

test('unverifiable estimate provenance leaves actual funding independently readable', function (array $patch): void {
    config()->set('collections.enabled', true);
    [, $customer, $agent] = $this->createLifecycleFixture();
    $confirmed = httpPlanConfirmed($customer, $agent->user, httpPlanData($customer, httpPlanRule($agent->user)));
    $plan = app(ThriftPlanService::class)->create($agent->user, $customer, $confirmed['attempt_reference'], $confirmed)['plan'];
    DB::table('fee_snapshots')->where('id', $plan->currentTermsRevision()->fee_snapshot_id)->update($patch);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $before = httpPlanOwnerRows();
    $this->actingAs($agent->user)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->where('plan.estimate.status', 'unavailable')->where('plan.estimate.estimated_payout', null)
        ->where('plan.financial_summary.funded_principal', '₦0.00'));
    expect(httpPlanOwnerRows())->toEqual($before);
})->with([
    'wrong cycle' => [['source_id' => 'PLN-OTHER-R1']],
    'wrong currency' => [['currency' => 'USD']],
    'wrong kind' => [['kind' => 'registration']],
    'nonzero no fee' => [['amount_kobo' => 100]],
]);

/** @param array<string, mixed> $patch */
function httpPlanPublishConfiguration(User $actor, array $patch): void
{
    $settings = app(BusinessSettings::class);
    $settings->import();
    $draft = $settings->saveDraft($actor, $patch, BusinessProfile::current()->version, (string) Str::uuid());
    $preview = $settings->preview($actor, $draft['draft_id'], $draft['revision'], null);
    $request = Request::create('/admin/business-settings', 'POST');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    $settings->publish($actor, $draft['draft_id'], $draft['revision'], $preview['reference'],
        'TEST FIXTURE reviewed prospective configuration.', (string) Str::uuid(), $request);
}

function httpPlanEnableCertifiedCreation(User $actor): void
{
    $actor->givePermissionTo(AdminPermission::BusinessSettingsManage);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    config()->set('app.financial_release_revision', 'plan-calendar-test-release');
    $service = app(FinancialReleaseEvidenceService::class);
    foreach ($service::ROLES as $role) {
        $service->record($actor, ['capability' => 'plan_creation', 'owner_role' => $role, 'version' => 1,
            'state' => 'accepted', 'dependency_hash' => $service->dependencyHash(), 'valid_until' => now()->addDay()->toIso8601String(),
            'evidence' => 'TEST FIXTURE plan lifecycle release acceptance.']);
    }
    httpPlanPublishConfiguration($actor, ['plan_creation' => true]);
}

function httpPlanRule(User $agent): FeeRule
{
    return FeeRule::create([
        'version' => 1, 'name' => 'No plan fee', 'kind' => 'plan', 'rule_key' => 'daily',
        'model' => 'no_fee', 'timing' => 'first_contribution', 'basis' => 'none',
        'settlement_source' => 'external_receipt', 'currency' => 'NGN', 'amount_kobo' => 0,
        'customer_description' => 'No fee', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $agent->id, 'publication_reason' => 'Fixture',
    ]);
}

/** @return array<string, mixed> */
function httpPlanData(CustomerProfile $customer, FeeRule $rule): array
{
    return [
        'name' => 'Daily plan', 'amount_ngn' => '2000.00',
        'start_date' => now('Africa/Lagos')->toDateString(), 'contribution_days' => 3,
        'customer_visible_notes' => 'Agreed at the branch.', 'fee_rule_id' => $rule->id,
        'fee_rule_version' => $rule->version, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version,
        'business_version' => BusinessProfile::current()->version,
        'customer_agreement_attested' => true,
    ];
}

/** @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function httpPlanPreviewData(array $data): array
{
    return array_intersect_key($data, array_flip([
        'name', 'amount_ngn', 'start_date', 'contribution_days', 'customer_visible_notes', 'fee_rule_id',
    ]));
}

/** @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function httpPlanConfirmed(CustomerProfile $customer, User $agent, array $data): array
{
    return [
        ...$data,
        'preview_fingerprint' => app(ThriftPlanService::class)->preview($agent, $customer, $data)['preview_fingerprint'],
        'attempt_reference' => (string) Str::uuid(),
    ];
}

function assertNoHttpPlanEffects(): void
{
    expect(ThriftPlan::count())->toBe(0)
        ->and(ContributionSlot::count())->toBe(0)
        ->and(DB::table('fee_snapshots')->whereIn('source_type', ['plan', 'plan_terms_revision'])->count())->toBe(0)
        ->and(PlanOperationAttempt::count())->toBe(0)
        ->and(DB::table('plan_notification_intents')->count())->toBe(0)
        ->and(DB::table('collection_receipts')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
}

/** @return array<string, array<int, object>> */
function httpPlanOwnerRows(): array
{
    $rows = [];
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'fee_snapshots', 'plan_operation_attempts',
        'plan_lifecycle_events', 'plan_notification_intents', 'collection_receipts', 'fee_obligations', 'fee_obligation_entries',
        'ledger_posting_groups', 'ledger_entries', 'withdrawal_requests', 'withdrawal_reservations'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

test('pause and eligible resume preserve funded savings live reservation original dates and blocked status history', function (string $blockedStatus): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    config()->set('collections.enabled', true);
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $actor = $agent->user;
    FinancialPeriod::factory()->create(['month' => '2026-10-01']);
    LedgerAccount::query()->where('currency', 'NGN')->update(['mapping_status' => 'mapped']);
    $plan = $this->createLifecyclePlan($customer, $actor);
    $terms = $plan->currentTermsRevision()->getAttributes();
    $slots = $plan->slots()->orderBy('id')->get()->map->getAttributes()->all();
    $collections = app(CollectionService::class);
    $collection = $this->lifecycleCollectionPayload($customer, $plan);
    $collection['preview_fingerprint'] = $collections->preview($actor, $customer, $collection)['preview_fingerprint'];
    $receipt = $collections->record($actor, $customer, $collection);
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($actor, $customer, $customer->currentAssignment, $plan->fresh());
    $financialTables = array_flip(['plan_terms_revisions', 'contribution_slots', 'fee_snapshots', 'collection_receipts',
        'fee_obligations', 'fee_obligation_entries', 'ledger_posting_groups', 'ledger_entries',
        'withdrawal_requests', 'withdrawal_reservations']);
    $beforePause = array_intersect_key(httpPlanOwnerRows(), $financialTables);
    $position = app(CollectionReadService::class)->position($customer);
    expect($position)->toBe(['liability_kobo' => 100000, 'reservations_kobo' => 30000, 'available_kobo' => 70000]);
    $plan->refresh();
    $action = ['attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'plan_version' => $plan->version,
        'reason' => 'Customer requests a pause with pending payout retained.',
        'customer_explanation' => 'Your savings and original dates remain unchanged.'];
    $this->actingAs($actor)->post(route('plans.pause', $plan->plan_id), $action)->assertRedirect();
    $plan->refresh();
    expect(array_intersect_key(httpPlanOwnerRows(), $financialTables))->toEqual($beforePause)
        ->and($plan->status->value)->toBe('paused')
        ->and($plan->open_customer_profile_id)->toBe($customer->id);
    $blockedCollection = [...$collection, 'attempt_reference' => (string) Str::uuid(), 'plan_version' => $plan->version];
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $blockedCollection)->assertUnprocessable();
    $data = httpPlanData($customer, $plan->currentTermsRevision()->feeSnapshot->feeRule);
    $this->getJson(route('customers.plans.create', ['customer' => $customer->customer_id,
        'preview' => 1, ...httpPlanPreviewData($data)]))->assertUnprocessable()->assertInvalid(['customer']);
    expect(ThriftPlan::count())->toBe(1);
    $this->travel(1)->days();
    $customer = app(CustomerStatusManagementService::class)->transition($admin, $customer,
        CustomerStatus::from($blockedStatus), $customer->version, 'Owner review blocks new contributions.',
        'Your existing savings remain recorded.');
    $beforeDeniedResume = httpPlanOwnerRows();
    $action = [...$action, 'attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'plan_version' => $plan->version];
    $this->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])
        ->postJson(route('plans.resume', $plan->plan_id), $action)->assertConflict();
    expect(httpPlanOwnerRows())->toEqual($beforeDeniedResume)
        ->and(app(CollectionReadService::class)->position($customer))->toBe($position)
        ->and($withdrawal->fresh()->state)->toBe('pending_review')
        ->and($withdrawal->fresh()->held)->toBe($blockedStatus === 'restricted');
    $this->travel(1)->days();
    $customer = app(CustomerStatusManagementService::class)->transition($admin, $customer,
        CustomerStatus::Active, $customer->version, 'Review restores eligible participation.',
        'You may explicitly resume your existing cycle.');
    $beforeResume = array_intersect_key(httpPlanOwnerRows(), $financialTables);
    $resume = [...$action, 'attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'reason' => 'Customer agrees to resume original dates after status review.'];
    $this->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])
        ->post(route('plans.resume', $plan->plan_id), $resume)->assertRedirect();
    $beforeReplay = httpPlanOwnerRows();
    $this->post(route('plans.resume', $plan->plan_id), $resume)->assertRedirect();
    expect(httpPlanOwnerRows())->toEqual($beforeReplay)
        ->and(array_intersect_key(httpPlanOwnerRows(), $financialTables))->toEqual($beforeResume)
        ->and(app(CollectionReadService::class)->position($customer))->toBe($position)
        ->and($plan->fresh()->status->value)->toBe('active')
        ->and($plan->fresh()->open_customer_profile_id)->toBe($customer->id)
        ->and($plan->currentTermsRevision()->getAttributes())->toBe($terms)
        ->and($plan->slots()->orderBy('id')->get()->map->getAttributes()->all())->toBe($slots)
        ->and($withdrawal->fresh()->state)->toBe('pending_review')
        ->and($withdrawal->fresh()->held)->toBeFalse()
        ->and($receipt->recorded_by_user_id)->toBe($actor->id);
    $events = $plan->lifecycleEvents()->orderBy('id')->get();
    expect($events->pluck('event_type')->all())->toBe(['pause', 'resume'])
        ->and($events->map(fn (PlanLifecycleEvent $event): string => $event->effective_at->setTimezone('Africa/Lagos')->format('Y-m-d H:i:s'))->all())
        ->toBe(['2026-10-05 12:00:00', '2026-10-07 12:00:00']);
    $card = app(CollectionReadService::class)->fundingCard($plan->fresh());
    expect(array_column($card['slots'], 'due_date'))->toBe(['2026-10-05', '2026-10-06'])
        ->and(array_column($card['slots'], 'status'))->toBe(['partial', 'blocked'])
        ->and(array_column($card['slots'], 'funded_kobo'))->toBe([100000, 0])
        ->and($card['funded_kobo'])->toBe(100000);
    $work = app(CollectionWorkspaceService::class)->dueWork($actor, '2026-10-06', '2026-10-07', '', 'all');
    expect($work['slots']->total())->toBe(1)
        ->and($work['slots']->items()[0]['status'])->toBe('blocked')
        ->and($work['totals']['outstanding_kobo'])->toBe(0)
        ->and($work['totals']['blocked_target_kobo'])->toBe(200000);
    $this->assertDatabaseCount('collection_annotations', 0);
})->with(['Inactive Customer' => 'inactive', 'Restricted Customer' => 'restricted']);

test('renewal rejects open and already linked predecessors at HTTP preview and confirmation without owner effects', function (string $state): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [, $customer, $agent] = $this->createLifecycleFixture();
    $actor = $agent->user;
    $rule = httpPlanRule($actor);
    $data = httpPlanData($customer, $rule);
    $service = app(ThriftPlanService::class);
    $confirmed = httpPlanConfirmed($customer, $actor, $data);
    $plan = $service->create($actor, $customer, $confirmed['attempt_reference'], $confirmed)['plan'];
    if ($state === 'paused' || $state === 'already_linked') {
        $service->transition($actor, $plan, $state === 'paused' ? 'pause' : 'cancel', (string) Str::uuid(), [
            'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
            'plan_version' => $plan->version, 'reason' => 'Customer requests this unused lifecycle action.',
            'customer_explanation' => 'Original agreement is retained in history.',
        ]);
    }
    if ($state === 'completed') {
        config()->set('collections.enabled', true);
        FinancialPeriod::factory()->create(['month' => '2026-10-01']);
        LedgerAccount::query()->where('currency', 'NGN')->update(['mapping_status' => 'mapped']);
        $collections = app(CollectionService::class);
        $collection = [...$this->lifecycleCollectionPayload($customer, $plan),
            'savings_ngn' => (string) ($data['contribution_days'] * 2000).'.00'];
        $collection['preview_fingerprint'] = $collections->preview($actor, $customer, $collection)['preview_fingerprint'];
        $collections->record($actor, $customer, $collection);
        expect($plan->fresh()->status->value)->toBe('completed');
    }
    if ($state === 'already_linked') {
        $renewal = httpPlanConfirmed($customer, $actor, [...$data, 'predecessor_plan_id' => $plan->plan_id]);
        $successor = $service->create($actor, $customer, $renewal['attempt_reference'], $renewal)['plan'];
        $service->transition($actor, $successor, 'cancel', (string) Str::uuid(), [
            'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
            'plan_version' => $successor->version, 'reason' => 'Customer cancels the unused successor.',
            'customer_explanation' => 'The original predecessor retains its single successor link.',
        ]);
        expect(ThriftPlan::whereNotNull('open_customer_profile_id')->count())->toBe(0);
    }
    $baseline = httpPlanOwnerRows();
    $this->actingAs($actor)->getJson(route('customers.plans.create', ['customer' => $customer->customer_id,
        'preview' => 1, ...httpPlanPreviewData($data), 'predecessor_plan_id' => $plan->plan_id]))
        ->assertUnprocessable()->assertInvalid([$state === 'already_linked' ? 'predecessor_plan_id' : 'customer']);
    $this->postJson(route('customers.plans.store', $customer->customer_id), [...$data,
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $confirmed['preview_fingerprint'],
        'predecessor_plan_id' => $plan->plan_id])->assertUnprocessable()->assertInvalid(['predecessor_plan_id']);
    expect(httpPlanOwnerRows())->toEqual($baseline);
})->with(['Active predecessor' => 'active', 'Paused predecessor' => 'paused',
    'Completed predecessor' => 'completed', 'Previously linked Cancelled predecessor' => 'already_linked']);

test('Customer status changes invalidate reviewed creation renewal and amendment without plan or financial effects', function (string $status, string $operation): void {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $actor = $agent->user;
    $data = httpPlanData($customer, httpPlanRule($actor));
    $plan = null;
    if ($operation !== 'create') {
        $plan = $this->createLifecyclePlan($customer, $actor);
        $data = [...$data, 'fee_rule_id' => $plan->currentTermsRevision()->feeSnapshot->fee_rule_id,
            'fee_rule_version' => $plan->currentTermsRevision()->feeSnapshot->fee_rule_version];
    }
    if ($operation === 'amend') {
        $data = [...$data, 'name' => 'Revised agreed daily plan', 'plan_version' => $plan->version, 'terms_revision' => 1,
            'reason' => 'Customer reviewed the proposed name.', 'customer_explanation' => 'Daily dates remain unchanged.',
            'attempt_reference' => (string) Str::uuid()];
        $data['preview_fingerprint'] = app(ThriftPlanService::class)->previewRevision($actor, $plan, $data)['preview_fingerprint'];
    }
    if ($operation === 'renew' || ($operation === 'amend' && $status === 'archived')) {
        app(ThriftPlanService::class)->transition($actor, $plan, 'cancel', (string) Str::uuid(), [
            'plan_version' => $plan->version, 'customer_version' => $customer->version,
            'assignment_version' => $customer->currentAssignment->version,
            'reason' => 'Customer ends this unused cycle.', 'customer_explanation' => 'The original dated cycle remains in history.',
        ]);
        $plan->refresh();
    }
    if ($operation === 'renew') {
        $data['predecessor_plan_id'] = $plan->plan_id;
    }
    if ($operation !== 'amend') {
        $data = httpPlanConfirmed($customer, $actor, $data);
    }
    if ($status === 'archived') {
        app(CustomerLifecycleService::class)->execute($admin, $customer, 'archive', $this->lifecyclePayload($customer));
    } else {
        app(CustomerStatusManagementService::class)->transition($admin, $customer, CustomerStatus::from($status), $customer->version,
            'Customer participation reviewed.', 'Your participation status changed.');
    }
    $customer->refresh();
    $baseline = httpPlanOwnerRows();
    $this->actingAs($actor);
    $url = $operation === 'amend' ? route('plans.update', $plan->plan_id) : route('customers.plans.store', $customer->customer_id);
    $this->json($operation === 'amend' ? 'PATCH' : 'POST', $url, $data)->assertConflict();
    $currentVersions = [...$data, 'customer_version' => $customer->version];
    if ($operation === 'amend') {
        $currentVersions['plan_version'] = $plan->version;
    }
    $response = $this->json($operation === 'amend' ? 'PATCH' : 'POST', $url, $currentVersions);
    if ($operation === 'amend' && $status === 'archived') {
        $response->assertConflict();
    } else {
        $response->assertUnprocessable()->assertInvalid(['customer_status']);
    }
    $previewUrl = $operation === 'amend' ? route('plans.edit', ['plan' => $plan->plan_id, 'preview' => 1, ...httpPlanPreviewData($data)])
        : route('customers.plans.create', ['customer' => $customer->customer_id, 'preview' => 1, ...httpPlanPreviewData($data),
            ...($operation === 'renew' ? ['predecessor_plan_id' => $plan->plan_id] : [])]);
    $preview = $this->getJson($previewUrl);
    if ($operation === 'amend' && $status === 'archived') {
        $preview->assertConflict();
    } else {
        $preview->assertUnprocessable();
    }
    expect(httpPlanOwnerRows())->toEqual($baseline)
        ->and($customer->fresh()->operational_status)->toBe(CustomerStatus::from($status));
})->with([
    'Inactive creation' => ['inactive', 'create'], 'Restricted creation' => ['restricted', 'create'], 'Archived creation' => ['archived', 'create'],
    'Inactive renewal' => ['inactive', 'renew'], 'Restricted renewal' => ['restricted', 'renew'], 'Archived renewal' => ['archived', 'renew'],
    'Inactive amendment' => ['inactive', 'amend'], 'Restricted amendment' => ['restricted', 'amend'], 'Archived historical amendment' => ['archived', 'amend'],
]);

test('plan mutations recheck Agent operational onboarding and current session eligibility after preview', function (string $failure, int $status): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    $actor = $agent->user;
    $confirmed = httpPlanConfirmed($customer, $actor, httpPlanData($customer, httpPlanRule($actor)));
    match ($failure) {
        'inactive' => $agent->update(['operational_status' => 'inactive']),
        'unconfirmed_mfa' => $actor->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save(),
        'revoked_session' => $actor->increment('lifecycle_access_version'),
        'recovery_pending' => $actor->forceFill(['recovery_pending' => true])->save(),
        default => $actor->update(['account_state' => AccountState::from($failure)]),
    };
    $baseline = httpPlanOwnerRows();
    $this->actingAs($actor->fresh())->withSession(['auth.lifecycle_access_version' => 0])
        ->getJson(route('customers.plans.create', ['customer' => $customer->customer_id, 'preview' => 1, ...httpPlanPreviewData($confirmed)]))->assertStatus($status);
    $this->actingAs($actor->fresh())->withSession(['auth.lifecycle_access_version' => 0])
        ->postJson(route('customers.plans.store', $customer->customer_id), $confirmed)->assertStatus($status);
    expect(httpPlanOwnerRows())->toEqual($baseline);
    $plan = $this->createLifecyclePlan($customer, $actor);
    $baseline = httpPlanOwnerRows();
    $revision = [...$confirmed, 'fee_rule_id' => $plan->currentTermsRevision()->feeSnapshot->fee_rule_id,
        'plan_version' => $plan->version, 'terms_revision' => 1,
        'reason' => 'Requested revised daily terms.', 'customer_explanation' => 'No change while Agent is unavailable.'];
    $this->actingAs($actor->fresh())->withSession(['auth.lifecycle_access_version' => 0])
        ->getJson(route('plans.edit', $plan->plan_id))->assertStatus($status);
    $this->actingAs($actor->fresh())->withSession(['auth.lifecycle_access_version' => 0])
        ->patchJson(route('plans.update', $plan->plan_id), $revision)->assertStatus($status);
    foreach (['pause', 'resume', 'cancel'] as $action) {
        $response = $this->actingAs($actor->fresh())->withSession(['auth.lifecycle_access_version' => 0])
            ->postJson(route('plans.'.$action, $plan->plan_id), [
                'attempt_reference' => (string) Str::uuid(), 'plan_version' => $plan->version,
                'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
                'reason' => 'Agent requested '.$action.'.', 'customer_explanation' => 'The original plan remains.',
            ])->assertStatus($status);
        if ($status === 302) {
            $response->assertRedirect(route($failure === 'mfa_setup_required' ? 'two-factor.enrolment' : 'login'));
        }
    }
    if ($status === 302 && $failure !== 'mfa_setup_required') {
        $this->assertGuest();
    }
    expect(httpPlanOwnerRows())->toEqual($baseline);
})->with([
    'operationally inactive' => ['inactive', 403],
    'invited onboarding' => ['invited', 302],
    'MFA onboarding' => ['mfa_setup_required', 302],
    'unconfirmed MFA' => ['unconfirmed_mfa', 404],
    'suspended' => ['suspended', 302],
    'deactivated' => ['deactivated', 302],
    'revoked existing session' => ['revoked_session', 302],
    'pending recovery' => ['recovery_pending', 302],
]);

test('a temporary authentication lock preserves legitimate current Agent plan work without granting assignment eligibility', function (): void {
    $this->freezeTime();
    [, $customer, $agent] = $this->createLifecycleFixture();
    $actor = $agent->user;
    $confirmed = httpPlanConfirmed($customer, $actor, httpPlanData($customer, httpPlanRule($actor)));
    $this->actingAs($actor)->withSession(['auth.lifecycle_access_version' => $actor->lifecycle_access_version])
        ->get(route('customers.plans.create', $customer->customer_id))->assertOk();
    $actor->lockTemporarily(30, 'password', 'Test authentication-path throttle.');
    $this->post(route('customers.plans.store', $customer->customer_id), $confirmed)->assertRedirect();
    $plan = ThriftPlan::firstOrFail();
    $slots = $plan->slots()->orderBy('id')->get()->map->getAttributes()->all();
    $originalTerms = $plan->currentTermsRevision()->getAttributes();
    $revision = [...$confirmed, 'attempt_reference' => (string) Str::uuid(), 'name' => 'Reviewed daily plan',
        'plan_version' => $plan->version, 'terms_revision' => 1,
        'reason' => 'Customer agreed to the clearer name.', 'customer_explanation' => 'Daily amounts and dates remain unchanged.'];
    $revision['preview_fingerprint'] = app(ThriftPlanService::class)->previewRevision($actor, $plan, $revision)['preview_fingerprint'];
    $this->get(route('plans.edit', ['plan' => $plan->plan_id, 'preview' => 1, ...httpPlanPreviewData($revision)]))
        ->assertInertia(fn (Assert $page) => $page->where('preview.terms.name', 'Reviewed daily plan'));
    $this->patch(route('plans.update', $plan->plan_id), $revision)->assertRedirect();
    foreach (['pause', 'resume', 'cancel'] as $action) {
        $plan->refresh();
        $this->post(route('plans.'.$action, $plan->plan_id), [
            'attempt_reference' => (string) Str::uuid(), 'plan_version' => $plan->version,
            'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
            'reason' => 'Customer agreed to '.$action.'.', 'customer_explanation' => 'Original dated terms remain.',
        ])->assertRedirect();
    }
    expect($actor->fresh()->account_state)->toBe(AccountState::Active)
        ->and($actor->fresh()->isTemporarilyLocked('password'))->toBeTrue()
        ->and(app(AgentEligibilityService::class)->canReceiveAssignment($actor->fresh()))->toBeFalse()
        ->and($plan->fresh()->status->value)->toBe('cancelled')
        ->and($plan->fresh()->currentTermsRevision()->name)->toBe('Reviewed daily plan')
        ->and($plan->termsRevisions()->where('revision', 1)->firstOrFail()->getAttributes())->toBe($originalTerms)
        ->and($plan->slots()->orderBy('id')->get()->map->getAttributes()->all())->toBe($slots)
        ->and(DB::table('collection_receipts')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0)
        ->and(DB::table('fee_obligations')->count())->toBe(0)
        ->and(DB::table('withdrawal_reservations')->count())->toBe(0);
});

test('calendar schedules preserve local dates through calendar boundaries and later configuration publication', function (string $timezone, string $clock, array $dates, int $boundarySeconds): void {
    $this->travelTo(CarbonImmutable::parse($clock, $timezone));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    BusinessProfile::current()->update(['timezone' => $timezone]);
    httpPlanEnableCertifiedCreation($admin);
    $data = [...httpPlanData($customer, httpPlanRule($agent->user)), 'start_date' => $dates[0], 'contribution_days' => 4];
    $preview = app(ThriftPlanService::class)->preview($agent->user, $customer, $data);
    expect(array_column($preview['slots'], 'due_date'))->toBe($dates)
        ->and($preview['business']['timezone'])->toBe($timezone)
        ->and($preview['terms']['scheduled_end_date'])->toBe($dates[3]);
    $midnights = array_map(fn (string $date): int => CarbonImmutable::parse($date, $timezone)->timestamp, $dates);
    expect($midnights[2] - $midnights[1])->toBe($boundarySeconds);

    $this->actingAs($agent->user)->withHeader('X-Timezone', 'Pacific/Honolulu')
        ->get(route('customers.plans.create', ['customer' => $customer->customer_id, 'preview' => 1, ...httpPlanPreviewData($data)]))
        ->assertInertia(fn (Assert $page) => $page->where('business.timezone', $timezone)
            ->where('preview.slots', $preview['slots']));
    $this->post(route('customers.plans.store', $customer->customer_id), httpPlanConfirmed($customer, $agent->user, $data))->assertRedirect();
    $plan = ThriftPlan::firstOrFail();
    $slots = $plan->slots()->orderBy('ordinal')->get(['id', 'due_date', 'expected_amount_kobo'])->toArray();
    expect(array_column($slots, 'due_date'))->toBe($dates)
        ->and(array_column($slots, 'expected_amount_kobo'))->toBe([200000, 200000, 200000, 200000])
        ->and(array_unique(array_column($slots, 'id')))->toHaveCount(4)
        ->and($plan->currentTermsRevision()->timezone)->toBe($timezone)
        ->and($plan->currentTermsRevision()->business_version)->toBe($data['business_version']);
    httpPlanPublishConfiguration($admin, ['display_name' => 'Updated business identity']);
    expect($plan->slots()->orderBy('ordinal')->get(['id', 'due_date', 'expected_amount_kobo'])->toArray())->toBe($slots)
        ->and($plan->fresh()->currentTermsRevision()->business_version)->toBe($data['business_version'])
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
    $this->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->where('plan.current_terms.timezone', $timezone)
        ->where('plan.current_terms.scheduled_end_date', $dates[3]));
})->with([
    'leap day' => ['Africa/Lagos', '2028-02-26 12:00', ['2028-02-27', '2028-02-28', '2028-02-29', '2028-03-01'], 86400],
    'year boundary' => ['Africa/Lagos', '2027-12-29 12:00', ['2027-12-30', '2027-12-31', '2028-01-01', '2028-01-02'], 86400],
    'spring clock change' => ['America/New_York', '2027-03-12 12:00', ['2027-03-13', '2027-03-14', '2027-03-15', '2027-03-16'], 82800],
    'fall clock change' => ['America/New_York', '2027-11-05 12:00', ['2027-11-06', '2027-11-07', '2027-11-08', '2027-11-09'], 90000],
]);

test('published configuration invalidates a plan confirmation until the Agent reviews a fresh preview', function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 12:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    httpPlanEnableCertifiedCreation($admin);
    $data = httpPlanData($customer, httpPlanRule($agent->user));
    $oldConfirmation = httpPlanConfirmed($customer, $agent->user, $data);
    httpPlanPublishConfiguration($admin, ['display_name' => 'Reviewed new business identity']);
    $this->actingAs($agent->user)->postJson(route('customers.plans.store', $customer->customer_id), $oldConfirmation)->assertConflict();
    assertNoHttpPlanEffects();
    $newData = [...$data, 'business_version' => BusinessProfile::current()->version];
    $this->postJson(route('customers.plans.store', $customer->customer_id), [...$oldConfirmation, 'business_version' => $newData['business_version']])->assertConflict();
    assertNoHttpPlanEffects();
    $newConfirmation = [...httpPlanConfirmed($customer, $agent->user, $newData), 'attempt_reference' => $oldConfirmation['attempt_reference']];
    $this->post(route('customers.plans.store', $customer->customer_id), $newConfirmation)->assertRedirect();
    $this->post(route('customers.plans.store', $customer->customer_id), $newConfirmation)->assertRedirect();
    expect(ThriftPlan::count())->toBe(1)
        ->and(ThriftPlan::firstOrFail()->currentTermsRevision()->business_version)->toBe($newData['business_version'])
        ->and(PlanOperationAttempt::count())->toBe(1)
        ->and(ContributionSlot::count())->toBe(3)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
});

test('plan preview and creation reject unsupported modes and server-managed input', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    $data = httpPlanData($customer, httpPlanRule($agent->user));
    $confirmed = httpPlanConfirmed($customer, $agent->user, $data);
    $this->actingAs($agent->user);

    foreach (['frequency' => 'weekly', 'currency' => 'USD', 'interest_rate' => '5', 'owners' => [1, 2], 'bulk' => true, 'scheduled_end_date' => '2027-01-01'] as $field => $value) {
        $this->getJson(route('customers.plans.create', [
            'customer' => $customer->customer_id, 'preview' => 1, ...httpPlanPreviewData($data), $field => $value,
        ]))->assertUnprocessable()->assertInvalid([$field]);
        $this->postJson(route('customers.plans.store', $customer->customer_id), [
            ...$confirmed, 'attempt_reference' => (string) Str::uuid(), $field => $value,
        ])->assertUnprocessable()->assertInvalid([$field]);
    }
    $this->postJson(route('customers.plans.store', $customer->customer_id), [
        ...$confirmed, 'customer_profile_id' => $customer->id + 1,
    ])->assertUnprocessable()->assertInvalid(['customer_profile_id']);

    assertNoHttpPlanEffects();
});

test('revision and transition reject unsupported fields without mutating a plan', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $rule = $plan->currentTermsRevision()->feeSnapshot->feeRule;
    $data = [
        ...httpPlanData($customer, $rule), 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => str_repeat('a', 64), 'plan_version' => $plan->version,
        'terms_revision' => 1, 'reason' => 'Customer requested a change.',
        'customer_explanation' => 'The plan changed.',
    ];
    $this->actingAs($agent->user);

    $this->getJson(route('plans.edit', ['plan' => $plan->plan_id, 'preview' => 1, ...httpPlanPreviewData($data), 'interest_rate' => '5']))
        ->assertUnprocessable()->assertInvalid(['interest_rate']);
    $this->patchJson(route('plans.update', $plan->plan_id), [...$data, 'frequency' => 'weekly'])
        ->assertUnprocessable()->assertInvalid(['frequency']);
    $this->postJson(route('plans.pause', $plan->plan_id), [
        'attempt_reference' => (string) Str::uuid(), 'plan_version' => $plan->version,
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'reason' => 'Pause requested.', 'customer_explanation' => 'Your plan is paused.',
        'amount_ngn' => '1.00',
    ])->assertUnprocessable()->assertInvalid(['amount_ngn']);

    expect($plan->fresh()->status->value)->toBe('active')
        ->and($plan->termsRevisions()->count())->toBe(1)
        ->and($plan->slots()->count())->toBe(2)
        ->and(DB::table('fee_snapshots')->where('source_type', 'plan')->count())->toBe(1)
        ->and($plan->lifecycleEvents()->count())->toBe(0)
        ->and(PlanOperationAttempt::count())->toBe(0);
});

test('Customers and Admins cannot write plans through direct endpoints', function (): void {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $admin->givePermissionTo(AdminPermission::cases());
    config()->set('collections.settlement_enabled', true);
    $confirmed = httpPlanConfirmed($customer, $agent->user, httpPlanData($customer, httpPlanRule($agent->user)));

    foreach ([$admin, $customer->user] as $actor) {
        $this->actingAs($actor)->get(route('customers.plans.create', $customer->customer_id))->assertForbidden();
        $this->postJson(route('customers.plans.store', $customer->customer_id), $confirmed)->assertForbidden();
    }
    assertNoHttpPlanEffects();

    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $revision = [
        ...$confirmed, 'plan_version' => $plan->version, 'terms_revision' => 1,
        'reason' => 'Unauthorized change.', 'customer_explanation' => 'No change.',
    ];
    $transition = [
        'attempt_reference' => (string) Str::uuid(), 'plan_version' => $plan->version,
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'reason' => 'Unauthorized pause.', 'customer_explanation' => 'No change.',
    ];
    $ownerRows = httpPlanOwnerRows();
    foreach ([$admin, $customer->user] as $actor) {
        $this->actingAs($actor)->get(route('plans.edit', $plan->plan_id))->assertForbidden();
        $this->patchJson(route('plans.update', $plan->plan_id), $revision)->assertForbidden();
        foreach (['pause', 'resume', 'cancel'] as $action) {
            $this->postJson(route('plans.'.$action, $plan->plan_id), $transition)->assertForbidden();
        }
        $this->getJson(route('plans.settlement', $plan->plan_id))->assertForbidden();
        $this->postJson(route('plans.settlement.confirm', ['plan' => $plan->plan_id, 'action' => 'close']), [
            'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => str_repeat('a', 64),
            'reason' => 'Unauthorized closure.', 'customer_explanation' => 'No change.', 'confirmed' => true,
        ])->assertForbidden();
    }
    expect(httpPlanOwnerRows())->toEqual($ownerRows);
    expect($plan->fresh()->status->value)->toBe('active')
        ->and($plan->termsRevisions()->count())->toBe(1)
        ->and(PlanOperationAttempt::count())->toBe(0);
});

test('unsupported lifecycle state actions reject assigned Agents and Customer calls without owner mutations', function (string $state, string $action): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $plan->update(['status' => $state, 'open_customer_profile_id' => in_array($state, ['active', 'paused', 'completed'], true) ? $customer->id : null]);
    $data = ['attempt_reference' => (string) Str::uuid(), 'plan_version' => $plan->version,
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'reason' => 'Requested unsupported action.', 'customer_explanation' => 'Original plan remains unchanged.'];
    $baseline = httpPlanOwnerRows();
    $this->actingAs($agent->user)->postJson(route('plans.'.$action, $plan->plan_id), $data)->assertConflict();
    $this->actingAs($customer->user)->postJson(route('plans.'.$action, $plan->plan_id), $data)->assertForbidden();
    expect(httpPlanOwnerRows())->toEqual($baseline);
})->with([
    'active cannot resume' => ['active', 'resume'],
    'paused cannot pause' => ['paused', 'pause'],
    'completed cannot pause' => ['completed', 'pause'],
    'completed cannot resume' => ['completed', 'resume'],
    'completed cannot cancel' => ['completed', 'cancel'],
    'closed cannot pause' => ['closed', 'pause'],
    'closed cannot resume' => ['closed', 'resume'],
    'closed cannot cancel' => ['closed', 'cancel'],
    'cancelled cannot pause' => ['cancelled', 'pause'],
    'cancelled cannot resume' => ['cancelled', 'resume'],
    'cancelled cannot cancel' => ['cancelled', 'cancel'],
]);

test('assigned Agent HTTP pause resume and unused cancellation retain agreed terms without financial effects', function (bool $cancelWhilePaused): void {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $admin->givePermissionTo(AdminPermission::cases());
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $slots = $plan->slots()->orderBy('id')->get()->map->getAttributes()->all();
    $terms = $plan->currentTermsRevision()->getAttributes();
    $this->actingAs($agent->user);
    $actions = $cancelWhilePaused ? ['pause', 'resume', 'pause', 'cancel'] : ['pause', 'resume', 'cancel'];
    foreach ($actions as $action) {
        $plan->refresh();
        $data = ['attempt_reference' => (string) Str::uuid(), 'plan_version' => $plan->version,
            'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
            'reason' => 'Customer agreed to '.$action.'.', 'customer_explanation' => 'Your original dated terms remain.'];
        $beforeDenials = httpPlanOwnerRows();
        foreach ([$admin, $customer->user] as $actor) {
            $this->actingAs($actor)->postJson(route('plans.'.$action, $plan->plan_id), $data)->assertForbidden();
        }
        expect(httpPlanOwnerRows())->toEqual($beforeDenials);
        $this->actingAs($agent->user);
        $this->post(route('plans.'.$action, $plan->plan_id), $data)->assertRedirect(route('plans.show', $plan->plan_id));
        $baseline = httpPlanOwnerRows();
        $this->post(route('plans.'.$action, $plan->plan_id), $data)->assertRedirect();
        expect(httpPlanOwnerRows())->toEqual($baseline);
    }
    expect($plan->fresh()->status->value)->toBe('cancelled')
        ->and($plan->fresh()->open_customer_profile_id)->toBeNull()
        ->and($plan->lifecycleEvents()->pluck('event_type')->all())->toBe($actions)
        ->and($plan->slots()->orderBy('id')->get()->map->getAttributes()->all())->toBe($slots)
        ->and($plan->fresh()->currentTermsRevision()->getAttributes())->toBe($terms)
        ->and(DB::table('collection_receipts')->count())->toBe(0)
        ->and(DB::table('fee_obligations')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0)
        ->and(DB::table('withdrawal_requests')->count())->toBe(0)
        ->and(DB::table('withdrawal_reservations')->count())->toBe(0);
})->with(['cancel Active' => [false], 'cancel Paused' => [true]]);

test('TPC-AC-051: unsupported lifecycle authority stays unavailable without creating Admin permissions', function (): void {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $admin->givePermissionTo(AdminPermission::cases());
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $baseline = httpPlanOwnerRows();
    $permissions = DB::table('permissions')->orderBy('id')->get()->all();
    $directGrants = DB::table('model_has_permissions')->orderBy('permission_id')->orderBy('model_type')->orderBy('model_id')->get()->all();
    $roleGrants = DB::table('role_has_permissions')->orderBy('permission_id')->orderBy('role_id')->get()->all();
    foreach ([$agent->user, $customer->user, $admin] as $actor) {
        $this->actingAs($actor);
        foreach (['complete', 'reopen', 'extend', 'restore', 'terminate'] as $action) {
            $this->postJson(route('plans.show', $plan->plan_id).'/'.$action, [
                'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
            ])->assertNotFound();
        }
        $this->postJson(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'reopen']), [
            'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => str_repeat('0', 64),
            'reason' => 'Unsupported reopening request.', 'customer_explanation' => 'No reopening authority is available.',
            'confirmed' => true,
        ])->assertNotFound();
    }
    expect(httpPlanOwnerRows())->toEqual($baseline);
    expect(DB::table('permissions')->orderBy('id')->get()->all())->toEqual($permissions)
        ->and(DB::table('model_has_permissions')->orderBy('permission_id')->orderBy('model_type')->orderBy('model_id')->get()->all())->toEqual($directGrants)
        ->and(DB::table('role_has_permissions')->orderBy('permission_id')->orderBy('role_id')->get()->all())->toEqual($roleGrants);
});

test('plan preview validates text, money and day boundaries while preserving Unicode terms', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    $data = httpPlanData($customer, httpPlanRule($agent->user));
    $this->actingAs($agent->user);

    foreach ([
        ['name', '   '], ['name', str_repeat('A', 101)],
        ['amount_ngn', '0.99'], ['amount_ngn', '2.001'], ['amount_ngn', '10000000.01'],
        ['contribution_days', 0], ['contribution_days', 1.5], ['contribution_days', 367],
        ['customer_visible_notes', str_repeat('N', 2001)],
    ] as [$field, $value]) {
        $this->getJson(route('customers.plans.create', [
            'customer' => $customer->customer_id, 'preview' => 1, ...httpPlanPreviewData($data), $field => $value,
        ]))->assertUnprocessable()->assertInvalid([$field]);
    }
    $valid = [...$data, 'name' => 'Ọjọ́ savings', 'amount_ngn' => '1.00', 'contribution_days' => 366];
    $this->get(route('customers.plans.create', [
        'customer' => $customer->customer_id, 'preview' => 1, ...httpPlanPreviewData($data),
        'name' => $valid['name'], 'amount_ngn' => $valid['amount_ngn'], 'contribution_days' => $valid['contribution_days'],
    ]))->assertInertia(fn (Assert $page) => $page->component('plans/Create')
        ->where('preview.terms.name', 'Ọjọ́ savings')
        ->where('preview.terms.contribution_amount_kobo', 100)
        ->has('preview.slots', 366)
        ->where('preview.terms.customer_visible_notes', 'Agreed at the branch.')
        ->where('preview.fee.basis', 'none')
        ->where('preview.fee.timing', 'first_contribution'));
    $this->get(route('customers.plans.create', [
        'customer' => $customer->customer_id, 'preview' => 1, ...httpPlanPreviewData($data),
        'amount_ngn' => '10000000.00', 'contribution_days' => 1,
    ]))->assertInertia(fn (Assert $page) => $page->where('preview.terms.expected_gross_kobo', 1_000_000_000));

    assertNoHttpPlanEffects();
    $this->post(route('customers.plans.store', $customer->customer_id), httpPlanConfirmed($customer, $agent->user, $valid))
        ->assertRedirect();
    expect(ThriftPlan::first()->currentTermsRevision()->name)->toBe('Ọjọ́ savings')
        ->and(ContributionSlot::count())->toBe(366)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
});

test('invalid starts fail and a future plan occupies open-cycle capacity', function (): void {
    $this->travelTo(now('Africa/Lagos')->setDate(2027, 2, 10)->startOfDay());
    [, $customer, $agent] = $this->createLifecycleFixture();
    $data = httpPlanData($customer, httpPlanRule($agent->user));
    $this->actingAs($agent->user);

    foreach (['2027-02-09', '2028-02-11'] as $date) {
        $this->getJson(route('customers.plans.create', [
            'customer' => $customer->customer_id, 'preview' => 1, ...httpPlanPreviewData($data), 'start_date' => $date,
        ]))->assertUnprocessable()->assertInvalid(['start_date']);
    }
    $this->getJson(route('customers.plans.create', [
        'customer' => $customer->customer_id, 'preview' => 1, ...httpPlanPreviewData($data), 'scheduled_end_date' => '2027-03-01',
    ]))->assertUnprocessable()->assertInvalid(['scheduled_end_date']);
    assertNoHttpPlanEffects();

    $future = [...$data, 'start_date' => '2028-02-10'];
    $this->post(route('customers.plans.store', $customer->customer_id), httpPlanConfirmed($customer, $agent->user, $future))
        ->assertRedirect();
    expect(ThriftPlan::count())->toBe(1)
        ->and(ThriftPlan::first()->open_customer_profile_id)->toBe($customer->id)
        ->and(ContributionSlot::query()->orderBy('ordinal')->value('due_date'))->toBe('2028-02-10')
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
    $this->getJson(route('customers.plans.create', [
        'customer' => $customer->customer_id, 'preview' => 1, ...httpPlanPreviewData($data),
    ]))->assertUnprocessable()->assertInvalid(['customer']);
});

test('fee and business timezone must be authoritative before plan confirmation', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    $rule = httpPlanRule($agent->user);
    $data = httpPlanData($customer, $rule);
    $confirmed = httpPlanConfirmed($customer, $agent->user, $data);
    $this->actingAs($agent->user);

    $this->getJson(route('customers.plans.create', [
        'customer' => $customer->customer_id, 'preview' => 1, ...httpPlanPreviewData($data), 'fee_rule_id' => '',
    ]))->assertUnprocessable()->assertInvalid(['fee_rule_id']);
    $missingFee = $confirmed;
    unset($missingFee['fee_rule_id']);
    $this->postJson(route('customers.plans.store', $customer->customer_id), $missingFee)
        ->assertUnprocessable()->assertInvalid(['fee_rule_id']);
    $registrationRuleId = DB::table('fee_snapshots')->where('source_type', 'registration')->value('fee_rule_id');
    $this->getJson(route('customers.plans.create', [
        'customer' => $customer->customer_id, 'preview' => 1, ...httpPlanPreviewData($data), 'fee_rule_id' => $registrationRuleId,
    ]))->assertUnprocessable()->assertInvalid(['fee_rule_id']);
    $rule->update(['retired_at' => now()]);
    $this->getJson(route('customers.plans.create', [
        'customer' => $customer->customer_id, 'preview' => 1, ...httpPlanPreviewData($data),
    ]))->assertUnprocessable()->assertInvalid(['fee_rule_id']);
    $this->postJson(route('customers.plans.store', $customer->customer_id), $confirmed)->assertConflict();
    DB::table('business_profiles')->update(['timezone' => 'Invalid/Zone']);
    $this->getJson(route('customers.plans.create', [
        'customer' => $customer->customer_id, 'preview' => 1, ...httpPlanPreviewData($data),
    ]))->assertUnprocessable()->assertInvalid(['timezone']);
    $this->postJson(route('customers.plans.store', $customer->customer_id), $confirmed)
        ->assertUnprocessable()->assertInvalid(['timezone']);

    assertNoHttpPlanEffects();
});

test('TPC-AC-015: complete reviewed terms require Agent attestation before one matching cycle is created', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [, $customer, $agent] = $this->createLifecycleFixture();
    $rule = FeeRule::create(['version' => 1, 'name' => 'One contractual day agreed once', 'kind' => 'plan', 'rule_key' => 'confirmation-terms',
        'model' => 'one_day', 'timing' => 'first_contribution', 'basis' => 'contractual_daily_contribution',
        'settlement_source' => 'external_receipt', 'currency' => 'NGN', 'amount_kobo' => 0,
        'customer_description' => 'One agreed daily contribution, payable separately once.', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $agent->user_id, 'publication_reason' => 'Reviewed complete confirmation terms.']);
    $data = [...httpPlanData($customer, $rule), 'name' => 'Market savings agreement', 'amount_ngn' => '2000.01',
        'customer_visible_notes' => 'Customer agreed the dated three contribution cycle.'];
    $confirmed = httpPlanConfirmed($customer, $agent->user, $data);
    $baseline = httpPlanOwnerRows();
    $this->actingAs($agent->user)->get(route('customers.plans.create', [
        'customer' => $customer->customer_id, 'preview' => 1, ...httpPlanPreviewData($data),
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('preview.terms.name', 'Market savings agreement')
        ->where('preview.terms.customer_visible_notes', 'Customer agreed the dated three contribution cycle.')
        ->where('preview.terms.contribution_amount_kobo', 200001)->where('preview.terms.contribution_days', 3)
        ->where('preview.terms.start_date', '2026-10-05')->where('preview.terms.scheduled_end_date', '2026-10-07')
        ->where('preview.terms.currency', 'NGN')->where('preview.terms.expected_gross_kobo', 600003)
        ->where('preview.business.timezone', 'Africa/Lagos')->where('preview.business.version', $data['business_version'])
        ->where('preview.fee.rule_id', $rule->id)->where('preview.fee.rule_version', 1)
        ->where('preview.fee.name', 'One contractual day agreed once')->where('preview.fee.model', 'one_day')
        ->where('preview.fee.basis', 'contractual_daily_contribution')->where('preview.fee.timing', 'first_contribution')
        ->where('preview.fee.amount_kobo', 200001)
        ->where('preview.fee.customer_description', 'One agreed daily contribution, payable separately once.')
        ->where('preview.slots.0.due_date', '2026-10-05')->where('preview.slots.1.due_date', '2026-10-06')
        ->where('preview.slots.2.due_date', '2026-10-07')->has('preview.slots', 3)
        ->where('preview.preview_fingerprint', $confirmed['preview_fingerprint']));
    expect(httpPlanOwnerRows())->toEqual($baseline);
    $missing = $confirmed;
    unset($missing['customer_agreement_attested']);
    foreach ([$missing, [...$confirmed, 'customer_agreement_attested' => false]] as $unconfirmed) {
        $this->postJson(route('customers.plans.store', $customer->customer_id), $unconfirmed)
            ->assertUnprocessable()->assertInvalid(['customer_agreement_attested']);
        expect(httpPlanOwnerRows())->toEqual($baseline);
    }
    $this->postJson(route('customers.plans.store', $customer->customer_id), [
        ...$confirmed, 'customer_profile_id' => $customer->id + 1,
    ])->assertUnprocessable()->assertInvalid(['customer_profile_id']);
    $otherCustomer = CustomerProfile::factory()->create();
    $this->postJson(route('customers.plans.store', $otherCustomer->customer_id), $confirmed)->assertNotFound();
    expect(httpPlanOwnerRows())->toEqual($baseline);
    assertNoHttpPlanEffects();
    $this->post(route('customers.plans.store', $customer->customer_id), $confirmed)->assertRedirect()->assertSessionHasNoErrors();
    $plan = ThriftPlan::query()->sole();
    $terms = $plan->currentTermsRevision();
    expect($terms->name)->toBe('Market savings agreement')
        ->and($terms->customer_visible_notes)->toBe('Customer agreed the dated three contribution cycle.')
        ->and($terms->contribution_amount_kobo)->toBe(200001)->and($terms->contribution_days)->toBe(3)
        ->and($terms->start_date)->toBe('2026-10-05')->and($terms->currency)->toBe('NGN')
        ->and($terms->timezone)->toBe('Africa/Lagos')->and($terms->expected_gross_kobo)->toBe(600003)
        ->and($terms->attested_by_user_id)->toBe($agent->user_id)->and($terms->attested_at)->not->toBeNull();
    $snapshot = $terms->feeSnapshot;
    expect($snapshot->fee_rule_id)->toBe($rule->id)->and($snapshot->fee_rule_version)->toBe(1)
        ->and($snapshot->model->value)->toBe('one_day')->and($snapshot->timing->value)->toBe('first_contribution')
        ->and($snapshot->basis->value)->toBe('contractual_daily_contribution')->and($snapshot->basis_amount_kobo)->toBe(200001)
        ->and($snapshot->amount_kobo)->toBe(200001)->and($snapshot->currency)->toBe('NGN');
    expect($plan->slots()->orderBy('ordinal')->pluck('due_date')->all())->toBe(['2026-10-05', '2026-10-06', '2026-10-07']);
    expect($plan->lifecycleEvents()->where('event_type', 'created')->sole()->payload['agreement_attested'])->toBeTrue();
    $this->assertDatabaseCount('plan_operation_attempts', 1);
    $this->assertDatabaseCount('collection_receipts', 0);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
});

test('repeated plan names retain scoped counts pagination state filters and historical identities', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plans = [];
    foreach (range(1, 26) as $ordinal) {
        $plan = $this->createLifecyclePlan($customer, $agent->user);
        if ($ordinal > 1) {
            $plan->update(['predecessor_plan_id' => $plans[$ordinal - 2]->id]);
        }
        if ($ordinal < 26) {
            $plan->update(['status' => 'cancelled', 'open_customer_profile_id' => null]);
        }
        $plans[] = $plan;
    }
    [, $otherCustomer, $otherAgent] = $this->createLifecycleFixture();
    $foreign = $this->createLifecyclePlan($otherCustomer, $otherAgent->user);

    foreach ([$agent->user, $customer->user] as $viewer) {
        $this->actingAs($viewer)->get(route('plans.index', ['search' => 'Daily plan', 'status' => 'all', 'per_page' => 25]))
            ->assertInertia(fn (Assert $page) => $page->component('plans/Index')->has('plans.data', 25)
                ->where('plans.total', 26)->where('plans.last_page', 2)->where('plans.data.0.id', $plans[25]->plan_id)
                ->where('plans.data.24.id', $plans[1]->plan_id));
        $this->get(route('plans.index', ['search' => 'Daily plan', 'status' => 'all', 'page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('plans.data', 1)->where('plans.total', 26)
                ->where('plans.data.0.id', $plans[0]->plan_id)->where('filters.search', 'Daily plan')
                ->where('directory_context', ['search' => 'Daily plan', 'status' => 'all', 'page' => 2, 'per_page' => 25]));
        $context = ['search' => 'Daily plan', 'status' => 'all', 'page' => 2, 'per_page' => 25,
            'start_from' => '2026-10-05', 'start_to' => '2026-10-05'];
        $this->get(route('plans.show', ['plan' => $plans[0]->plan_id, 'directory' => $context]))
            ->assertInertia(fn (Assert $page) => $page->where('plan.id', $plans[0]->plan_id)
                ->where('directory_context', $context));
        $this->get(route('plans.index', $context))->assertInertia(fn (Assert $page) => $page
            ->where('plans.current_page', 2)->where('plans.total', 26)->has('plans.data', 1)
            ->where('plans.data.0.id', $plans[0]->plan_id)->where('directory_context', $context));
        $this->get(route('plans.index', ['status' => 'cancelled']))->assertInertia(fn (Assert $page) => $page
            ->where('plans.total', 25)->where('plans.data.0.status', 'cancelled'));
        $this->get(route('plans.index', ['search' => $foreign->plan_id]))->assertInertia(fn (Assert $page) => $page
            ->where('plans.total', 0)->has('plans.data', 0));
        $this->get(route('plans.show', $foreign->plan_id))->assertNotFound();
        $this->get(route('plans.show', $plans[25]->plan_id))->assertInertia(fn (Assert $page) => $page
            ->where('plan.predecessor.id', $plans[24]->plan_id)->where('plan.predecessor.status', 'cancelled')
            ->where('directory_context', []));
    }
    $this->actingAs($customer->user)->get(route('plans.index', ['search' => $plans[25]->plan_id]))
        ->assertInertia(fn (Assert $page) => $page->where('plans.total', 1)->where('plans.data.0.agent', null));
    $this->actingAs($admin)->get(route('plans.index', ['status' => 'all']))->assertInertia(fn (Assert $page) => $page
        ->where('plans.total', 27));
    $this->assertDatabaseCount('collection_receipts', 0);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
});

test('plan detail directory context accepts only bounded internal filter values', function (array $context, string $error): void {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);

    $this->actingAs($customer->user)->getJson(route('plans.show', ['plan' => $plan->plan_id, 'directory' => $context]))
        ->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    'external return destination' => [['return_url' => 'https://example.test'], 'directory'],
    'oversized search' => [['search' => str_repeat('a', 101)], 'directory.search'],
    'unsupported lifecycle' => [['status' => 'unknown'], 'directory.status'],
    'unsupported page size' => [['per_page' => 1000], 'directory.per_page'],
    'nonpositive page' => [['page' => 0], 'directory.page'],
    'unbounded page' => [['page' => 1000001], 'directory.page'],
    'invalid date' => [['start_from' => 'tomorrow'], 'directory.start_from'],
    'inverted dates' => [['start_from' => '2026-10-05', 'start_to' => '2026-10-04'], 'directory.start_to'],
]);

test('plan detail directory context preserves Admin filters without granting foreign scope or Agent filtering', function (): void {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    [, $otherCustomer, $otherAgent] = $this->createLifecycleFixture();
    $foreign = $this->createLifecyclePlan($otherCustomer, $otherAgent->user);
    $context = ['agent' => $agent->agent_id, 'status' => 'all', 'per_page' => 50, 'page' => 1];

    $this->actingAs($admin)->get(route('plans.show', ['plan' => $plan->plan_id, 'directory' => $context]))
        ->assertInertia(fn (Assert $page) => $page->where('directory_context', $context));
    $this->get(route('plans.index', $context))->assertInertia(fn (Assert $page) => $page
        ->where('plans.total', 1)->where('plans.data.0.id', $plan->plan_id)->where('directory_context', $context));
    foreach ([$agent->user, $customer->user] as $viewer) {
        $this->actingAs($viewer)->get(route('plans.show', ['plan' => $plan->plan_id, 'directory' => $context]))->assertForbidden();
        $this->get(route('plans.show', ['plan' => $foreign->plan_id,
            'directory' => ['search' => $foreign->plan_id, 'status' => 'all']]))->assertNotFound();
    }
});

test('plan directory applies current Agent and start date filters while denying foreign filter authority', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $this->travel(2)->days();
    [, $otherCustomer, $otherAgent] = $this->createLifecycleFixture();
    $otherPlan = $this->createLifecyclePlan($otherCustomer, $otherAgent->user);

    $this->actingAs($admin)->get(route('plans.index', ['agent' => $agent->agent_id, 'start_from' => '2026-10-05', 'start_to' => '2026-10-05']))
        ->assertInertia(fn (Assert $page) => $page->where('plans.total', 1)->where('plans.data.0.id', $plan->plan_id)
            ->where('plans.data.0.agent.id', $agent->agent_id)->where('plans.data.0.customer.status', 'Active')
            ->where('plans.data.0.timezone', 'Africa/Lagos')->where('plans.data.0.currency', 'NGN')
            ->where('plans.data.0.fee.name', 'No plan fee')->where('filters.agent', $agent->agent_id)
            ->where('filters.start_from', '2026-10-05')->where('filters.start_to', '2026-10-05'));
    $this->get(route('plans.index', ['start_from' => '2026-10-06']))->assertInertia(fn (Assert $page) => $page
        ->where('plans.total', 1)->where('plans.data.0.id', $otherPlan->plan_id));
    $this->get(route('plans.index', ['start_to' => '2026-10-04']))->assertInertia(fn (Assert $page) => $page->where('plans.total', 0));
    $this->get(route('plans.index', ['agent' => 'AGENT-UNKNOWN']))->assertInertia(fn (Assert $page) => $page->where('plans.total', 0));
    $revision = ['name' => 'Revised daily plan', 'amount_ngn' => '2000.00', 'start_date' => '2026-10-09',
        'contribution_days' => 2, 'customer_visible_notes' => '', 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => 1,
        'plan_version' => $plan->version, 'terms_revision' => 1, 'customer_agreement_attested' => true,
        'fee_rule_id' => $plan->currentTermsRevision()->feeSnapshot->fee_rule_id, 'fee_rule_version' => 1,
        'reason' => 'Customer agreed to start later.', 'customer_explanation' => 'Your new dates are recorded.'];
    $service = app(ThriftPlanService::class);
    $revision['preview_fingerprint'] = $service->previewRevision($agent->user, $plan, $revision)['preview_fingerprint'];
    $service->revise($agent->user, $plan, (string) Str::uuid(), $revision);
    $this->get(route('plans.index', ['start_to' => '2026-10-05']))->assertInertia(fn (Assert $page) => $page->where('plans.total', 0));
    $this->get(route('plans.index', ['search' => $plan->plan_id, 'start_from' => '2026-10-09']))
        ->assertInertia(fn (Assert $page) => $page->where('plans.total', 1)->where('plans.data.0.start_date', '2026-10-09'));
    $this->get(route('plans.index', ['start_from' => '2026-10-07', 'start_to' => '2026-10-05']))
        ->assertSessionHasErrors(['start_to' => 'Choose an end date on or after the start date.']);
    $this->getJson(route('plans.index', ['start_from' => '2026-02-30']))->assertUnprocessable()->assertJsonValidationErrors('start_from');
    foreach ([$agent->user, $customer->user] as $viewer) {
        $this->actingAs($viewer)->get(route('plans.index', ['agent' => $otherAgent->agent_id]))->assertForbidden();
    }
    $this->assertDatabaseCount('ledger_posting_groups', 0);
});

test('manual cycle fee history prevents unused cancellation and financial amendment without releasing capacity', function (bool $paused, bool $waived): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    config()->set('fees.manual_charges_enabled', true);
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    FinancialPeriod::factory()->create(['month' => '2026-10-01']);
    $service = app(ThriftPlanService::class);
    $confirmed = httpPlanConfirmed($customer, $agent->user, httpPlanData($customer, httpPlanRule($agent->user)));
    $plan = $service->create($agent->user, $customer, $confirmed['attempt_reference'], $confirmed)['plan'];
    if ($paused) {
        $plan = $service->transition($agent->user, $plan, 'pause', (string) Str::uuid(), [
            'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
            'plan_version' => $plan->version, 'reason' => 'Customer requests a pause.', 'customer_explanation' => 'Original agreement retained.']);
    }
    $reviewedRevision = [...$confirmed, 'attempt_reference' => (string) Str::uuid(),
        'plan_version' => $plan->version, 'terms_revision' => 1, 'amount_ngn' => '3000.00',
        'reason' => 'Customer reviews changed terms before the service charge.', 'customer_explanation' => 'Reviewed new agreement.'];
    $reviewedRevision['preview_fingerprint'] = $service->previewRevision($agent->user, $plan, $reviewedRevision)['preview_fingerprint'];
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $this->actingAs($admin)->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp])
        ->post(route('admin.charges.publish'), ['publication_reference' => (string) Str::uuid(), 'category_key' => 'unused-cycle-fee',
            'kind' => 'manual_fee', 'purpose' => 'Approved additional service', 'customer_description' => 'Reviewed service fee',
            'amount_ngn' => '100.00', 'confirmed' => true])->assertRedirect();
    $category = ChargeCategoryVersion::query()->sole();
    $this->post(route('admin.charges.assess'), reviewManualCharge($this, ['operation_reference' => (string) Str::uuid(),
        'customer_id' => $customer->customer_id, 'plan_id' => $plan->plan_id, 'category_id' => $category->id,
        'customer_version' => $customer->version, 'plan_version' => $plan->version,
        'reason' => 'Customer agreed to the independent service.', 'confirmed' => true]))->assertRedirect();
    $obligation = FeeObligation::query()->sole();
    if ($waived) {
        $this->post(route('admin.fees.obligations.waive', $obligation), ['amount_ngn' => '100.00',
            'reason' => 'Independent approved waiver.', 'customer_description' => 'Your additional fee was waived.',
            'attempt_reference' => (string) Str::uuid()])->assertRedirect();
    }
    expect($obligation->fresh()->outstandingAmountKobo())->toBe($waived ? 0 : 10000);
    $plan->refresh();
    expect($plan->activity_started_at)->toBeNull()->and($plan->currentTermsRevision()->feeSnapshot->obligation)->toBeNull()
        ->and(DB::table('collection_receipts')->count())->toBe(0)
        ->and(DB::table('fee_obligations')->count())->toBe(1);
    $baseline = httpPlanOwnerRows();
    $charges = DB::table('manual_charges')->orderBy('id')->get()->all();
    $intents = DB::table('manual_charge_notification_intents')->orderBy('id')->get()->all();
    $data = ['attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'plan_version' => $plan->version,
        'reason' => 'Requested cancellation with an unpaid service fee.', 'customer_explanation' => 'Original plan retained.'];
    $this->actingAs($agent->user)->postJson(route('plans.cancel', $plan->plan_id), $data)->assertConflict();
    $this->patchJson(route('plans.update', $plan->plan_id), $reviewedRevision)->assertConflict();
    $this->postJson(route('plans.cancel', $plan->plan_id), $data)->assertConflict();
    $this->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page->where('actions.can_cancel', false));
    $this->get(route('plans.edit', $plan->plan_id))->assertInertia(fn (Assert $page) => $page->where('financial_terms_locked', true));
    expect(fn () => $service->previewRevision($agent->user, $plan, [...$confirmed,
        'plan_version' => $plan->version, 'terms_revision' => 1, 'amount_ngn' => '3000.00',
        'reason' => 'Requested changed financial terms.', 'customer_explanation' => 'Original terms retained.']))
        ->toThrow(ConflictHttpException::class, 'Financial and schedule terms are locked after activity.');
    expect($plan->fresh()->status->value)->toBe($paused ? 'paused' : 'active')
        ->and($plan->fresh()->open_customer_profile_id)->toBe($customer->id)
        ->and(httpPlanOwnerRows())->toEqual($baseline)
        ->and(DB::table('manual_charges')->orderBy('id')->get()->all())->toEqual($charges)
        ->and(DB::table('manual_charge_notification_intents')->orderBy('id')->get()->all())->toEqual($intents);
})->with(['Active unpaid' => [false, false], 'Paused unpaid' => [true, false], 'Active waived' => [false, true], 'Paused waived' => [true, true]]);

test('TPC-AC-003: Customer plan search history and notices never disclose another Customer', function (): void {
    $this->freezeTime();
    Queue::fake();
    [, $customer, $agent] = $this->createLifecycleFixture();
    [, $foreignCustomer, $foreignAgent] = $this->createLifecycleFixture();
    $rule = httpPlanRule($agent->user);
    $service = app(ThriftPlanService::class);
    $plans = [];
    $attempts = [];
    foreach ([[$customer, $agent, 'Own'], [$foreignCustomer, $foreignAgent, 'Foreign']] as [$subject, $custodian, $label]) {
        $confirmed = httpPlanConfirmed($subject, $custodian->user, [
            ...httpPlanData($subject, $rule), 'name' => $label.' original private plan',
        ]);
        $this->actingAs($custodian->user)->post(route('customers.plans.store', $subject->customer_id), $confirmed)
            ->assertRedirect()->assertSessionHasNoErrors();
        $plan = ThriftPlan::query()->where('customer_profile_id', $subject->id)->sole();
        $revision = [...$confirmed, 'name' => $label.' revised private plan',
            'plan_version' => $plan->version, 'terms_revision' => 1,
            'reason' => $label.' internal amendment reason', 'customer_explanation' => $label.' agreed amendment'];
        $revision['preview_fingerprint'] = $service->previewRevision($custodian->user, $plan, $revision)['preview_fingerprint'];
        $service->revise($custodian->user, $plan, (string) Str::uuid(), $revision);
        $plans[] = $plan->fresh();
        $attempts[] = $confirmed['attempt_reference'];
    }
    [$own, $foreign] = $plans;
    foreach (PlanNotificationIntent::query()->where('channel', 'database')->get() as $intent) {
        (new DeliverPlanNotificationIntent($intent->id))->handle(app(AgentEligibilityService::class));
    }
    Queue::assertPushed(DeliverPlanNotificationIntent::class);
    $baseline = httpPlanOwnerRows();
    $this->actingAs($customer->user);
    $this->get(route('plans.index'))->assertInertia(fn (Assert $page) => $page
        ->where('plans.total', 1)->has('plans.data', 1)->where('plans.data.0.id', $own->plan_id));
    foreach ([$foreign->plan_id, $foreignCustomer->customer_id, $foreignCustomer->user->name,
        'Foreign original private plan', 'Foreign revised private plan'] as $search) {
        $this->get(route('plans.index', ['search' => $search]))->assertInertia(fn (Assert $page) => $page
            ->where('plans.total', 0)->has('plans.data', 0));
    }
    $detail = $this->get(route('plans.show', ['plan' => $own->plan_id,
        'revision' => $foreign->currentTermsRevision()->id]))->assertInertia(fn (Assert $page) => $page
        ->where('plan.id', $own->plan_id)->has('plan.revisions', 2)
        ->where('plan.revisions.0.name', 'Own original private plan')
        ->where('plan.revisions.1.name', 'Own revised private plan')
        ->where('plan.revisions.0.reason', null)->where('plan.revisions.1.reason', null));
    expect(json_encode($detail->inertiaProps('plan'), JSON_THROW_ON_ERROR))->not->toContain($foreign->plan_id, 'Foreign');
    $this->get(route('plans.show', $foreign->plan_id))->assertNotFound();
    $this->getJson(route('plans.attempts.show', $attempts[1]))->assertNotFound();
    $inbox = $this->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
        ->has('inbox.items', 2)->where('inbox.unread_count', 2));
    $ownNoticeIds = PlanNotificationIntent::query()->where('recipient_user_id', $customer->user_id)
        ->where('channel', 'database')->pluck('notification_id')->all();
    expect(array_column($inbox->inertiaProps('inbox.items'), 'id'))->toEqualCanonicalizing($ownNoticeIds);
    expect(json_encode($inbox->inertiaProps('inbox'), JSON_THROW_ON_ERROR))->not->toContain($foreign->plan_id, 'Foreign');
    $this->getJson(route('notifications.sync'))->assertOk()->assertJsonPath('unread_count', 2);
    $foreignNotices = PlanNotificationIntent::query()->where('recipient_user_id', $foreignCustomer->user_id)
        ->where('channel', 'database')->get();
    expect($foreignNotices)->toHaveCount(2);
    foreach ($foreignNotices as $intent) {
        $this->get(route('notifications.show', $intent->notification_id))->assertNotFound();
        $this->get(route('notifications.open', $intent->notification_id))->assertNotFound();
        $this->patchJson(route('notifications.read', $intent->notification_id), ['read' => true, 'version' => 1])
            ->assertNotFound();
    }
    $notice = PlanNotificationIntent::query()->where('recipient_user_id', $customer->user_id)
        ->where('channel', 'database')->firstOrFail();
    $this->get(route('notifications.show', $notice->notification_id))->assertOk();
    expect(httpPlanOwnerRows())->toEqual($baseline);
});

test('TPC-AC-022: partial advance and catch-up funding preserve finite owner slots through exact completion', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    config()->set('collections.enabled', true);
    [, $customer, $agent] = $this->createLifecycleFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    $confirmed = httpPlanConfirmed($customer, $agent->user, [
        ...httpPlanData($customer, httpPlanRule($agent->user)),
        'contribution_days' => 5,
    ]);
    $this->actingAs($agent->user)->post(route('customers.plans.store', $customer->customer_id), $confirmed)
        ->assertRedirect()->assertSessionHasNoErrors();
    $plan = ThriftPlan::query()->where('customer_profile_id', $customer->id)->sole();
    $slots = $plan->slots()->orderBy('active_ordinal')->get();
    $originalSlots = $slots->map->getAttributes()->all();
    $this->travel(2)->days();
    $this->actingAs($agent->user)->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp]);
    $first = [...$this->lifecycleCollectionPayload($customer, $plan), 'savings_ngn' => '1000.00',
        'allocations' => [['slot_id' => $slots[4]->id, 'amount_ngn' => '1000.00']]];
    $first['preview_fingerprint'] = $this->postJson(route('customers.collections.preview', $customer->customer_id), $first)
        ->assertOk()->json('preview_fingerprint');
    $this->post(route('customers.collections.store', $customer->customer_id), $first)->assertRedirect();
    $card = app(CollectionReadService::class)->card($plan->fresh());
    expect($card['slots'][4]['status'])->toBe('partial');
    expect(DB::table('collection_allocations')->sole()->is_advance)->toBe(1);

    $catchUp = [...$this->lifecycleCollectionPayload($customer, $plan->fresh()), 'savings_ngn' => '4500.00'];
    $preview = $this->postJson(route('customers.collections.preview', $customer->customer_id), $catchUp)->assertOk()->json();
    expect(array_column($preview['allocations'], 'slot_id'))->toBe([$slots[0]->id, $slots[1]->id, $slots[2]->id]);
    expect(array_column($preview['allocations'], 'amount_kobo'))->toBe([200000, 200000, 50000]);
    $catchUp['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $catchUp)->assertRedirect();
    $card = app(CollectionReadService::class)->card($plan->fresh());
    expect(array_column($card['slots'], 'status'))->toBe(['paid', 'paid', 'partial', 'pending', 'partial']);
    $before = httpPlanOwnerRows();
    $excess = [...$this->lifecycleCollectionPayload($customer, $plan->fresh()), 'savings_ngn' => '4500.01'];
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $excess)
        ->assertUnprocessable()->assertJsonValidationErrors(['allocations']);
    $this->postJson(route('customers.collections.store', $customer->customer_id), [...$excess,
        'preview_fingerprint' => str_repeat('a', 64)])->assertUnprocessable()->assertJsonValidationErrors(['allocations']);
    expect(httpPlanOwnerRows())->toEqual($before);
    $final = [...$this->lifecycleCollectionPayload($customer, $plan->fresh()), 'savings_ngn' => '4500.00'];
    $final['preview_fingerprint'] = $this->postJson(route('customers.collections.preview', $customer->customer_id), $final)
        ->assertOk()->json('preview_fingerprint');
    $this->post(route('customers.collections.store', $customer->customer_id), $final)->assertRedirect();
    expect($plan->fresh()->status->value)->toBe('completed');
    expect($plan->slots()->orderBy('active_ordinal')->get()->map->getAttributes()->all())->toBe($originalSlots);
    expect(app(CollectionReadService::class)->card($plan->fresh())['paid_slots'])->toBe(5);
    expect(app(CollectionReadService::class)->position($customer))->toBe([
        'liability_kobo' => 1000000, 'reservations_kobo' => 0, 'available_kobo' => 1000000,
    ]);
    expect(DB::table('collection_receipts')->count())->toBe(3);
    expect(DB::table('contribution_slots')->count())->toBe(5);
});
