<?php

namespace App\Enums;

enum FeeRuleKind: string
{
    case Registration = 'registration';
    case Plan = 'plan';
    case Manual = 'manual';

    public function displayName(): string
    {
        return match ($this) {
            self::Registration => 'Registration Fee',
            self::Plan => 'Plan Fee',
            self::Manual => 'Manual Fee',
        };
    }
}
