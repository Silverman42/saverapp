<?php

namespace App\Enums;

enum FeeLedgerPostingType: string
{
    case ExternalFeeReceipt = 'external_fee_receipt';
    case SavingsFeeApplication = 'savings_fee_application';
    case SavingsFeeRefund = 'savings_fee_refund';
    case ExternalRefundEntitlement = 'external_refund_entitlement';
    case OtherDeduction = 'other_deduction';
}
