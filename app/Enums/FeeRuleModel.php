<?php

namespace App\Enums;

enum FeeRuleModel: string
{
    case Fixed = 'fixed';
    case NoFee = 'no_fee';
    case OneDay = 'one_day';
    case Percentage = 'percentage';

    public function displayName(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed Amount',
            self::NoFee => 'Explicit Zero / No Fee',
            self::OneDay => 'One Contractual Day',
            self::Percentage => 'Percentage',
        };
    }
}
