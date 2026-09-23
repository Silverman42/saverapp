<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_profile_id
 * @property CustomerStatus|null $from_status
 * @property CustomerStatus $to_status
 * @property string $reason
 * @property string|null $customer_facing_explanation
 * @property int|null $audit_event_id
 * @property int $changed_by_user_id
 * @property Carbon $created_at
 */
#[Fillable([
    'customer_profile_id',
    'from_status',
    'to_status',
    'reason',
    'customer_facing_explanation',
    'audit_event_id',
    'changed_by_user_id',
    'created_at',
])]
class CustomerStatusHistory extends Model
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
            'from_status' => CustomerStatus::class,
            'to_status' => CustomerStatus::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the customer profile associated with this status history.
     *
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
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

    /** @return HasMany<CustomerStatusNotificationIntent, $this> */
    public function notificationIntents(): HasMany
    {
        return $this->hasMany(CustomerStatusNotificationIntent::class, 'customer_status_history_id');
    }

    /** @return BelongsTo<AuditEvent, $this> */
    public function auditEvent(): BelongsTo
    {
        return $this->belongsTo(AuditEvent::class);
    }
}
