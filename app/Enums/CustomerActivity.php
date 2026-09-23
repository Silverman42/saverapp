<?php

namespace App\Enums;

enum CustomerActivity
{
    case CreatePlan;
    case AmendPlan;
    case RecordContribution;
    case InitiateWithdrawal;
    case ApproveWithdrawal;
    case PostPayout;
    case SettleExistingSavings;
    case ApplyAgreedFee;
    case AssessDiscretionaryFee;
    case PostCorrectiveReversal;
    case ReconcileHistoricalCollections;
}
