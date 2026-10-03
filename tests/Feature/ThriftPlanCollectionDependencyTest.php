<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\CustomerAssignmentStatus;
use App\Models\AgentProfile;
use App\Models\ContributionSlot;
use App\Models\CustomerAssignment;
use App\Models\FeeRule;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\PlanOperationAttempt;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\CollectionReadService;
use App\Services\CollectionService;
use App\Services\FeeObligationService;
use App\Services\PlanSettlementService;
use App\Services\RegistrationFeeService;
use App\Services\ThriftPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\CreatesLifecycleCustomers;

require_once __DIR__.'/../ReversalFixtures.php';

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

test('creation persistence failure preserves every owner boundary and the same attempt can recover once', function (string $table, string $operation, string $condition): void {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Deterministic persistence faults require the isolated SQLite test database.');
    }
    $this->freezeTime();
    [, $customer, $agent, $registration] = $this->createLifecycleFixture(15000);
    $registrationTerms = $registration->refresh()->toArray();
    Queue::fake();
    $rule = FeeRule::create([
        'version' => 1, 'name' => 'Agreed completion fee', 'kind' => 'plan', 'rule_key' => 'daily',
        'model' => 'fixed', 'timing' => 'cycle_completion', 'basis' => 'none',
        'settlement_source' => 'savings_application', 'currency' => 'NGN', 'amount_kobo' => 10000,
        'customer_description' => 'The agreed fee is due when the cycle completes.', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $agent->user_id, 'publication_reason' => 'Published fixture terms.',
    ]);
    $data = ['name' => 'Atomic daily cycle', 'amount_ngn' => '2000.00',
        'start_date' => now('Africa/Lagos')->toDateString(), 'contribution_days' => 3,
        'customer_visible_notes' => 'Original agreement.', 'fee_rule_id' => $rule->id,
        'fee_rule_version' => $rule->version, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version,
        'business_version' => 1, 'customer_agreement_attested' => true];
    $service = app(ThriftPlanService::class);
    $data['preview_fingerprint'] = $service->preview($agent->user, $customer, $data)['preview_fingerprint'];
    $tables = ['customer_profiles', 'customer_assignments', 'agent_profiles', 'public_id_sequences',
        'plan_operation_attempts', 'thrift_plans', 'fee_snapshots', 'plan_terms_revisions', 'contribution_slots',
        'plan_lifecycle_events', 'fee_obligations', 'fee_obligation_entries', 'collection_receipts',
        'ledger_posting_groups', 'ledger_entries', 'withdrawal_reservations', 'plan_notification_intents',
        'notification_events', 'notification_inbox_intents', 'notification_inbox_aliases', 'notification_inbox_attempts',
        'notifications', 'audit_events', 'canonical_audit_events', 'audit_protected_payloads', 'audit_projection_work',
        'platform_recovery_work', 'platform_recovery_attempts'];
    $before = [];
    foreach ($tables as $boundary) {
        $key = match ($boundary) {
            'public_id_sequences' => 'entity_type',
            'audit_projection_work' => 'canonical_event_id',
            default => 'id',
        };
        $before[$boundary] = DB::table($boundary)->orderBy($key)->get()->all();
    }
    $reference = (string) Str::uuid();
    DB::statement("CREATE TRIGGER reject_plan_boundary BEFORE {$operation} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(FAIL, 'forced plan boundary failure'); END");
    try {
        expect(fn () => $service->create($agent->user, $customer, $reference, $data))
            ->toThrow(QueryException::class, 'forced plan boundary failure');
    } finally {
        DB::statement('DROP TRIGGER reject_plan_boundary');
    }
    foreach ($tables as $boundary) {
        $key = match ($boundary) {
            'public_id_sequences' => 'entity_type',
            'audit_projection_work' => 'canonical_event_id',
            default => 'id',
        };
        expect(DB::table($boundary)->orderBy($key)->get()->all())
            ->toEqual($before[$boundary]);
    }
    Queue::assertNothingPushed();
    $created = $service->create($agent->user, $customer->fresh(), $reference, $data);
    $plan = $created['plan'];
    expect($created['replayed'])->toBeFalse()->and($plan->status->value)->toBe('active')
        ->and($plan->slots()->count())->toBe(3)->and($plan->termsRevisions()->count())->toBe(1)
        ->and($plan->currentTermsRevision()->feeSnapshot->amount_kobo)->toBe(10000)
        ->and($registration->fresh()->toArray())->toBe($registrationTerms);
    $counts = [];
    foreach ($tables as $boundary) {
        $counts[$boundary] = DB::table($boundary)->count();
    }
    $replayed = $service->create($agent->user, $customer->fresh(), $reference, $data);
    expect($replayed['replayed'])->toBeTrue()->and($replayed['plan']->id)->toBe($plan->id);
    foreach ($tables as $boundary) {
        expect(DB::table($boundary)->count())->toBe($counts[$boundary]);
    }
    expect(PlanOperationAttempt::query()->where('attempt_reference', $reference)->value('status'))->toBe('committed')
        ->and(DB::table('canonical_audit_events')->where('event_type', 'thrift_plan.created')->count())->toBe(1)
        ->and(DB::table('plan_lifecycle_events')->where('thrift_plan_id', $plan->id)->where('event_type', 'created')->count())->toBe(1)
        ->and(DB::table('plan_notification_intents')->where('thrift_plan_id', $plan->id)->count())->toBeGreaterThan(0);
    foreach (['fee_obligations', 'fee_obligation_entries', 'collection_receipts', 'ledger_posting_groups', 'ledger_entries', 'withdrawal_reservations'] as $financialTable) {
        expect(DB::table($financialTable)->get()->all())->toEqual($before[$financialTable]);
    }
})->with([
    'operation insert' => ['plan_operation_attempts', 'INSERT', '1 = 1'],
    'public identity increment' => ['public_id_sequences', 'UPDATE', "NEW.entity_type = 'plan'"],
    'plan insert' => ['thrift_plans', 'INSERT', '1 = 1'],
    'fee snapshot insert' => ['fee_snapshots', 'INSERT', '1 = 1'],
    'terms insert' => ['plan_terms_revisions', 'INSERT', '1 = 1'],
    'first slot insert' => ['contribution_slots', 'INSERT', 'NEW.ordinal = 1'],
    'later slot insert' => ['contribution_slots', 'INSERT', 'NEW.ordinal = 3'],
    'lifecycle insert' => ['plan_lifecycle_events', 'INSERT', '1 = 1'],
    'first notification insert' => ['plan_notification_intents', 'INSERT', "NEW.audience_type = 'subject_customer' AND NEW.channel = 'database'"],
    'later notification insert' => ['plan_notification_intents', 'INSERT', "NEW.audience_type = 'current_agent'"],
    'shared notification event' => ['notification_events', 'INSERT', "NEW.family = 'plan'"],
    'shared inbox insert' => ['notification_inbox_intents', 'INSERT', '1 = 1'],
    'notification alias insert' => ['notification_inbox_aliases', 'INSERT', "NEW.family = 'plan'"],
    'notification recovery registration' => ['platform_recovery_work', 'INSERT', "NEW.owner = 'notification_inbox'"],
    'legacy audit insert' => ['audit_events', 'INSERT', "NEW.event_type = 'thrift_plan.created'"],
    'canonical audit insert' => ['canonical_audit_events', 'INSERT', "NEW.event_type = 'thrift_plan.created'"],
    'audit projection registration' => ['audit_projection_work', 'INSERT', '1 = 1'],
    'audit recovery registration' => ['platform_recovery_work', 'INSERT', "NEW.owner = 'audit_projection'"],
    'committed attempt update' => ['plan_operation_attempts', 'UPDATE', "NEW.status = 'committed'"],
]);

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

test('pre-activity revision updates slots while posted cash locks financial terms', function (bool $initiallyPaused): void {
    config()->set('collections.enabled', true);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $service = app(ThriftPlanService::class);
    if ($initiallyPaused) {
        $service->transition($agent->user, $plan, 'pause', (string) Str::uuid(), [
            'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
            'plan_version' => $plan->version, 'reason' => 'Customer requests a pause before reviewing terms.',
            'customer_explanation' => 'Collections remain paused until you agree to resume.',
        ]);
        $plan->refresh();
    }
    $originalTerms = $plan->currentTermsRevision();
    $originalTermsAttributes = $originalTerms->getAttributes();
    $originalFeeAttributes = $originalTerms->feeSnapshot->getAttributes();
    $originalSlots = $plan->slots()->orderBy('id')->get();
    $data = ['name' => 'Revised daily plan', 'amount_ngn' => '3000.00',
        'start_date' => now('Africa/Lagos')->toDateString(), 'contribution_days' => 3,
        'customer_visible_notes' => '', 'fee_rule_id' => $plan->currentTermsRevision()->feeSnapshot->fee_rule_id,
        'fee_rule_version' => 1, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version,
        'business_version' => 1, 'plan_version' => $plan->version,
        'terms_revision' => 1, 'customer_agreement_attested' => true,
        'reason' => 'Customer agreed to one more day.',
        'customer_explanation' => 'Your plan now has three days.'];
    $data['preview_fingerprint'] = $service->previewRevision($agent->user, $plan, $data)['preview_fingerprint'];
    $data['attempt_reference'] = (string) Str::uuid();
    foreach (['reason' => '', 'customer_agreement_attested' => false] as $field => $value) {
        $this->actingAs($agent->user)->patchJson(route('plans.update', $plan->plan_id), [...$data, $field => $value])
            ->assertUnprocessable()->assertInvalid([$field]);
        expect($plan->fresh()->version)->toBe($plan->version)
            ->and($plan->termsRevisions()->count())->toBe(1)
            ->and($plan->slots()->count())->toBe(2);
    }
    $revised = $service->revise($agent->user, $plan, $data['attempt_reference'], $data);
    expect($revised->status->value)->toBe($initiallyPaused ? 'paused' : 'active')
        ->and($originalTerms->fresh()->getAttributes())->toBe($originalTermsAttributes)
        ->and($originalTerms->feeSnapshot->fresh()->getAttributes())->toBe($originalFeeAttributes);
    foreach ($originalSlots as $slot) {
        $retained = $slot->fresh();
        expect($retained->active_ordinal)->toBeNull()
            ->and($retained->superseded_at)->not->toBeNull()
            ->and($retained->due_date)->toBe($slot->due_date)
            ->and($retained->expected_amount_kobo)->toBe($slot->expected_amount_kobo)
            ->and($retained->plan_terms_revision_id)->toBe($originalTerms->id);
    }

    expect($revised->currentTermsRevision()->reason)->toBe($data['reason'])
        ->and($revised->currentTermsRevision()->attested_by_user_id)->toBe($agent->user_id)
        ->and($revised->currentTermsRevision()->attested_at)->not->toBeNull()
        ->and($revised->slots()->whereNotNull('active_ordinal')->orderBy('active_ordinal')->pluck('due_date')->all())
        ->toBe(['2026-10-05', '2026-10-06', '2026-10-07'])
        ->and($revised->slots()->whereNotNull('active_ordinal')->pluck('expected_amount_kobo')->all())->toBe([300000, 300000, 300000]);
    expect($revised->current_terms_revision)->toBe(2)
        ->and($revised->slots()->whereNotNull('active_ordinal')->count())->toBe(3)
        ->and($revised->termsRevisions()->count())->toBe(2)
        ->and($revised->slots()->whereNull('active_ordinal')->count())->toBe(2)
        ->and($revised->slots()->whereNull('active_ordinal')->where('plan_terms_revision_id', $revised->termsRevisions()->where('revision', 1)->value('id'))->count())->toBe(2);
    LedgerAccount::query()->whereIn('code', ['agent_receivable_ngn', 'business_cash_ngn'])->update(['mapping_status' => 'mapped']);
    FinancialPeriod::factory()->create(['month' => '2026-10-01', 'changed_by_user_id' => $agent->user_id]);
    if ($initiallyPaused) {
        expect(fn () => app(CollectionService::class)->preview($agent->user, $customer, $this->lifecycleCollectionPayload($customer, $revised)))
            ->toThrow(ValidationException::class);
        $revised = $service->transition($agent->user, $revised, 'resume', (string) Str::uuid(), [
            'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
            'plan_version' => $revised->version, 'reason' => 'Customer agrees to resume revised terms.',
            'customer_explanation' => 'Collections may now use the revised dates.',
        ]);
    }
    $payload = $this->lifecycleCollectionPayload($customer, $revised);
    $currentSlot = $revised->slots()->whereNotNull('active_ordinal')->orderBy('active_ordinal')->firstOrFail();
    expect($originalSlots->pluck('id')->contains($currentSlot->id))->toBeFalse();
    $payload['allocations'] = [['slot_id' => $currentSlot->id, 'amount_ngn' => '1000.00']];
    $payload['plan_version'] = $revised->version;
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent->user, $customer, $payload)['preview_fingerprint'];
    $staleAllocation = [...$payload, 'allocations' => [['slot_id' => $originalSlots->firstOrFail()->id, 'amount_ngn' => '1000.00']]];
    $this->actingAs($agent->user)->postJson(route('customers.collections.preview', $customer->customer_id), $staleAllocation)
        ->assertUnprocessable()->assertInvalid(['allocations']);
    $this->postJson(route('customers.collections.store', $customer->customer_id), $staleAllocation)
        ->assertUnprocessable()->assertInvalid(['allocations']);
    $this->assertDatabaseCount('collection_receipts', 0);
    $this->assertDatabaseCount('collection_allocations', 0);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
    app(CollectionService::class)->record($agent->user, $customer, $payload);
    $this->assertDatabaseCount('collection_receipts', 1);
    expect(DB::table('collection_allocations')->whereIn('contribution_slot_id', $originalSlots->pluck('id'))->count())->toBe(0)
        ->and(DB::table('collection_allocations')->where('contribution_slot_id', $currentSlot->id)->sum('amount_kobo'))->toBe(100000);

    $data['amount_ngn'] = '1000.00';
    expect(fn () => $service->previewRevision($agent->user, $revised->fresh(), $data))
        ->toThrow(ConflictHttpException::class);
    expect($revised->fresh()->current_terms_revision)->toBe(2)
        ->and($revised->fresh()->status->value)->toBe('active')
        ->and($revised->fresh()->activity_started_at)->not->toBeNull()
        ->and($revised->slots()->whereNull('active_ordinal')->count())->toBe(2);
})->with(['Active' => false, 'Paused' => true]);

test('fully reversed contributions prevent cancellation and retain financial term locks and reviewed descriptive history', function (bool $paused): void {
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [, $customer, $agent] = $this->createLifecycleFixture();
    LedgerAccount::query()->where('currency', 'NGN')->update(['mapping_status' => 'mapped']);
    FinancialPeriod::factory()->create(['month' => '2026-10-01']);
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $originalTerms = $plan->currentTermsRevision();
    $termsAttributes = $originalTerms->getAttributes();
    $feeAttributes = $originalTerms->feeSnapshot->getAttributes();
    $slots = $plan->slots()->orderBy('id')->get()->map->getAttributes()->all();
    $collections = app(CollectionService::class);
    $payload = $this->lifecycleCollectionPayload($customer, $plan);
    $payload['preview_fingerprint'] = $collections->preview($agent->user, $customer, $payload)['preview_fingerprint'];
    $receipt = $collections->record($agent->user, $customer, $payload);
    $activityStartedAt = $plan->fresh()->activity_started_at;
    $correction = approveReceiptCorrection($this, $agent->user, $customer, $customer->currentAssignment,
        LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id))->fresh();
    $plan->refresh();
    expect(app(CollectionReadService::class)->fundingCard($plan)['funded_kobo'])->toBe(0)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0)
        ->and($correction->state)->toBe('approved_posted')
        ->and($plan->activity_started_at)->toEqual($activityStartedAt)
        ->and(DB::table('collection_allocations')->sum('amount_kobo'))->toBe(100000)
        ->and(DB::table('collection_allocation_releases')->join('collection_allocations', 'collection_allocations.id', '=', 'collection_allocation_releases.collection_allocation_id')->sum('collection_allocations.amount_kobo'))->toBe(100000);
    $service = app(ThriftPlanService::class);
    if ($paused) {
        $plan = $service->transition($agent->user, $plan, 'pause', (string) Str::uuid(), [
            'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
            'plan_version' => $plan->version, 'reason' => 'Customer requested a pause after correction.',
            'customer_explanation' => 'Original agreement retained.']);
    }
    $beforeCancellation = [];
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'fee_snapshots', 'plan_operation_attempts',
        'plan_lifecycle_events', 'plan_notification_intents', 'collection_receipts', 'collection_allocations',
        'collection_allocation_releases', 'reversal_requests', 'reversal_events', 'fee_obligations', 'fee_obligation_entries',
        'ledger_posting_groups', 'ledger_entries', 'withdrawal_requests', 'withdrawal_reservations'] as $table) {
        $beforeCancellation[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $data = ['attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'plan_version' => $plan->version,
        'reason' => 'Request to cancel the fully corrected cycle.', 'customer_explanation' => 'Original history retained.'];
    $this->actingAs($agent->user)->postJson(route('plans.cancel', $plan->plan_id), $data)->assertConflict();
    $this->postJson(route('plans.cancel', $plan->plan_id), $data)->assertConflict();
    expect($plan->fresh()->status->value)->toBe($paused ? 'paused' : 'active')
        ->and($plan->fresh()->open_customer_profile_id)->toBe($customer->id);
    foreach ($beforeCancellation as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
    $financialRows = [];
    foreach (['collection_receipts', 'collection_allocations', 'collection_allocation_releases', 'reversal_requests',
        'reversal_events', 'ledger_posting_groups', 'ledger_entries', 'fee_obligations', 'fee_obligation_entries',
        'withdrawal_requests', 'withdrawal_reservations'] as $table) {
        $financialRows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $data = ['name' => 'Corrected service name', 'amount_ngn' => '2000.00',
        'start_date' => $originalTerms->start_date, 'contribution_days' => 2,
        'customer_visible_notes' => 'The original dates and contribution amount still apply.',
        'fee_rule_id' => $originalTerms->feeSnapshot->fee_rule_id, 'fee_rule_version' => 1,
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'business_version' => 1, 'plan_version' => $plan->version, 'terms_revision' => 1,
        'customer_agreement_attested' => true, 'reason' => 'Correcting the service description after review.',
        'customer_explanation' => 'Your original financial agreement remains unchanged.'];
    $service = app(ThriftPlanService::class);
    $data['preview_fingerprint'] = $service->previewRevision($agent->user, $plan, $data)['preview_fingerprint'];
    $otherRule = $originalTerms->feeSnapshot->feeRule->replicate();
    $otherRule->version = 2;
    $otherRule->save();
    foreach ([['amount_ngn' => '3000.00'], ['start_date' => '2026-10-06'], ['contribution_days' => 3],
        ['fee_rule_id' => $otherRule->id, 'fee_rule_version' => 2]] as $changedTerms) {
        $changed = [...$data, ...$changedTerms];
        expect(fn () => $service->previewRevision($agent->user, $plan, $changed))->toThrow(ConflictHttpException::class);
        expect(fn () => $service->revise($agent->user, $plan, (string) Str::uuid(), $changed))->toThrow(ConflictHttpException::class);
        expect($plan->fresh()->current_terms_revision)->toBe(1);
    }
    $attemptReference = (string) Str::uuid();
    $revised = $service->revise($agent->user, $plan, $attemptReference, $data);
    $version = $revised->version;
    $service->revise($agent->user, $revised, $attemptReference, $data);
    $current = $revised->currentTermsRevision();
    expect($revised->fresh()->version)->toBe($version)
        ->and($revised->fresh()->activity_started_at)->toEqual($activityStartedAt)
        ->and($current->name)->toBe($data['name'])
        ->and($current->customer_visible_notes)->toBe($data['customer_visible_notes'])
        ->and($current->reason)->toBe($data['reason'])
        ->and($current->contribution_amount_kobo)->toBe($originalTerms->contribution_amount_kobo)
        ->and($current->start_date)->toBe($originalTerms->start_date)
        ->and($current->contribution_days)->toBe($originalTerms->contribution_days)
        ->and($current->feeSnapshot->amount_kobo)->toBe($originalTerms->feeSnapshot->amount_kobo)
        ->and($originalTerms->fresh()->getAttributes())->toBe($termsAttributes)
        ->and($originalTerms->feeSnapshot->fresh()->getAttributes())->toBe($feeAttributes)
        ->and($revised->slots()->orderBy('id')->get()->map->getAttributes()->all())->toBe($slots);
    $event = $revised->lifecycleEvents()->where('event_type', 'details_corrected')->sole();
    expect($event->reason)->toBe($data['reason'])
        ->and($event->payload['financial_terms_changed'])->toBeFalse()
        ->and($event->payload['terms_revision'])->toBe(2);
    foreach ($financialRows as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
})->with(['Active' => false, 'Paused' => true]);

test('a verified closed cycle renews with fresh identity and no financial carryover', function (): void {
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

    config()->set('collections.settlement_enabled', true);
    $settlement = app(PlanSettlementService::class);
    $quote = $settlement->preview($agent->user, $predecessor, 'prepare_termination');
    $prepare = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'reason' => 'Customer requested early termination.', 'customer_explanation' => 'Separate settled closure follows.'];
    $settlement->confirm($agent->user, $predecessor, 'prepare_termination', $prepare);
    $quote = $settlement->preview($agent->user, $predecessor->fresh());
    $settlement->confirm($agent->user, $predecessor, 'close', [...$prepare, 'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint']]);
    expect($predecessor->fresh()->status->value)->toBe('closed');

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

test('renewal uses published current fee terms and fresh agreement without carrying registration or money', function (string $terminalState): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    [$admin, $customer, $agent, $registrationSnapshot] = $this->createLifecycleFixture(15000);
    $registration = app(FeeObligationService::class)->assessSnapshot($registrationSnapshot, $admin);
    expect($registration)->not->toBeNull();
    $registrationAttributes = $registration->fresh()->getAttributes();
    $registrationSnapshotAttributes = $registrationSnapshot->fresh()->getAttributes();
    $registrationEntries = DB::table('fee_obligation_entries')->orderBy('id')->get()->all();
    $rule = FeeRule::create([
        'version' => 1, 'name' => 'Original no fee', 'kind' => 'plan', 'rule_key' => 'daily',
        'model' => 'no_fee', 'timing' => 'first_contribution', 'basis' => 'none',
        'settlement_source' => 'external_receipt', 'currency' => 'NGN', 'amount_kobo' => 0,
        'customer_description' => 'No fee', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $admin->id, 'publication_reason' => 'Original agreed fixture.',
    ]);
    $data = ['name' => 'Original cycle', 'amount_ngn' => '2000.00', 'start_date' => '2026-10-05',
        'contribution_days' => 2, 'customer_visible_notes' => 'Original agreement.',
        'fee_rule_id' => $rule->id, 'fee_rule_version' => 1, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => 1,
        'customer_agreement_attested' => true];
    $service = app(ThriftPlanService::class);
    $data['preview_fingerprint'] = $service->preview($agent->user, $customer, $data)['preview_fingerprint'];
    $predecessor = $service->create($agent->user, $customer, (string) Str::uuid(), $data)['plan'];
    if ($terminalState === 'cancelled') {
        $service->transition($agent->user, $predecessor, 'cancel', (string) Str::uuid(), [
            'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
            'plan_version' => $predecessor->version, 'reason' => 'Customer ends this unused cycle.',
            'customer_explanation' => 'The original agreement is retained in history.',
        ]);
    } else {
        config()->set('collections.settlement_enabled', true);
        $settlement = app(PlanSettlementService::class);
        $quote = $settlement->preview($agent->user, $predecessor, 'prepare_termination');
        $instruction = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
            'reason' => 'End this unused cycle.', 'customer_explanation' => 'Close only after owner gates verify settlement.'];
        $settlement->confirm($agent->user, $predecessor, 'prepare_termination', $instruction);
        $quote = $settlement->preview($agent->user, $predecessor->fresh());
        $settlement->confirm($agent->user, $predecessor, 'close', [...$instruction,
            'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint']]);
    }
    $predecessor->refresh();
    $predecessorAttributes = $predecessor->getAttributes();
    $originalTerms = $predecessor->currentTermsRevision();
    $originalTermsAttributes = $originalTerms->getAttributes();
    $originalFeeAttributes = $originalTerms->feeSnapshot->getAttributes();
    $originalSlots = $predecessor->slots()->orderBy('id')->get()->map->getAttributes()->all();
    $oldRenewal = [...$data, 'predecessor_plan_id' => $predecessor->plan_id];
    $oldRenewal['preview_fingerprint'] = $service->preview($agent->user, $customer, $oldRenewal)['preview_fingerprint'];
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $request = Request::create('/admin/fees/rules', 'POST');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    $publicationOwner = app(RegistrationFeeService::class);
    $publicationData = [
        'name' => 'Current fixed completion fee', 'kind' => 'plan', 'rule_key' => 'daily',
        'model' => 'fixed', 'timing' => 'cycle_completion', 'basis' => 'none',
        'settlement_source' => 'savings_application', 'amount_kobo' => 10000,
        'customer_description' => 'A fixed one hundred naira fee on completed funding.',
        'publication_reason' => 'TEST FIXTURE prospective current agreed fee option.',
    ];
    $publicationData['confirmed'] = true;
    $publicationData['preview_fingerprint'] = $publicationOwner->previewPublication($admin, $publicationData)['preview_fingerprint'];
    $currentRule = $publicationOwner->publishRule($admin, $publicationData, $request);
    expect($rule->fresh()->retired_at)->not->toBeNull();
    expect(fn () => $service->preview($agent->user, $customer, $oldRenewal))->toThrow(ValidationException::class);
    expect(fn () => $service->create($agent->user, $customer, (string) Str::uuid(), $oldRenewal))->toThrow(ConflictHttpException::class);
    expect(ThriftPlan::count())->toBe(1)
        ->and(PlanOperationAttempt::where('operation_type', 'plan_renew')->count())->toBe(0);
    $renewal = [...$oldRenewal, 'name' => 'New reviewed cycle', 'amount_ngn' => '3000.00',
        'start_date' => '2026-10-10', 'contribution_days' => 3, 'customer_visible_notes' => 'Fresh agreement and fee accepted.',
        'fee_rule_id' => $currentRule->id, 'fee_rule_version' => $currentRule->version];
    $renewal['preview_fingerprint'] = $service->preview($agent->user, $customer, $renewal)['preview_fingerprint'];
    $reference = (string) Str::uuid();
    $created = $service->create($agent->user, $customer, $reference, $renewal);
    $successor = $created['plan'];
    $successorAttributes = $successor->fresh()->getAttributes();
    $replayed = $service->create($agent->user, $customer, $reference, $renewal);
    $terms = $successor->currentTermsRevision();
    expect($created['replayed'])->toBeFalse()
        ->and($replayed['replayed'])->toBeTrue()
        ->and($replayed['plan']->id)->toBe($successor->id)
        ->and($successor->fresh()->getAttributes())->toBe($successorAttributes)
        ->and($successor->plan_id)->not->toBe($predecessor->plan_id)
        ->and($successor->predecessor_plan_id)->toBe($predecessor->id)
        ->and($terms->name)->toBe($renewal['name'])
        ->and($terms->contribution_amount_kobo)->toBe(300000)
        ->and($terms->start_date)->toBe('2026-10-10')
        ->and($terms->expected_gross_kobo)->toBe(900000)
        ->and($terms->fee_snapshot_id)->not->toBe($originalTerms->fee_snapshot_id)
        ->and($terms->feeSnapshot->fee_rule_id)->toBe($currentRule->id)
        ->and($terms->feeSnapshot->fee_rule_version)->toBe($currentRule->version)
        ->and($terms->feeSnapshot->amount_kobo)->toBe(10000)
        ->and($terms->feeSnapshot->source_id)->toBe($successor->plan_id.'-R1')
        ->and($successor->slots()->orderBy('active_ordinal')->pluck('due_date')->all())->toBe(['2026-10-10', '2026-10-11', '2026-10-12'])
        ->and($successor->slots()->pluck('id')->intersect(array_column($originalSlots, 'id'))->isEmpty())->toBeTrue()
        ->and($predecessor->fresh()->getAttributes())->toBe($predecessorAttributes)
        ->and($originalTerms->fresh()->getAttributes())->toBe($originalTermsAttributes)
        ->and($originalTerms->feeSnapshot->fresh()->getAttributes())->toBe($originalFeeAttributes)
        ->and($predecessor->slots()->orderBy('id')->get()->map->getAttributes()->all())->toBe($originalSlots)
        ->and($registration->fresh()->getAttributes())->toBe($registrationAttributes)
        ->and($registrationSnapshot->fresh()->getAttributes())->toBe($registrationSnapshotAttributes)
        ->and(DB::table('fee_obligation_entries')->orderBy('id')->get()->all())->toEqual($registrationEntries)
        ->and(ThriftPlan::where('predecessor_plan_id', $predecessor->id)->count())->toBe(1)
        ->and(ThriftPlan::whereNotNull('open_customer_profile_id')->count())->toBe(1)
        ->and(PlanOperationAttempt::where('operation_type', 'plan_renew')->count())->toBe(1)
        ->and(DB::table('fee_obligations')->count())->toBe(1)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0)
        ->and(DB::table('collection_receipts')->count())->toBe(0)
        ->and(DB::table('withdrawal_requests')->count())->toBe(0)
        ->and(DB::table('withdrawal_reservations')->count())->toBe(0);
})->with(['Cancelled predecessor' => 'cancelled', 'Closed predecessor' => 'closed']);

test('resume after the final date preserves original outstanding slots and permits only finite catch up', function (): void {
    config()->set('collections.enabled', true);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    FinancialPeriod::factory()->create(['month' => '2026-10-01']);
    [, $customer, $agent] = $this->createLifecycleFixture();
    LedgerAccount::query()->whereIn('code', ['agent_receivable_ngn', 'business_cash_ngn'])->update(['mapping_status' => 'mapped']);
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $terms = $plan->currentTermsRevision()->getAttributes();
    $slots = $plan->slots()->orderBy('active_ordinal')->get()->map->getAttributes()->all();
    $service = app(ThriftPlanService::class);
    $transition = ['customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'plan_version' => $plan->version, 'reason' => 'Customer requests a temporary pause.', 'customer_explanation' => 'Original dates remain agreed.'];
    $service->transition($agent->user, $plan, 'pause', (string) Str::uuid(), $transition);
    $this->travel(3)->days();
    $plan->refresh();
    $collections = app(CollectionService::class);
    $payload = $this->lifecycleCollectionPayload($customer, $plan);
    expect(fn () => $collections->preview($agent->user, $customer, $payload))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('collection_receipts', 0);
    $this->assertDatabaseCount('ledger_posting_groups', 0);

    $service->transition($agent->user, $plan, 'resume', (string) Str::uuid(), [...$transition,
        'plan_version' => $plan->version, 'reason' => 'Resume after the original final date.', 'customer_explanation' => 'Catch up against original outstanding days.']);
    $plan->refresh();
    expect($plan->status->value)->toBe('active');
    expect($plan->currentTermsRevision()->getAttributes())->toBe($terms);
    expect($plan->slots()->orderBy('active_ordinal')->get()->map->getAttributes()->all())->toBe($slots);
    expect($plan->lifecycleEvents()->pluck('event_type')->all())->toBe(['pause', 'resume']);
    $card = app(CollectionReadService::class)->card($plan);
    expect(array_column($card['slots'], 'due_date'))->toBe(['2026-10-05', '2026-10-06']);
    expect(array_column($card['slots'], 'remaining_kobo'))->toBe([200000, 200000]);
    expect($card['funded_kobo'])->toBe(0);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
    $this->actingAs($agent->user)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->component('plans/Show')->where('plan.current_terms.scheduled_end_date', '2026-10-06')
        ->where('actions.can_pause', true)->where('actions.can_resume', false));
    $this->get(route('plans.card', $plan))->assertInertia(fn (Assert $page) => $page
        ->component('collections/Card')->where('card.slots.0.due_date', '2026-10-05')
        ->where('card.slots.1.due_date', '2026-10-06')->where('card.slots.0.remaining_kobo', 200000)
        ->where('card.slots.1.remaining_kobo', 200000));

    $payload = $this->lifecycleCollectionPayload($customer, $plan);
    $payload['preview_fingerprint'] = $collections->preview($agent->user, $customer, $payload)['preview_fingerprint'];
    $receipt = $collections->record($agent->user, $customer, $payload);
    expect($receipt->received_date)->toBe('2026-10-08');
    $card = app(CollectionReadService::class)->card($plan->fresh());
    expect(array_column($card['slots'], 'remaining_kobo'))->toBe([100000, 200000]);
    expect(array_column($card['slots'], 'status'))->toBe(['partial', 'blocked']);
    $overpayment = [...$this->lifecycleCollectionPayload($customer, $plan->fresh()), 'savings_ngn' => '3000.01'];
    expect(fn () => $collections->preview($agent->user, $customer, $overpayment))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('collection_receipts', 1);
    $this->assertDatabaseCount('contribution_slots', 2);

    $final = [...$this->lifecycleCollectionPayload($customer, $plan->fresh()), 'savings_ngn' => '3000.00'];
    $final['preview_fingerprint'] = $collections->preview($agent->user, $customer, $final)['preview_fingerprint'];
    $collections->record($agent->user, $customer, $final);
    expect($plan->fresh()->status->value)->toBe('completed');
    $card = app(CollectionReadService::class)->card($plan->fresh());
    expect(array_column($card['slots'], 'due_date'))->toBe(['2026-10-05', '2026-10-06']);
    expect(array_column($card['slots'], 'status'))->toBe(['paid', 'paid']);
    expect($card['funded_kobo'])->toBe(400000);
    $this->assertDatabaseCount('contribution_slots', 2);
    $this->assertDatabaseCount('collection_receipts', 2);
    $this->assertDatabaseCount('cash_executions', 0);
});
