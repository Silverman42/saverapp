<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Uncertain = 'uncertain';

    /**
     * Get the human-readable display label.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Sent => 'Sent',
            self::Failed => 'Delivery failed',
            self::Uncertain => 'Delivery could not be confirmed',
        };
    }
}
