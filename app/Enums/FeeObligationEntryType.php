<?php

namespace App\Enums;

enum FeeObligationEntryType: string
{
    case Assessment = 'assessment';
    case Settlement = 'settlement';
    case Waiver = 'waiver';
    case AssessmentCorrection = 'assessment_correction';
    case AssessmentCorrectionIncrease = 'assessment_correction_increase';
    case SettlementReversal = 'settlement_reversal';
    case SavingsRefund = 'savings_refund';
    case ExternalRefundEntitlement = 'external_refund_entitlement';
}
