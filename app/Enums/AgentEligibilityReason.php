<?php

namespace App\Enums;

enum AgentEligibilityReason: string
{
    case MissingProfile = 'missing_profile';
    case RoleDrift = 'role_drift';
    case UnusableAccount = 'unusable_account';
    case IncompleteMfa = 'incomplete_mfa';
    case OperationallyInactive = 'operationally_inactive';
    case TemporarilyLocked = 'temporarily_locked';

    /**
     * Get the human-readable explanation of this reason code.
     */
    public function message(): string
    {
        return match ($this) {
            self::MissingProfile => 'Agent operational profile is missing.',
            self::RoleDrift => 'User role has drifted or does not match Agent classification.',
            self::UnusableAccount => 'Agent user account is not active.',
            self::IncompleteMfa => 'Agent has not completed required MFA setup.',
            self::OperationallyInactive => 'Agent is operationally inactive.',
            self::TemporarilyLocked => 'Agent authentication account is temporarily locked.',
        };
    }
}
