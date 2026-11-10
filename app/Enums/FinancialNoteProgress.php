<?php

namespace App\Enums;

enum FinancialNoteProgress: string
{
    case Pending = 'pending';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    /**
     * Get the human-readable label for this progress status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
        };
    }
}
