<?php

use App\Enums\AccountState;
use App\Enums\CustomerAssignmentStatus;
use App\Models\AgentProfile;
use App\Models\ContributionSlot;
use App\Models\CustomerAssignment;
use App\Models\FeeRule;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\PlanOperationAttempt;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\ThriftPlanService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\CreatesLifecycleCustomers;

uses(CreatesLifecycleCustomers::class);

test('an invited Customer gets one plan while replay and reassignment preserve the original result', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    $customer->user->update(['account_state' => AccountState::Invited]);
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

    $originalAssignment = $customer->currentAssignment;
    $originalAssignment->update(['status' => CustomerAssignmentStatus::Ended]);
    $replacement = AgentProfile::factory()->active()->create([
        'user_id' => User::factory()->agent()->withTwoFactor()->create()->id,
    ]);
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customer->id,
        'agent_profile_id' => $replacement->id,
        'assigned_by_user_id' => $agent->user_id,
        'version' => $originalAssignment->version + 1,
    ]);

    $this->actingAs($agent->user)->getJson(route('plans.attempts.show', $reference))->assertNotFound();
    $this->actingAs($replacement->user)->getJson(route('plans.attempts.show', $reference))->assertNotFound();
    expect(ThriftPlan::count())->toBe(1)
        ->and(PlanOperationAttempt::where('attempt_reference', $reference)->count())->toBe(1)
        ->and($customer->user->fresh()->account_state)->toBe(AccountState::Invited);
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

test('an Admin or Customer is denied while the assigned Agent can pause a plan', function (): void {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $service = app(ThriftPlanService::class);
    $transition = [
        'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version,
        'plan_version' => $plan->version,
        'reason' => 'Unauthorized pause attempt.',
        'customer_explanation' => 'No change.',
    ];

    foreach ([$admin, $customer->user] as $actor) {
        expect(fn () => $service->previewRevision($actor, $plan, []))->toThrow(AuthorizationException::class);
        expect(fn () => $service->transition($actor, $plan, 'pause', (string) Str::uuid(), $transition))
            ->toThrow(AuthorizationException::class);
        $this->actingAs($actor)->post(route('plans.pause', $plan->plan_id), [
            ...$transition,
            'attempt_reference' => (string) Str::uuid(),
        ])->assertForbidden();
    }

    expect($plan->fresh()->status->value)->toBe('active')
        ->and($plan->lifecycleEvents()->count())->toBe(0)
        ->and(PlanOperationAttempt::count())->toBe(0);

    $this->actingAs($agent->user)->post(route('plans.pause', $plan->plan_id), [
        ...$transition,
        'attempt_reference' => (string) Str::uuid(),
    ])->assertRedirect(route('plans.show', $plan->plan_id));
    expect($plan->fresh()->status->value)->toBe('paused')
        ->and($plan->lifecycleEvents()->where('event_type', 'pause')->count())->toBe(1);
});

test('a January daily plan retains 31 local dates and rejects a changed fee rule after preview', function (): void {
    $this->travelTo(now('Africa/Lagos')->setDate(2027, 1, 20)->startOfDay());
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
        'start_date' => '2027-01-20', 'contribution_days' => 31,
        'customer_visible_notes' => '', 'fee_rule_id' => $rule->id,
        'fee_rule_version' => $rule->version, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version,
        'business_version' => 1, 'customer_agreement_attested' => true,
    ];
    $service = app(ThriftPlanService::class);
    $preview = $service->preview($agent->user, $customer, $data);
    $data['preview_fingerprint'] = $preview['preview_fingerprint'];

    expect($preview['terms']['scheduled_end_date'])->toBe('2027-02-19')
        ->and($preview['slots'])->toHaveCount(31)
        ->and($preview['slots'][0]['due_date'])->toBe('2027-01-20')
        ->and($preview['slots'][30]['due_date'])->toBe('2027-02-19');

    $rule->update(['retired_at' => now()]);
    expect(fn () => $service->create($agent->user, $customer, (string) Str::uuid(), $data))
        ->toThrow(ConflictHttpException::class);
    expect(ThriftPlan::count())->toBe(0);

    $newRule = FeeRule::create([
        'version' => 2, 'name' => 'New no-fee plan rule', 'kind' => 'plan', 'rule_key' => 'daily',
        'model' => 'no_fee', 'timing' => 'first_contribution', 'basis' => 'none',
        'settlement_source' => 'external_receipt', 'currency' => 'NGN', 'amount_kobo' => 0,
        'customer_description' => 'No fee', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $agent->user_id, 'publication_reason' => 'Replacement fixture',
    ]);
    $data['fee_rule_id'] = $newRule->id;
    $data['fee_rule_version'] = 2;
    $data['preview_fingerprint'] = $service->preview($agent->user, $customer, $data)['preview_fingerprint'];
    $plan = $service->create($agent->user, $customer, (string) Str::uuid(), $data)['plan'];
    $dates = $plan->slots()->orderBy('ordinal')->pluck('due_date')->all();
    expect($dates)->toHaveCount(31)
        ->and($dates[0])->toBe('2027-01-20')
        ->and($dates[30])->toBe('2027-02-19')
        ->and(array_unique($dates))->toHaveCount(31);
});

test('a failed lifecycle evidence write rolls back the entire plan creation', function (): void {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('This deterministic failure injection uses an isolated SQLite trigger.');
    }

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
    DB::statement("CREATE TRIGGER reject_plan_lifecycle BEFORE INSERT ON plan_lifecycle_events BEGIN SELECT RAISE(FAIL, 'forced lifecycle failure'); END");

    expect(fn () => $service->create($agent->user, $customer, (string) Str::uuid(), $data))
        ->toThrow(QueryException::class);
    expect(ThriftPlan::count())->toBe(0)
        ->and(ContributionSlot::count())->toBe(0)
        ->and(DB::table('plan_terms_revisions')->count())->toBe(0)
        ->and(PlanOperationAttempt::count())->toBe(0)
        ->and(DB::table('plan_notification_intents')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
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
    expect($revised->fresh()->current_terms_revision)->toBe(2)
        ->and($revised->fresh()->status->value)->toBe('active')
        ->and($revised->fresh()->activity_started_at)->not->toBeNull()
        ->and($revised->slots()->whereNull('active_ordinal')->count())->toBe(2);
});
