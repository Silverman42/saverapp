<?php

namespace App\Enums;

enum FinancialNoteType: string
{
    case Credit = 'credit';
    case Debit = 'debit';

    /**
     * Get the human-readable label for this note type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Credit',
            self::Debit => 'Debit',
        };
    }
}
