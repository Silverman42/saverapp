<?php

namespace App\Enums;

enum AccountState: string
{
    case Invited = 'invited';
    case MfaSetupRequired = 'mfa_setup_required';
    case Active = 'active';
    case TemporarilyLocked = 'temporarily_locked';
    case Suspended = 'suspended';
    case Deactivated = 'deactivated';

    /**
     * Determine if the account state permits signing in.
     */
    public function canSignIn(): bool
    {
        return $this === self::Active;
    }

    /**
     * Determine if the account state allows setup-only access.
     */
    public function allowsSetupOnly(): bool
    {
        return $this === self::MfaSetupRequired;
    }

    /**
     * Determine if the account is active.
     */
    public function isActive(): bool
    {
        return $this === self::Active;
    }

    /**
     * Determine if the account is suspended.
     */
    public function isSuspended(): bool
    {
        return $this === self::Suspended;
    }

    /**
     * Determine if the account is deactivated.
     */
    public function isDeactivated(): bool
    {
        return $this === self::Deactivated;
    }

    /**
     * Determine if the account is invited and pending activation.
     */
    public function isInvited(): bool
    {
        return $this === self::Invited;
    }

    /**
     * Determine if the account is pending mandatory MFA setup.
     */
    public function requiresMfaSetup(): bool
    {
        return $this === self::MfaSetupRequired;
    }

    /**
     * Determine if the account is temporarily locked.
     */
    public function isTemporarilyLocked(): bool
    {
        return $this === self::TemporarilyLocked;
    }
}
