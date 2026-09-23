<?php

namespace App\Enums;

enum FeeSettlementSource: string
{
    case ExternalReceipt = 'external_receipt';
    case SavingsApplication = 'savings_application';
    case WithdrawalPayout = 'withdrawal_payout';
}
