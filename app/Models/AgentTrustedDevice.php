<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $device_token_hash
 * @property string $device_name
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $trusted_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'device_token_hash',
    'device_name',
    'ip_address',
    'user_agent',
    'trusted_until',
])]
class AgentTrustedDevice extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'trusted_until' => 'datetime',
    ];

    /**
     * Get the user that owns the trusted device.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include active, unexpired trusted devices.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('trusted_until', '>', Carbon::now());
    }

    /**
     * Determine if this trusted device authorization has expired.
     */
    public function isExpired(): bool
    {
        return $this->trusted_until->isPast();
    }

    /**
     * Hash a raw device token for secure lookup.
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
