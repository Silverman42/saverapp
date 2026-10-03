<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Enums\ThriftPlanStatus;
use App\Models\AgentProfile;
use App\Models\AuditEvent;
use App\Models\CashExecution;
use App\Models\CustomerProfile;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\CustomerReassignmentService;
use App\Services\CustomerStatusManagementService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\PlanSettlementService;
use App\Services\ThriftPlanService;
use App\Services\WithdrawalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\CreatesLifecycleCustomers;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../WithdrawalFixtures.php';
require_once __DIR__.'/../ReversalFixtures.php';
require_once __DIR__.'/../CashExecutionFixtures.php';

uses(CreatesLifecycleCustomers::class);

beforeEach(function (): void {
    config()->set(['collections.enabled' => true, 'collections.settlement_enabled' => true,
        'collections.receipt_corrections_enabled' => true]);
});

/** @return array<string, array<int, object>> */
function restrictedEndingFinancialRows(): array
{
    $rows = [];
    foreach (['plan_terms_revisions', 'contribution_slots', 'fee_snapshots', 'fee_obligations',
        'fee_obligation_entries', 'collection_receipts', 'ledger_posting_groups', 'ledger_entries',
        'withdrawal_requests', 'withdrawal_reservations', 'cash_executions', 'cash_disbursements'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

/** @return array{User, User, CustomerProfile, ThriftPlan} */
function restrictedFundedClosureFixture(object $test, string $payout = '1900.00'): array
{
    [$actor, $customer, $assignment, $plan, $date] = collectionFixture(1, feeAmountKobo: 10000);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::cases());
    $collection = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $collection['preview_fingerprint'] = app(CollectionService::class)->preview($actor, $customer, $collection)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($actor, $customer, $collection);
    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Completed)
        ->and($plan->currentTermsRevision()->feeSnapshot->obligation->outstandingAmountKobo())->toBe(0);
    $test->travel(1)->days();
    $test->artisan('collections:freeze-batches')->assertSuccessful();
    $test->travelBack();
    $batch = $receipt->batch;
    $test->actingAs($admin)->withSession(cashSession())->post(route('collection-batches.remittances.store', $batch), [
        'batch_version' => $batch->fresh()->version, 'handoff_reference' => 'RESTRICTED-CYCLE-'.$batch->id,
        'amount_ngn' => '2000.00', 'handoff_date' => $date, 'receiving_location' => 'Business till',
        'source_attestation' => 'Counted original Customer tender.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    $test->post(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'Independent review reconciles original custody.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($batch->fresh()->status)->toBe('reconciled');
    app(LedgerTransactionProjectionService::class)->rebuild();
    enableFixtureMethod();
    $instruction = [...withdrawalPayload($customer, $assignment, $plan->fresh()), 'gross_ngn' => $payout,
        'type' => $payout === '1900.00' ? 'end_of_cycle' : 'partial'];
    $quote = app(WithdrawalService::class)->preview($actor, $customer->fresh(), $instruction);
    $withdrawal = app(WithdrawalService::class)->submit($actor, $customer, [...$instruction,
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'quote_expires_at' => $quote['quote_expires_at'], 'customer_version' => $quote['customer_version'],
        'assignment_version' => $quote['assignment_version'], 'plan_version' => $quote['plan_version'],
        'business_version' => $quote['business_version'], 'instruction_attested' => true, 'confirmed' => true]);
    $test->actingAs($admin)->withSession(cashSession())->post(route('withdrawals.approve', $withdrawal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->version, 'confirmed' => true,
        'decision_note' => 'Reviewed original settled funds.'])->assertRedirect()->assertSessionHasNoErrors();
    $test->post(route('withdrawals.cash.start', $withdrawal), ['execution_reference' => (string) Str::uuid(),
        'version' => $withdrawal->fresh()->version, 'evidence' => 'Verified Customer at business till.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    $execution = CashExecution::query()->where('withdrawal_request_id', $withdrawal->id)->sole();
    $test->post(route('cash-executions.handoff', $execution), ['evidence' => 'Counted exact net payout to Customer.', 'confirmed' => true])->assertRedirect();
    $test->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    expect($execution->fresh()->status)->toBe('posted');
    app(CustomerStatusManagementService::class)->transition($admin, $customer->fresh(), CustomerStatus::Restricted, $customer->fresh()->version,
        'Restricted after financial settlement review.', 'Your participation is restricted; settled funds remain unchanged.');

    return [$actor, $admin, $customer->fresh(), $plan->fresh()];
}

test('assigned Agent cancels an unused Restricted cycle without financial effects and replays its original audit', function (string $state): void {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $plan->update(['status' => $state]);
    $customer->update(['operational_status' => CustomerStatus::Restricted]);
    $data = ['attempt_reference' => (string) Str::uuid(), 'plan_version' => $plan->version,
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'reason' => 'Restricted unused cycle cancellation approved.', 'customer_explanation' => 'Your unused cycle is cancelled; your restriction remains.'];
    $financial = restrictedEndingFinancialRows();

    $this->actingAs($agent->user)->post(route('plans.cancel', $plan), $data)->assertRedirect();
    $events = $plan->lifecycleEvents()->orderBy('id')->get()->map->getAttributes()->all();
    $auditCount = AuditEvent::query()->where('event_type', 'thrift_plan.cancel')->count();
    $this->post(route('plans.cancel', $plan), $data)->assertRedirect();

    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Cancelled)
        ->and($plan->fresh()->open_customer_profile_id)->toBeNull()
        ->and($customer->fresh()->operational_status)->toBe(CustomerStatus::Restricted)
        ->and(restrictedEndingFinancialRows())->toEqual($financial)
        ->and($plan->lifecycleEvents()->get()->map->getAttributes()->all())->toEqual($events)
        ->and($auditCount)->toBe(1)
        ->and(AuditEvent::query()->where('event_type', 'thrift_plan.cancel')->count())->toBe($auditCount)
        ->and($plan->lifecycleEvents()->sole()->reason)->toBe($data['reason'])
        ->and($plan->lifecycleEvents()->sole()->customer_explanation)->toBe($data['customer_explanation'])
        ->and($plan->lifecycleEvents()->sole()->actor_user_id)->toBe($agent->user_id);
    $this->postJson(route('plans.cancel', $plan), [...$data, 'reason' => 'Changed reason.'])->assertConflict();
})->with(['Active' => 'active', 'Paused' => 'paused']);

test('Restricted ending authority excludes Admin Customer foreign Agent and ineligible assigned accounts', function (string $actorType): void {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $admin->givePermissionTo(AdminPermission::cases());
    $customer->update(['operational_status' => CustomerStatus::Restricted]);
    $actor = match ($actorType) {
        'Admin' => $admin,
        'Customer' => $customer->user,
        'foreign Agent' => User::factory()->agent()->withTwoFactor()->create(),
        default => $agent->user,
    };
    if ($actorType === 'suspended account') {
        $actor->update(['account_state' => 'suspended']);
    } elseif ($actorType === 'inactive Agent') {
        $agent->update(['operational_status' => 'inactive']);
    } elseif ($actorType === 'incomplete MFA') {
        $actor->update(['two_factor_confirmed_at' => null]);
    }

    expect(Gate::forUser($actor->fresh())->allows('managePlan', $customer->fresh()))->toBeFalse();
})->with(['Admin', 'Customer', 'foreign Agent', 'suspended account', 'inactive Agent', 'incomplete MFA']);

test('Admin permissions do not permit HTTP cancellation or closure of a Restricted cycle', function (): void {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $admin->givePermissionTo(AdminPermission::cases());
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $customer->update(['operational_status' => CustomerStatus::Restricted]);
    $financial = restrictedEndingFinancialRows();
    $data = ['attempt_reference' => (string) Str::uuid(), 'plan_version' => $plan->version,
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'reason' => 'Forbidden Admin ending.', 'customer_explanation' => 'No action authorized.'];

    $this->actingAs($admin)->postJson(route('plans.cancel', $plan), $data)->assertForbidden();
    $this->postJson(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'close']), [
        ...$data, 'preview_fingerprint' => str_repeat('0', 64), 'confirmed' => true])->assertForbidden();

    expect(restrictedEndingFinancialRows())->toEqual($financial)
        ->and($plan->fresh()->status)->toBe(ThriftPlanStatus::Active)
        ->and($plan->lifecycleEvents()->count())->toBe(0);
});

test('settled Restricted closure preserves restriction and finances with explicit reason audit and idempotency', function (string $state): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $service = app(PlanSettlementService::class);
    if ($state !== 'completed') {
        $quote = $service->preview($agent->user, $plan, 'prepare_termination');
        $service->confirm($agent->user, $plan, 'prepare_termination', ['attempt_reference' => (string) Str::uuid(),
            'preview_fingerprint' => $quote['preview_fingerprint'], 'reason' => 'Agreed zero fee disposition.',
            'customer_explanation' => 'No cycle funds or fees remain.']);
    }
    $plan->refresh()->update(['status' => $state]);
    $customer->update(['operational_status' => CustomerStatus::Restricted]);
    $quote = $service->preview($agent->user, $plan->fresh());
    expect($quote['can_close'])->toBeTrue();
    $data = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'reason' => 'Restricted cycle independently settled.', 'customer_explanation' => 'Your settled cycle is closed; the restriction remains.', 'confirmed' => true];
    $financial = restrictedEndingFinancialRows();

    $this->actingAs($agent->user)->post(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'close']), $data)->assertRedirect();
    $event = $plan->lifecycleEvents()->where('event_type', 'closed')->sole();
    $auditCount = AuditEvent::query()->where('event_type', 'thrift_plan.closed')->count();
    $this->post(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'close']), $data)->assertRedirect();

    expect($plan->fresh()->status)->toBe(ThriftPlanStatus::Closed)
        ->and($plan->fresh()->open_customer_profile_id)->toBeNull()
        ->and($customer->fresh()->operational_status)->toBe(CustomerStatus::Restricted)
        ->and(restrictedEndingFinancialRows())->toEqual($financial)
        ->and($plan->lifecycleEvents()->where('event_type', 'closed')->count())->toBe(1)
        ->and($event->reason)->toBe($data['reason'])->and($event->customer_explanation)->toBe($data['customer_explanation'])
        ->and($event->actor_user_id)->toBe($agent->user_id)
        ->and($auditCount)->toBe(1)
        ->and(AuditEvent::query()->where('event_type', 'thrift_plan.closed')->count())->toBe($auditCount);
})->with(['Completed' => 'completed', 'prepared Active' => 'active', 'prepared Paused' => 'paused']);

test('Restricted closure requires preparation and a reviewed confirmation with reasons', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $customer->update(['operational_status' => CustomerStatus::Restricted]);
    $quote = app(PlanSettlementService::class)->preview($agent->user, $plan);
    $data = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'reason' => 'No preparation was approved.', 'customer_explanation' => 'Plan remains open.', 'confirmed' => true];
    $financial = restrictedEndingFinancialRows();

    $this->actingAs($agent->user)->postJson(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'close']), $data)->assertConflict();
    $this->postJson(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'close']), [
        ...$data, 'reason' => '', 'customer_explanation' => '', 'confirmed' => false])->assertUnprocessable()
        ->assertJsonValidationErrors(['reason', 'customer_explanation', 'confirmed']);

    expect(restrictedEndingFinancialRows())->toEqual($financial)
        ->and($plan->fresh()->status)->toBe(ThriftPlanStatus::Active)
        ->and($plan->lifecycleEvents()->count())->toBe(0);
});

test('fully reversed historical Restricted contribution cannot become unused cancellation', function (): void {
    [$actor, $customer, $assignment, $plan, $date] = collectionFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $data = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($actor, $customer, $data)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($actor, $customer, $data);
    approveReceiptCorrection($this, $actor, $customer, $assignment, LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id));
    $customer->update(['operational_status' => CustomerStatus::Restricted]);
    $plan->refresh();
    $financial = restrictedEndingFinancialRows();

    $this->actingAs($actor)->postJson(route('plans.cancel', $plan), ['attempt_reference' => (string) Str::uuid(),
        'plan_version' => $plan->version, 'customer_version' => $customer->version, 'assignment_version' => $assignment->version,
        'reason' => 'Reversed contribution retains original history.', 'customer_explanation' => 'This used cycle cannot be cancelled.'])->assertConflict();

    expect(app(ThriftPlanService::class)->canCancelUnusedCycle($plan->fresh()))->toBeFalse()
        ->and(restrictedEndingFinancialRows())->toEqual($financial)
        ->and($plan->fresh()->activity_started_at)->not->toBeNull();
});

test('Restricted ending cannot release a held pending payout or its reservation', function (): void {
    [$actor, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($actor, $customer, $assignment, $plan);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::CustomersManage);
    app(CustomerStatusManagementService::class)->transition($admin, $customer, CustomerStatus::Restricted, $customer->version,
        'Review holds this Customer.', 'Your funds remain held for review.');
    $financial = restrictedEndingFinancialRows();
    $quote = app(PlanSettlementService::class)->preview($actor, $plan->fresh());

    $this->actingAs($actor)->postJson(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'close']), [
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'reason' => 'Attempted closure while payout held.', 'customer_explanation' => 'No payout is released.', 'confirmed' => true])->assertConflict();
    $this->postJson(route('plans.cancel', $plan), ['attempt_reference' => (string) Str::uuid(),
        'plan_version' => $plan->fresh()->version, 'customer_version' => $customer->fresh()->version,
        'assignment_version' => $assignment->version, 'reason' => 'Retained owner history prevents unused cancellation.',
        'customer_explanation' => 'Your held payout remains pending.'])->assertConflict();

    expect($quote['can_close'])->toBeFalse()->and($quote['blockers'])->toContain('Pending financial owner work remains.')
        ->and($withdrawal->fresh()->held)->toBeTrue()
        ->and(restrictedEndingFinancialRows())->toEqual($financial)
        ->and($plan->fresh()->status)->toBe(ThriftPlanStatus::Active);
});

test('Restricted cancellation rejects missing current fee disposition while retaining original agreement evidence', function (): void {
    [, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $customer->update(['operational_status' => CustomerStatus::Restricted]);
    DB::table('thrift_plans')->where('id', $plan->id)->update(['current_terms_revision' => 99]);
    $financial = restrictedEndingFinancialRows();

    $this->actingAs($agent->user)->postJson(route('plans.cancel', $plan), ['attempt_reference' => (string) Str::uuid(),
        'plan_version' => $plan->version, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'reason' => 'Fee disposition cannot be established.',
        'customer_explanation' => 'Cancellation awaits verified agreement evidence.'])->assertConflict();

    expect(restrictedEndingFinancialRows())->toEqual($financial)
        ->and($plan->fresh()->status)->toBe(ThriftPlanStatus::Active)
        ->and($plan->lifecycleEvents()->count())->toBe(0);
});

test('actual funded Completed Restricted cycle closes only after fee settlement payout and custody reconciliation', function (): void {
    [$actor, , $customer, $plan] = restrictedFundedClosureFixture($this);
    $quote = app(PlanSettlementService::class)->preview($actor, $plan);
    expect($quote['can_close'])->toBeTrue()->and($quote['position']['cycle_liability_kobo'])->toBe(0);
    $financial = restrictedEndingFinancialRows();
    $data = ['attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'reason' => 'Restricted funded cycle has independently settled owners.',
        'customer_explanation' => 'Your settled cycle closes without changing your restriction.', 'confirmed' => true];

    $this->actingAs($actor)->post(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'close']), $data)->assertRedirect();
    $this->post(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'close']), $data)->assertRedirect();

    expect(restrictedEndingFinancialRows())->toEqual($financial)
        ->and($plan->fresh()->status)->toBe(ThriftPlanStatus::Closed)
        ->and($customer->fresh()->operational_status)->toBe(CustomerStatus::Restricted)
        ->and($plan->lifecycleEvents()->where('event_type', 'closed')->count())->toBe(1);
});

test('one kobo remaining from actual funded payout blocks Restricted closure without moving money', function (): void {
    [$actor, , , $plan] = restrictedFundedClosureFixture($this, '1899.99');
    $quote = app(PlanSettlementService::class)->preview($actor, $plan);
    $financial = restrictedEndingFinancialRows();

    $this->actingAs($actor)->postJson(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'close']), [
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'reason' => 'One kobo remains payable.', 'customer_explanation' => 'Closure awaits the remaining savings.', 'confirmed' => true])->assertConflict();

    expect($quote['can_close'])->toBeFalse()->and($quote['position']['cycle_liability_kobo'])->toBe(1)
        ->and(restrictedEndingFinancialRows())->toEqual($financial)
        ->and($plan->fresh()->status)->toBe(ThriftPlanStatus::Completed);
});

test('actual handover after Restricted settled closure preview removes original Agent ending authority', function (): void {
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $admin->givePermissionTo(AdminPermission::CustomersReassign);
    $actor = $agent->user;
    $plan = $this->createLifecyclePlan($customer, $actor);
    $plan->update(['status' => ThriftPlanStatus::Completed]);
    $customer->update(['operational_status' => CustomerStatus::Restricted]);
    $quote = app(PlanSettlementService::class)->preview($actor, $plan);
    $successor = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $handover = app(CustomerReassignmentService::class);
    $preview = $handover->preview($admin, $customer, $successor->id);
    $handover->execute($admin, $customer, [...$preview, 'attempt_reference' => (string) Str::uuid(),
        'target_agent_id' => $successor->id, 'reason' => 'Service responsibility transferred after settlement.',
        'customer_explanation' => 'Your new Agent manages the settled cycle.', 'confirmed' => true]);
    $financial = restrictedEndingFinancialRows();

    $this->actingAs($actor)->postJson(route('plans.settlement.confirm', ['plan' => $plan, 'action' => 'close']), [
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $quote['preview_fingerprint'],
        'reason' => 'Former Agent attempts stale closure.', 'customer_explanation' => 'Current Agent confirmation is required.', 'confirmed' => true])->assertForbidden();

    expect(restrictedEndingFinancialRows())->toEqual($financial)
        ->and($plan->fresh()->status)->toBe(ThriftPlanStatus::Completed)
        ->and($plan->lifecycleEvents()->where('event_type', 'closed')->count())->toBe(0);
});
