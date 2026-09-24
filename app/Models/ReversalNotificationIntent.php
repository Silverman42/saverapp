<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** @property array<string, string> $payload */
#[Fillable([
    'notification_id', 'reversal_event_id', 'customer_profile_id', 'recipient_user_id',
    'audience_type', 'channel', 'payload', 'status', 'delivered_at', 'suppressed_at',
])]
class ReversalNotificationIntent extends Model
{
    protected function casts(): array
    {
        return ['payload' => 'array', 'delivered_at' => 'immutable_datetime', 'suppressed_at' => 'immutable_datetime'];
    }
}
