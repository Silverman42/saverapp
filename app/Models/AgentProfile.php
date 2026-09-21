<?php

namespace App\Models;

use App\Enums\AccountState;
use App\Enums\AgentStatus;
use App\Support\PhoneNormalizer;
use Database\Factories\AgentProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * @property int $id
 * @property int $user_id
 * @property string $agent_id
 * @property string $phone
 * @property string $phone_normalized
 * @property string|null $address
 * @property string|null $profile_photo_path
 * @property Carbon|null $employment_date
 * @property string|null $notes
 * @property AgentStatus $operational_status
 * @property int $version
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'agent_id',
    'phone',
    'phone_normalized',
    'address',
    'profile_photo_path',
    'employment_date',
    'notes',
    'operational_status',
    'version',
    'created_by_user_id',
    'updated_by_user_id',
])]
class AgentProfile extends Model
{
    /** @use HasFactory<AgentProfileFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operational_status' => AgentStatus::class,
            'employment_date' => 'date',
            'version' => 'integer',
        ];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saving(function (AgentProfile $profile): void {
            if (filled($profile->phone)) {
                $normalized = PhoneNormalizer::normalize($profile->phone);
                if ($normalized === null) {
                    throw new InvalidArgumentException("Invalid phone number format [{$profile->phone}].");
                }
                $profile->phone_normalized = $normalized;
            }
        });

        static::updating(function (AgentProfile $profile): void {
            if ($profile->isDirty('agent_id')) {
                throw new RuntimeException('Cannot change immutable agent_id.');
            }

            if ($profile->isDirty('user_id')) {
                throw new RuntimeException('Cannot change user_id on an existing agent profile.');
            }
        });
    }

    /**
     * Get the user authentication account linked to this agent profile.
     *
     * @return BelongsTo<User, AgentProfile>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the user who created this agent profile.
     *
     * @return BelongsTo<User, AgentProfile>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the user who last updated this agent profile.
     *
     * @return BelongsTo<User, AgentProfile>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Scope query to active agents.
     *
     * @param  Builder<AgentProfile>  $query
     * @return Builder<AgentProfile>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('operational_status', AgentStatus::Active);
    }

    /**
     * Scope query to inactive agents.
     *
     * @param  Builder<AgentProfile>  $query
     * @return Builder<AgentProfile>
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('operational_status', AgentStatus::Inactive);
    }

    /**
     * Find profile by normalized phone.
     *
     * @param  Builder<AgentProfile>  $query
     * @return Builder<AgentProfile>
     */
    public function scopeWhereNormalizedPhone(Builder $query, string $phone): Builder
    {
        $normalized = PhoneNormalizer::normalize($phone);

        return $query->where('phone_normalized', $normalized);
    }

    public function isActive(): bool
    {
        return $this->operational_status === AgentStatus::Active;
    }

    public function isInactive(): bool
    {
        return $this->operational_status === AgentStatus::Inactive;
    }

    /**
     * Check if the Agent is eligible for customer operations.
     * Section 9.2:
     * 1. Account state Active with completed MFA.
     * 2. Operational status Active.
     */
    public function isEligible(): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $user = $this->user;
        if (! $user) {
            return false;
        }

        return $user->account_state === AccountState::Active
            && $user->hasConfirmedTwoFactor();
    }
}
