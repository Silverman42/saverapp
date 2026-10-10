<?php

namespace App\Enums;

enum LockNotificationStatus: string
{
    case NotSent = 'not_sent';
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    /**
     * Human-readable label for the Admin lockout view.
     */
    public function label(): string
    {
        return match ($this) {
            self::NotSent => 'Not sent (already notified for this lock)',
            self::Queued => 'Queued for email',
            self::Sent => 'Emailed',
            self::Failed => 'Email failed',
        };
    }
}
