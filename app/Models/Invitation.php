<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\InvitationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $target_email
 * @property string $target_email_normalized
 * @property string $role
 * @property string $token_hash
 * @property int $generation
 * @property InvitationStatus $status
 * @property DeliveryStatus $delivery_status
 * @property string|null $delivery_error
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $opened_at
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property string|null $cancellation_reason
 * @property CarbonImmutable $expires_at
 * @property int $invited_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'user_id',
    'target_email',
    'target_email_normalized',
    'role',
    'token_hash',
    'generation',
    'status',
    'delivery_status',
    'delivery_error',
    'sent_at',
    'opened_at',
    'activated_at',
    'cancelled_at',
    'cancelled_by_user_id',
    'cancellation_reason',
    'expires_at',
    'invited_by_user_id',
])]
class Invitation extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvitationStatus::class,
            'delivery_status' => DeliveryStatus::class,
            'generation' => 'integer',
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'activated_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Get the user authentication account linked to this invitation.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the admin who issued this invitation.
     *
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * Get the user who cancelled this invitation.
     *
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        if ($this->isExpired()) {
            return false;
        }

        return $this->status->isUsable();
    }

    public function canResend(): bool
    {
        return $this->status->canResend() || $this->isExpired();
    }

    /**
     * Scope query for hashed challenge token lookup.
     *
     * @param  Builder<Invitation>  $query
     * @return Builder<Invitation>
     */
    public function scopeWherePlainToken(Builder $query, string $plainToken): Builder
    {
        return $query->where('token_hash', hash('sha256', $plainToken));
    }
}
