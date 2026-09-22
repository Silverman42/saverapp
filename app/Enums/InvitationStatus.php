<?php

namespace App\Enums;

enum InvitationStatus: string
{
    case PendingDelivery = 'pending_delivery';
    case Sent = 'sent';
    case Opened = 'opened';
    case Activated = 'activated';
    case DeliveryFailed = 'delivery_failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * Get the human-readable display label.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::PendingDelivery => 'Pending delivery',
            self::Sent => 'Sent',
            self::Opened => 'Opened',
            self::Activated => 'Activated',
            self::DeliveryFailed => 'Delivery failed',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Determine if the invitation allows an authorized resend.
     */
    public function canResend(): bool
    {
        return in_array($this, [
            self::PendingDelivery,
            self::Sent,
            self::Opened,
            self::DeliveryFailed,
            self::Expired,
        ], true);
    }

    /**
     * Determine if the invitation is active/usable for activation.
     */
    public function isUsable(): bool
    {
        return in_array($this, [
            self::PendingDelivery,
            self::Sent,
            self::Opened,
        ], true);
    }
}
