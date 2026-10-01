<?php

namespace App\Models;

use App\Enums\ThriftPlanStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * @property CarbonImmutable $effective_at
 * @property ThriftPlanStatus|null $from_status
 * @property ThriftPlanStatus|null $to_status
 */
#[Fillable(['thrift_plan_id', 'plan_operation_attempt_id', 'event_type', 'from_status', 'to_status', 'actor_user_id', 'assignment_version', 'plan_version', 'reason', 'customer_explanation', 'payload', 'effective_at'])]
class PlanLifecycleEvent extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'from_status' => ThriftPlanStatus::class,
            'to_status' => ThriftPlanStatus::class,
            'assignment_version' => 'integer',
            'plan_version' => 'integer',
            'effective_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Plan lifecycle events are immutable.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Plan lifecycle events cannot be deleted.');
        });
    }

    /** @return BelongsTo<ThriftPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ThriftPlan::class, 'thrift_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
