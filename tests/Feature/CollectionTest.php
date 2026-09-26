<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerAssignmentStatus;
use App\Enums\FeeRuleBasis;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Enums\ThriftPlanStatus;
use App\Jobs\DeliverCollectionNotificationIntent;
use App\Models\AgentProfile;
use App\Models\CollectionBatch;
use App\Models\CollectionException;
use App\Models\CollectionReceipt;
use App\Models\ContributionSlot;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\FeeRule;
use App\Models\FeeSnapshot;
use App\Models\PlanLifecycleEvent;
use App\Models\PlanTermsRevision;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\CollectionReadService;
use App\Services\FeeObligationService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\LedgerTransactionReadService;
use App\Services\NotificationPipeline;
use App\Services\StatementPreviewService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('collections.enabled', true);
});

test('cash collection routes remain unavailable until the release gate is enabled', function (): void {
    config()->set('collections.enabled', false);
    $this->actingAs(User::factory()->admin()->create())->get(route('collections.index'))->assertStatus(503);
    $this->artisan('collections:freeze-batches')->assertSuccessful();
});

function collectionFixture(): array
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
    $rule = FeeRule::create([
        'version' => 1, 'name' => 'No plan fee', 'kind' => FeeRuleKind::Plan,
        'rule_key' => 'test-plan', 'model' => FeeRuleModel::NoFee,
        'timing' => FeeRuleTiming::FirstContribution, 'basis' => FeeRuleBasis::None,
        'settlement_source' => FeeSettlementSource::ExternalReceipt,
        'currency' => 'NGN', 'amount_kobo' => 0, 'customer_description' => 'No fee',
        'effective_at' => now()->subDay(), 'published_by_user_id' => $agent->id,
        'publication_reason' => 'Test plan rule.',
    ]);
    $plan = ThriftPlan::create([
        'plan_id' => 'PLN-TEST-001', 'customer_profile_id' => $customer->id,
        'created_by_user_id' => $agent->id, 'open_customer_profile_id' => $customer->id,
        'status' => ThriftPlanStatus::Active, 'current_terms_revision' => 1, 'version' => 1,
    ]);
    $snapshot = FeeSnapshot::create([
        'customer_profile_id' => $customer->id, 'source_type' => 'plan', 'source_id' => $plan->plan_id,
        'fee_rule_id' => $rule->id, 'fee_rule_version' => 1, 'name' => 'No plan fee',
        'kind' => FeeRuleKind::Plan, 'model' => FeeRuleModel::NoFee,
        'timing' => FeeRuleTiming::FirstContribution, 'basis' => FeeRuleBasis::None,
        'settlement_source' => FeeSettlementSource::ExternalReceipt,
        'currency' => 'NGN', 'amount_kobo' => 0, 'basis_amount_kobo' => 0,
        'customer_description' => 'No fee', 'acknowledged_at' => now(),
    ]);
    $today = CarbonImmutable::now('Africa/Lagos')->toDateString();
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

function collectionPayload(CustomerProfile $customer, CustomerAssignment $assignment, ThriftPlan $plan, string $date, string $amount): array
{
    return [
        'attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $assignment->version, 'business_version' => 1,
        'plan_id' => $plan->plan_id, 'plan_version' => $plan->version,
        'received_date' => $date, 'savings_ngn' => $amount,
        'fees' => [], 'allocations' => [], 'late_reason' => '', 'notes' => '', 'confirmed' => true,
    ];
}

test('COL-AC-030: one confirmed cash receipt funds slots once and replays by key', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '3000.00');
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    expect($preview['allocations'])->toHaveCount(2)
        ->and($preview['allocations'][0]['amount_kobo'])->toBe(200000)
        ->and($preview['allocations'][1]['amount_kobo'])->toBe(100000);
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];

    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    $changed = $payload;
    $changed['notes'] = 'Different request under one key.';
    $this->post(route('customers.collections.store', $customer->customer_id), $changed)->assertStatus(409);

    expect(CollectionReceipt::query()->count())->toBe(1)
        ->and(DB::table('collection_allocations')->count())->toBe(2)
        ->and(DB::table('ledger_posting_groups')->where('event_type', 'cash_contribution')->count())->toBe(1)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(300000)
        ->and($plan->fresh()->status)->toBe(ThriftPlanStatus::Active);
});

test('LED-AC-012/032: one receipt projects one scoped transaction after a verified rebuild', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();

    $receipt = CollectionReceipt::query()->sole();
    $reader = app(LedgerTransactionReadService::class);
    $agentResult = $reader->search($agent, ['from' => $today, 'to' => $today]);
    expect($agentResult['status'])->toBe('ready')
        ->and($agentResult['total'])->toBe(1)
        ->and($agentResult['data'][0]['reference'])->toBe($receipt->receipt_reference)
        ->and($agentResult['data'][0]['savings_effect_kobo'])->toBe(200000);
    $statement = app(StatementPreviewService::class)->preview($customer->user, $customer, $today, $today, 'Africa/Lagos');
    expect($statement)->toMatchArray([
        'status' => 'ready', 'opening_kobo' => 0, 'activity_kobo' => 200000,
        'closing_kobo' => 200000, 'current_available_kobo' => 200000,
    ]);
    $this->actingAs($customer->user)->get(route('transactions.show', $receipt->receipt_reference))->assertOk();
    $this->get(route('customers.show', $customer->customer_id))->assertOk();
    $this->get(route('customers.statements.preview', [
        'customer' => $customer->customer_id, 'from' => $today, 'to' => $today,
    ]))->assertOk();
    $unrelated = CustomerProfile::factory()->create();
    $this->actingAs($unrelated->user)->get(route('transactions.show', $receipt->receipt_reference))->assertNotFound();
    $this->get(route('customers.statements.preview', $customer->customer_id))->assertNotFound();
    $this->get(route('transactions.index', ['from' => $today, 'to' => $today, 'cursor' => 'invalid']))
        ->assertUnprocessable();
});

test('COL-AC-002: another eligible Agent cannot record for this Customer', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $other = User::factory()->agent()->create([
        'two_factor_secret' => 'OTHER-SECRET', 'two_factor_confirmed_at' => now(),
    ]);
    AgentProfile::factory()->active()->create(['user_id' => $other->id]);
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');

    $this->actingAs($other)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertNotFound();
    expect(CollectionReceipt::query()->count())->toBe(0);
});

test('COL-AC-008: the thirty-day local lookback requires a reason and rejects older or future dates', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, CarbonImmutable::parse($today)->subDays(30)->toDateString(), '1000.00');
    $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertUnprocessable();
    $payload['late_reason'] = 'Received during field visit.';
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk();
    $payload['received_date'] = CarbonImmutable::parse($today)->subDays(31)->toDateString();
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertUnprocessable();
    $payload['received_date'] = CarbonImmutable::parse($today)->addDay()->toDateString();
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertUnprocessable();
    expect(CollectionReceipt::query()->count())->toBe(0);
});

test('COL-AC-010/029: a stale preview or missing custody mapping leaves no receipt', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $plan->update(['version' => 2]);
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertStatus(409);
    $payload['plan_version'] = 2;
    $preview = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    DB::table('ledger_accounts')->where('code', 'agent_receivable_ngn')->update(['mapping_status' => 'unmapped']);
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertStatus(409);
    expect(CollectionReceipt::query()->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
});

test('COL-AC-015: over-capacity receipt leaves no financial effect', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '5000.00');

    $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertUnprocessable();
    expect(CollectionReceipt::query()->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
});

test('COL-AC-014: explicit slot allocation funds the chosen future slot', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $future = $plan->slots()->orderByDesc('active_ordinal')->firstOrFail();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '1000.00');
    $payload['allocations'] = [['slot_id' => $future->id, 'amount_ngn' => '1000.00']];
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();

    expect(DB::table('collection_allocations')->where('contribution_slot_id', $future->id)->value('amount_kobo'))->toBe(100000)
        ->and(DB::table('collection_allocations')->where('is_advance', true)->count())->toBe(1);
});

test('COL-AC-009/024: split cash settles a fee without crediting it to savings', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $rule = FeeRule::create([
        'version' => 1, 'name' => 'Registration cash fee', 'kind' => FeeRuleKind::Registration,
        'rule_key' => 'test-registration', 'model' => FeeRuleModel::Fixed,
        'timing' => FeeRuleTiming::Registration, 'basis' => FeeRuleBasis::None,
        'settlement_source' => FeeSettlementSource::ExternalReceipt,
        'currency' => 'NGN', 'amount_kobo' => 50000, 'customer_description' => 'Registration fee',
        'effective_at' => now()->subDay(), 'published_by_user_id' => $agent->id,
        'publication_reason' => 'Test registration fee.',
    ]);
    $snapshot = FeeSnapshot::create([
        'customer_profile_id' => $customer->id, 'source_type' => 'registration', 'source_id' => $customer->customer_id,
        'fee_rule_id' => $rule->id, 'fee_rule_version' => 1, 'name' => 'Registration cash fee',
        'kind' => FeeRuleKind::Registration, 'model' => FeeRuleModel::Fixed,
        'timing' => FeeRuleTiming::Registration, 'basis' => FeeRuleBasis::None,
        'settlement_source' => FeeSettlementSource::ExternalReceipt,
        'currency' => 'NGN', 'amount_kobo' => 50000, 'basis_amount_kobo' => 0,
        'customer_description' => 'Registration fee', 'acknowledged_at' => now(),
    ]);
    $obligation = app(FeeObligationService::class)->assessSnapshot($snapshot, $agent);
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['fees'] = [['obligation_id' => $obligation->id, 'amount_ngn' => '500.00']];
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000)
        ->and($obligation->fresh()->outstandingAmountKobo())->toBe(0)
        ->and(CollectionReceipt::query()->firstOrFail()->tender_amount_kobo)->toBe(250000);
    $intent = DB::table('notification_inbox_intents')->where('category', 'financial')->sole();
    app(NotificationPipeline::class)->materialize((int) $intent->id);
    expect(json_decode(DB::table('notifications')->where('id', $intent->notification_id)->value('data'), true)['message'])
        ->toContain('Savings: ₦2,000.00; external fee: ₦500.00; total tender: ₦2,500.00.');
    $projected = app(LedgerTransactionReadService::class)->search($customer->user, ['from' => $today, 'to' => $today]);
    expect($projected['total'])->toBe(1)
        ->and($projected['data'][0]['gross_amount_kobo'])->toBe(250000)
        ->and($projected['data'][0]['fee_amount_kobo'])->toBe(50000)
        ->and($projected['data'][0]['savings_effect_kobo'])->toBe(200000)
        ->and($projected['data'][0]['posting_group_count'])->toBe(2);
    $this->get(route('agent.dashboard'))->assertInertia(fn ($page) => $page
        ->where('dashboard.sections.collections.metrics.0.value', 200000)
        ->where('dashboard.sections.collections.metrics.1.value', 50000)
        ->where('dashboard.sections.collections.metrics.2.value', 250000)
        ->where('dashboard.sections.custody.metrics.0.value', 250000));
    $batch = CollectionBatch::query()->firstOrFail();
    $this->get(route('collection-batches.show', $batch))->assertOk()
        ->assertInertia(fn ($page) => $page->where('batch.savings_kobo', 200000)
            ->where('batch.fees_kobo', 50000));
});

test('COL-AC-026: live gross reservation reduces available savings without changing funded slots', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    DB::table('withdrawal_reservations')->insert([
        'customer_profile_id' => $customer->id, 'owner_reference' => 'test-reservation',
        'gross_amount_kobo' => 50000, 'status' => 'live', 'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(app(CollectionReadService::class)->position($customer))->toBe([
        'liability_kobo' => 200000, 'reservations_kobo' => 50000, 'available_kobo' => 150000,
    ])->and(app(CollectionReadService::class)->card($plan)['paid_slots'])->toBe(1);
});

test('COL-AC-019: a paused interval is shown as blocked without inventing a payment', function (): void {
    [$agent, $customer, $assignment, $plan] = collectionFixture();
    $plan->update(['status' => ThriftPlanStatus::Paused, 'version' => 2]);
    PlanLifecycleEvent::create([
        'thrift_plan_id' => $plan->id, 'event_type' => 'pause',
        'from_status' => ThriftPlanStatus::Active, 'to_status' => ThriftPlanStatus::Paused,
        'actor_user_id' => $agent->id, 'assignment_version' => $assignment->version,
        'plan_version' => 2, 'reason' => 'Customer requested a pause.',
        'customer_explanation' => 'Plan paused.', 'payload' => [], 'effective_at' => now(),
    ]);

    expect(app(CollectionReadService::class)->card($plan->fresh())['slots'][1]['status'])->toBe('blocked')
        ->and(DB::table('collection_allocations')->count())->toBe(0);
});

test('COL-AC-037/039: daily workspace separates due coverage from cash received', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();

    $this->get(route('collections.index', ['date' => $today]))->assertOk()
        ->assertInertia(fn ($page) => $page->component('collections/Index')
            ->where('totals.tender_kobo', 200000)
            ->where('totals.receipt_count', 1)
            ->where('due_slots.data.0.funded_kobo', 200000)
            ->where('due_slots.data.0.advance_kobo', 0));
});

test('COL-AC-001: Admin cannot record a collection for an assigned Customer', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $admin = User::factory()->admin()->create();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');

    $this->actingAs($admin)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertForbidden();
    expect(CollectionReceipt::query()->count())->toBe(0);
});

test('COL-AC-016: the final fully funded slot completes the plan in the receipt transaction', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '4000.00');
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];

    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();

    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Completed)
        ->and(DB::table('plan_lifecycle_events')->where('thrift_plan_id', $plan->id)->where('event_type', 'completed')->count())->toBe(1)
        ->and(app(CollectionReadService::class)->card($plan->fresh())['paid_slots'])->toBe(2);
});

test('COL-AC-042/045: a confirmed cash handoff moves Agent custody without changing Customer savings', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();

    $batch = CollectionBatch::query()->firstOrFail();
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();
    $batch->refresh();
    expect($batch->status)->toBe('ready_for_review');

    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::ReconciliationManage->value);
    $this->actingAs($admin)->post(route('collection-batches.remittances.store', $batch), [
        'handoff_reference' => 'HANDOFF-001', 'amount_ngn' => '2000.00',
        'handoff_date' => CarbonImmutable::now('Africa/Lagos')->toDateString(),
        'receiving_location' => 'Lagos office', 'source_attestation' => 'I counted and received the cash.',
        'batch_version' => $batch->version, 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $batch->refresh();
    $this->post(route('collection-batches.review', $batch), [
        'batch_version' => $batch->version, 'reason' => 'Counted cash matches posted receipts.',
        'confirmed' => true,
    ])->assertRedirect();

    expect($batch->fresh()->status)->toBe('reconciled')
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000)
        ->and(DB::table('ledger_posting_groups')->where('event_type', 'cash_remittance')->count())->toBe(1);
});

test('COL-AC-044: a later receipt creates a linked batch supplement without reopening a frozen revision', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $first = collectionPayload($customer, $assignment, $plan, $today, '1000.00');
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $first)
        ->assertOk()->json();
    $first['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $first)->assertRedirect();
    $original = CollectionBatch::query()->firstOrFail();
    $original->update(['status' => 'ready_for_review', 'version' => 2, 'frozen_at' => now()]);

    $second = collectionPayload($customer, $assignment, $plan->fresh(), $today, '1000.00');
    $preview = $this->postJson(route('customers.collections.preview', $customer->customer_id), $second)
        ->assertOk()->json();
    $second['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $second)->assertRedirect();

    $supplement = CollectionBatch::query()->where('revision', 2)->firstOrFail();
    expect($supplement->predecessor_batch_id)->toBe($original->id)
        ->and($supplement->status)->toBe('open')
        ->and($original->fresh()->status)->toBe('ready_for_review')
        ->and($original->receipts()->count())->toBe(1)
        ->and($supplement->receipts()->count())->toBe(1);
});

test('COL-AC-020: a versioned attendance note never changes slot funding', function (): void {
    [$agent, $customer, $assignment, $plan] = collectionFixture();
    $slot = $plan->slots()->orderBy('active_ordinal')->firstOrFail();

    $this->actingAs($agent)->post(route('plans.card.annotations.store', [$plan, $slot]), [
        'version' => 0, 'kind' => 'skipped', 'reason' => 'Customer was away.',
    ])->assertRedirect();

    expect(app(CollectionReadService::class)->card($plan)['slots'][0]['status'])->toBe('skipped')
        ->and(DB::table('collection_allocations')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
});

test('COL-AC-060: receipt notification delivery is idempotent and preserves posting', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();

    $intent = DB::table('collection_notification_intents')->first();
    $job = new DeliverCollectionNotificationIntent($intent->id);
    $job->handle();
    $job->handle();

    expect(DB::table('collection_notification_intents')->where('id', $intent->id)->value('status'))->toBe('delivered')
        ->and($customer->user->notifications()->count())->toBe(1)
        ->and(CollectionReceipt::query()->count())->toBe(1);
});

test('COL-AC-047/048/050: a shortage stays open until cash is remitted and the reasoned exception is resolved', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    $batch = CollectionBatch::query()->firstOrFail();
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();

    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::ReconciliationManage->value);
    $this->actingAs($admin);
    $handoff = [
        'amount_ngn' => '1000.00', 'handoff_date' => $today,
        'receiving_location' => 'Lagos office', 'source_attestation' => 'Counted cash.',
        'confirmed' => true,
    ];
    $this->post(route('collection-batches.remittances.store', $batch), $handoff + [
        'handoff_reference' => 'PART-001', 'batch_version' => $batch->fresh()->version,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('collection-batches.review', $batch), [
        'batch_version' => $batch->fresh()->version, 'reason' => 'One handoff remains outstanding.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $exception = CollectionException::query()->firstOrFail();
    expect($batch->fresh()->status)->toBe('exception')->and($exception->status)->toBe('open');

    $this->post(route('collection-batches.exceptions.resolve', [$batch, $exception]), [
        'batch_version' => $batch->fresh()->version, 'reason' => 'Cash was located.', 'confirmed' => true,
    ])->assertStatus(409);
    $this->post(route('collection-batches.remittances.store', $batch), $handoff + [
        'handoff_reference' => 'PART-002', 'batch_version' => $batch->fresh()->version,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('collection-batches.exceptions.resolve', [$batch, $exception]), [
        'batch_version' => $batch->fresh()->version, 'reason' => 'Second counted handoff received.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('collection-batches.review', $batch), [
        'batch_version' => $batch->fresh()->version, 'reason' => 'All counted cash received.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($batch->fresh()->status)->toBe('reconciled')
        ->and($exception->fresh()->status)->toBe('resolved')
        ->and(DB::table('collection_exception_events')->where('collection_exception_id', $exception->id)->count())->toBe(2)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);

    $this->post(route('collection-batches.exceptions.reopen', [$batch, $exception]), [
        'batch_version' => $batch->fresh()->version, 'reason' => 'New count evidence requires review.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($batch->fresh()->status)->toBe('exception')
        ->and($exception->fresh()->status)->toBe('open')
        ->and(DB::table('collection_exception_events')->where('collection_exception_id', $exception->id)->count())->toBe(3);
});

test('COL-AC-049: counted cash overage opens an investigation without Customer credit', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    $batch = CollectionBatch::query()->firstOrFail();
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::ReconciliationManage->value);
    $this->actingAs($admin)->post(route('collection-batches.exceptions.store', $batch), [
        'batch_version' => $batch->fresh()->version, 'kind' => 'overage', 'amount_ngn' => '500.00',
        'reason' => 'Counted more cash than posted receipts.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($batch->fresh()->status)->toBe('exception')
        ->and(CollectionException::query()->firstOrFail()->amount_kobo)->toBe(50000)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000)
        ->and(DB::table('cash_remittances')->count())->toBe(0);
});
