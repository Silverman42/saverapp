<?php

namespace App\Enums;

enum FeeRuleKind: string
{
    case Registration = 'registration';

    public function displayName(): string
    {
        return match ($this) {
            self::Registration => 'Registration Fee',
        };
    }
}
