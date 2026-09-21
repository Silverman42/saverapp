<?php

namespace App\Models;

use App\Enums\AdminPermission;
use App\Enums\AuthorizationRestrictionType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property AuthorizationRestrictionType $restriction_type
 * @property AdminPermission|string|null $permission_code
 * @property string $source
 * @property string|null $source_reference
 * @property Carbon $started_at
 * @property Carbon|null $expires_at
 * @property int|null $created_by
 * @property Carbon|null $cleared_at
 * @property int|null $cleared_by
 * @property string|null $clear_reason
 * @property int $applied_permission_version
 * @property int|null $cleared_permission_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property User $user
 * @property User|null $creator
 * @property User|null $clearedByUser
 *
 * @method static Builder<static> active(?CarbonInterface $now = null)
 * @method static Builder<static> elapsed(?CarbonInterface $now = null)
 */
#[Fillable([
    'user_id',
    'restriction_type',
    'permission_code',
    'source',
    'source_reference',
    'started_at',
    'expires_at',
    'created_by',
    'cleared_at',
    'cleared_by',
    'clear_reason',
    'applied_permission_version',
    'cleared_permission_version',
])]
class AuthorizationRestriction extends Model
{
    /**
     * The target user (Administrator) under restriction.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The user who created the restriction.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who cleared the restriction.
     *
     * @return BelongsTo<User, $this>
     */
    public function clearedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by');
    }

    /**
     * Scope query to active (effective) restrictions.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now = $now ?? Carbon::now();

        return $query->whereNull('cleared_at')
            ->where('started_at', '<=', $now)
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            });
    }

    /**
     * Scope query to elapsed but uncleared restrictions.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeElapsed(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now = $now ?? Carbon::now();

        return $query->whereNull('cleared_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now);
    }

    /**
     * Determine whether the restriction is currently effective.
     */
    public function isEffective(?CarbonInterface $now = null): bool
    {
        $now = $now ?? Carbon::now();

        if ($this->cleared_at !== null) {
            return false;
        }

        if ($this->started_at->isAfter($now)) {
            return false;
        }

        if ($this->expires_at !== null && ! $this->expires_at->isAfter($now)) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the restriction has elapsed (expired by timestamp without explicit clear).
     */
    public function isElapsed(?CarbonInterface $now = null): bool
    {
        $now = $now ?? Carbon::now();

        return $this->cleared_at === null
            && $this->expires_at !== null
            && ! $this->expires_at->isAfter($now);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'restriction_type' => AuthorizationRestrictionType::class,
            'permission_code' => AdminPermission::class,
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'cleared_at' => 'datetime',
            'applied_permission_version' => 'integer',
            'cleared_permission_version' => 'integer',
        ];
    }
}
