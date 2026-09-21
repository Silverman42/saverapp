<?php

namespace App\Enums;

enum CustomerAssignmentStatus: string
{
    case Current = 'current';
    case Ended = 'ended';

    /**
     * Get the human-readable display name.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::Current => 'Current',
            self::Ended => 'Ended',
        };
    }

    /**
     * Determine if the assignment is current.
     */
    public function isCurrent(): bool
    {
        return $this === self::Current;
    }

    /**
     * Determine if the assignment is ended.
     */
    public function isEnded(): bool
    {
        return $this === self::Ended;
    }
}
