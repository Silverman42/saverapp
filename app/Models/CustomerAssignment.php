<?php

namespace App\Models;

use App\Enums\CustomerAssignmentStatus;
use Database\Factories\CustomerAssignmentFactory;
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
 * @property int $customer_profile_id
 * @property int $agent_profile_id
 * @property int $assigned_by_user_id
 * @property string $reason
 * @property CustomerAssignmentStatus $status
 * @property int|null $is_current
 * @property Carbon $effective_at
 * @property Carbon|null $ended_at
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'customer_profile_id',
    'agent_profile_id',
    'assigned_by_user_id',
    'reason',
    'status',
    'is_current',
    'effective_at',
    'ended_at',
    'version',
])]
class CustomerAssignment extends Model
{
    /** @use HasFactory<CustomerAssignmentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CustomerAssignmentStatus::class,
            'is_current' => 'integer',
            'effective_at' => 'datetime',
            'ended_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saving(function (CustomerAssignment $assignment): void {
            $reason = trim($assignment->reason);
            if ($reason === '') {
                throw new InvalidArgumentException('Assignment reason is required.');
            }

            if (mb_strlen($reason) > 500) {
                throw new InvalidArgumentException('Assignment reason must not exceed 500 characters.');
            }

            $assignment->reason = $reason;

            if ($assignment->version < 1) {
                throw new InvalidArgumentException('Assignment version must be a positive integer.');
            }

            // Invariant: current assignments have is_current = 1 and ended_at = null
            // historical assignments have is_current = null and ended_at != null
            if ($assignment->status === CustomerAssignmentStatus::Current) {
                $assignment->is_current = 1;
                $assignment->ended_at = null;
            } elseif ($assignment->status === CustomerAssignmentStatus::Ended) {
                $assignment->is_current = null;
                if ($assignment->ended_at === null) {
                    $assignment->ended_at = Carbon::now();
                }
            }
        });

        static::updating(function (CustomerAssignment $assignment): void {
            $immutableFields = [
                'customer_profile_id',
                'agent_profile_id',
                'assigned_by_user_id',
                'effective_at',
                'version',
            ];

            foreach ($immutableFields as $field) {
                if ($assignment->isDirty($field)) {
                    throw new RuntimeException("Cannot modify immutable customer assignment attribute [{$field}].");
                }
            }
        });

        static::deleting(function (CustomerAssignment $assignment): void {
            throw new RuntimeException('Customer assignments are immutable historical records and cannot be deleted.');
        });
    }

    /**
     * Get the customer profile linked to this assignment.
     *
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'customer_profile_id');
    }

    /**
     * Get the agent profile linked to this assignment.
     *
     * @return BelongsTo<AgentProfile, $this>
     */
    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class, 'agent_profile_id');
    }

    /**
     * Get the user who made this assignment.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    /**
     * Scope query to current assignments.
     *
     * @param  Builder<CustomerAssignment>  $query
     * @return Builder<CustomerAssignment>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', 1);
    }

    /**
     * Scope query to ended assignments.
     *
     * @param  Builder<CustomerAssignment>  $query
     * @return Builder<CustomerAssignment>
     */
    public function scopeEnded(Builder $query): Builder
    {
        return $query->where('status', CustomerAssignmentStatus::Ended);
    }

    /**
     * Scope query to assignments for a specific agent.
     *
     * @param  Builder<CustomerAssignment>  $query
     * @return Builder<CustomerAssignment>
     */
    public function scopeForAgent(Builder $query, int $agentProfileId): Builder
    {
        return $query->where('agent_profile_id', $agentProfileId);
    }

    /**
     * Determine if this assignment is current.
     */
    public function isCurrent(): bool
    {
        return $this->is_current === 1 && $this->status === CustomerAssignmentStatus::Current;
    }

    /**
     * Determine if this assignment has ended.
     */
    public function isEnded(): bool
    {
        return $this->status === CustomerAssignmentStatus::Ended;
    }
}
