<?php

namespace App\Enums;

enum AuthenticatorState: string
{
    case NotConfigured = 'not_configured';
    case PendingConfirmation = 'pending_confirmation';
    case Active = 'active';
    case ReplacementPending = 'replacement_pending';
    case Revoked = 'revoked';
    case RecoveryRequired = 'recovery_required';

    /**
     * Determine if the authenticator can satisfy normal MFA challenges.
     */
    public function canSatisfyChallenge(): bool
    {
        return $this === self::Active || $this === self::ReplacementPending;
    }

    /**
     * Determine if an authenticator setup or replacement is currently pending.
     */
    public function isPending(): bool
    {
        return $this === self::PendingConfirmation || $this === self::ReplacementPending;
    }

    /**
     * Determine if recovery is required.
     */
    public function requiresRecovery(): bool
    {
        return $this === self::RecoveryRequired;
    }
}
