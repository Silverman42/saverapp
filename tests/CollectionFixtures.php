<?php

use App\Enums\CustomerAssignmentStatus;
use App\Enums\FeeRuleBasis;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Enums\ThriftPlanStatus;
use App\Models\AgentProfile;
use App\Models\ContributionSlot;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\FeeRule;
use App\Models\FeeSnapshot;
use App\Models\FinancialPeriod;
use App\Models\PlanTermsRevision;
use App\Models\ThriftPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function collectionFixture(int $days = 2, int $startOffsetDays = 0, int $slotAmountKobo = 200000, int $feeAmountKobo = 0, FeeRuleTiming $feeTiming = FeeRuleTiming::FirstContribution, ?FeeSettlementSource $feeSource = null): array
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
        'rule_key' => 'test-plan', 'model' => $feeAmountKobo > 0 ? FeeRuleModel::Fixed : FeeRuleModel::NoFee,
        'timing' => $feeTiming, 'basis' => FeeRuleBasis::None,
        'settlement_source' => $feeSource ?? ($feeAmountKobo > 0 ? FeeSettlementSource::SavingsApplication : FeeSettlementSource::ExternalReceipt),
        'currency' => 'NGN', 'amount_kobo' => $feeAmountKobo, 'customer_description' => 'No fee',
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
        'kind' => FeeRuleKind::Plan, 'model' => $feeAmountKobo > 0 ? FeeRuleModel::Fixed : FeeRuleModel::NoFee,
        'timing' => $feeTiming, 'basis' => FeeRuleBasis::None,
        'settlement_source' => $feeSource ?? ($feeAmountKobo > 0 ? FeeSettlementSource::SavingsApplication : FeeSettlementSource::ExternalReceipt),
        'currency' => 'NGN', 'amount_kobo' => $feeAmountKobo, 'basis_amount_kobo' => 0,
        'customer_description' => 'No fee', 'acknowledged_at' => now(),
    ]);
    $today = CarbonImmutable::now('Africa/Lagos')->toDateString();
    $thisMonth = CarbonImmutable::parse($today, 'Africa/Lagos')->startOfMonth();
    foreach ([$thisMonth, $thisMonth->subMonth()] as $month) {
        FinancialPeriod::factory()->create(['month' => $month->toDateString()]);
    }
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
