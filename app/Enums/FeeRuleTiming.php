<?php

namespace App\Enums;

enum FeeRuleTiming: string
{
    case Manual = 'manual';
    case Registration = 'registration';
    case FirstContribution = 'first_contribution';
    case CycleCompletion = 'cycle_completion';
    case Withdrawal = 'withdrawal';
}
