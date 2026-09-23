<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_profile_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string $reason
 * @property string|null $agent_facing_explanation
 * @property int $changed_by_user_id
 * @property int|null $audit_event_id
 * @property Carbon $created_at
 */
#[Fillable([
    'agent_profile_id',
    'from_status',
    'to_status',
    'reason',
    'agent_facing_explanation',
    'changed_by_user_id',
    'audit_event_id',
])]
class AgentStatusHistory extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the agent profile associated with this status history.
     *
     * @return BelongsTo<AgentProfile, $this>
     */
    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }

    /**
     * Get the user who changed the status.
     *
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    /** @return HasMany<AgentStatusNotificationIntent, $this> */
    public function notificationIntents(): HasMany
    {
        return $this->hasMany(AgentStatusNotificationIntent::class);
    }
}
