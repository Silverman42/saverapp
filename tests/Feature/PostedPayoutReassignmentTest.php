<?php

use App\Enums\AdminPermission;
use App\Models\AgentProfile;
use App\Models\User;
use App\Services\CustomerReassignmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../CashExecutionFixtures.php';

/** @return array<string, array<int, object>> */
function payoutHandoverFinancialRows(): array
{
    $rows = [];
    foreach (['thrift_plans', 'plan_terms_revisions', 'fee_snapshots', 'fee_obligations', 'fee_obligation_entries',
        'ledger_posting_groups', 'ledger_entries', 'withdrawal_requests', 'withdrawal_reservations', 'cash_executions',
        'cash_remittances', 'collection_receipts', 'collection_batches'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

test('an actual posted cash payout permits reassignment without transferring original financial responsibility', function (): void {
    $this->freezeTime();
    Queue::fake();
    [$admin, $customer, , $withdrawal] = cashPaymentFixture();
    $admin->givePermissionTo(AdminPermission::CustomersReassign);
    $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $originalAssignment = $customer->currentAssignment;
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Counted net cash handed to the Customer.', 'confirmed' => true])->assertRedirect();
    $this->actingAs($customer->user)->post(route('cash-executions.acknowledge', $execution), ['confirmed' => true])->assertRedirect();
    expect($withdrawal->fresh()->state)->toBe('posted');
    $this->assertDatabaseHas('withdrawal_reservations', ['id' => $withdrawal->withdrawal_reservation_id, 'status' => 'consumed']);
    $before = payoutHandoverFinancialRows();
    $this->actingAs($admin);
    $preview = $this->postJson(route('customers.reassignment.preview', $customer->customer_id), ['target_agent_id' => $replacement->id])
        ->assertOk()->assertJsonPath('pending_withdrawals', 0)->json();
    $data = ['attempt_reference' => (string) Str::uuid(), 'version' => $preview['version'], 'assignment_version' => $preview['assignment_version'],
        'target_agent_id' => $replacement->id, 'preview_token' => $preview['preview_token'], 'confirmed' => true,
        'reason' => 'Service continuity after completed payout.', 'customer_explanation' => 'Your service contact is changing.'];

    $result = $this->postJson(route('customers.reassignment.store', $customer->customer_id), $data)
        ->assertOk()->assertJsonPath('status', 'committed')->json();

    expect($customer->fresh()->currentAssignment->agent_profile_id)->toBe($replacement->id)
        ->and($originalAssignment->fresh()->ended_at)->not->toBeNull()
        ->and(payoutHandoverFinancialRows())->toEqual($before);
    $this->postJson(route('customers.reassignment.store', $customer->customer_id), $data)->assertOk()->assertExactJson($result);
    $this->assertDatabaseCount('customer_handover_events', 1);
    expect(payoutHandoverFinancialRows())->toEqual($before);
});

test('approved unpaid payout remains pending during reassignment with its live reservation unchanged', function (): void {
    $this->freezeTime();
    Queue::fake();
    [$admin, $customer, , $withdrawal] = cashPaymentFixture();
    $admin->givePermissionTo(AdminPermission::CustomersReassign);
    $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $before = payoutHandoverFinancialRows();
    $service = app(CustomerReassignmentService::class);
    $preview = $service->preview($admin, $customer, $replacement->id);
    expect($preview['pending_withdrawals'])->toBe(1);

    $service->execute($admin, $customer, ['attempt_reference' => (string) Str::uuid(), 'version' => $preview['version'],
        'assignment_version' => $preview['assignment_version'], 'target_agent_id' => $replacement->id,
        'preview_token' => $preview['preview_token'], 'confirmed' => true, 'reason' => 'Transfer service follow-up.',
        'customer_explanation' => 'Your pending request remains unchanged.']);

    expect($customer->fresh()->currentAssignment->agent_profile_id)->toBe($replacement->id)
        ->and($withdrawal->fresh()->state)->toBe('approved')->and(payoutHandoverFinancialRows())->toEqual($before);
    $this->assertDatabaseHas('withdrawal_reservations', ['id' => $withdrawal->withdrawal_reservation_id, 'status' => 'live']);
});

test('uncertain actual cash handoff does not become a terminal payout during reassignment', function (): void {
    $this->freezeTime();
    Queue::fake();
    [$admin, $customer, , $withdrawal] = cashPaymentFixture();
    $admin->givePermissionTo(AdminPermission::CustomersReassign);
    $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    [$execution] = startCashFixture($this, $admin, $withdrawal);
    $this->post(route('cash-executions.handoff', $execution), ['evidence' => 'Handoff requires bound Customer acknowledgement.', 'confirmed' => true])->assertRedirect();
    expect($withdrawal->fresh()->state)->toBe('outcome_unknown');
    $before = payoutHandoverFinancialRows();
    $oldAssignment = $customer->currentAssignment->id;

    $this->postJson(route('customers.reassignment.preview', $customer->customer_id), ['target_agent_id' => $replacement->id])
        ->assertConflict()->assertJsonPath('message', 'A required handover owner is unavailable.');

    expect($customer->fresh()->currentAssignment->id)->toBe($oldAssignment)
        ->and(payoutHandoverFinancialRows())->toEqual($before);
    $this->assertDatabaseCount('customer_handover_events', 0);
    $this->assertDatabaseHas('withdrawal_reservations', ['id' => $withdrawal->withdrawal_reservation_id, 'status' => 'live']);
});
