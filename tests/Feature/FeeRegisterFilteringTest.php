<?php

use App\Enums\AdminPermission;
use App\Models\AgentProfile;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeRule;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\CustomerReassignmentService;
use App\Services\LedgerTransactionProjectionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../FeeFixtures.php';
require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../CashExecutionFixtures.php';

/** @return array{User, User} */
function feeRegisterActors(): array
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $agent = User::factory()->agent()->withTwoFactor()->create();
    AgentProfile::factory()->active()->create(['user_id' => $agent->id]);

    return [$admin, $agent];
}

function feeRegisterAssessment(User $agent): FeeObligation
{
    $customer = CustomerProfile::factory()->create();
    CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id,
        'agent_profile_id' => $agent->agentProfile->id, 'assigned_by_user_id' => $agent->id]);

    return reportFeeObligation($agent, $customer, 10000, ((int) FeeRule::query()->where('kind', 'registration')->max('version')) + 1);
}

test('fee register savings-return history follows an actual retained-fee concession', function (): void {
    config()->set('fees.refunds_enabled', true);
    [$admin, $customer, , $withdrawal] = cashPaymentFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), [
        'evidence' => 'Verified full Customer handoff.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), [
        'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $fee = FeeObligation::query()->sole();
    $this->actingAs($admin)->withSession(cashSession())->post(route('admin.fees.refunds.store', $fee), [
        'refund_reference' => (string) Str::uuid(), 'kind' => 'savings', 'amount_ngn' => '1.00',
        'reason' => 'Approved retained savings fee concession.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->get(route('admin.fees.index', ['refund_status' => 'savings_returned']))->assertInertia(fn (Assert $page) => $page
        ->where('summary.obligation_count', 1)->where('summary.outstanding_amount_kobo', 0)
        ->where('obligations.data.0.id', $fee->id)->where('obligations.data.0.settled_amount_kobo', 600));
    $this->get(route('admin.fees.index', ['refund_status' => 'external_entitlement']))->assertInertia(fn (Assert $page) => $page
        ->where('summary.obligation_count', 0)->has('obligations.data', 0));
    $this->get(route('admin.fees.index', ['refund_status' => 'none']))->assertInertia(fn (Assert $page) => $page
        ->where('summary.obligation_count', 0)->has('obligations.data', 0));
    $this->assertDatabaseCount('fee_refunds', 1);
    $this->assertDatabaseCount('cash_disbursements', 0);
});

test('fee register pages retain equal-time ordering and full filtered outstanding across page sizes', function (int $size): void {
    $this->freezeTime();
    [$admin, $agent] = feeRegisterActors();
    $fees = collect(range(1, 26))->map(fn (): FeeObligation => feeRegisterAssessment($agent));
    $otherAgent = User::factory()->agent()->withTwoFactor()->create();
    AgentProfile::factory()->active()->create(['user_id' => $otherAgent->id]);
    feeRegisterAssessment($otherAgent);
    $query = ['current_agent' => $agent->agentProfile->agent_id, 'kind' => 'registration', 'per_page' => $size];

    $response = $this->actingAs($admin)->get(route('admin.fees.index', $query))->assertInertia(fn (Assert $page) => $page
        ->where('summary.obligation_count', 26)->where('summary.outstanding_amount_kobo', 260000)
        ->where('summary.pending_count', 26)->where('summary.obligation_totals_status', 'available')
        ->where('obligations.total', 26)->where('obligations.per_page', $size)
        ->has('obligations.data', min($size, 26))->where('obligations.data.0.id', $fees->last()->id));
    if ($size === 25) {
        $url = $response->viewData('page')['props']['obligations']['next_page_url'];
        $this->get($url)->assertInertia(fn (Assert $page) => $page->has('obligations.data', 1)
            ->where('obligations.data.0.id', $fees->first()->id)->where('summary.outstanding_amount_kobo', 260000)
            ->where('filters.current_agent', $agent->agentProfile->agent_id)->where('filters.kind', 'registration'));
    }
    $this->get(route('admin.fees.index', [...$query, 'sort' => 'oldest']))->assertInertia(fn (Assert $page) => $page
        ->where('obligations.data.0.id', $fees->first()->id));
})->with([25, 50, 100]);

test('assessment dates use inclusive Lagos days while committed business earnings remain unfiltered', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00:00', 'UTC'));
    [$admin, $agent] = feeRegisterActors();
    $fees = collect(range(1, 4))->map(fn (): FeeObligation => feeRegisterAssessment($agent));
    foreach (['2026-10-02 22:59:59', '2026-10-02 23:00:00', '2026-10-03 22:59:59', '2026-10-03 23:00:00'] as $index => $date) {
        DB::table('fee_obligations')->where('id', $fees[$index]->id)->update(['created_at' => $date]);
    }
    config()->set('collections.enabled', true);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    FinancialPeriod::factory()->create(['month' => '2026-10-01']);
    $customer = $fees[0]->customerProfile;
    $data = ['attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => 1, 'plan_id' => null,
        'plan_version' => null, 'received_date' => '2026-10-03', 'savings_ngn' => '0.00',
        'fees' => [['obligation_id' => $fees[0]->id, 'amount_ngn' => '100.00']], 'allocations' => [],
        'late_reason' => '', 'notes' => '', 'confirmed' => true];
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $data);

    $this->actingAs($admin)->get(route('admin.fees.index', ['date_from' => '2026-10-03', 'date_to' => '2026-10-03']))
        ->assertInertia(fn (Assert $page) => $page->has('obligations.data', 2)->where('summary.outstanding_amount_kobo', 20000)
            ->where('obligations.data.0.id', $fees[2]->id)->where('obligations.data.1.id', $fees[1]->id)
            ->where('summary.earnings.lifetime_net_kobo', 10000)->where('summary.earnings.status', 'available'));
});

test('current assignment filtering changes after actual handover while original assessment Agent stays retained', function (): void {
    [$admin, $agent] = feeRegisterActors();
    $fee = feeRegisterAssessment($agent);
    $admin->givePermissionTo(AdminPermission::CustomersReassign);
    $successor = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $customer = $fee->customerProfile;
    $handover = app(CustomerReassignmentService::class);
    $review = $handover->preview($admin, $customer, $successor->id);
    $handover->execute($admin, $customer, [...$review, 'attempt_reference' => (string) Str::uuid(), 'target_agent_id' => $successor->id,
        'reason' => 'Service handover preserves the assessment actor.', 'customer_explanation' => 'Your new Agent follows up.', 'confirmed' => true]);

    $this->actingAs($admin)->get(route('admin.fees.index', ['current_agent' => $agent->agentProfile->agent_id]))
        ->assertInertia(fn (Assert $page) => $page->where('obligations.total', 0)->where('summary.outstanding_amount_kobo', 0));
    $this->get(route('admin.fees.index', ['current_agent' => $successor->agent_id, 'original_agent' => $agent->agentProfile->agent_id]))
        ->assertInertia(fn (Assert $page) => $page->where('obligations.total', 1)->where('obligations.data.0.id', $fee->id));
});

test('cycle model source currency and receipt batch filters use actual cycle assessment and custody', function (): void {
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(1, feeAmountKobo: 10000);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $data = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $data);
    $fee = $plan->currentTermsRevision()->feeSnapshot->obligation;
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    reportFeeObligation($agent, $customer, 10000);
    $query = ['cycle' => $plan->plan_id, 'customer' => $customer->customer_id, 'kind' => 'plan', 'model' => 'fixed',
        'source' => 'plan', 'currency' => 'NGN', 'status' => 'settled', 'refund_status' => 'none', 'reconciliation_status' => 'none'];

    $this->actingAs($admin)->get(route('admin.fees.index', $query))->assertInertia(fn (Assert $page) => $page
        ->where('obligations.total', 1)->where('obligations.data.0.id', $fee->id)->where('summary.outstanding_amount_kobo', 0)
        ->where('obligations.data.0.formatted_settled_amount', '₦100.00')->where('obligations.data.0.formatted_waived_amount', '₦0.00'));
    $this->get(route('admin.fees.index', [...$query, 'reconciliation_status' => 'open']))->assertInertia(fn (Assert $page) => $page
        ->where('obligations.total', 0));
});

test('unavailable fee history retains a labelled row and null totals instead of guessed zero', function (): void {
    [$admin, $agent] = feeRegisterActors();
    $valid = feeRegisterAssessment($agent);
    $broken = feeRegisterAssessment($agent);
    DB::table('fee_obligation_entries')->where('fee_obligation_id', $broken->id)->update(['currency' => 'USD']);

    $this->actingAs($admin)->get(route('admin.fees.index'))->assertInertia(fn (Assert $page) => $page
        ->where('summary.obligation_totals_status', 'unavailable')->where('summary.outstanding_amount_kobo', null)
        ->where('summary.formatted_outstanding_amount', null)->where('summary.unavailable_count', 1)
        ->where('obligations.data.0.status', 'unavailable')->where('obligations.data.0.amount_kobo', null)
        ->where('obligations.data.0.can_waive', false)->where('obligations.data.0.can_apply_savings', false));
    $this->get(route('admin.fees.index', ['status' => 'unavailable']))->assertInertia(fn (Assert $page) => $page
        ->where('obligations.total', 1)->where('obligations.data.0.id', $broken->id));
    $this->get(route('admin.fees.index', ['status' => 'pending']))->assertInertia(fn (Assert $page) => $page
        ->where('obligations.total', 1)->where('obligations.data.0.id', $valid->id)->where('summary.outstanding_amount_kobo', 10000));
});

test('fee register rejects unsupported filter inputs with 422', function (string $field, mixed $value): void {
    [$admin] = feeRegisterActors();

    $this->actingAs($admin)->getJson(route('admin.fees.index', [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['status' => ['status', 'invented'], 'model' => ['model', 'unlimited'], 'kind' => ['kind', 'deduction'],
    'source' => ['source', 'provider_guess'], 'currency' => ['currency', 'USD'], 'refund' => ['refund_status', 'cash_paid'],
    'reconciliation' => ['reconciliation_status', 'unknown'], 'sort' => ['sort', 'created_at desc; DROP TABLE users'],
    'page size' => ['per_page', 10], 'date' => ['date_from', '2026-02-30'], 'page' => ['page', -1]]);

test('fee register date errors explain an invalid range and no-match inputs remain empty', function (): void {
    [$admin, $agent] = feeRegisterActors();
    feeRegisterAssessment($agent);

    $this->actingAs($admin)->getJson(route('admin.fees.index', ['date_from' => '2026-10-05', 'date_to' => '2026-10-03']))
        ->assertUnprocessable()->assertJsonPath('errors.date_to.0', 'Choose an end date on or after the start date.');
    $this->get(route('admin.fees.index', ['customer' => "missing' OR 1=1 --"]))->assertInertia(fn (Assert $page) => $page
        ->where('obligations.total', 0)->where('summary.outstanding_amount_kobo', 0));
});

test('fee register returns 403 for roles or grants without fee management', function (string $role): void {
    $actor = match ($role) {
        'Customer' => User::factory()->customer()->create(), 'Agent' => User::factory()->agent()->withTwoFactor()->create(),
        default => User::factory()->admin()->withTwoFactor()->create()
    };

    $this->actingAs($actor)->getJson(route('admin.fees.index'))->assertForbidden();
})->with(['Customer', 'Agent', 'ungranted Admin']);

test('derived waiver and corrected cancellation filters follow actual immutable disposition entries', function (string $operation, string $amount, string $status, int $outstanding): void {
    [$admin, $agent] = feeRegisterActors();
    $fee = feeRegisterAssessment($agent);
    $other = feeRegisterAssessment($agent);
    $payload = ['attempt_reference' => (string) Str::uuid(), 'amount_ngn' => $amount,
        'reason' => 'Reviewed original fee disposition.', 'customer_description' => 'Your agreed fee disposition is recorded.'];
    if ($operation === 'correct') {
        $payload['direction'] = 'reduce';
    }
    $this->actingAs($admin)->withSession(cashSession())->post(route('admin.fees.obligations.'.$operation, $fee), $payload)
        ->assertRedirect()->assertSessionHasNoErrors();

    $this->get(route('admin.fees.index', ['status' => $status]))->assertInertia(fn (Assert $page) => $page
        ->where('obligations.total', 1)->where('obligations.data.0.id', $fee->id)
        ->where('obligations.data.0.status', $status)->where('summary.outstanding_amount_kobo', $outstanding));
    $this->get(route('admin.fees.index', ['status' => 'pending']))->assertInertia(fn (Assert $page) => $page
        ->where('obligations.total', 1)->where('obligations.data.0.id', $other->id));
})->with(['partial waiver' => ['waive', '20.00', 'partially_settled', 8000],
    'full waiver' => ['waive', '100.00', 'waived', 0], 'full correction' => ['correct', '100.00', 'cancelled', 0]]);

test('actual external fee custody progresses through batch filters and recorded refund entitlement without claiming cash paid', function (): void {
    config()->set(['collections.enabled' => true, 'fees.refunds_enabled' => true]);
    $this->freezeTime();
    [$admin, $agent] = feeRegisterActors();
    $admin->givePermissionTo(AdminPermission::ReconciliationManage);
    $fee = feeRegisterAssessment($agent);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    $customer = $fee->customerProfile;
    $date = now('Africa/Lagos')->toDateString();
    $data = ['attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => 1, 'plan_id' => null,
        'plan_version' => null, 'received_date' => $date, 'savings_ngn' => '0.00',
        'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '100.00']], 'allocations' => [],
        'late_reason' => '', 'notes' => '', 'confirmed' => true];
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $data);

    $this->actingAs($admin)->get(route('admin.fees.index', ['reconciliation_status' => 'open', 'status' => 'settled']))
        ->assertInertia(fn (Assert $page) => $page->where('obligations.total', 1)->where('obligations.data.0.id', $fee->id));
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();
    $batch = $receipt->batch;
    expect($batch->fresh()->status)->toBe('ready_for_review');
    $this->get(route('admin.fees.index', ['reconciliation_status' => 'ready_for_review']))->assertInertia(fn (Assert $page) => $page
        ->where('obligations.total', 1));
    $this->withSession(cashSession())->post(route('collection-batches.remittances.store', $batch), [
        'batch_version' => $batch->fresh()->version, 'handoff_reference' => 'FILTER-CUSTODY', 'amount_ngn' => '100.00',
        'handoff_date' => $date, 'receiving_location' => 'Business till', 'source_attestation' => 'Counted original fee tender.', 'confirmed' => true])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'Independent review confirms fee tender.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('admin.fees.index', ['reconciliation_status' => 'reconciled']))->assertInertia(fn (Assert $page) => $page
        ->where('obligations.total', 1));
    $this->withSession(cashSession())->post(route('admin.fees.refunds.store', $fee), ['refund_reference' => (string) Str::uuid(),
        'kind' => 'external', 'amount_ngn' => '20.00', 'reason' => 'Reviewed retained external fee concession.', 'confirmed' => true])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('admin.fees.index', ['refund_status' => 'external_entitlement']))->assertInertia(fn (Assert $page) => $page
        ->where('obligations.total', 1)->where('summary.refund_payable.amount_kobo', 2000));
    app(LedgerTransactionProjectionService::class)->rebuild();
    $this->get(route('reports.show', 'exceptions'))->assertInertia(fn (Assert $page) => $page
        ->where('report.sections.refund_payables.status', 'Partial')
        ->where('report.sections.refund_payables.total', 1)
        ->where('report.sections.refund_payables.rows.0.customer', $customer->customer_id)
        ->where('report.sections.refund_payables.metrics', fn ($metrics) => collect($metrics)->firstWhere('code', 'refund_payable')['value'] === 2000));
    $this->get(route('reports.show', 'fees'))->assertInertia(fn (Assert $page) => $page
        ->where('report.sections.fee_refunds.total', 1)
        ->where('report.sections.fee_refunds.rows.0.kind', 'external')
        ->where('report.sections.fee_refunds.metrics', fn ($metrics) => collect($metrics)->firstWhere('code', 'fee_activity_amount')['value'] === 2000));
    $this->actingAs(CustomerProfile::factory()->create()->user)->get(route('reports.show', 'exceptions'))->assertInertia(fn (Assert $page) => $page
        ->where('report.sections.refund_payables.total', 0));
    $this->actingAs($admin);
    $this->get(route('admin.fees.index', ['refund_status' => 'none']))->assertInertia(fn (Assert $page) => $page
        ->where('obligations.total', 0));
    $this->assertDatabaseCount('cash_disbursements', 0);
});
