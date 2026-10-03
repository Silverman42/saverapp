<?php

use App\Enums\AdminPermission;
use App\Enums\FeeAssessmentCorrectionDirection;
use App\Models\AgentProfile;
use App\Models\BusinessProfile;
use App\Models\ChargeCategoryVersion;
use App\Models\CollectionBatch;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeRule;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\ManualCharge;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\CollectionReadService;
use App\Services\CollectionService;
use App\Services\CustomerReassignmentService;
use App\Services\FeeConcessionPosition;
use App\Services\FeeObligationService;
use App\Services\LedgerPostingService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\PlanFeeHistoryReadService;
use App\Services\PlatformState;
use App\Services\ReversalService;
use App\Services\ThriftPlanService;
use App\Services\WithdrawalBalanceService;
use App\Support\PlatformBlocked;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\CreatesLifecycleCustomers;

require_once __DIR__.'/../ManualChargeFixtures.php';
require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../CashExecutionFixtures.php';

uses(CreatesLifecycleCustomers::class);

function feeHistoryPlan(User $admin, CustomerProfile $customer, User $agent, bool $funded = true, string $timing = 'first_contribution', bool $zeroFee = false, string $settlementSource = 'external_receipt'): ThriftPlan
{
    config()->set('collections.enabled', true);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $month = now('Africa/Lagos')->startOfMonth()->toDateString();
    if (! FinancialPeriod::query()->where('business_profile_id', BusinessProfile::current()->id)
        ->where('timezone', 'Africa/Lagos')->whereDate('month', $month)->exists()) {
        FinancialPeriod::factory()->create(['month' => $month]);
    }
    enableFixtureMethod();
    $rule = FeeRule::create(['version' => (int) FeeRule::query()->where('kind', 'plan')->max('version') + 1,
        'name' => 'Captured cycle fee', 'kind' => 'plan', 'rule_key' => 'daily', 'model' => $zeroFee ? 'no_fee' : 'fixed',
        'timing' => $timing, 'basis' => 'none', 'settlement_source' => $settlementSource, 'currency' => 'NGN',
        'amount_kobo' => $zeroFee ? 0 : 10000, 'customer_description' => 'Your agreed cycle fee.',
        'effective_at' => now()->subDay(), 'published_by_user_id' => $admin->id, 'publication_reason' => 'Isolated fee history fixture.']);
    $data = ['name' => 'Cycle with fee history', 'amount_ngn' => '1000.00', 'start_date' => now('Africa/Lagos')->toDateString(),
        'contribution_days' => 2, 'customer_visible_notes' => '', 'fee_rule_id' => $rule->id, 'fee_rule_version' => $rule->version,
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'business_version' => BusinessProfile::current()->version, 'customer_agreement_attested' => true];
    $owner = app(ThriftPlanService::class);
    $data['preview_fingerprint'] = $owner->preview($agent, $customer, $data)['preview_fingerprint'];
    $plan = $owner->create($agent, $customer, (string) Str::uuid(), $data)['plan'];
    if ($funded) {
        $payload = collectionPayload($customer, $customer->currentAssignment, $plan, now('Africa/Lagos')->toDateString(), '1000.00');
        $collections = app(CollectionService::class);
        $payload['preview_fingerprint'] = $collections->preview($agent, $customer, $payload)['preview_fingerprint'];
        $collections->record($agent, $customer, $payload);
    }

    return $plan->fresh();
}

function feeHistoryRequest(): Request
{
    $request = Request::create('/admin/fees', 'POST');
    $session = new Store('cycle-fee-review', new ArraySessionHandler(600));
    $session->put(cashSession());
    $request->setLaravelSession($session);

    return $request;
}

function feeHistoryRows(): array
{
    $rows = [];
    foreach (['fee_obligations', 'fee_obligation_entries', 'fee_snapshots', 'collection_receipts', 'ledger_posting_groups', 'ledger_entries', 'withdrawal_requests'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

function feeHistoryAppliedManualFee(object $test, User $admin, CustomerProfile $customer, ThriftPlan $plan): FeeObligation
{
    config()->set(['fees.manual_charges_enabled' => true, 'fees.savings_applications_enabled' => true]);
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $key = 'approved-service-'.$customer->id;
    $test->actingAs($admin)->withSession(cashSession())->post(route('admin.charges.publish'), [
        'publication_reference' => (string) Str::uuid(), 'category_key' => $key, 'kind' => 'manual_fee',
        'purpose' => 'Approved service category.', 'customer_description' => 'Agreed separate service fee.',
        'amount_ngn' => '100.01', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $category = ChargeCategoryVersion::query()->where('category_key', $key)->sole();
    $reference = (string) Str::uuid();
    $test->post(route('admin.charges.assess'), reviewManualCharge($test, ['operation_reference' => $reference,
        'customer_id' => $customer->customer_id, 'plan_id' => $plan->plan_id, 'category_id' => $category->id,
        'customer_version' => $customer->version, 'plan_version' => $plan->version,
        'reason' => 'PRIVATE separately approved cycle service.', 'confirmed' => true]))->assertRedirect()->assertSessionHasNoErrors();
    $charge = ManualCharge::query()->where('operation_reference', $reference)->sole();
    $fee = FeeObligation::query()->findOrFail($charge->fee_obligation_id);
    $data = ['plan_id' => $plan->plan_id, 'reason' => 'PRIVATE confirmed savings source.', 'customer_description' => 'Agreed service fee paid from cycle savings.'];
    $quote = $test->postJson(route('admin.fees.obligations.savings-preview', $fee), $data)->assertOk()->json();
    $test->postJson(route('admin.fees.obligations.apply-savings', $fee), [...$data,
        'attempt_reference' => (string) Str::uuid(), 'confirmed' => true, 'preview_fingerprint' => $quote['preview_fingerprint'],
        'quote_expires_at' => $quote['quote_expires_at']])->assertOk();

    return $fee->fresh();
}

test('a reviewed manual cycle fee savings application has verified safe history for each permitted viewer and rejects a damaged debit', function (bool $damage): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = feeHistoryPlan($admin, $customer, $agent->user, zeroFee: true);
    $fee = feeHistoryAppliedManualFee($this, $admin, $customer, $plan);
    $before = feeHistoryRows();
    $reader = app(PlanFeeHistoryReadService::class);

    foreach ([$admin, $agent->user, $customer->user] as $viewer) {
        $detail = $reader->read($viewer, $plan);
        $batch = $reader->readMany($viewer, [$plan])[$plan->plan_id];
        expect($detail['status'])->toBe('available');
        expect($detail['totals'])->toBe(['original_assessed' => '₦100.01', 'assessed' => '₦100.01',
            'settled' => '₦100.01', 'waived' => '₦0.00', 'outstanding' => '₦0.00']);
        expect($detail['history']['total'])->toBe(2);
        expect($detail['history']['data'][0])->toMatchArray(['type' => 'settlement', 'kind_label' => 'Separate manual fee',
            'description' => 'Agreed service fee paid from cycle savings.']);
        expect(json_encode($detail, JSON_THROW_ON_ERROR))->not->toContain('PRIVATE');
        expect($batch['totals'])->toBe($detail['totals'])->and($batch['source_version'])->toBe($detail['source_version']);
        $this->actingAs($viewer)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
            ->where('plan.fee_history.status', 'available')->where('plan.fee_history.totals.settled', '₦100.01')
            ->missing('plan.fee_history.history.data.0.reason')->missing('plan.fee_history.history.data.0.actor_user_id'));
    }
    expect($fee->outstandingAmountKobo())->toBe(0);
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_liability_kobo'])->toBe(89999);
    expect(feeHistoryRows())->toEqual($before);
    if (! $damage) {
        return;
    }
    $group = LedgerPostingGroup::query()->where('source_type', 'fee_savings_application')->sole();
    DB::table('ledger_entries')->where('ledger_posting_group_id', $group->id)->where('side', 'debit')->update(['amount_kobo' => 10000]);
    $before = feeHistoryRows();

    expect($reader->read($customer->user, $plan)['status'])->toBe('unavailable');
    expect($reader->readMany($customer->user, [$plan])[$plan->plan_id]['status'])->toBe('unavailable');
    expect(feeHistoryRows())->toEqual($before);
})->with(['valid original application' => false, 'damaged savings debit' => true]);

test('explicit savings application cycle histories retain bounded query growth and isolate one damaged application', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    $plans = [];
    $admin = null;
    for ($index = 0; $index < 8; $index++) {
        [$owner, $customer, $agent] = $this->createLifecycleFixture();
        $admin ??= $owner;
        $plan = feeHistoryPlan($owner, $customer, $agent->user, zeroFee: true);
        feeHistoryAppliedManualFee($this, $owner, $customer, $plan);
        $plans[] = $plan;
    }
    $reader = app(PlanFeeHistoryReadService::class);
    DB::enableQueryLog();
    $one = $reader->readMany($admin, [$plans[0]]);
    $singleQueries = count(DB::getQueryLog());
    DB::flushQueryLog();
    $many = $reader->readMany($admin, $plans);
    $batchQueries = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();
    expect($one[$plans[0]->plan_id]['status'])->toBe('available');
    expect($batchQueries)->toBeLessThanOrEqual($singleQueries + 2);
    foreach ($many as $summary) {
        expect($summary['status'])->toBe('available')->and($summary['totals']['settled'])->toBe('₦100.01');
    }
    $customerIds = array_map(fn (ThriftPlan $plan): int => $plan->customer_profile_id, $plans);
    $balances = app(CollectionReadService::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $balances->positions([$customerIds[0]]);
    $singleBalanceQueries = count(DB::getQueryLog());
    DB::flushQueryLog();
    $positions = $balances->positions($customerIds);
    $batchBalanceQueries = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();
    expect($batchBalanceQueries)->toBeLessThanOrEqual($singleBalanceQueries + 1);
    foreach ($positions as $position) {
        expect($position['available_kobo'])->toBe(89999);
    }
    $group = LedgerPostingGroup::query()->where('source_type', 'fee_savings_application')->where('thrift_plan_id', $plans[0]->id)->sole();
    DB::table('ledger_entries')->where('ledger_posting_group_id', $group->id)->where('side', 'debit')->update(['amount_kobo' => 10000]);
    $before = feeHistoryRows();
    $many = $reader->readMany($admin, $plans);
    expect($many[$plans[0]->plan_id]['status'])->toBe('unavailable');
    foreach (array_slice($plans, 1) as $plan) {
        expect($many[$plan->plan_id]['status'])->toBe('available')->and($many[$plan->plan_id]['totals']['settled'])->toBe('₦100.01');
    }
    $positions = $balances->positions($customerIds);
    expect($positions[$customerIds[0]])->toBeNull();
    foreach (array_slice($customerIds, 1) as $customerId) {
        expect($positions[$customerId]['available_kobo'])->toBe(89999);
    }
    expect(feeHistoryRows())->toEqual($before);
});

test('cycle fee history exposes actual owner amounts and safe entries for each permitted role', function (string $role): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture(50000);
    $plan = feeHistoryPlan($admin, $customer, $agent->user);
    $obligation = FeeObligation::query()->where('kind', 'plan')->sole();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    app(FeeObligationService::class)->waive($admin, $obligation->id, 2500, 'Private review must never appear in plan history.',
        'Twenty-five naira of your fee was waived.', (string) Str::uuid(), feeHistoryRequest());
    $receipt = collectionPayload($customer, $customer->currentAssignment, $plan, now('Africa/Lagos')->toDateString(), '0.00');
    $receipt['plan_id'] = '';
    $receipt['plan_version'] = null;
    $receipt['fees'] = [['obligation_id' => $obligation->id, 'amount_ngn' => '50.00']];
    $receipt['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $receipt)['preview_fingerprint'];
    app(CollectionService::class)->record($agent->user, $customer, $receipt);
    $before = feeHistoryRows();
    $viewer = match ($role) {
        'admin' => $admin, 'agent' => $agent->user, 'customer' => $customer->user
    };

    $this->actingAs($viewer)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->component('plans/Show')->where('plan.fee_history.status', 'available')
        ->where('plan.fee_history.totals', ['original_assessed' => '₦100.00', 'assessed' => '₦100.00',
            'settled' => '₦50.00', 'waived' => '₦25.00', 'outstanding' => '₦25.00'])
        ->where('plan.fee_history.as_of', '2026-10-05T11:00:00+00:00')
        ->has('plan.fee_history.history.data', 3)->where('plan.fee_history.history.data.0.type', 'settlement')
        ->where('plan.fee_history.history.data.1.description', 'Twenty-five naira of your fee was waived.')
        ->missing('plan.fee_history.history.data.1.reason')->missing('plan.fee_history.history.data.1.actor_user_id'));
    $reader = app(PlanFeeHistoryReadService::class);
    $detail = $reader->read($viewer, $plan);
    $batch = $reader->readMany($viewer, [$plan])[$plan->plan_id];
    expect($batch['totals'])->toBe($detail['totals'])->and($batch['source_version'])->toBe($detail['source_version']);
    expect($batch)->not->toHaveKey('obligation_ids')->not->toHaveKey('history');
    $this->get(route('plans.index', ['search' => $plan->plan_id]))->assertInertia(fn (Assert $page) => $page
        ->where('plans.total', 1)->where('plans.data.0.fee.amount', '₦100.00')
        ->where('plans.data.0.fee_actuals.totals', ['original_assessed' => '₦100.00', 'assessed' => '₦100.00',
            'settled' => '₦50.00', 'waived' => '₦25.00', 'outstanding' => '₦25.00'])
        ->missing('plans.data.0.fee_actuals.history')->missing('plans.data.0.fee_actuals.obligation_ids'));

    expect(feeHistoryRows())->toEqual($before);
})->with(['admin', 'agent', 'customer']);

test('fee entry pagination is stable across equal timestamps and never limits whole cycle amounts', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = feeHistoryPlan($admin, $customer, $agent->user);
    $obligation = FeeObligation::query()->sole();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    for ($index = 0; $index < 27; $index++) {
        app(FeeObligationService::class)->waive($admin, $obligation->id, 100, 'Private controlled concession.',
            'One naira of your fee was waived.', (string) Str::uuid(), feeHistoryRequest());
    }
    $ids = $obligation->entries()->orderByDesc('id')->reorder('id', 'desc')->pluck('id')->all();
    $before = feeHistoryRows();
    $reader = app(PlanFeeHistoryReadService::class);
    $first = $reader->read($agent->user, $plan, 1);
    $second = $reader->read($agent->user, $plan, 2);

    expect(array_column($first['history']['data'], 'id'))->toBe(array_slice($ids, 0, 25));
    expect(array_column($second['history']['data'], 'id'))->toBe(array_slice($ids, 25));
    expect($second['totals'])->toBe(['original_assessed' => '₦100.00', 'assessed' => '₦100.00', 'settled' => '₦0.00', 'waived' => '₦27.00', 'outstanding' => '₦73.00']);
    expect($second['source_version'])->toBe($first['source_version']);
    $this->actingAs($agent->user)->get(route('plans.show', ['plan' => $plan->plan_id, 'fee_page' => 2]))
        ->assertInertia(fn (Assert $page) => $page->where('plan.fee_history.history.current_page', 2)->where('plan.fee_history.history.total', 28)
            ->has('plan.fee_history.history.data', 3)->where('plan.fee_history.totals.waived', '₦27.00'));
    $this->get(route('plans.show', ['plan' => $plan->plan_id, 'fee_per_page' => 50]))
        ->assertInertia(fn (Assert $page) => $page->has('plan.fee_history.history.data', 28));
    expect(feeHistoryRows())->toEqual($before);
});

test('untriggered captured fees and explicit no fee agreements have a verified empty fee history', function (bool $funded, string $timing, bool $zeroFee): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = feeHistoryPlan($admin, $customer, $agent->user, $funded, $timing, $zeroFee);

    $this->actingAs($customer->user)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->where('plan.fee_history.status', 'available')->where('plan.fee_history.totals.outstanding', '₦0.00')
        ->where('plan.fee_history.history.total', 0)->has('plan.fee_history.history.data', 0));
})->with(['Before first contribution' => [false, 'first_contribution', false],
    'Before completion' => [true, 'cycle_completion', false], 'Explicit no fee after funding' => [true, 'first_contribution', true]]);

test('damaged cycle fee sources show unavailable without hiding independent agreement or changing history', function (string $damage): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = feeHistoryPlan($admin, $customer, $agent->user);
    $obligation = FeeObligation::query()->sole();
    if ($damage === 'missing obligation') {
        DB::table('fee_obligation_entries')->where('fee_obligation_id', $obligation->id)->delete();
        DB::table('fee_obligations')->where('id', $obligation->id)->delete();
    } else {
        match ($damage) {
            'missing assessment' => DB::table('fee_obligation_entries')->where('fee_obligation_id', $obligation->id)->delete(),
            'foreign obligation' => DB::table('fee_obligations')->where('id', $obligation->id)->update(['customer_profile_id' => CustomerProfile::factory()->create()->id]),
            'foreign source' => DB::table('fee_obligations')->where('id', $obligation->id)->update(['source_id' => 'PLN-FOREIGN-R1']),
            'fractional snapshot' => DB::table('fee_snapshots')->where('id', $obligation->fee_snapshot_id)->update(['amount_kobo' => 10000.5]),
            'unknown entry' => DB::table('fee_obligation_entries')->where('fee_obligation_id', $obligation->id)->update(['entry_type' => 'unrecognized_fee_effect']),
            default => throw new LogicException('Unknown damaged source.'),
        };
    }
    $before = feeHistoryRows();

    $this->actingAs($agent->user)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->where('plan.current_terms.name', 'Cycle with fee history')->where('plan.fee_history.status', 'unavailable')
        ->where('plan.fee_history.totals', null)->where('plan.fee_history.history', null));
    $batch = app(PlanFeeHistoryReadService::class)->readMany($agent->user, [$plan])[$plan->plan_id];
    expect($batch['status'])->toBe('unavailable')->and($batch['totals'])->toBeNull();
    expect(feeHistoryRows())->toEqual($before);
})->with(['missing assessment', 'missing obligation', 'foreign obligation', 'foreign source', 'fractional snapshot', 'unknown entry']);

test('stale plan versions do not return a current fee summary', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = feeHistoryPlan($admin, $customer, $agent->user);
    DB::table('thrift_plans')->where('id', $plan->id)->increment('version');
    $before = feeHistoryRows();

    $summary = app(PlanFeeHistoryReadService::class)->read($agent->user, $plan);

    expect($summary['status'])->toBe('unavailable')->and($summary['totals'])->toBeNull();
    expect(app(PlanFeeHistoryReadService::class)->readMany($agent->user, [$plan])[$plan->plan_id]['status'])->toBe('unavailable');
    expect(feeHistoryRows())->toEqual($before);
});

test('current reassignment removes former Agent fee history access and preserves permitted new Agent history', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = feeHistoryPlan($admin, $customer, $agent->user);
    $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $admin->givePermissionTo(AdminPermission::CustomersReassign);
    $owner = app(CustomerReassignmentService::class);
    $preview = $owner->preview($admin, $customer->fresh(), $replacement->id);
    $owner->execute($admin, $customer->fresh(), ['attempt_reference' => (string) Str::uuid(), 'version' => $preview['version'],
        'assignment_version' => $preview['assignment_version'], 'preview_token' => $preview['preview_token'],
        'target_agent_id' => $replacement->id, 'confirmed' => true, 'reason' => 'Reviewed transfer.', 'customer_explanation' => 'Your service contact changed.']);

    $this->actingAs($agent->user)->get(route('plans.show', $plan->plan_id))->assertNotFound();
    expect(fn () => app(PlanFeeHistoryReadService::class)->read($agent->user, $plan))->toThrow(NotFoundHttpException::class);
    expect(app(PlanFeeHistoryReadService::class)->readMany($agent->user, [$plan])[$plan->plan_id]['status'])->toBe('unavailable');
    $this->actingAs($replacement->user)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->where('plan.fee_history.status', 'available')->where('plan.fee_history.totals.outstanding', '₦100.00'));
});

test('actual withdrawal fee history retains the original payment and an independent savings concession', function (bool $fixed): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    config()->set('fees.refunds_enabled', true);
    [$admin, $customer, $plan, $withdrawal] = cashPaymentFixture(true, $fixed);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Verified full handoff.', 'confirmed' => true])->assertRedirect();
    $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    $fee = FeeObligation::query()->sole();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $this->actingAs($admin)->withSession(cashSession())->post(route('admin.fees.refunds.store', $fee), [
        'refund_reference' => (string) Str::uuid(), 'kind' => 'savings', 'amount_ngn' => '1.00',
        'reason' => 'Private independently approved concession.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $before = feeHistoryRows();

    $this->actingAs($customer->user)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->where('plan.fee_history.status', 'available')->where('plan.fee_history.totals.original_assessed', '₦6.00')
        ->where('plan.fee_history.totals.settled', '₦6.00')->where('plan.fee_history.totals.outstanding', '₦0.00')
        ->has('plan.fee_history.history.data', 3)->where('plan.fee_history.history.data.0.type', 'savings_refund')
        ->where('plan.fee_history.history.data.0.amount', '₦1.00')->missing('plan.fee_history.history.data.0.reason'));
    $batch = app(PlanFeeHistoryReadService::class)->readMany($customer->user, [$plan->fresh()])[$plan->plan_id];
    expect($batch['status'])->toBe('available')->and($batch['totals']['settled'])->toBe('₦6.00');
    expect(feeHistoryRows())->toEqual($before);
})->with(['Fixed withdrawal' => [true], 'Percentage withdrawal' => [false]]);

test('plan detail validates fee history page parameters before returning fee records', function (string $key, int $value): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = feeHistoryPlan($admin, $customer, $agent->user);
    $before = feeHistoryRows();

    $this->actingAs($agent->user)->getJson(route('plans.show', ['plan' => $plan->plan_id, $key => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($key);
    expect(feeHistoryRows())->toEqual($before);
})->with(['Invalid page' => ['fee_page', 0], 'Unsupported size' => ['fee_per_page', 26]]);

test('descriptive cycle revision preserves original fee entry history and owner assessment', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = feeHistoryPlan($admin, $customer, $agent->user);
    $terms = $plan->currentTermsRevision();
    $data = ['name' => 'Customer clarified cycle name', 'amount_ngn' => '1000.00', 'start_date' => $terms->start_date,
        'contribution_days' => 2, 'customer_visible_notes' => 'Agreed clearer description.', 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => BusinessProfile::current()->version,
        'plan_version' => $plan->version, 'terms_revision' => $plan->current_terms_revision, 'customer_agreement_attested' => true,
        'fee_rule_id' => $terms->feeSnapshot->fee_rule_id, 'fee_rule_version' => $terms->feeSnapshot->fee_rule_version,
        'reason' => 'Reviewed descriptive correction.', 'customer_explanation' => 'Your financial agreement remains unchanged.'];
    $before = feeHistoryRows();
    $owner = app(ThriftPlanService::class);
    $data['preview_fingerprint'] = $owner->previewRevision($agent->user, $plan, $data)['preview_fingerprint'];
    $plan = $owner->revise($agent->user, $plan, (string) Str::uuid(), $data);

    $this->actingAs($customer->user)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->where('plan.current_terms.name', 'Customer clarified cycle name')->where('plan.fee_history.status', 'available')
        ->where('plan.fee_history.totals.original_assessed', '₦100.00')->where('plan.fee_history.totals.outstanding', '₦100.00')
        ->where('plan.fee_history.history.total', 1));
    expect(app(PlanFeeHistoryReadService::class)->readMany($customer->user, [$plan])[$plan->plan_id]['totals']['outstanding'])->toBe('₦100.00');
    expect(feeHistoryRows())->toEqual($before);
});

test('a refund of an actual revision sourced cycle fee restores savings to its original cycle and retains projection dimensions', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    config()->set('fees.refunds_enabled', true);
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = feeHistoryPlan($admin, $customer, $agent->user, true, 'first_contribution', false, 'savings_application');
    $fee = FeeObligation::query()->where('customer_profile_id', $customer->id)->sole();
    expect($fee->feeSnapshot->source_type)->toBe('plan_terms_revision');
    expect($fee->settledAmountKobo())->toBe(10000);
    expect(app(LedgerPostingService::class)->planForObligation($fee))->toBe($plan->id);
    $terms = $plan->currentTermsRevision();
    $revision = ['name' => 'Clarified cycle name', 'amount_ngn' => '1000.00', 'start_date' => $terms->start_date,
        'contribution_days' => 2, 'customer_visible_notes' => 'Agreed clearer description.', 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => BusinessProfile::current()->version,
        'plan_version' => $plan->version, 'terms_revision' => $plan->current_terms_revision, 'customer_agreement_attested' => true,
        'fee_rule_id' => $terms->feeSnapshot->fee_rule_id, 'fee_rule_version' => $terms->feeSnapshot->fee_rule_version,
        'reason' => 'Reviewed descriptive correction.', 'customer_explanation' => 'Your financial agreement remains unchanged.'];
    $plans = app(ThriftPlanService::class);
    $revision['preview_fingerprint'] = $plans->previewRevision($agent->user, $plan, $revision)['preview_fingerprint'];
    $plan = $plans->revise($agent->user, $plan, (string) Str::uuid(), $revision);
    expect($plan->current_terms_revision)->toBe(2);
    expect(app(LedgerPostingService::class)->planForObligation($fee))->toBe($plan->id);
    $date = now('Africa/Lagos')->toDateString();
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $batch = CollectionBatch::query()->sole();
    $admin->givePermissionTo(AdminPermission::ReconciliationManage);
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $this->actingAs($admin)->withSession(feeHistoryRequest()->session()->all())
        ->post(route('collection-batches.remittances.store', $batch), ['batch_version' => $batch->version,
            'handoff_reference' => 'CYCLE-REFUND-BACKING', 'amount_ngn' => '1000.00', 'handoff_date' => $date,
            'receiving_location' => 'Business cash office', 'source_attestation' => 'Counted original contribution into business custody.',
            'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    $payload = ['refund_reference' => (string) Str::uuid(), 'kind' => 'savings', 'amount_ngn' => '50.00',
        'reason' => 'Approved partial cycle concession.', 'confirmed' => true];

    $this->withSession(feeHistoryRequest()->session()->all())->post(route('admin.fees.refunds.store', $fee), $payload)
        ->assertRedirect()->assertSessionHasNoErrors();

    $group = LedgerPostingGroup::query()->where('source_type', 'fee_refund')->sole();
    expect($group->thrift_plan_id)->toBe($plan->id);
    expect($group->entries()->where('side', 'credit')->sole()->thrift_plan_id)->toBe($plan->id);
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_liability_kobo'])->toBe(95000);
    expect(app(WithdrawalBalanceService::class)->positions([$plan])[$plan->id]['cycle_liability_kobo'])->toBe(95000);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $before = feeHistoryRows();
    $this->post(route('admin.fees.refunds.store', $fee), $payload)->assertRedirect()->assertSessionHasNoErrors();
    expect(feeHistoryRows())->toEqual($before);
});

test('cycle resolution rejects a damaged original revision fee source without changing financial history', function (string $table, string $column, mixed $value): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = feeHistoryPlan($admin, $customer, $agent->user);
    $fee = FeeObligation::query()->where('customer_profile_id', $customer->id)->sole();
    DB::table($table)->where($table === 'fee_snapshots' ? 'id' : 'thrift_plan_id',
        $table === 'fee_snapshots' ? $fee->fee_snapshot_id : $plan->id)->update([$column => $value]);
    $before = feeHistoryRows();

    expect(fn () => app(LedgerPostingService::class)->planForObligation($fee))->toThrow(ConflictHttpException::class);

    expect(feeHistoryRows())->toEqual($before);
})->with([
    'mismatched immutable source identity' => ['fee_snapshots', 'source_id', 'PLN-FOREIGN-R1'],
    'missing original revision identity' => ['plan_terms_revisions', 'revision', 2],
]);

test('assessment correction keeps original fee amount and safe correction description in cycle history', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = feeHistoryPlan($admin, $customer, $agent->user);
    $fee = FeeObligation::query()->sole();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    app(FeeObligationService::class)->correctUnsettledAssessment($admin, $fee->id, 4000,
        FeeAssessmentCorrectionDirection::Reduce, 'Private review of the original assessment.',
        'Forty naira was removed from your assessment.', (string) Str::uuid(), feeHistoryRequest());
    $before = feeHistoryRows();

    $this->actingAs($customer->user)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->where('plan.fee_history.status', 'available')->where('plan.fee_history.totals.original_assessed', '₦100.00')
        ->where('plan.fee_history.totals.assessed', '₦60.00')->where('plan.fee_history.totals.outstanding', '₦60.00')
        ->where('plan.fee_history.history.data.0.type', 'assessment_correction')
        ->where('plan.fee_history.history.data.0.description', 'Forty naira was removed from your assessment.')
        ->where('plan.fee_history.history.data.1.amount', '₦100.00')->missing('plan.fee_history.history.data.0.reason'));
    expect(feeHistoryRows())->toEqual($before);
});

test('cycle fee history includes separately authorized manual fees and verifies their original charge sources', function (string $damage): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    config()->set('fees.manual_charges_enabled', true);
    [$admin, $customer, $agent] = $this->createLifecycleFixture(50000);
    $plan = feeHistoryPlan($admin, $customer, $agent->user);
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $this->actingAs($admin)->withSession(cashSession())->post(route('admin.charges.publish'), [
        'publication_reference' => (string) Str::uuid(), 'category_key' => 'approved-cycle-service', 'kind' => 'manual_fee',
        'purpose' => 'Approved service category.', 'customer_description' => 'Agreed separate service fee.',
        'amount_ngn' => '100.01', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $category = ChargeCategoryVersion::query()->latest('id')->firstOrFail();
    $this->post(route('admin.charges.assess'), reviewManualCharge($this, ['operation_reference' => (string) Str::uuid(),
        'customer_id' => $customer->customer_id, 'plan_id' => $plan->plan_id, 'category_id' => $category->id,
        'customer_version' => $customer->version, 'plan_version' => $plan->version,
        'reason' => 'Private Customer agreement review.', 'confirmed' => true]))->assertRedirect()->assertSessionHasNoErrors();
    $manual = ManualCharge::query()->sole();
    if ($damage === 'missing fee binding') {
        DB::table('manual_charges')->where('id', $manual->id)->update(['fee_obligation_id' => null]);
    } elseif ($damage === 'wrong source') {
        DB::table('fee_obligations')->where('id', $manual->fee_obligation_id)->update(['source_id' => 'OTHER-MANUAL-SOURCE']);
    } elseif ($damage === 'wrong category') {
        DB::table('charge_category_versions')->where('id', $category->id)->update(['fee_rule_id' => $plan->currentTermsRevision()->feeSnapshot->fee_rule_id]);
    }
    $before = feeHistoryRows();

    $this->actingAs($customer->user)->get(route('plans.show', $plan->plan_id))->assertInertia(function (Assert $page) use ($damage): void {
        if ($damage === 'valid') {
            $page->where('plan.fee_history.status', 'available')->where('plan.fee_history.totals.assessed', '₦200.01')
                ->where('plan.fee_history.totals.outstanding', '₦200.01')->where('plan.fee_history.history.total', 2)
                ->where('plan.fee_history.history.data.0.kind_label', 'Separate manual fee')
                ->where('plan.fee_history.history.data.0.amount', '₦100.01')
                ->where('plan.fee_history.history.data.1.kind_label', 'Cycle agreement fee')
                ->missing('plan.fee_history.history.data.0.reason');
        } else {
            $page->where('plan.fee_history.status', 'unavailable')->where('plan.fee_history.totals', null)
                ->where('plan.fee_history.history', null);
        }
    });
    $batch = app(PlanFeeHistoryReadService::class)->readMany($customer->user, [$plan->fresh()])[$plan->plan_id];
    expect($batch['status'])->toBe($damage === 'valid' ? 'available' : 'unavailable');
    expect($batch['totals'])->toBe($damage === 'valid' ? ['original_assessed' => '₦200.01', 'assessed' => '₦200.01', 'settled' => '₦0.00', 'waived' => '₦0.00', 'outstanding' => '₦200.01'] : null);
    expect(feeHistoryRows())->toEqual($before);
})->with(['valid', 'missing fee binding', 'wrong source', 'wrong category']);

test('read only platform mode retains verified fee history while fee mutation remains blocked', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = feeHistoryPlan($admin, $customer, $agent->user);
    $fee = FeeObligation::query()->sole();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    app(PlatformState::class)->transition(['mode' => 'read_only', 'expected_version' => 1,
        'operation_id' => (string) Str::uuid(), 'operator' => 'isolated-test-operator',
        'reason' => 'Read-only incident exercise.', 'incident' => 'TEST-FEE-READ', 'expires_at' => null]);
    $before = feeHistoryRows();

    $this->actingAs($customer->user)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->where('plan.fee_history.status', 'available')->where('plan.fee_history.totals.outstanding', '₦100.00')
        ->where('plan.fee_history.history.total', 1));
    expect(app(PlanFeeHistoryReadService::class)->readMany($customer->user, [$plan])[$plan->plan_id]['totals']['outstanding'])->toBe('₦100.00');
    $this->actingAs($customer->user)->get(route('plans.index', ['search' => $plan->plan_id]))->assertInertia(fn (Assert $page) => $page
        ->where('plans.total', 1)->where('plans.data.0.savings_summary.customer.available', '₦1,000.00')
        ->where('plans.data.0.savings_summary.cycle.available', '₦1,000.00')->where('plans.data.0.posted_activity.status', 'available'));
    expect(fn () => app(FeeObligationService::class)->waive($admin, $fee->id, 100,
        'Private proposed concession.', 'Proposed concession.', (string) Str::uuid(), feeHistoryRequest()))
        ->toThrow(PlatformBlocked::class);
    expect(feeHistoryRows())->toEqual($before);
});

test('directory fee batches keep equal-time scoped pagination and constant query growth for settled cycles', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    $plans = [];
    $admin = null;
    for ($index = 0; $index < 26; $index++) {
        [$owner, $customer, $agent] = $this->createLifecycleFixture();
        $admin ??= $owner;
        $plan = feeHistoryPlan($owner, $customer, $agent->user);
        $fee = FeeObligation::query()->where('kind', 'plan')->where('customer_profile_id', $customer->id)->sole();
        $payload = collectionPayload($customer, $customer->currentAssignment, $plan, now('Africa/Lagos')->toDateString(), '0.00');
        $payload['plan_id'] = '';
        $payload['plan_version'] = null;
        $payload['fees'] = [['obligation_id' => $fee->id, 'amount_ngn' => '10.00']];
        $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $payload)['preview_fingerprint'];
        app(CollectionService::class)->record($agent->user, $customer, $payload);
        $plans[] = $plan;
    }
    $before = feeHistoryRows();
    $reader = app(PlanFeeHistoryReadService::class);
    DB::enableQueryLog();
    $one = $reader->readMany($admin, [$plans[0]]);
    $singleQueries = count(DB::getQueryLog());
    DB::flushQueryLog();
    $many = $reader->readMany($admin, $plans);
    $batchQueries = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    expect($one[$plans[0]->plan_id]['status'])->toBe('available');
    expect($batchQueries)->toBeLessThanOrEqual($singleQueries + 2);
    foreach ($many as $summary) {
        expect($summary['status'])->toBe('available')->and($summary['totals']['settled'])->toBe('₦10.00')
            ->and($summary['totals']['outstanding'])->toBe('₦90.00');
        expect($summary)->not->toHaveKey('obligation_ids')->not->toHaveKey('history');
    }
    $this->actingAs($admin)->get(route('plans.index'))->assertInertia(fn (Assert $page) => $page
        ->where('plans.total', 26)->has('plans.data', 25)->where('plans.data.0.id', $plans[25]->plan_id)
        ->where('plans.data.0.fee_actuals.totals.settled', '₦10.00')->where('plans.data.0.fee_actuals.totals.outstanding', '₦90.00'));
    $this->get(route('plans.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page
        ->where('plans.total', 26)->has('plans.data', 1)->where('plans.data.0.id', $plans[0]->plan_id)
        ->where('plans.data.0.fee_actuals.totals.settled', '₦10.00'));
    expect(feeHistoryRows())->toEqual($before);
});

test('one damaged cycle source does not replace another cycle fee summary with zero or unavailable', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $firstCustomer, $firstAgent] = $this->createLifecycleFixture();
    $first = feeHistoryPlan($admin, $firstCustomer, $firstAgent->user);
    [, $secondCustomer, $secondAgent] = $this->createLifecycleFixture();
    $second = feeHistoryPlan($admin, $secondCustomer, $secondAgent->user);
    $damaged = FeeObligation::query()->where('kind', 'plan')->where('customer_profile_id', $firstCustomer->id)->sole();
    DB::table('fee_obligation_entries')->where('fee_obligation_id', $damaged->id)->update(['entry_type' => 'unknown_fee_effect']);
    $before = feeHistoryRows();

    $batch = app(PlanFeeHistoryReadService::class)->readMany($admin, [$first, $second]);

    expect($batch[$first->plan_id]['status'])->toBe('unavailable')->and($batch[$first->plan_id]['totals'])->toBeNull();
    expect($batch[$second->plan_id]['status'])->toBe('available')->and($batch[$second->plan_id]['totals']['outstanding'])->toBe('₦100.00');
    $this->actingAs($secondAgent->user)->get(route('plans.index', ['search' => $first->plan_id]))
        ->assertInertia(fn (Assert $page) => $page->where('plans.total', 0)->has('plans.data', 0));
    $foreign = app(PlanFeeHistoryReadService::class)->readMany($firstCustomer->user, [$second]);
    expect($foreign[$second->plan_id]['status'])->toBe('unavailable')->and($foreign[$second->plan_id]['totals'])->toBeNull();
    expect(feeHistoryRows())->toEqual($before);
});

test('cycle fee summary batches reject oversized and duplicate identity requests before querying', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = feeHistoryPlan($admin, $customer, $agent->user);
    $reader = app(PlanFeeHistoryReadService::class);
    $before = feeHistoryRows();
    DB::enableQueryLog();

    expect($reader->readMany($agent->user, []))->toBe([]);
    expect(fn () => $reader->readMany($agent->user, [$plan, $plan]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $reader->readMany($agent->user, array_fill(0, 101, $plan)))->toThrow(InvalidArgumentException::class);
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
    DB::flushQueryLog();
    expect(feeHistoryRows())->toEqual($before);
});

test('reviewed manual fee payment compensation preserves scoped unpaid fee history and rejects damaged restoration proof', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = feeHistoryPlan($admin, $customer, $agent->user, zeroFee: true);
    $fee = feeHistoryAppliedManualFee($this, $admin, $customer, $plan);
    config()->set('fees.savings_application_corrections_enabled', true);
    $original = LedgerPostingGroup::query()->where('source_type', 'fee_savings_application')->sole();
    $service = app(ReversalService::class);
    $preview = $service->preview($agent->user, $original);
    $reversal = $service->submit($agent->user, $original, ['attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $preview['preview_fingerprint'], 'customer_version' => $customer->fresh()->version,
        'assignment_version' => $customer->currentAssignment->version, 'reason_category' => 'incorrect_fee_deduction',
        'internal_reason' => 'PRIVATE erroneous payment evidence.', 'customer_explanation' => 'Savings restored; the valid service fee remains unpaid.',
        'evidence_text' => 'PRIVATE original savings payment verified.', 'confirmed' => true]);
    $reviewer = User::factory()->admin()->withTwoFactor()->create();
    $reviewer->givePermissionTo(AdminPermission::ReversalsReview);
    $preview = $service->reviewPreview($reviewer, $reversal);
    $this->actingAs($reviewer)->withSession(cashSession())->post(route('reversals.approve', $reversal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $reversal->version,
        'preview_fingerprint' => $preview['preview_fingerprint'], 'decision_reason' => 'Verified erroneous payment.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $reader = app(PlanFeeHistoryReadService::class);
    foreach ([$admin, $agent->user, $customer->user] as $viewer) {
        $detail = $reader->read($viewer, $plan);
        expect($detail['status'])->toBe('available');
        expect($detail['totals'])->toMatchArray(['assessed' => '₦100.01', 'settled' => '₦0.00', 'outstanding' => '₦100.01']);
        expect($reader->readMany($viewer, [$plan])[$plan->plan_id]['totals'])->toEqual($detail['totals']);
        expect(json_encode($detail, JSON_THROW_ON_ERROR))->not->toContain('PRIVATE');
    }
    expect(app(FeeConcessionPosition::class)->retainedSources($fee->fresh()))->toBe(['savings_kobo' => 0, 'external_kobo' => 0]);
    DB::table('fee_obligation_entries')->where('source_type', 'reversal_request')->update(['amount_kobo' => 10000]);
    expect($reader->read($customer->user, $plan)['status'])->toBe('unavailable');
    expect($reader->readMany($customer->user, [$plan])[$plan->plan_id]['status'])->toBe('unavailable');
});
