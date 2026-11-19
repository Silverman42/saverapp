<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One automatic plan→wallet transfer for one completion of a plan (Module 18 §6.4). It moves from pending to posted or superseded only.
 *
 * @property int $id
 * @property int $thrift_plan_id
 * @property int $customer_profile_id
 * @property int $completion_event_id
 * @property string $status
 * @property string|null $blocker
 * @property int $attempts
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $alerted_at
 * @property int|null $withdrawal_request_id
 * @property int $version
 * @property CarbonImmutable $created_at
 */
#[Fillable(['thrift_plan_id', 'customer_profile_id', 'completion_event_id', 'status', 'blocker', 'attempts',
    'next_attempt_at', 'alerted_at', 'withdrawal_request_id', 'version'])]
class PlanWalletSweep extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_POSTED = 'posted';

    public const STATUS_SUPERSEDED = 'superseded';

    protected static function booted(): void
    {
        static::updating(function (PlanWalletSweep $sweep): void {
            if ($sweep->isDirty(['thrift_plan_id', 'customer_profile_id', 'completion_event_id'])
                || ($sweep->getOriginal('status') !== self::STATUS_PENDING && $sweep->isDirty('status'))) {
                throw new RuntimeException('A settled wallet sweep is immutable.');
            }
        });
        static::deleting(function (): never {
            throw new RuntimeException('Wallet sweeps cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'attempts' => 'integer', 'version' => 'integer',
            'next_attempt_at' => 'immutable_datetime', 'alerted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ThriftPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ThriftPlan::class, 'thrift_plan_id');
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<WithdrawalRequest, $this> */
    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(WithdrawalRequest::class, 'withdrawal_request_id');
    }
}
