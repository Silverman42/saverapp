<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array{title: string, message: string, status: string, effective_at: string, url?: string} $payload
 */
#[Fillable([
    'notification_id', 'agent_status_history_id', 'recipient_user_id', 'agent_profile_id',
    'customer_profile_id', 'audience_type', 'channel', 'purpose', 'payload', 'status',
    'failure_reason', 'delivered_at', 'suppressed_at',
])]
class AgentStatusNotificationIntent extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivered_at' => 'datetime',
            'suppressed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AgentStatusHistory, $this> */
    public function history(): BelongsTo
    {
        return $this->belongsTo(AgentStatusHistory::class, 'agent_status_history_id');
    }
}
