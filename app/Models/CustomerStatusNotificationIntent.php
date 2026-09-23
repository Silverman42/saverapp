<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array{title: string, message: string, status: string, effective_at: string, customer_id: string, url?: string} $payload
 */
#[Fillable([
    'notification_id', 'customer_status_history_id', 'recipient_user_id', 'audience_type',
    'channel', 'purpose', 'customer_profile_id', 'payload', 'status', 'failure_reason',
    'delivered_at', 'suppressed_at',
])]
class CustomerStatusNotificationIntent extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivered_at' => 'datetime',
            'suppressed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CustomerStatusHistory, $this> */
    public function history(): BelongsTo
    {
        return $this->belongsTo(CustomerStatusHistory::class, 'customer_status_history_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
