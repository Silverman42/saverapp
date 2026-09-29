<?php

use App\Models\BusinessProfile;
use App\Models\ContributionSlot;
use App\Models\CustomerProfile;
use App\Models\FeeRule;
use App\Models\PlanOperationAttempt;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\ThriftPlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\CreatesLifecycleCustomers;

uses(CreatesLifecycleCustomers::class);

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
        ->and(DB::table('fee_snapshots')->where('source_type', 'plan')->count())->toBe(0)
        ->and(PlanOperationAttempt::count())->toBe(0)
        ->and(DB::table('plan_notification_intents')->count())->toBe(0)
        ->and(DB::table('collection_receipts')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
}

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
    foreach ([$admin, $customer->user] as $actor) {
        $this->actingAs($actor)->get(route('plans.edit', $plan->plan_id))->assertForbidden();
        $this->patchJson(route('plans.update', $plan->plan_id), $revision)->assertForbidden();
        $this->postJson(route('plans.pause', $plan->plan_id), $transition)->assertForbidden();
    }
    expect($plan->fresh()->status->value)->toBe('active')
        ->and($plan->termsRevisions()->count())->toBe(1)
        ->and(PlanOperationAttempt::count())->toBe(0);
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

test('missing attestation and mismatched Customer identifiers cannot create a plan', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    $confirmed = httpPlanConfirmed($customer, $agent->user, httpPlanData($customer, httpPlanRule($agent->user)));
    $this->actingAs($agent->user);

    $this->postJson(route('customers.plans.store', $customer->customer_id), [
        ...$confirmed, 'customer_agreement_attested' => false,
    ])->assertUnprocessable()->assertInvalid(['customer_agreement_attested']);
    $this->postJson(route('customers.plans.store', $customer->customer_id), [
        ...$confirmed, 'customer_profile_id' => $customer->id + 1,
    ])->assertUnprocessable()->assertInvalid(['customer_profile_id']);
    $otherCustomer = CustomerProfile::factory()->create();
    $this->postJson(route('customers.plans.store', $otherCustomer->customer_id), $confirmed)->assertNotFound();

    assertNoHttpPlanEffects();
});
