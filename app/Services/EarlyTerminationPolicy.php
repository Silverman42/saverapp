<?php

namespace App\Services;

use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Models\FeeRule;
use App\Models\FeeSnapshot;
use App\Support\MoneyFormatter;

class EarlyTerminationPolicy
{
    public const VERSION = 1;

    public function disclosure(FeeRule|FeeSnapshot $terms): string
    {
        if ($terms->model === FeeRuleModel::NoFee) {
            return 'Early termination has no plan fee. Closure pays no money; any remaining savings must be returned through an authorized withdrawal. Financial history and settlement gates remain required.';
        }

        $amount = match ($terms->model) {
            FeeRuleModel::Fixed => MoneyFormatter::formatNaira($terms->amount_kobo),
            FeeRuleModel::OneDay => 'one contractual contribution day',
            FeeRuleModel::Percentage => MoneyFormatter::decimal($terms->basis_points ?? 0).'% of '.($terms->timing === FeeRuleTiming::Withdrawal
                ? 'the gross savings debit for each successful withdrawal, including its net payout and fee'
                : 'posted cycle contributions minus approved contribution reversals, before withdrawals'),
        };
        $trigger = $terms->timing === FeeRuleTiming::Withdrawal
            ? 'The agreed fee of '.$amount.' is charged only through a successful withdrawal. Preparing or closing the plan does not trigger this fee.'
            : 'The agreed fee of '.$amount.' applies once to a funded cycle ending early, without prorating completed days or an additional termination penalty.';
        $source = match ($terms->settlement_source) {
            FeeSettlementSource::ExternalReceipt => 'Settlement requires a permitted external receipt.',
            FeeSettlementSource::SavingsApplication => 'Savings settlement requires a separate authorized application with sufficient available savings.',
            FeeSettlementSource::WithdrawalPayout => 'Settlement occurs only through the authorized withdrawal payout.',
        };

        return $trigger.' Paid or waived amounts are not collected again; only unpaid amounts remain due. '.$source.' Insufficient savings never create an overdraft: unpaid fees block closure until permitted external settlement or an authorized recorded waiver. Closure pays no money; remaining savings require an authorized withdrawal. Previously used cycles whose contributions are fully reversed require explicit correction and fee disposition.';
    }

    public function supports(FeeSnapshot $snapshot): bool
    {
        return $snapshot->early_termination_policy_version === self::VERSION
            && $snapshot->isAcknowledged()
            && is_string($snapshot->early_termination_description)
            && hash_equals($this->disclosure($snapshot), $snapshot->early_termination_description);
    }
}
