<?php

use App\Enums\AccountState;
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
use App\Models\BusinessProfile;
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
use App\Services\AuditCapture;
use App\Services\CollectionReadService;
use App\Services\CollectionService;
use App\Services\CollectionWorkspaceService;
use App\Services\FeeObligationService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\LedgerTransactionReadService;
use App\Services\NotificationPipeline;
use App\Services\StatementPreviewService;
use App\Services\ThriftPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config()->set('collections.enabled', true);
});

test('cash collection routes remain unavailable until the release gate is enabled', function (): void {
    config()->set('collections.enabled', false);
    $this->actingAs(User::factory()->admin()->create())->get(route('collections.index'))->assertStatus(503);
    $this->artisan('collections:freeze-batches')->assertSuccessful();
});

test('exact cash parsing rejects zero extra decimals and the amount above the approved cap', function (): void {
    $service = app(CollectionService::class);
    expect($service->amountToKobo('2000.01'))->toBe(200001)
        ->and($service->amountToKobo('9999999999.99'))->toBe(999999999999);
    foreach (['0', '-1', '1.001', '10000000000'] as $amount) {
        expect(fn () => $service->amountToKobo($amount))->toThrow(ValidationException::class);
    }
});

test('COL-AC-005: receipt preview rejects unsupported currency and malformed tender without posting', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '1000.00');
    $payload['currency'] = 'USD';

    $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['currency']);
    unset($payload['currency']);
    foreach (['0', '-1', '1.001', '10000000000.00'] as $amount) {
        $payload['savings_ngn'] = $amount;
        $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['savings_ngn']);
    }
    expect(CollectionReceipt::query()->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
});

test('COL-AC-006: a transfer claim cannot be accepted by the cash receipt endpoint', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '1000.00');
    $payload['method'] = 'transfer';

    $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['method']);
    expect(CollectionReceipt::query()->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
});

function collectionFixture(int $days = 2, int $startOffsetDays = 0, int $slotAmountKobo = 200000): array
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
    $startDate = CarbonImmutable::parse($today, 'Africa/Lagos')->addDays($startOffsetDays)->toDateString();
    $terms = PlanTermsRevision::create([
        'thrift_plan_id' => $plan->id, 'revision' => 1, 'name' => 'Daily plan',
        'contribution_amount_kobo' => $slotAmountKobo, 'currency' => 'NGN', 'start_date' => $startDate,
        'contribution_days' => $days, 'frequency' => 'daily', 'timezone' => 'Africa/Lagos',
        'business_version' => 1, 'expected_gross_kobo' => $days * $slotAmountKobo,
        'fee_snapshot_id' => $snapshot->id, 'attested_by_user_id' => $agent->id,
        'attested_at' => now(),
    ]);
    for ($index = 0; $index < $days; $index++) {
        ContributionSlot::create([
            'thrift_plan_id' => $plan->id, 'plan_terms_revision_id' => $terms->id,
            'ordinal' => $index + 1, 'active_ordinal' => $index + 1,
            'due_date' => CarbonImmutable::parse($startDate, 'Africa/Lagos')->addDays($index)->toDateString(),
            'expected_amount_kobo' => $slotAmountKobo,
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

test('inactive Customers Agents and paused plans cannot accept new savings cash', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '1000.00');
    $this->actingAs($agent);
    $customer->update(['operational_status' => 'inactive']);
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertUnprocessable();
    $customer->update(['operational_status' => 'active']);
    $plan->update(['status' => ThriftPlanStatus::Paused]);
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertUnprocessable();
    $plan->update(['status' => ThriftPlanStatus::Active]);
    $assignment->agentProfile->update(['operational_status' => 'inactive']);
    $this->actingAs($agent->fresh());
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertForbidden();
    expect(CollectionReceipt::count())->toBe(0);
});

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

test('COL-AC-002: reassignment invalidates a former Agents reviewed receipt', function (): void {
    [$formerAgent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $preview = $this->actingAs($formerAgent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $replacement = User::factory()->agent()->withTwoFactor()->create();
    $replacementProfile = AgentProfile::factory()->active()->create(['user_id' => $replacement->id]);
    $assignment->update(['status' => CustomerAssignmentStatus::Ended]);
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customer->id, 'agent_profile_id' => $replacementProfile->id,
        'assigned_by_user_id' => $replacement->id, 'status' => CustomerAssignmentStatus::Current, 'version' => 2,
    ]);

    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertNotFound();
    $this->actingAs($replacement)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk();
    expect(CollectionReceipt::count())->toBe(0);
});

test('COL-AC-003/004: invited Customer remains eligible while restricted Customers and MFA incomplete Agents cannot collect', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '1000.00');
    $customer->user->update(['account_state' => AccountState::Invited]);
    $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk();
    foreach (['restricted', 'archived'] as $status) {
        $customer->update(['operational_status' => $status]);
        $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertUnprocessable();
    }
    $customer->update(['operational_status' => 'active']);
    $agent->update(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);
    $this->actingAs($agent->fresh())->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertNotFound();
    expect(CollectionReceipt::count())->toBe(0);
});

test('COL-AC-004: Agent account and plan lifecycle changes are rechecked before cash preview', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '1000.00');
    $this->actingAs($agent);

    $agent->update(['locked_until' => now()->addMinutes(10)]);
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk();
    $agent->update(['locked_until' => null]);

    foreach ([AccountState::Suspended, AccountState::Deactivated] as $state) {
        $agent->update(['account_state' => $state]);
        $this->actingAs($agent->fresh())->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
            ->assertRedirect(route('login'));
    }
    $agent->update(['account_state' => AccountState::Active]);

    foreach ([ThriftPlanStatus::Paused, ThriftPlanStatus::Completed, ThriftPlanStatus::Closed, ThriftPlanStatus::Cancelled] as $status) {
        $plan->update(['status' => $status]);
        $this->actingAs($agent->fresh())->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['plan_id']);
    }
    expect(CollectionReceipt::query()->count())->toBe(0);
});

test('COL-AC-004: a revoked Agent session cannot preview or commit cash', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '1000.00');
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $agent->increment('lifecycle_access_version');

    $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertRedirect(route('login'));
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)
        ->assertRedirect(route('login'));
    expect(CollectionReceipt::query()->count())->toBe(0);
});

test('COL-AC-024: cash savings posts one balanced Agent receivable and Customer liability group', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();

    $entries = DB::table('ledger_entries as entries')
        ->join('ledger_accounts as accounts', 'accounts.id', '=', 'entries.ledger_account_id')
        ->join('ledger_posting_groups as groups', 'groups.id', '=', 'entries.ledger_posting_group_id')
        ->where('groups.event_type', 'cash_contribution')
        ->orderBy('entries.line_number')->get(['accounts.code', 'entries.side', 'entries.amount_kobo', 'entries.agent_profile_id']);
    expect($entries->count())->toBe(2)
        ->and($entries[0]->code)->toBe('agent_receivable_ngn')
        ->and($entries[0]->side)->toBe('debit')
        ->and((int) $entries[0]->amount_kobo)->toBe(200000)
        ->and((int) $entries[0]->agent_profile_id)->toBe($assignment->agent_profile_id)
        ->and($entries[1]->code)->toBe('customer_savings_liability_ngn')
        ->and($entries[1]->side)->toBe('credit')
        ->and((int) $entries[1]->amount_kobo)->toBe(200000);
});

test('COL-AC-028: audit capture failure rolls back receipt allocation ledger batch and notice', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $audit = Mockery::mock(AuditCapture::class);
    $audit->shouldReceive('record')->andThrow(new RuntimeException('Injected audit failure.'));
    app()->instance(AuditCapture::class, $audit);

    expect(fn () => app(CollectionService::class)->record($agent, $customer, $payload))
        ->toThrow(RuntimeException::class, 'Injected audit failure.');
    expect(CollectionReceipt::count())->toBe(0)
        ->and(DB::table('collection_allocations')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0)
        ->and(DB::table('fee_obligation_entries')->count())->toBe(0)
        ->and(DB::table('collection_batches')->count())->toBe(0)
        ->and(DB::table('collection_notification_intents')->count())->toBe(0);
});

test('COL-AC-028/029: fee assessment failure rolls back a reviewed cash receipt', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $fees = Mockery::mock(FeeObligationService::class);
    $fees->shouldReceive('assessSnapshot')->andThrow(new RuntimeException('Injected fee failure.'));
    app()->instance(FeeObligationService::class, $fees);

    expect(fn () => app(CollectionService::class)->record($agent, $customer, $payload))
        ->toThrow(RuntimeException::class, 'Injected fee failure.');
    expect(CollectionReceipt::count())->toBe(0)
        ->and(DB::table('collection_allocations')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0)
        ->and(DB::table('fee_obligation_entries')->count())->toBe(0)
        ->and(DB::table('collection_batches')->count())->toBe(0)
        ->and(DB::table('collection_notification_intents')->count())->toBe(0);
});

test('COL-AC-011/016: partial receipts fill exact residual before a plan completes', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $this->actingAs($agent);
    foreach (['3000.00', '1000.00'] as $index => $amount) {
        $payload = collectionPayload($customer, $assignment, $plan->fresh(), $today, $amount);
        $preview = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
            ->assertOk()->json();
        if ($index === 1) {
            expect($preview['allocations'])->toHaveCount(1)
                ->and($preview['allocations'][0]['slot_id'])->toBe($plan->slots()->orderByDesc('active_ordinal')->firstOrFail()->id)
                ->and($preview['allocations'][0]['amount_kobo'])->toBe(100000);
        }
        $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
        $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
        if ($index === 0) {
            expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Active)
                ->and(app(CollectionReadService::class)->card($plan->fresh())['slots'][1]['status'])->toBe('partial')
                ->and(app(CollectionReadService::class)->card($plan->fresh())['slots'][1]['remaining_kobo'])->toBe(100000);
            $this->travel(3)->days();
            expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Active);
            $this->travelBack();
        }
    }

    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Completed)
        ->and(CollectionReceipt::count())->toBe(2)
        ->and(app(CollectionReadService::class)->card($plan->fresh())['paid_slots'])->toBe(2)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(400000);
});

test('COL-AC-011: three thousand then two thousand naira exactly funds a five thousand naira slot', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(1, 0, 500000);
    $this->actingAs($agent);
    foreach (['3000.00', '2000.00'] as $index => $amount) {
        $payload = collectionPayload($customer, $assignment, $plan->fresh(), $today, $amount);
        $preview = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
            ->assertOk()->json();
        expect($preview['allocations'])->toHaveCount(1)
            ->and($preview['allocations'][0]['amount_kobo'])->toBe($index === 0 ? 300000 : 200000);
        $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
        $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
        if ($index === 0) {
            expect(app(CollectionReadService::class)->card($plan->fresh())['slots'][0]['status'])->toBe('partial')
                ->and(app(CollectionReadService::class)->card($plan->fresh())['slots'][0]['remaining_kobo'])->toBe(200000);
        }
    }

    expect(app(CollectionReadService::class)->card($plan->fresh())['slots'][0]['status'])->toBe('paid')
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(500000);
});

test('COL-AC-012: one receipt funds three slots without becoming three receipts', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(3);
    $payload = collectionPayload($customer, $assignment, $plan, $today, '6000.00');
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();

    expect(CollectionReceipt::count())->toBe(1)
        ->and(DB::table('collection_allocations')->count())->toBe(3)
        ->and(app(CollectionReadService::class)->card($plan->fresh())['paid_slots'])->toBe(3)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(600000);
});

test('COL-AC-013: one advance receipt funds five future slots while counted once today', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(5, 1);
    $payload = collectionPayload($customer, $assignment, $plan, $today, '10000.00');
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();

    expect(CollectionReceipt::count())->toBe(1)
        ->and(DB::table('collection_allocations')->where('is_advance', true)->count())->toBe(5)
        ->and(app(CollectionReadService::class)->card($plan->fresh())['paid_slots'])->toBe(5);
    $this->get(route('collections.index', ['date' => $today]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('totals.tender_kobo', 1000000)
            ->where('totals.receipt_count', 1));
});

test('COL-AC-027: unaffordable agreed savings fee remains outstanding without a hidden hold', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $snapshot = $plan->currentTermsRevision()->feeSnapshot;
    DB::table('fee_rules')->where('id', $snapshot->fee_rule_id)->update([
        'model' => 'fixed', 'amount_kobo' => 500000, 'settlement_source' => 'savings_application',
    ]);
    DB::table('fee_snapshots')->where('id', $snapshot->id)->update([
        'model' => 'fixed', 'amount_kobo' => 500000, 'settlement_source' => 'savings_application',
    ]);
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();

    $obligation = $plan->fresh()->currentTermsRevision()->feeSnapshot->obligation;
    expect($obligation)->not->toBeNull();
    expect($obligation->outstandingAmountKobo())->toBe(500000)
        ->and(app(CollectionReadService::class)->position($customer))->toBe([
            'liability_kobo' => 200000, 'reservations_kobo' => 0, 'available_kobo' => 200000,
        ])
        ->and(DB::table('ledger_posting_groups')->where('event_type', 'cash_contribution')->count())->toBe(1);
});

test('COL-AC-059: current Agent entry points expose one guarded cash form', function (): void {
    [$agent, $customer, $assignment, $plan] = collectionFixture();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $this->actingAs($agent)->get(route('agent.dashboard'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('dashboard.sections.schedule.rows.0.customer_id', $customer->customer_id)
            ->where('dashboard.sections.schedule.rows.0.can_record_cash', true));
    $this->actingAs($agent)->get(route('customers.show', $customer->customer_id))->assertOk()
        ->assertInertia(fn ($page) => $page->where('customer.plans.can_record_cash', true));
    $this->get(route('plans.card', $plan))->assertOk()
        ->assertInertia(fn ($page) => $page->where('can_record', true)->where('customer_id', $customer->customer_id));
    $this->get(route('customers.collections.create', $customer->customer_id))->assertOk()
        ->assertInertia(fn ($page) => $page->component('collections/Create'));

    $customer->update(['operational_status' => 'restricted']);
    $this->get(route('customers.show', $customer->customer_id))->assertOk()
        ->assertInertia(fn ($page) => $page->where('customer.plans.can_record_cash', false));
    $this->get(route('plans.card', $plan))->assertOk()
        ->assertInertia(fn ($page) => $page->where('can_record', false));
    $this->postJson(route('customers.collections.preview', $customer->customer_id),
        collectionPayload($customer, $assignment, $plan, now('Africa/Lagos')->toDateString(), '1000.00'))->assertUnprocessable();
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

test('COL-AC-007: a changed business timezone explicitly blocks ambiguous plan date collection', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    BusinessProfile::current()->update(['timezone' => 'Europe/London', 'version' => 2]);
    $payload = collectionPayload($customer, $assignment, $plan, $today, '1000.00');
    $payload['business_version'] = 2;

    $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['plan_id']);
    expect(CollectionReceipt::count())->toBe(0)
        ->and($plan->fresh()->currentTermsRevision()->timezone)->toBe('Africa/Lagos');
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

test('COL-AC-015: explicit allocations reject another Customers slot and duplicate slot entries', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $ownSlot = $plan->slots()->firstOrFail();
    $otherCustomer = CustomerProfile::factory()->create();
    $otherPlan = ThriftPlan::create([
        'plan_id' => 'PLN-OTHER-001', 'customer_profile_id' => $otherCustomer->id,
        'created_by_user_id' => $agent->id, 'open_customer_profile_id' => $otherCustomer->id,
        'status' => ThriftPlanStatus::Active, 'current_terms_revision' => 1, 'version' => 1,
    ]);
    $otherTerms = PlanTermsRevision::query()->findOrFail($ownSlot->plan_terms_revision_id)->replicate();
    $otherTerms->thrift_plan_id = $otherPlan->id;
    $otherTerms->save();
    $otherSlot = ContributionSlot::create([
        'thrift_plan_id' => $otherPlan->id, 'plan_terms_revision_id' => $otherTerms->id,
        'ordinal' => 1, 'active_ordinal' => 1, 'due_date' => $today, 'expected_amount_kobo' => 200000,
    ]);
    $payload = collectionPayload($customer, $assignment, $plan, $today, '1000.00');
    $payload['allocations'] = [['slot_id' => $otherSlot->id, 'amount_ngn' => '1000.00']];

    $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['allocations']);
    $payload['allocations'] = [
        ['slot_id' => $ownSlot->id, 'amount_ngn' => '500.00'],
        ['slot_id' => $ownSlot->id, 'amount_ngn' => '1000.00'],
    ];
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['allocations']);
    $payload['allocations'] = [['slot_id' => $ownSlot->id, 'amount_ngn' => '-1.00']];
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['allocations.0.amount_ngn']);
    expect(CollectionReceipt::query()->count())->toBe(0)
        ->and(DB::table('collection_allocations')->count())->toBe(0);
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
            ->where('batch.fees_kobo', 50000)
            ->where('batch.receipt_count', 1)
            ->where('receipts', null)
            ->has('remittances.data', 0));
    $manager = User::factory()->admin()->create();
    $manager->givePermissionTo(AdminPermission::ReconciliationManage->value);
    $this->actingAs($manager)->get(route('collection-batches.show', $batch))->assertOk()
        ->assertInertia(fn ($page) => $page->has('receipts.data', 1)
            ->where('receipts.data.0.tender_kobo', 250000));
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $frozenVersion = $batch->fresh()->version;
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();
    expect($batch->fresh()->status)->toBe('ready_for_review')
        ->and($batch->fresh()->version)->toBe($frozenVersion)
        ->and((int) $batch->receipts()->sum('savings_amount_kobo'))->toBe(200000)
        ->and((int) $batch->receipts()->sum('fee_amount_kobo'))->toBe(50000);
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
            ->where('totals.savings_kobo', 200000)
            ->where('totals.fees_kobo', 0)
            ->where('totals.receipt_count', 1)
            ->where('due_totals.scheduled_kobo', 200000)
            ->where('due_totals.covered_kobo', 200000)
            ->where('due_totals.outstanding_kobo', 0)
            ->where('due_slots.data.0.status', 'paid')
            ->where('due_slots.data.0.funded_kobo', 200000)
            ->where('due_slots.data.0.advance_kobo', 0));

    $this->get(route('collections.index', ['date' => $today, 'status' => 'paid', 'search' => $customer->customer_id]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('due_totals.slot_count', 1)
            ->where('due_slots.data.0.customer_id', $customer->customer_id));
    $this->get(route('collections.index', ['date' => $today, 'status' => 'pending']))->assertOk()
        ->assertInertia(fn ($page) => $page->where('due_totals.slot_count', 0)
            ->has('due_slots.data', 0));
});

test('COL-AC-038: advance-covered due work stays separate from catch-up cash received today', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(4, -1);
    $yesterday = CarbonImmutable::parse($today, 'Africa/Lagos')->subDay()->toDateString();
    $todaySlot = $plan->slots()->where('due_date', $today)->firstOrFail();
    $advance = collectionPayload($customer, $assignment, $plan, $yesterday, '2000.00');
    $advance['late_reason'] = 'Counted after the field visit.';
    $advance['allocations'] = [['slot_id' => $todaySlot->id, 'amount_ngn' => '2000.00']];
    $advancePreview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $advance)
        ->assertOk()->json();
    $advance['preview_fingerprint'] = $advancePreview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $advance)->assertRedirect();

    $catchUp = collectionPayload($customer, $assignment, $plan->fresh(), $today, '6000.00');
    $catchUpPreview = $this->postJson(route('customers.collections.preview', $customer->customer_id), $catchUp)
        ->assertOk()->json();
    $catchUp['preview_fingerprint'] = $catchUpPreview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $catchUp)->assertRedirect();

    $this->get(route('collections.index', ['date' => $today]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('totals.tender_kobo', 600000)
            ->where('totals.receipt_count', 1)
            ->where('due_totals.covered_kobo', 200000)
            ->where('due_totals.outstanding_kobo', 0)
            ->where('due_slots.data.0.advance_kobo', 200000));
    $this->get(route('collections.index', ['date' => $today, 'status' => 'advance-covered']))->assertOk()
        ->assertInertia(fn ($page) => $page->where('due_totals.slot_count', 1)
            ->where('due_slots.data.0.customer_id', $customer->customer_id));
    $unassigned = User::factory()->agent()->withTwoFactor()->create();
    AgentProfile::factory()->active()->create(['user_id' => $unassigned->id]);
    $this->actingAs($unassigned)->get(route('collections.index', ['date' => $today]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('due_totals.slot_count', 0)
            ->has('due_slots.data', 0));
    $this->get(route('customers.collections.create', $customer->customer_id))->assertNotFound();
});

test('COL-AC-039: a late-recorded receipt belongs to its received-date filter exactly once', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(2, -1);
    $yesterday = CarbonImmutable::parse($today, 'Africa/Lagos')->subDay()->toDateString();
    $payload = collectionPayload($customer, $assignment, $plan, $yesterday, '2000.00');
    $payload['late_reason'] = 'Cash counted after returning from the field.';
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();

    $this->get(route('collections.index', ['date' => $yesterday, 'search' => $customer->customer_id, 'status' => 'paid']))
        ->assertOk()->assertInertia(fn ($page) => $page->where('totals.receipt_count', 1)
        ->where('totals.tender_kobo', 200000)
        ->where('due_totals.slot_count', 1));
    $this->get(route('collections.index', ['date' => $today, 'search' => 'no-matching-customer', 'status' => 'pending']))
        ->assertOk()->assertInertia(fn ($page) => $page->where('totals.receipt_count', 0)
        ->where('totals.tender_kobo', 0)
        ->where('due_totals.slot_count', 0));
    expect(CollectionReceipt::query()->sole()->recorded_at->toDateString())->toBe(now()->toDateString());
});

test('daily work totals cover every scoped row while the page and query count stay bounded', function (): void {
    [$agent, $customer, , $plan, $today] = collectionFixture();
    $agentProfile = $customer->currentAssignment->agentProfile;
    $rule = FeeRule::query()->where('kind', 'plan')->firstOrFail();
    for ($index = 0; $index < 25; $index++) {
        $anotherCustomer = CustomerProfile::factory()->create();
        CustomerAssignment::factory()->create(['customer_profile_id' => $anotherCustomer->id,
            'agent_profile_id' => $agentProfile->id, 'assigned_by_user_id' => $agent->id,
            'status' => CustomerAssignmentStatus::Current]);
        $data = ['name' => 'Daily plan', 'amount_ngn' => '2000.00', 'start_date' => $today,
            'contribution_days' => 1, 'customer_visible_notes' => '', 'fee_rule_id' => $rule->id,
            'fee_rule_version' => $rule->version, 'customer_version' => $anotherCustomer->version,
            'assignment_version' => $anotherCustomer->currentAssignment->version,
            'business_version' => 1, 'customer_agreement_attested' => true];
        $service = app(ThriftPlanService::class);
        $data['preview_fingerprint'] = $service->preview($agent, $anotherCustomer, $data)['preview_fingerprint'];
        $service->create($agent, $anotherCustomer, (string) Str::uuid(), $data);
    }
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work = app(CollectionWorkspaceService::class)->dueWork($agent, $today, $today, '', 'all');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($work['totals']['slot_count'])->toBe(26)
        ->and($work['totals']['scheduled_kobo'])->toBe(5200000)
        ->and($work['slots']->count())->toBe(25)
        ->and($work['slots']->total())->toBe(26)
        ->and($queries)->toBeLessThanOrEqual(6);
    $this->actingAs($agent)->get(route('collections.index', ['date' => $today, 'due_page' => 2]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('due_totals.slot_count', 26)
            ->has('due_slots.data', 1));
});

test('COL-AC-001: Customer and Admin cannot record a collection', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $admin = User::factory()->admin()->create();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['preview_fingerprint'] = str_repeat('a', 64);

    $this->actingAs($customer->user)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertForbidden();
    $this->postJson(route('customers.collections.store', $customer->customer_id), $payload)
        ->assertForbidden();
    $this->actingAs($admin)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertForbidden();
    $this->postJson(route('customers.collections.store', $customer->customer_id), $payload)
        ->assertForbidden();
    expect(CollectionReceipt::query()->count())->toBe(0)
        ->and(DB::table('collection_allocations')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
});

test('COL-AC-032: original attempt lookup is scoped after assignment ends', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '1000.00');
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();

    $this->getJson(route('collections.attempts.show', $payload['attempt_reference']))->assertOk()
        ->assertJsonPath('status', 'posted');
    $assignment->update(['status' => CustomerAssignmentStatus::Ended]);
    $this->getJson(route('collections.attempts.show', $payload['attempt_reference']))->assertNotFound();
    expect(CollectionReceipt::query()->count())->toBe(1)
        ->and(DB::table('ledger_posting_groups')->where('event_type', 'cash_contribution')->count())->toBe(1);
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
    $this->get(route('collections.index', ['date' => $today, 'status' => 'paid']))->assertOk()
        ->assertInertia(fn ($page) => $page->where('due_totals.slot_count', 1)
            ->where('due_slots.data.0.status', 'paid'));
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

test('COL-AC-043/045/046: only a reconciliation manager can confirm original Agent cash after reassignment', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    $batch = CollectionBatch::query()->sole();
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();
    $replacement = User::factory()->agent()->withTwoFactor()->create();
    $replacementProfile = AgentProfile::factory()->active()->create(['user_id' => $replacement->id]);
    $assignment->update(['status' => CustomerAssignmentStatus::Ended]);
    CustomerAssignment::factory()->create([
        'customer_profile_id' => $customer->id, 'agent_profile_id' => $replacementProfile->id,
        'assigned_by_user_id' => $replacement->id, 'status' => CustomerAssignmentStatus::Current, 'version' => 2,
    ]);
    $handoff = [
        'handoff_reference' => 'REASSIGNED-001', 'amount_ngn' => '1000.00',
        'handoff_date' => $today, 'receiving_location' => 'Lagos office',
        'source_attestation' => 'Counted one thousand naira from the original Agent.',
        'batch_version' => $batch->fresh()->version, 'confirmed' => true,
    ];
    $baseline = User::factory()->admin()->withTwoFactor()->create();
    $this->actingAs($agent)->get(route('collection-batches.show', $batch))->assertOk()
        ->assertInertia(fn ($page) => $page->where('can_manage', false)->where('receipts', null));
    $this->post(route('collection-batches.remittances.store', $batch), $handoff)->assertForbidden();
    $this->actingAs($replacement)->post(route('collection-batches.remittances.store', $batch), $handoff)->assertForbidden();
    $this->actingAs($baseline)->post(route('collection-batches.remittances.store', $batch), $handoff)->assertForbidden();
    $this->actingAs($baseline)->post(route('collection-batches.review', $batch), [
        'batch_version' => $batch->fresh()->version, 'reason' => 'Unpermitted review.', 'confirmed' => true,
    ])->assertForbidden();
    expect(DB::table('cash_remittances')->count())->toBe(0);

    $manager = User::factory()->admin()->withTwoFactor()->create();
    $manager->givePermissionTo(AdminPermission::ReconciliationManage->value);
    $this->actingAs($manager)->post(route('collection-batches.remittances.store', $batch), $handoff)
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('collection-batches.remittances.store', $batch), $handoff)->assertRedirect();
    expect(DB::table('cash_remittances')->count())->toBe(1);
    $agentBalances = DB::table('ledger_entries as entries')
        ->join('ledger_accounts as accounts', 'accounts.id', '=', 'entries.ledger_account_id')
        ->where('accounts.code', 'agent_receivable_ngn')
        ->selectRaw("entries.agent_profile_id, SUM(CASE WHEN entries.side = 'debit' THEN entries.amount_kobo ELSE -entries.amount_kobo END) as balance")
        ->groupBy('entries.agent_profile_id')->pluck('balance', 'entries.agent_profile_id');
    expect((int) $agentBalances[$assignment->agent_profile_id])->toBe(100000)
        ->and($agentBalances->has($replacementProfile->id))->toBeFalse()
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
});

test('COL-AC-044: a later receipt creates a linked batch supplement without reopening a frozen revision', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $first = collectionPayload($customer, $assignment, $plan, $today, '1000.00');
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $first)
        ->assertOk()->json();
    $first['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $first)->assertRedirect();
    $original = CollectionBatch::query()->firstOrFail();
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();
    $manager = User::factory()->admin()->withTwoFactor()->create();
    $manager->givePermissionTo(AdminPermission::ReconciliationManage->value);
    $this->actingAs($manager)->post(route('collection-batches.remittances.store', $original), [
        'handoff_reference' => 'SUPPLEMENT-HANDOFF-001', 'amount_ngn' => '1000.00',
        'handoff_date' => $today, 'receiving_location' => 'Lagos office',
        'source_attestation' => 'Counted original batch cash.',
        'batch_version' => $original->fresh()->version, 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('collection-batches.review', $original), [
        'batch_version' => $original->fresh()->version, 'reason' => 'Original cash fully confirmed.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $originalVersion = $original->fresh()->version;
    expect($original->fresh()->status)->toBe('reconciled');

    $second = collectionPayload($customer, $assignment, $plan->fresh(), $today, '1000.00');
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $second)
        ->assertOk()->json();
    $second['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $second)->assertRedirect();

    $supplement = CollectionBatch::query()->where('revision', 2)->firstOrFail();
    expect($supplement->predecessor_batch_id)->toBe($original->id)
        ->and($supplement->status)->toBe('open')
        ->and($original->fresh()->status)->toBe('reconciled')
        ->and($original->fresh()->version)->toBe($originalVersion)
        ->and($original->receipts()->count())->toBe(1)
        ->and($supplement->receipts()->count())->toBe(1)
        ->and($original->remittances()->count())->toBe(1)
        ->and($supplement->remittances()->count())->toBe(0);
});

test('COL-AC-020: a versioned attendance note never changes slot funding', function (): void {
    [$agent, $customer, $assignment, $plan] = collectionFixture();
    $slot = $plan->slots()->orderBy('active_ordinal')->firstOrFail();

    $this->actingAs($agent)->post(route('plans.card.annotations.store', [$plan, $slot]), [
        'version' => 0, 'kind' => 'skipped', 'reason' => 'Customer was away.',
    ])->assertRedirect();

    expect(app(CollectionReadService::class)->card($plan)['slots'][0]['status'])->toBe('skipped')
        ->and(DB::table('collection_allocations')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0)
        ->and($plan->fresh()->status)->toBe(ThriftPlanStatus::Active);
    $this->post(route('plans.card.annotations.store', [$plan, $slot]), [
        'version' => 1, 'kind' => 'missed', 'reason' => 'Customer confirmed no payment today.',
    ])->assertRedirect();
    expect(app(CollectionReadService::class)->card($plan)['slots'][0]['status'])->toBe('missed')
        ->and(DB::table('collection_annotations')->where('contribution_slot_id', $slot->id)->count())->toBe(2)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
});

test('COL-AC-021: unauthorized stale future and funded attendance annotations are rejected', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $firstSlot = $plan->slots()->orderBy('active_ordinal')->firstOrFail();
    $futureSlot = $plan->slots()->orderByDesc('active_ordinal')->firstOrFail();
    $note = ['version' => 0, 'kind' => 'skipped', 'reason' => 'Customer was away.'];
    $unassigned = User::factory()->agent()->withTwoFactor()->create();
    AgentProfile::factory()->active()->create(['user_id' => $unassigned->id]);

    $this->actingAs($unassigned)->post(route('plans.card.annotations.store', [$plan, $firstSlot]), $note)
        ->assertNotFound();
    $this->actingAs($agent)->post(route('plans.card.annotations.store', [$plan, $futureSlot]), $note)
        ->assertStatus(409);
    $this->post(route('plans.card.annotations.store', [$plan, $firstSlot]), $note)->assertRedirect();
    $this->post(route('plans.card.annotations.store', [$plan, $firstSlot]), $note)->assertStatus(409);

    $payload = collectionPayload($customer, $assignment, $plan, $today, '1000.00');
    $preview = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    $this->post(route('plans.card.annotations.store', [$plan, $firstSlot]), [
        'version' => 1, 'kind' => 'missed', 'reason' => 'Should not change a partial slot.',
    ])->assertStatus(409);
    $finish = collectionPayload($customer, $assignment, $plan->fresh(), $today, '1000.00');
    $finishPreview = $this->postJson(route('customers.collections.preview', $customer->customer_id), $finish)
        ->assertOk()->json();
    $finish['preview_fingerprint'] = $finishPreview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $finish)->assertRedirect();
    $this->post(route('plans.card.annotations.store', [$plan, $firstSlot]), [
        'version' => 1, 'kind' => 'missed', 'reason' => 'Should not change a paid slot.',
    ])->assertStatus(409);
    expect(DB::table('collection_annotations')->count())->toBe(1)
        ->and(app(CollectionReadService::class)->card($plan->fresh())['slots'][0]['status'])->toBe('paid');
});

test('COL-AC-022: catch-up cash supersedes a skipped display while retaining the annotation', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(2, -1);
    $slot = $plan->slots()->orderBy('active_ordinal')->firstOrFail();
    $this->actingAs($agent)->post(route('plans.card.annotations.store', [$plan, $slot]), [
        'version' => 0, 'kind' => 'skipped', 'reason' => 'Customer was away.',
    ])->assertRedirect();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $preview = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];

    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();

    $card = app(CollectionReadService::class)->card($plan->fresh());
    expect($card['slots'][0]['status'])->toBe('paid')
        ->and($card['slots'][0]['due_date'])->toBe(CarbonImmutable::parse($today, 'Africa/Lagos')->subDay()->toDateString())
        ->and($card['slots'][0]['annotation_reason'])->toBe('Customer was away.')
        ->and(CollectionReceipt::query()->sole()->received_date)->toBe($today)
        ->and(DB::table('collection_annotations')->where('contribution_slot_id', $slot->id)->count())->toBe(1);
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
        'amount_ngn' => '1500.00', 'handoff_date' => $today,
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
    $agentDebt = DB::table('ledger_entries as entries')
        ->join('ledger_accounts as accounts', 'accounts.id', '=', 'entries.ledger_account_id')
        ->where('accounts.code', 'agent_receivable_ngn')
        ->where('entries.agent_profile_id', $assignment->agent_profile_id)
        ->selectRaw("SUM(CASE WHEN entries.side = 'debit' THEN entries.amount_kobo ELSE -entries.amount_kobo END) as balance")
        ->value('balance');
    expect($batch->fresh()->status)->toBe('exception')
        ->and($exception->status)->toBe('open')
        ->and((int) $agentDebt)->toBe(50000)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);

    $this->post(route('collection-batches.exceptions.resolve', [$batch, $exception]), [
        'batch_version' => $batch->fresh()->version, 'reason' => 'Cash was located.', 'confirmed' => true,
    ])->assertStatus(409);
    $this->post(route('collection-batches.remittances.store', $batch), [
        'amount_ngn' => '500.00', 'handoff_reference' => 'PART-002', 'batch_version' => $batch->fresh()->version,
    ] + $handoff)->assertRedirect()->assertSessionHasNoErrors();
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
