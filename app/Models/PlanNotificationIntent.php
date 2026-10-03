<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array{title: string, message: string, plan_id: string, status: string, url: string} $payload */
#[Fillable([
    'notification_id', 'plan_lifecycle_event_id', 'thrift_plan_id', 'customer_profile_id',
    'recipient_user_id', 'audience_type', 'channel', 'purpose', 'payload', 'status',
    'delivered_at', 'suppressed_at', 'failure_reason',
    'attempt_count', 'attempted_at', 'template_version', 'rendered_snapshot', 'rendered_hash', 'destination_hash',
])]
class PlanNotificationIntent extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivered_at' => 'immutable_datetime',
            'suppressed_at' => 'immutable_datetime',
            'attempted_at' => 'immutable_datetime',
            'attempt_count' => 'integer',
        ];
    }

    /** @return BelongsTo<PlanLifecycleEvent, $this> */
    public function lifecycleEvent(): BelongsTo
    {
        return $this->belongsTo(PlanLifecycleEvent::class, 'plan_lifecycle_event_id');
    }

    /** @return BelongsTo<ThriftPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ThriftPlan::class, 'thrift_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
