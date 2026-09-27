<?php

namespace Tests;

use App\Enums\AdminPermission;
use App\Enums\FeeRuleBasis;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Enums\LedgerAccountCode;
use App\Models\AgentProfile;
use App\Models\ContributionSlot;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\FeeRule;
use App\Models\FeeSnapshot;
use App\Models\LedgerAccount;
use App\Models\PlanTermsRevision;
use App\Models\ThriftPlan;
use App\Models\User;
use Illuminate\Support\Str;

trait CreatesLifecycleCustomers
{
    /** @return array{User, CustomerProfile, AgentProfile, FeeSnapshot} */
    protected function createLifecycleFixture(int $feeKobo = 0): array
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $admin->givePermissionTo(AdminPermission::CustomersManage);
        $customer = CustomerProfile::factory()->create();
        $agent = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
        CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $agent->id,
            'assigned_by_user_id' => $admin->id, 'version' => 1]);
        LedgerAccount::query()->whereIn('code', [LedgerAccountCode::CustomerSavingsLiability->value, LedgerAccountCode::RefundPayable->value])->update(['mapping_status' => 'mapped']);
        $snapshot = $this->createLifecycleSnapshot($admin, $customer, $feeKobo);

        return [$admin, $customer, $agent, $snapshot];
    }

    protected function createLifecycleSnapshot(User $admin, CustomerProfile $customer, int $amountKobo): FeeSnapshot
    {
        $terms = ['version' => ((int) FeeRule::query()->where('kind', 'registration')->max('version')) + 1, 'name' => 'Registration terms', 'kind' => FeeRuleKind::Registration,
            'rule_key' => 'registration', 'model' => $amountKobo > 0 ? FeeRuleModel::Fixed : FeeRuleModel::NoFee,
            'timing' => FeeRuleTiming::Registration, 'basis' => FeeRuleBasis::None,
            'settlement_source' => FeeSettlementSource::ExternalReceipt, 'currency' => 'NGN',
            'amount_kobo' => $amountKobo, 'customer_description' => 'Registration fee',
            'effective_at' => now()->subDay(), 'published_by_user_id' => $admin->id, 'publication_reason' => 'Confirmed fixture'];
        $rule = FeeRule::create($terms);

        return FeeSnapshot::create(['customer_profile_id' => $customer->id, 'source_type' => 'registration', 'source_id' => (string) $customer->id,
            'fee_rule_id' => $rule->id, 'fee_rule_version' => $rule->version, 'name' => $rule->name, 'kind' => $rule->kind,
            'model' => $rule->model, 'timing' => $rule->timing, 'basis' => $rule->basis, 'settlement_source' => $rule->settlement_source,
            'currency' => 'NGN', 'amount_kobo' => $amountKobo, 'basis_amount_kobo' => 0, 'customer_description' => $rule->customer_description]);
    }

    /** @return array<string, mixed> */
    protected function lifecyclePayload(CustomerProfile $customer): array
    {
        return ['attempt_reference' => (string) Str::uuid(), 'version' => $customer->version,
            'assignment_version' => $customer->currentAssignment?->version, 'confirmed' => true,
            'reason' => 'Internal review approved.', 'customer_explanation' => 'Your participation status was updated.'];
    }

    protected function createLifecyclePlan(CustomerProfile $customer, User $actor): ThriftPlan
    {
        $rule = FeeRule::create(['version' => ((int) FeeRule::query()->where('kind', 'plan')->max('version')) + 1,
            'name' => 'No plan fee', 'kind' => 'plan', 'rule_key' => 'daily', 'model' => 'no_fee',
            'timing' => 'first_contribution', 'basis' => 'none', 'settlement_source' => 'external_receipt',
            'currency' => 'NGN', 'amount_kobo' => 0, 'customer_description' => 'No fee',
            'effective_at' => now()->subDay(), 'published_by_user_id' => $actor->id, 'publication_reason' => 'Fixture']);
        $plan = ThriftPlan::create(['plan_id' => 'PLN-'.str_pad((string) (ThriftPlan::query()->count() + 1), 6, '0', STR_PAD_LEFT), 'customer_profile_id' => $customer->id,
            'created_by_user_id' => $actor->id, 'open_customer_profile_id' => $customer->id,
            'status' => 'active', 'current_terms_revision' => 1, 'version' => 1]);
        $snapshot = FeeSnapshot::create(['customer_profile_id' => $customer->id, 'source_type' => 'plan', 'source_id' => $plan->plan_id,
            'fee_rule_id' => $rule->id, 'fee_rule_version' => $rule->version, 'name' => $rule->name,
            'kind' => $rule->kind, 'model' => $rule->model, 'timing' => $rule->timing, 'basis' => $rule->basis,
            'settlement_source' => $rule->settlement_source, 'currency' => 'NGN', 'amount_kobo' => 0,
            'basis_amount_kobo' => 0, 'customer_description' => 'No fee']);
        $revision = PlanTermsRevision::create(['thrift_plan_id' => $plan->id, 'revision' => 1, 'name' => 'Daily plan',
            'contribution_amount_kobo' => 200000, 'currency' => 'NGN', 'start_date' => now('Africa/Lagos')->toDateString(),
            'contribution_days' => 2, 'frequency' => 'daily', 'timezone' => 'Africa/Lagos', 'business_version' => 1,
            'expected_gross_kobo' => 400000, 'fee_snapshot_id' => $snapshot->id,
            'attested_by_user_id' => $actor->id, 'attested_at' => now()]);
        foreach ([0, 1] as $ordinal) {
            ContributionSlot::create(['thrift_plan_id' => $plan->id, 'plan_terms_revision_id' => $revision->id,
                'ordinal' => $ordinal + 1, 'active_ordinal' => $ordinal + 1, 'due_date' => now('Africa/Lagos')->addDays($ordinal)->toDateString(),
                'expected_amount_kobo' => 200000]);
        }

        return $plan;
    }

    /** @return array<string, mixed> */
    protected function lifecycleCollectionPayload(CustomerProfile $customer, ThriftPlan $plan): array
    {
        return ['attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
            'assignment_version' => $customer->currentAssignment->version, 'business_version' => 1,
            'plan_id' => $plan->plan_id, 'plan_version' => $plan->version, 'received_date' => now('Africa/Lagos')->toDateString(),
            'savings_ngn' => '1000.00', 'fees' => [], 'allocations' => [], 'late_reason' => '', 'notes' => '', 'confirmed' => true];
    }
}
