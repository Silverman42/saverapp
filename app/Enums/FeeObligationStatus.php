<?php

namespace App\Enums;

enum FeeObligationStatus: string
{
    case Pending = 'pending';
    case PartiallySettled = 'partially_settled';
    case Settled = 'settled';
    case Waived = 'waived';
    case PartiallySettledAndWaived = 'partially_settled_and_waived';
    case Cancelled = 'cancelled';

    public function displayName(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::PartiallySettled => 'Part payment',
            self::Settled => 'Settled',
            self::Waived => 'Waived',
            self::PartiallySettledAndWaived => 'Part payment and waived',
            self::Cancelled => 'Cancelled',
        };
    }
}
