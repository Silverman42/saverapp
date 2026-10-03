<?php

use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\FeeRule;
use App\Models\ThriftPlan;
use App\Services\ThriftPlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\CreatesLifecycleCustomers;

uses(CreatesLifecycleCustomers::class);

test('confirmed plan captures the early termination disclosure shown in its agreement preview', function (): void {
    $this->freezeTime();
    Queue::fake();
    [, $customer, $agent] = $this->createLifecycleFixture();
    $rule = disclosurePlanRule($agent->user->id);
    $data = disclosurePlanData($customer, $rule);
    $this->actingAs($agent->user);
    $preview = $this->get(route('customers.plans.create', ['customer' => $customer->customer_id, 'preview' => 1,
        ...disclosurePreviewData($data)]))->assertInertia(fn (Assert $page) => $page
        ->where('preview.fee.early_termination_policy_version', 1)
        ->where('preview.fee.early_termination_description', fn ($description): bool => is_string($description) && str_contains($description, '₦100.00') && str_contains($description, 'without prorating') && str_contains($description, 'Paid or waived amounts are not collected again')));
    $description = $preview->inertiaProps('preview.fee.early_termination_description');
    $this->assertDatabaseCount('thrift_plans', 0);

    $this->post(route('customers.plans.store', $customer->customer_id), [...$data,
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $preview->inertiaProps('preview.preview_fingerprint')])
        ->assertRedirect()->assertSessionHasNoErrors();

    $plan = ThriftPlan::query()->sole();
    $snapshot = $plan->currentTermsRevision()->feeSnapshot;
    expect($snapshot->early_termination_policy_version)->toBe(1)
        ->and($snapshot->early_termination_description)->toBe($description)
        ->and($snapshot->acknowledged_at)->not->toBeNull();
    $this->assertDatabaseCount('fee_obligations', 0);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
    $this->actingAs($customer->user)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->where('plan.fee.early_termination_description', $description));
});

test('descriptive revision preserves an undisclosed legacy agreement and a confirmed financial revision captures the new disclosure', function (): void {
    $this->freezeTime();
    Queue::fake();
    [, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $original = $plan->currentTermsRevision()->feeSnapshot;
    $data = [...disclosurePlanData($customer, $original->feeRule), 'name' => 'Clearer original plan',
        'plan_version' => $plan->version, 'terms_revision' => $plan->current_terms_revision,
        'reason' => 'Clarify the agreed name.', 'customer_explanation' => 'Your original financial agreement remains.'];
    $this->actingAs($agent->user);
    $preview = $this->get(route('plans.edit', ['plan' => $plan->plan_id, 'preview' => 1,
        ...disclosurePreviewData($data)]))->assertInertia(fn (Assert $page) => $page
        ->where('preview.financial_terms_changed', false)
        ->where('preview.fee.early_termination_policy_version', null)
        ->where('preview.fee.early_termination_description', null));

    $this->patch(route('plans.update', $plan->plan_id), [...$data, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $preview->inertiaProps('preview.preview_fingerprint')])->assertRedirect()->assertSessionHasNoErrors();

    $plan->refresh();
    expect($plan->currentTermsRevision()->fee_snapshot_id)->toBe($original->id)
        ->and($original->fresh()->early_termination_policy_version)->toBeNull()
        ->and($original->fresh()->early_termination_description)->toBeNull();
    $newRule = disclosurePlanRule($agent->user->id);
    $changed = [...$data, 'fee_rule_id' => $newRule->id, 'fee_rule_version' => $newRule->version,
        'plan_version' => $plan->version, 'terms_revision' => $plan->current_terms_revision,
        'customer_explanation' => 'You reviewed the new fee and early termination terms.'];
    $review = $this->get(route('plans.edit', ['plan' => $plan->plan_id, 'preview' => 1,
        ...disclosurePreviewData($changed)]))->assertInertia(fn (Assert $page) => $page
        ->where('preview.financial_terms_changed', true)->where('preview.fee.early_termination_policy_version', 1));
    $description = $review->inertiaProps('preview.fee.early_termination_description');
    $this->patch(route('plans.update', $plan->plan_id), [...$changed, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $review->inertiaProps('preview.preview_fingerprint')])->assertRedirect()->assertSessionHasNoErrors();
    $newSnapshot = $plan->fresh()->currentTermsRevision()->feeSnapshot;
    expect($newSnapshot->id)->not->toBe($original->id)
        ->and($newSnapshot->early_termination_policy_version)->toBe(1)
        ->and($newSnapshot->early_termination_description)->toBe($description)
        ->and($original->fresh()->early_termination_description)->toBeNull();
    $this->assertDatabaseCount('fee_obligations', 0);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
});

test('captured early termination policy fields cannot be rewritten', function (array $change): void {
    $this->freezeTime();
    Queue::fake();
    [, $customer, $agent] = $this->createLifecycleFixture();
    $data = disclosurePlanData($customer, disclosurePlanRule($agent->user->id));
    $service = app(ThriftPlanService::class);
    $data['preview_fingerprint'] = $service->preview($agent->user, $customer, $data)['preview_fingerprint'];
    $plan = $service->create($agent->user, $customer, (string) Str::uuid(), $data)['plan'];
    $snapshot = $plan->currentTermsRevision()->feeSnapshot;
    $before = DB::table('fee_snapshots')->where('id', $snapshot->id)->first();

    expect(fn () => $snapshot->update($change))->toThrow(RuntimeException::class, 'Fee snapshot terms are immutable');

    expect(DB::table('fee_snapshots')->where('id', $snapshot->id)->first())->toEqual($before);
})->with([
    'policy version' => [['early_termination_policy_version' => 2]],
    'disclosure wording' => [['early_termination_description' => 'A new penalty was never agreed.']],
]);

function disclosurePlanRule(int $actorId): FeeRule
{
    return FeeRule::create(['version' => ((int) FeeRule::query()->where('kind', 'plan')->max('version')) + 1, 'name' => 'Agreed completion fee', 'kind' => 'plan', 'rule_key' => 'disclosure-completion',
        'model' => 'fixed', 'timing' => 'cycle_completion', 'basis' => 'none', 'settlement_source' => 'external_receipt',
        'currency' => 'NGN', 'amount_kobo' => 10000, 'customer_description' => 'Completion fee of NGN 100.',
        'effective_at' => now()->subDay(), 'published_by_user_id' => $actorId, 'publication_reason' => 'Synthetic policy fixture.']);
}

/** @return array<string, mixed> */
function disclosurePlanData(CustomerProfile $customer, FeeRule $rule): array
{
    return ['name' => 'Daily plan', 'amount_ngn' => '2000.00', 'start_date' => now('Africa/Lagos')->toDateString(),
        'contribution_days' => 2, 'customer_visible_notes' => '', 'fee_rule_id' => $rule->id, 'fee_rule_version' => $rule->version,
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'business_version' => BusinessProfile::current()->version, 'customer_agreement_attested' => true];
}

/** @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function disclosurePreviewData(array $data): array
{
    return array_intersect_key($data, array_flip(['name', 'amount_ngn', 'start_date', 'contribution_days',
        'customer_visible_notes', 'fee_rule_id', 'reason', 'customer_explanation']));
}
