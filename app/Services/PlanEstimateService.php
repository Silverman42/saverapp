<?php

namespace App\Services;

use App\Data\FeeQuote;
use App\Enums\FeeRuleBasis;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Models\FeeRule;
use App\Models\PlanTermsRevision;
use App\Models\ThriftPlan;
use App\Support\FeePercentageCalculator;
use App\Support\MoneyFormatter;

class PlanEstimateService
{
    public function __construct(private PlanFeeSnapshotBinding $snapshotBinding) {}

    /**
     * @return array{status: string, expected_gross: ?string, estimated_fee: ?string, estimated_payout: ?string,
     *     settlement_source: ?string, message: string}
     */
    public function forQuote(FeeRule $rule, FeeQuote $quote, int $expectedGrossKobo): array
    {
        if ($rule->kind !== FeeRuleKind::Plan || $quote->ruleId !== $rule->id || $quote->ruleVersion !== $rule->version
            || $quote->model !== $rule->model || $quote->timing !== $rule->timing || $quote->basis !== $rule->basis
            || $rule->currency !== 'NGN' || $quote->currency !== 'NGN') {
            return $this->unavailable();
        }

        return $this->project($expectedGrossKobo, $quote->amountKobo, $quote->model, $quote->timing, $rule->settlement_source);
    }

    /**
     * @return array{status: string, expected_gross: ?string, estimated_fee: ?string, estimated_payout: ?string,
     *     settlement_source: ?string, message: string}
     */
    public function forSnapshot(ThriftPlan $plan, PlanTermsRevision $terms): array
    {
        $snapshot = $terms->feeSnapshot;
        if ($snapshot === null || $terms->thrift_plan_id !== $plan->id || $terms->currency !== 'NGN'
            || $snapshot->currency !== 'NGN' || $snapshot->kind !== FeeRuleKind::Plan
            || $snapshot->customer_profile_id !== $plan->customer_profile_id
            || ! $this->snapshotBinding->isValid($plan, $terms, $snapshot)
            || $terms->contribution_days < 1 || $terms->contribution_days > 366 || $terms->contribution_amount_kobo < 1
            || $terms->contribution_amount_kobo > intdiv(PHP_INT_MAX, $terms->contribution_days)
            || $terms->expected_gross_kobo !== $terms->contribution_amount_kobo * $terms->contribution_days
            || ($snapshot->model === FeeRuleModel::OneDay && ($snapshot->amount_kobo !== $terms->contribution_amount_kobo
                || $snapshot->basis_amount_kobo !== $terms->contribution_amount_kobo
                || $snapshot->basis !== FeeRuleBasis::ContractualDailyContribution))
            || ($snapshot->model === FeeRuleModel::NoFee && $snapshot->amount_kobo !== 0)) {
            return $this->unavailable();
        }
        if ($snapshot->model === FeeRuleModel::Percentage && $snapshot->timing === FeeRuleTiming::CycleCompletion) {
            if ($snapshot->basis_points === null || $snapshot->basis_points < 0 || $snapshot->basis_points > 10_000
                || $snapshot->basis !== FeeRuleBasis::NetCycleContributions
                || $snapshot->basis_amount_kobo !== $terms->expected_gross_kobo
                || ($snapshot->basis_points !== 0 && $snapshot->basis_amount_kobo > intdiv(PHP_INT_MAX - 5_000, $snapshot->basis_points))
                || $snapshot->amount_kobo !== FeePercentageCalculator::calculate($snapshot->basis_amount_kobo, $snapshot->basis_points)) {
                return $this->unavailable();
            }
        }

        return $this->project($terms->expected_gross_kobo, $snapshot->amount_kobo, $snapshot->model, $snapshot->timing, $snapshot->settlement_source);
    }

    /**
     * @return array{status: string, expected_gross: ?string, estimated_fee: ?string, estimated_payout: ?string,
     *     settlement_source: ?string, message: string}
     */
    private function project(int $grossKobo, int $feeKobo, FeeRuleModel $model, FeeRuleTiming $timing, FeeSettlementSource $source): array
    {
        if ($grossKobo < 1 || $feeKobo < 0 || ! in_array($timing, [FeeRuleTiming::FirstContribution, FeeRuleTiming::CycleCompletion, FeeRuleTiming::Withdrawal], true)
            || ($model === FeeRuleModel::Percentage && $timing === FeeRuleTiming::FirstContribution)
            || ($source === FeeSettlementSource::WithdrawalPayout && $timing !== FeeRuleTiming::Withdrawal)) {
            return $this->unavailable();
        }
        $estimate = ['status' => 'available', 'expected_gross' => MoneyFormatter::formatNaira($grossKobo),
            'estimated_fee' => null, 'estimated_payout' => null, 'settlement_source' => $source->value,
            'message' => 'Assumes all agreed days are funded. Actual savings, charges and payouts may differ. Future discretionary charges are not included.'];
        if ($model === FeeRuleModel::Percentage && $timing === FeeRuleTiming::Withdrawal) {
            return [...$estimate, 'status' => 'partial',
                'message' => 'The fee and payout depend on each actual withdrawal quote. No cycle payout forecast is available.'];
        }
        $estimate['estimated_fee'] = MoneyFormatter::formatNaira($feeKobo);
        if ($source === FeeSettlementSource::ExternalReceipt) {
            return [...$estimate, 'estimated_payout' => MoneyFormatter::formatNaira($grossKobo),
                'message' => 'Assumes all agreed days are funded. This fee is settled separately and is not deducted from the savings payout estimate. Actual savings, charges and payouts can differ. Future discretionary charges are not included.'];
        }
        if ($feeKobo >= $grossKobo) {
            return [...$estimate, 'status' => 'partial',
                'message' => 'The agreed fee leaves no positive payout estimate. A payout requires a valid withdrawal quote and an approved way to settle the fee.'];
        }

        return [...$estimate, 'estimated_payout' => MoneyFormatter::formatNaira($grossKobo - $feeKobo)];
    }

    /**
     * @return array{status: string, expected_gross: ?string, estimated_fee: ?string, estimated_payout: ?string,
     *     settlement_source: ?string, message: string}
     */
    private function unavailable(): array
    {
        return ['status' => 'unavailable', 'expected_gross' => null, 'estimated_fee' => null,
            'estimated_payout' => null, 'settlement_source' => null, 'message' => 'The contractual estimate cannot be verified.'];
    }
}
