<?php

namespace App\Enums;

enum LedgerAccountClass: string
{
    case Asset = 'asset';
    case AgentReceivable = 'agent_receivable';
    case CustomerSavingsLiability = 'customer_savings_liability';
    case RefundPayable = 'refund_payable';
    case FeeIncome = 'fee_income';
    case OtherDeductionDestination = 'other_deduction_destination';
    case UnappliedFunds = 'unapplied_funds';
    case BusinessDistributions = 'business_distributions';
}
