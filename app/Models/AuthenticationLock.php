<?php

namespace App\Models;

use App\Enums\UnlockVerificationMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $email_normalized
 * @property string $lock_category
 * @property string $reason
 * @property int $failed_attempts_count
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $locked_at
 * @property Carbon $locked_until
 * @property bool $requires_review
 * @property Carbon|null $unlocked_at
 * @property int|null $unlocked_by_user_id
 * @property string|null $unlock_reason
 * @property UnlockVerificationMethod|null $unlock_verification_method
 * @property bool $notification_sent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read User|null $unlockedBy
 */
class AuthenticationLock extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'email_normalized',
        'lock_category',
        'reason',
        'failed_attempts_count',
        'ip_address',
        'user_agent',
        'locked_at',
        'locked_until',
        'requires_review',
        'unlocked_at',
        'unlocked_by_user_id',
        'unlock_reason',
        'unlock_verification_method',
        'notification_sent',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
            'locked_until' => 'datetime',
            'unlocked_at' => 'datetime',
            'requires_review' => 'boolean',
            'notification_sent' => 'boolean',
            'failed_attempts_count' => 'integer',
            'unlock_verification_method' => UnlockVerificationMethod::class,
        ];
    }

    /**
     * The user account associated with the lock.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The administrator who manually unlocked the account.
     *
     * @return BelongsTo<User, $this>
     */
    public function unlockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlocked_by_user_id');
    }

    /**
     * Scope a query to only active, unexpired, and not manually unlocked locks.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('unlocked_at')
            ->where('locked_until', '>', Carbon::now());
    }

    /**
     * Scope a query to locks requiring security review.
     */
    public function scopeRequiresReview(Builder $query): Builder
    {
        return $query->where('requires_review', true);
    }

    /**
     * Scope a query by lock category.
     */
    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('lock_category', $category);
    }

    /**
     * Determine if the lock is currently active.
     */
    public function isActive(): bool
    {
        return $this->unlocked_at === null && $this->locked_until->isFuture();
    }

    /**
     * Determine if the lock has expired naturally.
     */
    public function isExpired(): bool
    {
        return $this->unlocked_at === null && $this->locked_until->isPast();
    }

    /**
     * Return masked IP address for secret-free display (AUTH-063).
     */
    public function maskedIp(): string
    {
        if (empty($this->ip_address)) {
            return 'Unknown IP';
        }

        if (str_contains($this->ip_address, ':')) {
            $parts = explode(':', $this->ip_address);

            return ($parts[0] ?? '2001').':'.($parts[1] ?? 'db8').':****:****';
        }

        $parts = explode('.', $this->ip_address);
        if (count($parts) === 4) {
            return $parts[0].'.'.$parts[1].'.***.***';
        }

        return '***.***.***.***';
    }

    /**
     * Return a recognizable browser and platform summary from the user agent.
     */
    public function deviceContext(): string
    {
        if (empty($this->user_agent)) {
            return 'Unknown Device';
        }

        $ua = $this->user_agent;
        $browser = 'Browser';
        $platform = 'Device';

        if (str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS')) {
            $platform = 'macOS';
        } elseif (str_contains($ua, 'Windows')) {
            $platform = 'Windows';
        } elseif (str_contains($ua, 'Linux')) {
            $platform = 'Linux';
        } elseif (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) {
            $platform = 'iOS';
        } elseif (str_contains($ua, 'Android')) {
            $platform = 'Android';
        }

        if (str_contains($ua, 'Edg')) {
            $browser = 'Edge';
        } elseif (str_contains($ua, 'Chrome')) {
            $browser = 'Chrome';
        } elseif (str_contains($ua, 'Firefox')) {
            $browser = 'Firefox';
        } elseif (str_contains($ua, 'Safari') && ! str_contains($ua, 'Chrome')) {
            $browser = 'Safari';
        }

        return "{$browser} on {$platform}";
    }

    /**
     * Mark this lock as manually unlocked.
     */
    public function markUnlocked(
        User $admin,
        ?string $reason = null,
        UnlockVerificationMethod|string|null $verificationMethod = null,
    ): void {
        if (is_string($verificationMethod)) {
            $verificationMethod = UnlockVerificationMethod::from($verificationMethod);
        }

        $this->update([
            'unlocked_at' => Carbon::now(),
            'unlocked_by_user_id' => $admin->id,
            'unlock_reason' => $reason ?? 'Manually unlocked by administrator after identity verification',
            'unlock_verification_method' => $verificationMethod,
        ]);
    }
}
