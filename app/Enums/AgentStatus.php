<?php

namespace App\Enums;

enum AgentStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    /**
     * Get the human-readable display name.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }

    /**
     * Determine if the agent is operationally active.
     */
    public function isEligible(): bool
    {
        return $this === self::Active;
    }
}
