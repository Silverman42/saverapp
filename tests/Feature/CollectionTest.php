<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\CustomerAssignmentStatus;
use App\Enums\FeeObligationEntryType;
use App\Enums\FeeRuleBasis;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Enums\LedgerAccountCode;
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
use App\Models\FeeObligationEntry;
use App\Models\FeeRule;
use App\Models\FeeSnapshot;
use App\Models\FinancialPeriod;
use App\Models\PlanLifecycleEvent;
use App\Models\PlanTermsRevision;
use App\Models\ReversalRequest;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\AuditCapture;
use App\Services\CollectionBatchPosition;
use App\Services\CollectionReadService;
use App\Services\CollectionReceivedTime;
use App\Services\CollectionService;
use App\Services\CollectionWorkspaceService;
use App\Services\FeeObligationService;
use App\Services\FinancialCashPosition;
use App\Services\LedgerTransactionProjectionService;
use App\Services\LedgerTransactionReadService;
use App\Services\NotificationPipeline;
use App\Services\StatementPreviewService;
use App\Services\ThriftPlanService;
use App\Services\WithdrawalService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';
require_once __DIR__.'/../WithdrawalFixtures.php';

beforeEach(function (): void {
    config()->set('collections.enabled', true);
});

test('cash collection routes remain unavailable until the release gate is enabled', function (): void {
    config()->set('collections.enabled', false);
    $this->actingAs(User::factory()->admin()->create())->get(route('collections.index'))->assertStatus(503);
    $this->artisan('collections:freeze-batches')->assertSuccessful();
});

test('COL-AC-059: posted receipt recording links reflect current Customer and assigned Agent authority', function (string $viewerType, string $customerStatus, string $agentStatus, bool $canRecord): void {
    $this->freezeTime();
    Queue::fake();
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(1);
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $payload);
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Completed);
    $customer->update(['operational_status' => $customerStatus]);
    $assignment->agentProfile->update(['operational_status' => $agentStatus]);
    $viewer = match ($viewerType) {
        'admin' => User::factory()->admin()->withTwoFactor()->create(),
        'customer' => $customer->user,
        default => $agent->fresh(),
    };
    $before = DB::table('ledger_entries')->orderBy('id')->get()->all();

    $this->actingAs($viewer)->get(route('collections.index', ['date' => $today]))->assertInertia(fn ($page) => $page
        ->has('receipts.data', 1)->where('receipts.data.0.id', $receipt->receipt_reference)
        ->where('receipts.data.0.can_record', $canRecord));

    $this->get(route('collections.show', $receipt))->assertInertia(fn ($page) => $page
        ->where('receipt.id', $receipt->receipt_reference)->where('receipt.savings_kobo', 200000));
    expect(DB::table('ledger_entries')->orderBy('id')->get()->all())->toEqual($before);
    $this->assertDatabaseCount('collection_receipts', 1);
})->with([
    'eligible current Agent with completed source plan' => ['agent', 'active', 'active', true],
    'Admin historical reader' => ['admin', 'active', 'active', false],
    'Customer historical reader' => ['customer', 'active', 'active', false],
    'Inactive Customer' => ['agent', 'inactive', 'active', false],
    'Restricted Customer' => ['agent', 'restricted', 'active', false],
    'Inactive assigned Agent historical reader' => ['agent', 'active', 'inactive', false],
]);

test('COL-AC-059: reassigned receipt history grants recording to the replacement and removes former Agent scope', function (): void {
    $this->freezeTime();
    Queue::fake();
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '1000.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $payload);
    $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $assignment->update(['status' => CustomerAssignmentStatus::Ended, 'is_current' => null, 'ended_at' => now()]);
    CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $replacement->id,
        'assigned_by_user_id' => $agent->id, 'status' => CustomerAssignmentStatus::Current, 'version' => $assignment->version + 1]);
    $originalReceipt = DB::table('collection_receipts')->where('id', $receipt->id)->first();
    $before = DB::table('ledger_entries')->orderBy('id')->get()->all();

    $this->actingAs($agent)->get(route('collections.index', ['date' => $today]))->assertInertia(fn ($page) => $page->has('receipts.data', 0));
    $this->get(route('collections.show', $receipt))->assertNotFound();
    $this->actingAs($replacement->user)->get(route('collections.index', ['date' => $today]))->assertInertia(fn ($page) => $page
        ->has('receipts.data', 1)->where('receipts.data.0.id', $receipt->receipt_reference)->where('receipts.data.0.can_record', true));

    expect(DB::table('collection_receipts')->where('id', $receipt->id)->first())->toEqual($originalReceipt)
        ->and(DB::table('ledger_entries')->orderBy('id')->get()->all())->toEqual($before);
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

test('COL-AC-006: a transfer claim requires its configured method and payment evidence', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '1000.00');
    $payload['method'] = 'transfer';

    $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['collection_method_version_id', 'evidence_reference']);
    expect(CollectionReceipt::query()->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
});

test('a missing or closed financial month rejects cash before a receipt is posted', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '1000.00');
    $period = FinancialPeriod::query()->whereDate('month', substr($today, 0, 7).'-01')->firstOrFail();
    $period->delete();
    $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertStatus(409);
    $period = FinancialPeriod::factory()->create(['month' => substr($today, 0, 7).'-01', 'status' => 'closed']);
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertStatus(409);
    expect(CollectionReceipt::query()->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
});

test('closing the financial month after review rejects the stale receipt atomically', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '1000.00');
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json('preview_fingerprint');
    FinancialPeriod::query()->whereDate('month', substr($today, 0, 7).'-01')->update(['status' => 'closed']);

    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertStatus(409);
    expect(CollectionReceipt::query()->count())->toBe(0)
        ->and(DB::table('collection_allocations')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
});

test('reopening an old financial month does not extend the receipt lookback', function (): void {
    [$agent, $customer, $assignment, $plan] = collectionFixture();
    $oldDate = CarbonImmutable::now('Africa/Lagos')->subMonths(2)->startOfMonth()->toDateString();
    FinancialPeriod::factory()->create(['month' => $oldDate, 'status' => 'open']);
    $payload = collectionPayload($customer, $assignment, $plan, $oldDate, '1000.00');
    $payload['late_reason'] = 'Late submission.';

    $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['received_date']);
    expect(CollectionReceipt::query()->count())->toBe(0);
});

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

test('COL-AC-031: separate keys post identical cash only up to remaining slot capacity', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(1);
    $this->actingAs($agent);
    foreach (['1000.00', '1000.00'] as $amount) {
        $payload = collectionPayload($customer, $assignment, $plan->fresh(), $today, $amount);
        $payload['preview_fingerprint'] = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
            ->assertOk()->json('preview_fingerprint');
        $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    }
    $third = collectionPayload($customer, $assignment, $plan->fresh(), $today, '1000.00');
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $third)
        ->assertUnprocessable()->assertJsonValidationErrors(['plan_id']);

    expect(CollectionReceipt::count())->toBe(2)
        ->and((int) DB::table('collection_allocations')->sum('amount_kobo'))->toBe(200000)
        ->and(DB::table('ledger_posting_groups')->where('event_type', 'cash_contribution')->count())->toBe(2)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
});

test('COL-AC-005: cumulative savings can exceed the single receipt tender cap', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(2, 0, 600_000_000_000);
    $this->actingAs($agent);
    foreach (['6000000000.00', '6000000000.00'] as $amount) {
        $payload = collectionPayload($customer, $assignment, $plan->fresh(), $today, $amount);
        $payload['preview_fingerprint'] = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
            ->assertOk()->json('preview_fingerprint');
        $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    }

    expect(CollectionReceipt::count())->toBe(2)
        ->and((int) DB::table('collection_allocations')->sum('amount_kobo'))->toBe(1_200_000_000_000)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(1_200_000_000_000);
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

test('COL-AC-061: receipt and remittance audit evidence retains actors without private cash notes', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['notes'] = 'PRIVATE-CASH-NOTE-9264';
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json('preview_fingerprint');
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();

    $batch = CollectionBatch::query()->sole();
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();
    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(AdminPermission::ReconciliationManage->value);
    $this->actingAs($admin)->post(route('collection-batches.remittances.store', $batch), [
        'handoff_reference' => 'AUDIT-HANDOFF-001', 'amount_ngn' => '2000.00',
        'handoff_date' => $today, 'receiving_location' => 'Lagos office',
        'source_attestation' => 'PRIVATE-ATTESTATION-5731',
        'batch_version' => $batch->fresh()->version, 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    foreach (['collection.receipt_posted' => $agent->id, 'collection.remittance_confirmed' => $admin->id] as $eventType => $actorId) {
        $event = DB::table('canonical_audit_events')->where('event_type', $eventType)->sole();
        expect((int) $event->actor_id)->toBe($actorId)
            ->and($event->content)->not->toContain('PRIVATE-CASH-NOTE-9264')
            ->not->toContain('PRIVATE-ATTESTATION-5731');
        expect(fn () => DB::table('canonical_audit_events')->where('id', $event->id)->update(['outcome' => 'Changed']))
            ->toThrow(QueryException::class);
    }
});

test('COL-AC-028/029: fee assessment failure rolls back a reviewed cash receipt', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $fees = Mockery::mock(app(FeeObligationService::class))->makePartial();
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

test('COL-AC-029: queue dispatch outage retains one posted receipt and recoverable notice', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('Queue unavailable'));

    $this->actingAs($agent)->post(route('customers.collections.store', $customer->customer_id), $payload)
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(CollectionReceipt::count())->toBe(1)
        ->and(DB::table('ledger_posting_groups')->where('event_type', 'cash_contribution')->count())->toBe(1)
        ->and(DB::table('collection_notification_intents')->where('status', 'pending')->count())->toBe(2)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
});

test('COL-AC-029/062: search outage and rebuild preserve one receipt, card and batch across replay', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json('preview_fingerprint');
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    $receipt = CollectionReceipt::query()->sole();
    $batch = CollectionBatch::query()->sole();
    DB::table('ledger_projection_state')->where('id', 1)->update(['status' => 'unavailable']);

    expect(app(LedgerTransactionReadService::class)->search($customer->user, [])['status'])->toBe('unavailable')
        ->and(app(CollectionReadService::class)->card($plan->fresh())['paid_slots'])->toBe(1)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000)
        ->and($batch->receipts()->sum('tender_amount_kobo'))->toBe(200000);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)
        ->assertRedirect(route('collections.show', $receipt));

    expect(app(LedgerTransactionReadService::class)->search($customer->user, [])['total'])->toBe(1)
        ->and(CollectionReceipt::count())->toBe(1)
        ->and(DB::table('ledger_posting_groups')->where('event_type', 'cash_contribution')->count())->toBe(1)
        ->and($batch->receipts()->count())->toBe(1);
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

test('COL-AC-007: a cross-zone receipt uses the actual instant for plan-day advance status', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-30T03:00:00Z'));
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    BusinessProfile::current()->update(['timezone' => 'America/Los_Angeles', 'version' => 2]);
    FinancialPeriod::factory()->create(['timezone' => 'America/Los_Angeles', 'month' => '2026-09-01']);
    $payload = collectionPayload($customer, $assignment, $plan, '2026-09-29', '4000.00');
    $payload['business_version'] = 2;
    $payload['received_local_time'] = '16:30';
    $payload['received_utc_offset'] = '-07:00';

    $this->actingAs($agent)->getJson(route('customers.collections.time-options', $customer->customer_id).'?'.http_build_query([
        'plan_id' => $plan->plan_id, 'received_date' => '2026-09-29', 'received_local_time' => '16:30',
    ]))->assertOk()->assertJsonPath('options.0.offset', '-07:00');
    $preview = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->assertJsonPath('received_at_utc', '2026-09-29T23:30:00+00:00')
        ->assertJsonPath('plan_received_date', '2026-09-30')->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    $receipt = CollectionReceipt::query()->sole();
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)
        ->assertRedirect(route('collections.show', $receipt));
    $payload['received_local_time'] = '16:31';
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertStatus(409);
    BusinessProfile::current()->update(['timezone' => 'Europe/London', 'version' => 3]);
    expect($receipt->received_date)->toBe('2026-09-29')
        ->and($receipt->received_at_utc->toIso8601String())->toBe('2026-09-29T23:30:00+00:00')
        ->and($receipt->batch->timezone)->toBe('America/Los_Angeles')
        ->and($receipt->batch->received_date)->toBe('2026-09-29')
        ->and($receipt->allocations()->orderBy('id')->pluck('is_advance')->all())->toBe([false, true])
        ->and($plan->fresh()->currentTermsRevision()->timezone)->toBe('Africa/Lagos')
        ->and(app(CollectionReadService::class)->card($plan->fresh())['paid_slots'])->toBe(2)
        ->and(CollectionReceipt::count())->toBe(1);
});

test('COL-AC-007: cross-zone time validation rejects gaps, invalid offsets, future instants and stale previews', function (): void {
    $receivedTimes = app(CollectionReceivedTime::class);
    expect($receivedTimes->options('2026-03-29', '01:30', 'Europe/London'))->toBe([])
        ->and(array_column($receivedTimes->options('2026-10-25', '01:30', 'Europe/London'), 'offset'))
        ->toBe(['+01:00', '+00:00']);

    $this->travelTo(CarbonImmutable::parse('2026-09-30T03:00:00Z'));
    [$agent, $customer, $assignment, $plan] = collectionFixture();
    BusinessProfile::current()->update(['timezone' => 'America/Los_Angeles', 'version' => 2]);
    FinancialPeriod::factory()->create(['timezone' => 'America/Los_Angeles', 'month' => '2026-09-01']);
    $payload = collectionPayload($customer, $assignment, $plan, '2026-09-29', '1000.00');
    $payload['business_version'] = 2;
    $payload['received_local_time'] = '16:30';
    $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['received_local_time']);
    $payload['received_utc_offset'] = '+00:00';
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['received_utc_offset']);
    $payload['received_utc_offset'] = '-07:00';
    $preview = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $payload['received_local_time'] = '16:31';
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertStatus(409);
    $payload['received_local_time'] = '20:30';
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['received_local_time']);
    expect(CollectionReceipt::count())->toBe(0);
});

test('cross-zone offset choices require the current assigned Agent and a plan owned by the Customer', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    BusinessProfile::current()->update(['timezone' => 'Europe/London', 'version' => 2]);
    $url = route('customers.collections.time-options', $customer->customer_id).'?'.http_build_query([
        'plan_id' => $plan->plan_id, 'received_date' => $today, 'received_local_time' => '12:00',
    ]);
    $this->getJson($url)->assertUnauthorized();
    $this->actingAs(User::factory()->admin()->create())->getJson($url)->assertForbidden();
    $this->actingAs(User::factory()->agent()->create())->getJson($url)->assertNotFound();
    $this->actingAs($agent)->getJson($url)->assertOk()->assertJsonCount(1, 'options');

    $sameZonePayload = collectionPayload($customer, $assignment, $plan, $today, '1000.00');
    BusinessProfile::current()->update(['timezone' => 'Africa/Lagos', 'version' => 3]);
    $sameZonePayload['received_local_time'] = '12:00';
    $sameZonePayload['received_utc_offset'] = '+01:00';
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $sameZonePayload)
        ->assertUnprocessable()->assertJsonValidationErrors(['received_local_time']);
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
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertStatus(503);
    expect(CollectionReceipt::query()->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
});

test('COL-AC-010: a real plan terms revision invalidates a reviewed cash receipt', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json('preview_fingerprint');
    $terms = [
        'name' => 'Revised daily plan', 'amount_ngn' => '1000.00',
        'start_date' => $today, 'contribution_days' => 3,
        'customer_visible_notes' => '',
        'fee_rule_id' => $plan->currentTermsRevision()->feeSnapshot->fee_rule_id,
        'fee_rule_version' => 1, 'customer_version' => $customer->version,
        'assignment_version' => $assignment->version, 'business_version' => 1,
        'plan_version' => $plan->version, 'terms_revision' => 1,
        'customer_agreement_attested' => true, 'reason' => 'Customer agreed to smaller daily slots.',
        'customer_explanation' => 'Three smaller daily slots.',
    ];
    $plans = app(ThriftPlanService::class);
    $terms['preview_fingerprint'] = $plans->previewRevision($agent, $plan, $terms)['preview_fingerprint'];
    $plans->revise($agent, $plan, (string) Str::uuid(), $terms);

    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertStatus(409);
    expect(CollectionReceipt::count())->toBe(0)
        ->and(DB::table('collection_allocations')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->where('event_type', 'cash_contribution')->count())->toBe(0);
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
    $intent = DB::table('notification_inbox_intents')->where('category', 'financial')->where('recipient_user_id', $customer->user_id)->sole();
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

test('COL-AC-010: fee settlement after preview cannot post the stale tender', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $rule = FeeRule::create([
        'version' => 1, 'name' => 'Registration cash fee', 'kind' => FeeRuleKind::Registration,
        'rule_key' => 'stale-registration', 'model' => FeeRuleModel::Fixed,
        'timing' => FeeRuleTiming::Registration, 'basis' => FeeRuleBasis::None,
        'settlement_source' => FeeSettlementSource::ExternalReceipt, 'currency' => 'NGN',
        'amount_kobo' => 50000, 'customer_description' => 'Registration fee',
        'effective_at' => now()->subDay(), 'published_by_user_id' => $agent->id,
        'publication_reason' => 'Test registration fee.',
    ]);
    $snapshot = FeeSnapshot::create([
        'customer_profile_id' => $customer->id, 'source_type' => 'registration', 'source_id' => $customer->customer_id,
        'fee_rule_id' => $rule->id, 'fee_rule_version' => 1, 'name' => 'Registration cash fee',
        'kind' => FeeRuleKind::Registration, 'model' => FeeRuleModel::Fixed,
        'timing' => FeeRuleTiming::Registration, 'basis' => FeeRuleBasis::None,
        'settlement_source' => FeeSettlementSource::ExternalReceipt, 'currency' => 'NGN',
        'amount_kobo' => 50000, 'basis_amount_kobo' => 0,
        'customer_description' => 'Registration fee', 'acknowledged_at' => now(),
    ]);
    $obligation = app(FeeObligationService::class)->assessSnapshot($snapshot, $agent);
    expect($obligation->outstandingAmountKobo())->toBe(50000)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0)
        ->and(app(CollectionReadService::class)->position($customer)['reservations_kobo'])->toBe(0);
    $stale = collectionPayload($customer, $assignment, $plan, $today, '0');
    $stale['plan_id'] = null;
    $stale['plan_version'] = null;
    $stale['fees'] = [['obligation_id' => $obligation->id, 'amount_ngn' => '500.00']];
    $stale['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $stale)
        ->assertOk()->json('preview_fingerprint');
    $settling = [...$stale, 'attempt_reference' => (string) Str::uuid()];
    $this->post(route('customers.collections.store', $customer->customer_id), $settling)->assertRedirect();

    $this->postJson(route('customers.collections.store', $customer->customer_id), $stale)
        ->assertUnprocessable()->assertJsonValidationErrors(['fees']);
    expect(CollectionReceipt::count())->toBe(1)
        ->and($obligation->fresh()->outstandingAmountKobo())->toBe(0)
        ->and(DB::table('collection_fee_components')->count())->toBe(1);
});

test('COL-AC-010: a corrected fee assessment requires review even when its balance returns to the same amount', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $rule = FeeRule::create([
        'version' => 1, 'name' => 'Cash fee', 'kind' => FeeRuleKind::Registration,
        'rule_key' => 'corrected-registration', 'model' => FeeRuleModel::Fixed,
        'timing' => FeeRuleTiming::Registration, 'basis' => FeeRuleBasis::None,
        'settlement_source' => FeeSettlementSource::ExternalReceipt, 'currency' => 'NGN',
        'amount_kobo' => 50000, 'customer_description' => 'Registration fee',
        'effective_at' => now()->subDay(), 'published_by_user_id' => $agent->id,
        'publication_reason' => 'Test registration fee.',
    ]);
    $snapshot = FeeSnapshot::create([
        'customer_profile_id' => $customer->id, 'source_type' => 'registration', 'source_id' => $customer->customer_id,
        'fee_rule_id' => $rule->id, 'fee_rule_version' => 1, 'name' => 'Cash fee',
        'kind' => FeeRuleKind::Registration, 'model' => FeeRuleModel::Fixed,
        'timing' => FeeRuleTiming::Registration, 'basis' => FeeRuleBasis::None,
        'settlement_source' => FeeSettlementSource::ExternalReceipt, 'currency' => 'NGN',
        'amount_kobo' => 50000, 'basis_amount_kobo' => 0,
        'customer_description' => 'Registration fee', 'acknowledged_at' => now(),
    ]);
    $obligation = app(FeeObligationService::class)->assessSnapshot($snapshot, $agent);
    $payload = collectionPayload($customer, $assignment, $plan, $today, '0');
    $payload['plan_id'] = null;
    $payload['plan_version'] = null;
    $payload['fees'] = [['obligation_id' => $obligation->id, 'amount_ngn' => '500.00']];
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json('preview_fingerprint');

    foreach ([FeeObligationEntryType::AssessmentCorrectionIncrease, FeeObligationEntryType::AssessmentCorrection] as $entryType) {
        FeeObligationEntry::create([
            'fee_obligation_id' => $obligation->id, 'entry_type' => $entryType,
            'amount_kobo' => 100, 'currency' => 'NGN', 'source_type' => 'test_correction',
            'source_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(),
            'actor_user_id' => $agent->id, 'reason' => 'Correct fee terms.',
        ]);
    }
    expect($obligation->fresh()->outstandingAmountKobo())->toBe(50000);
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertStatus(409);
    expect(CollectionReceipt::query()->count())->toBe(0)
        ->and(DB::table('collection_fee_components')->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(0);
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

test('COL-AC-018: the card keeps paid partial missed blocked and pending days distinct through pause and resume', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(5, -3);
    $slots = $plan->slots()->orderBy('active_ordinal')->get();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '3000.00');
    $payload['allocations'] = [
        ['slot_id' => $slots[0]->id, 'amount_ngn' => '2000.00'],
        ['slot_id' => $slots[1]->id, 'amount_ngn' => '1000.00'],
    ];
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json('preview_fingerprint');
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    $initial = app(CollectionReadService::class)->card($plan->fresh());
    expect(array_column($initial['slots'], 'status'))->toBe(['paid', 'partial', 'missed', 'pending', 'pending']);

    $plan->update(['status' => ThriftPlanStatus::Paused, 'version' => 3]);
    PlanLifecycleEvent::create([
        'thrift_plan_id' => $plan->id, 'event_type' => 'pause',
        'from_status' => ThriftPlanStatus::Active, 'to_status' => ThriftPlanStatus::Paused,
        'actor_user_id' => $agent->id, 'assignment_version' => $assignment->version,
        'plan_version' => 3, 'reason' => 'Customer requested a pause.',
        'customer_explanation' => 'Plan paused.', 'payload' => [], 'effective_at' => now(),
    ]);
    expect(array_column(app(CollectionReadService::class)->card($plan->fresh())['slots'], 'status'))
        ->toBe(['paid', 'partial', 'missed', 'blocked', 'blocked']);

    $this->travel(1)->days();
    $plan->update(['status' => ThriftPlanStatus::Active, 'version' => 4]);
    PlanLifecycleEvent::create([
        'thrift_plan_id' => $plan->id, 'event_type' => 'resume',
        'from_status' => ThriftPlanStatus::Paused, 'to_status' => ThriftPlanStatus::Active,
        'actor_user_id' => $agent->id, 'assignment_version' => $assignment->version,
        'plan_version' => 4, 'reason' => 'Customer resumed the plan.',
        'customer_explanation' => 'Plan resumed.', 'payload' => [], 'effective_at' => now(),
    ]);
    $resumed = app(CollectionReadService::class)->card($plan->fresh());
    expect(array_column($resumed['slots'], 'status'))->toBe(['paid', 'partial', 'missed', 'blocked', 'pending'])
        ->and($resumed['paid_slots'])->toBe(1)
        ->and($resumed['funded_kobo'])->toBe(300000);
});

test('COL-AC-047: pending receipt correction blocks cash batch and financial month closure', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T10:00:00Z'));
    [$agent, $customer, $assignment, $plan] = collectionFixture(2, -3);
    $payload = collectionPayload($customer, $assignment, $plan, '2026-09-30', '2000.00');
    $payload['late_reason'] = 'Cash received during field visit.';
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json('preview_fingerprint');
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    $receipt = CollectionReceipt::query()->sole();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $batch = CollectionBatch::query()->sole();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::ReconciliationManage->value, AdminPermission::FinancialPeriodsManage->value]);
    $this->actingAs($admin)->post(route('collection-batches.remittances.store', $batch), [
        'handoff_reference' => 'PENDING-CORRECTION-HANDOFF', 'amount_ngn' => '2000.00',
        'handoff_date' => '2026-10-03', 'receiving_location' => 'Lagos office',
        'source_attestation' => 'Counted and received cash.', 'batch_version' => $batch->version,
        'confirmed' => true,
    ])->assertRedirect();

    $createCorrection = static fn (): ReversalRequest => ReversalRequest::create([
        'reversal_id' => (string) Str::uuid(), 'customer_profile_id' => $customer->id,
        'original_posting_group_id' => $receipt->savings_posting_group_id,
        'live_original_posting_group_id' => $receipt->savings_posting_group_id,
        'requested_by_user_id' => $agent->id, 'initiating_agent_profile_id' => $assignment->agent_profile_id,
        'assignment_id' => $assignment->id, 'state' => 'pending_review', 'version' => 1,
        'reason_category' => 'duplicate_posting', 'internal_reason' => 'Review cash receipt.',
        'customer_explanation' => 'We are reviewing this receipt.', 'evidence_text' => 'Cash count disputed.',
        'dependency_fingerprint' => str_repeat('b', 64),
        'dependency_snapshot' => ['summary' => [], 'dependencies' => []],
        'original_amount_kobo' => 200000, 'currency' => 'NGN',
    ]);
    $correction = $createCorrection();
    $review = ['batch_version' => $batch->fresh()->version, 'reason' => 'Counted cash matches.', 'confirmed' => true];
    $this->post(route('collection-batches.review', $batch), $review)->assertStatus(409);
    expect(DB::table('collection_batch_reviews')->count())->toBe(0);

    $correction->update(['state' => 'rejected', 'live_original_posting_group_id' => null]);
    $this->post(route('collection-batches.review', $batch), $review)->assertRedirect();
    expect($batch->fresh()->status)->toBe('reconciled');
    $createCorrection();
    $period = FinancialPeriod::query()->where('timezone', 'Africa/Lagos')->whereDate('month', '2026-09-01')->sole();
    $this->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp,
        'auth.mfa_confirmed_at' => now()->timestamp])->post(route('admin.financial-periods.close', '2026-09'), [
            'version' => $period->version, 'reason' => 'Close reviewed month.',
        ])->assertStatus(409);
    expect($period->fresh()->status)->toBe('open');
});

test('COL-AC-057: a suspended recording Agent cannot collect while Admin settles historical custody', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json('preview_fingerprint');
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    $batch = CollectionBatch::query()->sole();
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();

    $agent->forceFill(['account_state' => AccountState::Suspended])->save();
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertRedirect(route('login'));
    $manager = User::factory()->admin()->create();
    $manager->givePermissionTo(AdminPermission::ReconciliationManage->value);
    $this->actingAs($manager)->get(route('collection-batches.show', $batch))->assertOk();
    $this->post(route('collection-batches.remittances.store', $batch), [
        'handoff_reference' => 'SUSPENDED-AGENT-HANDOFF', 'amount_ngn' => '2000.00',
        'handoff_date' => $today, 'receiving_location' => 'Lagos office',
        'source_attestation' => 'Counted cash from historical Agent custody.',
        'batch_version' => $batch->fresh()->version, 'confirmed' => true,
    ])->assertRedirect();
    $this->post(route('collection-batches.review', $batch), [
        'batch_version' => $batch->fresh()->version, 'reason' => 'Historical cash settled.', 'confirmed' => true,
    ])->assertRedirect();

    expect($batch->fresh()->status)->toBe('reconciled')
        ->and(app(CollectionReadService::class)->agentOffboardingStatus($agent->agentProfile))->toBe('passed')
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000)
        ->and(CollectionReceipt::count())->toBe(1);
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
    $admin = User::factory()->admin()->withTwoFactor()->create();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $adminWork = app(CollectionWorkspaceService::class)->dueWork($admin, $today, $today, '', 'all');
    $adminQueries = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($adminWork['totals']['slot_count'])->toBe(26)
        ->and($adminWork['totals']['outstanding_kobo'])->toBe(5200000)
        ->and($adminWork['totals']['service_interrupted_target_kobo'])->toBe(0)
        ->and($adminWork['slots']->count())->toBe(25)
        ->and($adminQueries)->toBeLessThanOrEqual(8);
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

    $attemptUrl = route('collections.attempts.show', [$payload['attempt_reference'], 'customer' => $customer->customer_id]);
    $this->getJson($attemptUrl)->assertOk()
        ->assertJsonPath('status', 'posted');
    $assignment->update(['status' => CustomerAssignmentStatus::Ended]);
    $this->getJson($attemptUrl)->assertNotFound();
    $this->getJson(route('collections.attempts.show', [Str::uuid(), 'customer' => $customer->customer_id]))
        ->assertNotFound()->assertJsonMissing(['status' => 'unresolved']);
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertNotFound();
    expect(CollectionReceipt::query()->count())->toBe(1)
        ->and(DB::table('ledger_posting_groups')->where('event_type', 'cash_contribution')->count())->toBe(1);
});

test('COL-AC-033: unresolved lookup permits the original receipt attempt without duplicate posting', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '1000.00');
    $this->actingAs($agent);
    $payload['preview_fingerprint'] = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json('preview_fingerprint');

    $attemptUrl = route('collections.attempts.show', [$payload['attempt_reference'], 'customer' => $customer->customer_id]);
    $this->getJson($attemptUrl)
        ->assertNotFound()->assertJsonPath('status', 'unresolved');
    expect(CollectionReceipt::query()->count())->toBe(0)
        ->and(DB::table('ledger_posting_groups')->where('event_type', 'cash_contribution')->count())->toBe(0);

    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    $receipt = CollectionReceipt::query()->sole();
    $this->getJson($attemptUrl)
        ->assertOk()->assertJsonPath('status', 'posted')
        ->assertJsonPath('receipt_reference', $receipt->receipt_reference);
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)
        ->assertRedirect(route('collections.show', $receipt));

    expect(CollectionReceipt::query()->count())->toBe(1)
        ->and(DB::table('collection_allocations')->count())->toBe(1)
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
    $replacementAssignment = CustomerAssignment::factory()->create([
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
    $this->post(route('collection-batches.remittances.store', $batch), [
        ...$handoff, 'amount_ngn' => '500.00', 'batch_version' => $batch->fresh()->version,
    ])->assertStatus(409);
    expect(DB::table('cash_remittances')->count())->toBe(1);
    $agentBalances = DB::table('ledger_entries as entries')
        ->join('ledger_accounts as accounts', 'accounts.id', '=', 'entries.ledger_account_id')
        ->where('accounts.code', 'agent_receivable_ngn')
        ->selectRaw("entries.agent_profile_id, SUM(CASE WHEN entries.side = 'debit' THEN entries.amount_kobo ELSE -entries.amount_kobo END) as balance")
        ->groupBy('entries.agent_profile_id')->pluck('balance', 'entries.agent_profile_id');
    expect((int) $agentBalances[$assignment->agent_profile_id])->toBe(100000)
        ->and($agentBalances->has($replacementProfile->id))->toBeFalse()
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);

    $replacementReceipt = collectionPayload($customer->fresh(), $replacementAssignment, $plan->fresh(), $today, '2000.00');
    $replacementPreview = $this->actingAs($replacement)->postJson(route('customers.collections.preview', $customer->customer_id), $replacementReceipt)
        ->assertOk()->json();
    $replacementReceipt['preview_fingerprint'] = $replacementPreview['preview_fingerprint'];
    $this->post(route('customers.collections.store', $customer->customer_id), $replacementReceipt)->assertRedirect();
    $replacementBatch = CollectionBatch::query()->where('agent_profile_id', $replacementProfile->id)->sole();
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();
    $this->actingAs($manager)->post(route('collection-batches.remittances.store', $replacementBatch), [
        ...$handoff, 'batch_version' => $replacementBatch->fresh()->version,
    ])->assertStatus(409);
    expect(DB::table('cash_remittances')->count())->toBe(1)
        ->and($replacementBatch->fresh()->remittances()->count())->toBe(0);
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
        ->and($exception->fresh()->status)->toBe('reopened')
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

test('COL-AC-046: earnings and pending payouts cannot offset original Agent cash custody', function (): void {
    Queue::fake();
    config()->set(['withdrawals.cash_enabled' => true, 'withdrawals.cash_certified' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    $fee = reportFeeObligation($agent, $customer);
    $data = [...collectionPayload($customer, $assignment, $plan, $date, '2000.00'),
        'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '500.00']]];
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $data);
    $withdrawals = app(WithdrawalService::class);
    $instruction = [...withdrawalPayload($customer, $assignment, $plan), 'gross_ngn' => '1500.00',
        'destination_reference' => 'customer:'.$customer->id];
    $quote = $withdrawals->preview($agent, $customer, $instruction);
    $withdrawal = $withdrawals->submit($agent, $customer, [...$instruction, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'],
        'instruction_attested' => true, 'confirmed' => true]);
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();
    $batch = $receipt->batch->fresh();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::ReconciliationManage);
    $handoff = ['handoff_reference' => 'CASH-WITHOUT-OFFSETS', 'amount_ngn' => '500.00', 'handoff_date' => $date,
        'receiving_location' => 'Verified business till', 'source_attestation' => 'Counted only five hundred naira.',
        'batch_version' => $batch->version, 'confirmed' => true];
    $baseline = [];
    foreach (['collection_receipts', 'collection_batches', 'collection_allocations', 'ledger_posting_groups', 'ledger_entries',
        'fee_obligation_entries', 'withdrawal_requests', 'withdrawal_reservations', 'cash_remittances'] as $table) {
        $baseline[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $this->actingAs($admin);
    foreach (['earnings_offset_ngn' => '500.00', 'withdrawal_request_id' => $withdrawal->id,
        'other_agent_profile_id' => $assignment->agent_profile_id + 1, 'netting' => true,
        'offsets' => ['earnings_ngn' => '500.00', 'pending_payout_ngn' => '1500.00']] as $field => $claim) {
        $this->postJson(route('collection-batches.remittances.store', $batch), [...$handoff, $field => $claim])
            ->assertUnprocessable()->assertInvalid([$field]);
        foreach ($baseline as $table => $rows) {
            expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
        }
    }
    $this->post(route('collection-batches.remittances.store', $batch), $handoff)->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('collection-batches.remittances.store', $batch), $handoff)->assertRedirect()->assertSessionHasNoErrors();
    expect(app(CollectionBatchPosition::class)->read($batch->fresh())['outstanding_kobo'])->toBe(200000)
        ->and(app(CollectionReadService::class)->position($customer))->toBe(['liability_kobo' => 200000, 'reservations_kobo' => 150000, 'available_kobo' => 50000])
        ->and(app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome))->toBe(50000)
        ->and($fee->fresh()->settledAmountKobo())->toBe(50000)
        ->and($withdrawal->fresh()->state)->toBe('pending_review');
    $this->assertDatabaseCount('cash_remittances', 1);
    $this->post(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'Earnings and a pending payout are not cash remittance.', 'confirmed' => true])->assertRedirect();
    expect($batch->fresh()->status)->toBe('exception')
        ->and((int) DB::table('collection_batch_reviews')->where('collection_batch_id', $batch->id)->orderByDesc('id')->value('outstanding_kobo'))->toBe(200000);
});
