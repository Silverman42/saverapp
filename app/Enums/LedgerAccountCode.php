<?php

namespace App\Enums;

enum LedgerAccountCode: string
{
    case BusinessCash = 'business_cash_ngn';
    case AgentReceivable = 'agent_receivable_ngn';
    case CustomerSavingsLiability = 'customer_savings_liability_ngn';
    case FeeIncome = 'fee_income_ngn';
    case RefundPayable = 'refund_payable_ngn';
    case OtherDeductionDestination = 'other_deduction_destination_ngn';
    case UnappliedFunds = 'unapplied_funds_ngn';
    case BusinessDistributions = 'business_distributions_ngn';
}
