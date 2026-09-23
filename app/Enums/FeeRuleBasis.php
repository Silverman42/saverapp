<?php

namespace App\Enums;

enum FeeRuleBasis: string
{
    case None = 'none';
    case ContractualDailyContribution = 'contractual_daily_contribution';
    case NetCycleContributions = 'net_cycle_contributions';
    case GrossWithdrawalDebit = 'gross_withdrawal_debit';
}
