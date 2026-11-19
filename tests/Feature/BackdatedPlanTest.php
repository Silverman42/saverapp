<?php

use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\FeeRule;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\PlanNotificationIntent;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\CollectionReadService;
use App\Services\CollectionService;
use App\Services\CollectionWorkspaceService;
use App\Services\ThriftPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\CreatesLifecycleCustomers;

uses(CreatesLifecycleCustomers::class);

function backdatedPlanRule(User $agent): FeeRule
{
    return FeeRule::create([
        'version' => 1, 'name' => 'No plan fee', 'kind' => 'plan', 'rule_key' => 'daily',
        'model' => 'no_fee', 'timing' => 'first_contribution', 'basis' => 'none',
        'settlement_source' => 'external_receipt', 'currency' => 'NGN', 'amount_kobo' => 0,
        'customer_description' => 'No fee', 'effective_at' => now()->subYear(),
        'published_by_user_id' => $agent->id, 'publication_reason' => 'Fixture',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function backdatedPlanData(CustomerProfile $customer, FeeRule $rule, array $overrides = []): array
{
    return [
        'name' => 'Daily plan', 'amount_ngn' => '1000.00', 'start_date' => '2026-12-27', 'contribution_days' => 50,
        'customer_visible_notes' => null, 'fee_rule_id' => $rule->id, 'fee_rule_version' => $rule->version,
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'business_version' => BusinessProfile::current()->version, 'customer_agreement_attested' => true,
        'backdate_reason' => 'Customer has saved on a paper card since 27 December.',
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function backdatedPlanConfirmed(CustomerProfile $customer, User $agent, array $data): array
{
    return [...$data, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => app(ThriftPlanService::class)->preview($agent, $customer, $data)['preview_fingerprint']];
}

function openBackdatedPlanMonths(string ...$months): void
{
    foreach ($months as $month) {
        FinancialPeriod::factory()->create(['month' => $month]);
    }
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00', 'Africa/Lagos'));
});

test('an agent creates a plan that started 45 days ago with catch-up days and no money posted', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    openBackdatedPlanMonths('2026-12-01', '2027-01-01', '2027-02-01');
    $data = backdatedPlanData($customer, backdatedPlanRule($agent->user));
    $this->actingAs($agent->user);

    $this->get(route('customers.plans.create', ['customer' => $customer->customer_id, 'preview' => 1,
        ...array_intersect_key($data, array_flip(['name', 'amount_ngn', 'start_date', 'contribution_days', 'fee_rule_id', 'backdate_reason']))]))
        ->assertInertia(fn (Assert $page) => $page->component('plans/Create')
            ->where('preview.backdate.backdated', true)
            ->where('preview.backdate.pre_creation_slots', 45)
            ->where('preview.backdate.created_local_date', '2027-02-10')
            ->where('preview.backdate.formatted_pre_creation_total', '₦45,000.00'));

    $this->post(route('customers.plans.store', $customer->customer_id), backdatedPlanConfirmed($customer, $agent->user, $data))
        ->assertRedirect();

    $plan = ThriftPlan::query()->sole();
    $revision = $plan->currentTermsRevision();
    $card = app(CollectionReadService::class)->fundingCard($plan);
    $audit = AuditEvent::query()->where('event_type', 'thrift_plan.created')->sole();
    expect($revision->start_date)->toBe('2026-12-27')
        ->and($revision->created_local_date)->toBe('2027-02-10')
        ->and($revision->backdate_reason)->toBe('Customer has saved on a paper card since 27 December.')
        ->and($plan->status->value)->toBe('active')
        ->and(collect($card['slots'])->countBy('status')->all())->toBe(['catch-up' => 45, 'pending' => 5])
        ->and($card['slots'][44]['due_date'])->toBe('2027-02-09')
        ->and($audit->payload)->toMatchArray(['backdated' => true, 'created_local_date' => '2027-02-10', 'pre_creation_slots' => 45])
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0)
        ->and(PlanNotificationIntent::query()->where('channel', 'database')->get()->pluck('payload.message')->unique()->values()->all())
        ->toBe(['A daily thrift plan has been created for your account. It started on 27 Dec 2026; days before 10 Feb 2027 are catch-up days.']);
});

test('a past start date needs a reason and every month from the start to today open', function (string $field, array $openMonths, array $overrides, string $message): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    openBackdatedPlanMonths(...$openMonths);
    $data = backdatedPlanData($customer, backdatedPlanRule($agent->user), $overrides);

    $this->actingAs($agent->user)->getJson(route('customers.plans.create', ['customer' => $customer->customer_id, 'preview' => 1,
        ...array_intersect_key($data, array_flip(['name', 'amount_ngn', 'start_date', 'contribution_days', 'fee_rule_id', 'backdate_reason']))]))
        ->assertUnprocessable()->assertInvalid([$field => $message]);

    expect(ThriftPlan::count())->toBe(0);
})->with([
    'missing reason' => ['backdate_reason', ['2026-12-01', '2027-01-01', '2027-02-01'], ['backdate_reason' => ''],
        'Explain why this plan starts before today, in no more than 500 characters.'],
    'start month missing' => ['start_date', ['2027-01-01', '2027-02-01'], [],
        'December 2026 is not open for posting.'],
    'later month closed' => ['start_date', ['2026-12-01', '2027-02-01'], [],
        'January 2027 is not open for posting.'],
    'more than 365 days ahead' => ['start_date', [], ['start_date' => '2028-02-11', 'backdate_reason' => ''],
        'Start date must be within the next 365 calendar days.'],
]);

test('a month closing between preview and confirmation rejects the backdated plan with a conflict', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    openBackdatedPlanMonths('2026-12-01', '2027-01-01', '2027-02-01');
    $confirmed = backdatedPlanConfirmed($customer, $agent->user, backdatedPlanData($customer, backdatedPlanRule($agent->user)));
    FinancialPeriod::query()->whereDate('month', '2027-01-01')->update(['status' => 'closed']);

    $this->actingAs($agent->user)->postJson(route('customers.plans.store', $customer->customer_id), $confirmed)
        ->assertConflict();

    expect(ThriftPlan::count())->toBe(0);
});

test('a customer cannot create a backdated plan for themselves', function (): void {
    [, $customer] = $this->createLifecycleFixture();

    $this->actingAs($customer->user)->get(route('customers.plans.create', ['customer' => $customer->customer_id]))
        ->assertForbidden();
});

test('a late receipt for a backdated plan pre-creation day may be older than the lookback', function (): void {
    config()->set('collections.enabled', true);
    [, $customer, $agent] = $this->createLifecycleFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    openBackdatedPlanMonths('2026-12-01', '2027-01-01', '2027-02-01');
    $confirmed = backdatedPlanConfirmed($customer, $agent->user, backdatedPlanData($customer, backdatedPlanRule($agent->user)));
    $plan = app(ThriftPlanService::class)->create($agent->user, $customer, $confirmed['attempt_reference'], $confirmed)['plan'];
    $collections = app(CollectionService::class);
    $receipt = [...$this->lifecycleCollectionPayload($customer->fresh(), $plan), 'received_date' => '2027-01-01',
        'late_reason' => 'Paper card entry for 1 January.'];

    $receipt['preview_fingerprint'] = $collections->preview($agent->user, $customer->fresh(), $receipt)['preview_fingerprint'];
    $collections->record($agent->user, $customer->fresh(), $receipt);

    $card = app(CollectionReadService::class)->fundingCard($plan->fresh());
    $audit = AuditEvent::query()->where('event_type', 'collection.receipt_posted')->sole();
    expect(CollectionReceipt::query()->sole()->received_date)->toBe('2027-01-01')
        ->and($card['slots'][0]['status'])->toBe('paid')
        ->and($card['slots'][1]['status'])->toBe('catch-up')
        ->and($audit->payload['backdated_plan_lookback'])->toBeTrue();
});

test('a receipt older than the lookback outside a backdated plan pre-creation window is rejected', function (string $receivedDate, bool $backdated): void {
    config()->set('collections.enabled', true);
    [, $customer, $agent] = $this->createLifecycleFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    openBackdatedPlanMonths('2026-12-01', '2027-01-01', '2027-02-01');
    $plan = $backdated
        ? app(ThriftPlanService::class)->create($agent->user, $customer, ...(function () use ($customer, $agent): array {
            $confirmed = backdatedPlanConfirmed($customer, $agent->user, backdatedPlanData($customer, backdatedPlanRule($agent->user)));

            return [$confirmed['attempt_reference'], $confirmed];
        })())['plan']
        : $this->createLifecyclePlan($customer, $agent->user);
    $receipt = [...$this->lifecycleCollectionPayload($customer->fresh(), $plan), 'received_date' => $receivedDate,
        'late_reason' => 'Late entry.'];

    expect(fn () => app(CollectionService::class)->preview($agent->user, $customer->fresh(), $receipt))
        ->toThrow(ValidationException::class, 'Choose today or a received date within the configured late lookback.');
})->with([
    'plan that is not backdated' => ['2027-01-01', false],
    'before the plan start date' => ['2026-12-26', true],
]);

test('the workspace lists a past day of a backdated plan as catch-up and leaves it out of outstanding', function (): void {
    config()->set('collections.enabled', true);
    [, $customer, $agent] = $this->createLifecycleFixture();
    openBackdatedPlanMonths('2026-12-01', '2027-01-01', '2027-02-01');
    $confirmed = backdatedPlanConfirmed($customer, $agent->user, backdatedPlanData($customer, backdatedPlanRule($agent->user)));
    app(ThriftPlanService::class)->create($agent->user, $customer, $confirmed['attempt_reference'], $confirmed);

    $work = app(CollectionWorkspaceService::class)->dueWork($agent->user, '2027-01-05', '2027-02-10', '', 'all');

    expect(collect($work['slots']->items())->pluck('status')->all())->toBe(['catch-up'])
        ->and($work['totals']['outstanding_kobo'])->toBe(0);
});

test('a plan without activity whose start date has passed can still be renamed', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    $data = backdatedPlanData($customer, backdatedPlanRule($agent->user), ['start_date' => '2027-02-10', 'backdate_reason' => '']);
    $confirmed = backdatedPlanConfirmed($customer, $agent->user, $data);
    $service = app(ThriftPlanService::class);
    $plan = $service->create($agent->user, $customer, $confirmed['attempt_reference'], $confirmed)['plan'];
    $this->travel(3)->days();
    $revision = [...$data, 'name' => 'Renamed plan', 'plan_version' => $plan->version,
        'terms_revision' => $plan->current_terms_revision, 'reason' => 'Customer prefers a new name.',
        'customer_explanation' => 'Only the plan name changed.', 'customer_agreement_attested' => true];
    $revision['preview_fingerprint'] = $service->previewRevision($agent->user, $plan, $revision)['preview_fingerprint'];

    $revised = $service->revise($agent->user, $plan, (string) Str::uuid(), $revision);

    expect($revised->currentTermsRevision()->name)->toBe('Renamed plan')
        ->and($revised->currentTermsRevision()->start_date)->toBe('2027-02-10')
        ->and($revised->currentTermsRevision()->created_local_date)->toBe('2027-02-10');
});

test('a renewed page cannot start before the previous page closed', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    openBackdatedPlanMonths('2027-02-01');
    $rule = backdatedPlanRule($agent->user);
    $first = backdatedPlanConfirmed($customer, $agent->user, backdatedPlanData($customer, $rule, ['start_date' => '2027-02-10', 'backdate_reason' => '']));
    $plan = app(ThriftPlanService::class)->create($agent->user, $customer, $first['attempt_reference'], $first)['plan'];
    app(ThriftPlanService::class)->transition($agent->user, $plan, 'cancel', (string) Str::uuid(), [
        'customer_version' => $customer->fresh()->version, 'assignment_version' => $customer->currentAssignment->version,
        'plan_version' => $plan->version, 'reason' => 'Entered by mistake.', 'customer_explanation' => 'Plan cancelled.',
    ]);
    $customer = $customer->fresh();
    $renewal = backdatedPlanData($customer, $rule, ['start_date' => '2027-02-09', 'predecessor_plan_id' => $plan->plan_id,
        'customer_version' => $customer->version]);

    expect(fn () => app(ThriftPlanService::class)->preview($agent->user, $customer, $renewal))
        ->toThrow(ValidationException::class, 'A new page must start on or after 2027-02-10, the day the previous page closed.');

    $confirmed = backdatedPlanConfirmed($customer, $agent->user, [...$renewal, 'start_date' => '2027-02-10', 'backdate_reason' => '']);
    app(ThriftPlanService::class)->create($agent->user, $customer, $confirmed['attempt_reference'], $confirmed);
    expect(ThriftPlan::query()->where('predecessor_plan_id', $plan->id)->count())->toBe(1);
});
