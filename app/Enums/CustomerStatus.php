<?php

namespace App\Enums;

enum CustomerStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Restricted = 'restricted';
    case Archived = 'archived';

    /**
     * Get the human-readable display name.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Restricted => 'Restricted',
            self::Archived => 'Archived',
        };
    }

    /**
     * Determine if normal transactions (collections, new plans) are permitted.
     */
    public function canTransact(): bool
    {
        return $this === self::Active;
    }

    /**
     * Determine if withdrawal/settlement of existing funds is permitted.
     * Section 5: Inactive allows assigned-Agent withdrawal initiation of existing funds.
     */
    public function allowsWithdrawalOfExistingFunds(): bool
    {
        return in_array($this, [self::Active, self::Inactive], true);
    }

    /**
     * Determine if the customer is archived.
     */
    public function isArchived(): bool
    {
        return $this === self::Archived;
    }

    /**
     * Determine if the customer is restricted (financial hold).
     */
    public function isRestricted(): bool
    {
        return $this === self::Restricted;
    }
}
