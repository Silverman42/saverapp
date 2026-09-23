<?php

namespace App\Enums;

enum FeeObligationStatus: string
{
    case Pending = 'pending';
    case Settled = 'settled';
    case Waived = 'waived';
    case Cancelled = 'cancelled';

    public function displayName(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Settled => 'Settled',
            self::Waived => 'Waived',
            self::Cancelled => 'Cancelled',
        };
    }
}
