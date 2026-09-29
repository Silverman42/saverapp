<?php

use App\Models\ContributionSlot;
use App\Models\FeeRule;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\PlanOperationAttempt;
use App\Models\ThriftPlan;
use App\Services\CollectionService;
use App\Services\ThriftPlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\CreatesLifecycleCustomers;

uses(CreatesLifecycleCustomers::class);

test('plan creation captures slots once and a repeated operation returns the original plan', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    $rule = FeeRule::create([
        'version' => 1, 'name' => 'No plan fee', 'kind' => 'plan', 'rule_key' => 'daily',
        'model' => 'no_fee', 'timing' => 'first_contribution', 'basis' => 'none',
        'settlement_source' => 'external_receipt', 'currency' => 'NGN', 'amount_kobo' => 0,
        'customer_description' => 'No fee', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $agent->user_id, 'publication_reason' => 'Fixture',
    ]);
    $data = [
        'name' => 'Daily plan', 'amount_ngn' => '2000.00',
        'start_date' => now('Africa/Lagos')->toDateString(), 'contribution_days' => 3,
        'customer_visible_notes' => '', 'fee_rule_id' => $rule->id,
        'fee_rule_version' => $rule->version, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version,
        'business_version' => 1, 'customer_agreement_attested' => true,
    ];
    $service = app(ThriftPlanService::class);
    $preview = $service->preview($agent->user, $customer, $data);
    $data['preview_fingerprint'] = $preview['preview_fingerprint'];
    $reference = (string) Str::uuid();

    $first = $service->create($agent->user, $customer, $reference, $data);
    $replay = $service->create($agent->user, $customer, $reference, $data);
    expect(fn () => $service->create($agent->user, $customer, $reference, [...$data, 'name' => 'Changed plan']))
        ->toThrow(ConflictHttpException::class);

    expect($first['replayed'])->toBeFalse()
        ->and($replay['replayed'])->toBeTrue()
        ->and($replay['plan']->id)->toBe($first['plan']->id)
        ->and(ThriftPlan::count())->toBe(1)
        ->and(ContributionSlot::where('thrift_plan_id', $first['plan']->id)->count())->toBe(3)
        ->and(PlanOperationAttempt::where('attempt_reference', $reference)->count())->toBe(1)
        ->and(DB::table('collection_receipts')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
});

test('plan lifecycle rejects a stale version without changing its state', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $data = ['customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version,
        'plan_version' => $plan->version, 'reason' => 'Customer requested a pause.',
        'customer_explanation' => 'Your plan is paused.'];
    $service = app(ThriftPlanService::class);

    $paused = $service->transition($agent->user, $plan, 'pause', (string) Str::uuid(), $data);
    expect($paused->status->value)->toBe('paused');
    expect(fn () => $service->transition($agent->user, $plan, 'resume', (string) Str::uuid(), $data))
        ->toThrow(ConflictHttpException::class);
    expect($plan->fresh()->status->value)->toBe('paused');
});

test('a cancelled unused cycle renews with fresh identity and no financial carryover', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    $rule = FeeRule::create([
        'version' => 1, 'name' => 'No plan fee', 'kind' => 'plan', 'rule_key' => 'daily',
        'model' => 'no_fee', 'timing' => 'first_contribution', 'basis' => 'none',
        'settlement_source' => 'external_receipt', 'currency' => 'NGN', 'amount_kobo' => 0,
        'customer_description' => 'No fee', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $agent->user_id, 'publication_reason' => 'Fixture',
    ]);
    $data = [
        'name' => 'Daily plan', 'amount_ngn' => '2000.00',
        'start_date' => now('Africa/Lagos')->toDateString(), 'contribution_days' => 2,
        'customer_visible_notes' => '', 'fee_rule_id' => $rule->id,
        'fee_rule_version' => $rule->version, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version,
        'business_version' => 1, 'customer_agreement_attested' => true,
    ];
    $service = app(ThriftPlanService::class);
    $data['preview_fingerprint'] = $service->preview($agent->user, $customer, $data)['preview_fingerprint'];
    $predecessor = $service->create($agent->user, $customer, (string) Str::uuid(), $data)['plan'];

    $cancelled = $service->transition($agent->user, $predecessor, 'cancel', (string) Str::uuid(), [
        'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version,
        'plan_version' => $predecessor->version,
        'reason' => 'Customer requested cancellation.',
        'customer_explanation' => 'Your unused plan was cancelled.',
    ]);
    expect($cancelled->status->value)->toBe('cancelled')
        ->and($cancelled->open_customer_profile_id)->toBeNull();

    $renewalData = [...$data, 'predecessor_plan_id' => $predecessor->plan_id];
    $renewalData['preview_fingerprint'] = $service->preview($agent->user, $customer, $renewalData)['preview_fingerprint'];
    $successor = $service->create($agent->user, $customer, (string) Str::uuid(), $renewalData)['plan'];

    expect($successor->id)->not->toBe($predecessor->id)
        ->and($successor->predecessor_plan_id)->toBe($predecessor->id)
        ->and($successor->slots()->count())->toBe(2)
        ->and($predecessor->fresh()->slots()->count())->toBe(2)
        ->and(ThriftPlan::whereNotNull('open_customer_profile_id')->count())->toBe(1)
        ->and(PlanOperationAttempt::where('operation_type', 'plan_renew')->count())->toBe(1)
        ->and(DB::table('collection_receipts')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
});

test('pre-activity revision updates slots while posted cash locks financial terms', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $data = ['name' => 'Revised daily plan', 'amount_ngn' => '3000.00',
        'start_date' => now('Africa/Lagos')->toDateString(), 'contribution_days' => 3,
        'customer_visible_notes' => '', 'fee_rule_id' => $plan->currentTermsRevision()->feeSnapshot->fee_rule_id,
        'fee_rule_version' => 1, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version,
        'business_version' => 1, 'plan_version' => $plan->version,
        'terms_revision' => 1, 'customer_agreement_attested' => true,
        'reason' => 'Customer agreed to one more day.',
        'customer_explanation' => 'Your plan now has three days.'];
    $service = app(ThriftPlanService::class);
    $data['preview_fingerprint'] = $service->previewRevision($agent->user, $plan, $data)['preview_fingerprint'];
    $revised = $service->revise($agent->user, $plan, (string) Str::uuid(), $data);

    expect($revised->current_terms_revision)->toBe(2)
        ->and($revised->slots()->whereNotNull('active_ordinal')->count())->toBe(3)
        ->and($revised->termsRevisions()->count())->toBe(2)
        ->and($revised->slots()->whereNull('active_ordinal')->count())->toBe(2)
        ->and($revised->slots()->whereNull('active_ordinal')->where('plan_terms_revision_id', $revised->termsRevisions()->where('revision', 1)->value('id'))->count())->toBe(2);
    LedgerAccount::query()->whereIn('code', ['agent_receivable', 'business_cash'])->update(['mapping_status' => 'mapped']);
    FinancialPeriod::factory()->create(['changed_by_user_id' => $agent->user_id]);
    $payload = $this->lifecycleCollectionPayload($customer, $revised);
    $payload['plan_version'] = $revised->version;
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $payload)['preview_fingerprint'];
    app(CollectionService::class)->record($agent->user, $customer, $payload);

    $data['amount_ngn'] = '1000.00';
    expect(fn () => $service->previewRevision($agent->user, $revised->fresh(), $data))
        ->toThrow(ConflictHttpException::class);
    expect($revised->fresh()->current_terms_revision)->toBe(2);
});
