<?php

namespace App\Enums;

enum AuthorizationRestrictionType: string
{
    case PostRecoveryAdminManagement = 'post_recovery_admin_management';

    /**
     * Get the human-readable display name for this restriction type.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::PostRecoveryAdminManagement => 'Post-recovery Admin management restriction',
        };
    }

    /**
     * Get the default affected permission code for this restriction type.
     */
    public function affectedPermission(): AdminPermission
    {
        return match ($this) {
            self::PostRecoveryAdminManagement => AdminPermission::AdminsManage,
        };
    }

    /**
     * Default duration in minutes for this restriction type.
     */
    public function defaultDurationMinutes(): int
    {
        return match ($this) {
            self::PostRecoveryAdminManagement => 24 * 60, // 24 hours
        };
    }
}
