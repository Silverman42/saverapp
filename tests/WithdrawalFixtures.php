<?php

use App\Enums\CustomerAssignmentStatus;
use App\Enums\FeeRuleBasis;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Enums\ThriftPlanStatus;
use App\Models\AgentProfile;
use App\Models\CollectionBatch;
use App\Models\CollectionReceipt;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\FeeRule;
use App\Models\FeeSnapshot;
use App\Models\PlanTermsRevision;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\CollectionLedgerService;
use App\Services\WithdrawalMethodRegistry;
use App\Services\WithdrawalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function withdrawalFixture(): array
{
    $agent = User::factory()->agent()->create(['two_factor_secret' => 'CONFIRMED-SECRET', 'two_factor_confirmed_at' => now()]);
    $agentProfile = AgentProfile::factory()->active()->create(['user_id' => $agent->id]);
    $customer = CustomerProfile::factory()->create();
    $assignment = CustomerAssignment::factory()->create([
        'customer_profile_id' => $customer->id, 'agent_profile_id' => $agentProfile->id,
        'assigned_by_user_id' => $agent->id, 'status' => CustomerAssignmentStatus::Current,
    ]);
    $rule = FeeRule::create([
        'version' => 1, 'name' => 'Withdrawal fee', 'kind' => FeeRuleKind::Plan,
        'rule_key' => 'withdrawal-test', 'model' => FeeRuleModel::Percentage,
        'timing' => FeeRuleTiming::Withdrawal, 'basis' => FeeRuleBasis::GrossWithdrawalDebit,
        'settlement_source' => FeeSettlementSource::WithdrawalPayout,
        'currency' => 'NGN', 'amount_kobo' => 0, 'basis_points' => 200,
        'customer_description' => 'Two percent withdrawal fee',
        'effective_at' => now()->subDay(), 'published_by_user_id' => $agent->id,
        'publication_reason' => 'Test withdrawal terms.',
    ]);
    $plan = ThriftPlan::create([
        'plan_id' => 'PLN-WDL-001', 'customer_profile_id' => $customer->id,
        'created_by_user_id' => $agent->id, 'open_customer_profile_id' => $customer->id,
        'status' => ThriftPlanStatus::Active, 'current_terms_revision' => 1, 'version' => 1,
    ]);
    $snapshot = FeeSnapshot::create([
        'customer_profile_id' => $customer->id, 'source_type' => 'plan', 'source_id' => $plan->plan_id,
        'fee_rule_id' => $rule->id, 'fee_rule_version' => 1, 'name' => 'Withdrawal fee',
        'kind' => FeeRuleKind::Plan, 'model' => FeeRuleModel::Percentage,
        'timing' => FeeRuleTiming::Withdrawal, 'basis' => FeeRuleBasis::GrossWithdrawalDebit,
        'settlement_source' => FeeSettlementSource::WithdrawalPayout, 'currency' => 'NGN',
        'amount_kobo' => 0, 'basis_points' => 200, 'basis_amount_kobo' => 0,
        'customer_description' => 'Two percent withdrawal fee', 'acknowledged_at' => now(),
    ]);
    PlanTermsRevision::create([
        'thrift_plan_id' => $plan->id, 'revision' => 1, 'name' => 'Daily cycle',
        'contribution_amount_kobo' => 100000, 'currency' => 'NGN', 'start_date' => now()->toDateString(),
        'contribution_days' => 1, 'frequency' => 'daily', 'timezone' => 'Africa/Lagos',
        'business_version' => 1, 'expected_gross_kobo' => 100000,
        'fee_snapshot_id' => $snapshot->id, 'attested_by_user_id' => $agent->id, 'attested_at' => now(),
    ]);
    $batch = CollectionBatch::create([
        'agent_profile_id' => $agentProfile->id, 'received_date' => now()->toDateString(),
        'timezone' => 'Africa/Lagos', 'revision' => 1, 'status' => 'open', 'version' => 1,
    ]);
    $receipt = CollectionReceipt::create([
        'receipt_reference' => 'TXN-WDL-001', 'attempt_reference' => (string) Str::uuid(),
        'payload_hash' => str_repeat('a', 64), 'customer_profile_id' => $customer->id,
        'thrift_plan_id' => $plan->id, 'recording_agent_profile_id' => $agentProfile->id,
        'assignment_id' => $assignment->id, 'collection_batch_id' => $batch->id,
        'recorded_by_user_id' => $agent->id, 'received_date' => now()->toDateString(),
        'timezone' => 'Africa/Lagos', 'business_version' => 1,
        'tender_amount_kobo' => 100000, 'savings_amount_kobo' => 100000,
        'fee_amount_kobo' => 0, 'recorded_at' => now(),
    ]);
    DB::transaction(function () use ($receipt, $customer, $agentProfile, $agent): void {
        $group = app(CollectionLedgerService::class)->postCashSavings($receipt->id, $customer->id, $agentProfile->id, 100000, $agent);
        $receipt->update(['savings_posting_group_id' => $group->id]);
    });

    return [$agent, $customer, $assignment, $plan];
}

function withdrawalPayload(CustomerProfile $customer, CustomerAssignment $assignment, ThriftPlan $plan): array
{
    return [
        'plan_id' => $plan->plan_id, 'type' => 'partial', 'gross_ngn' => '300.00',
        'method' => 'cash', 'destination_reference' => 'verified-customer-cash',
        'reason' => 'Customer requested a partial payout', 'internal_notes' => '',
    ];
}

function enableFixtureMethod(): void
{
    $registry = Mockery::mock(WithdrawalMethodRegistry::class);
    $registry->shouldReceive('resolve')->andReturn([
        'version' => 1, 'destination_reference' => 'verified-customer-cash',
        'destination_mask' => 'Customer cash pickup',
    ]);
    app()->instance(WithdrawalMethodRegistry::class, $registry);
}

function submittedWithdrawal(User $agent, CustomerProfile $customer, CustomerAssignment $assignment, ThriftPlan $plan): WithdrawalRequest
{
    $payload = withdrawalPayload($customer, $assignment, $plan);
    $quote = app(WithdrawalService::class)->preview($agent, $customer->fresh(), $payload);

    return app(WithdrawalService::class)->submit($agent, $customer, [
        ...$payload, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'],
        'instruction_attested' => true, 'confirmed' => true,
    ]);
}
