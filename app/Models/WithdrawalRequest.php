<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * @property int $id
 * @property string $withdrawal_id
 * @property int $customer_profile_id
 * @property int $thrift_plan_id
 * @property int $withdrawal_reservation_id
 * @property int $gross_amount_kobo
 * @property int $fee_amount_kobo
 * @property int $net_amount_kobo
 * @property int $version
 * @property int|null $reviewed_by_user_id
 * @property string $state
 * @property string $method
 * @property string $destination_reference
 * @property string $destination_mask
 * @property string $reason
 * @property string|null $internal_notes
 * @property bool $held
 * @property string|null $hold_reason
 * @property CarbonImmutable|null $held_at
 * @property CarbonImmutable|null $hold_lifted_at
 * @property CarbonImmutable $submitted_at
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable $deadline_at
 * @property CarbonImmutable|null $terminal_at
 */
#[Fillable([
    'withdrawal_id', 'customer_profile_id', 'thrift_plan_id', 'live_thrift_plan_id',
    'initiating_agent_profile_id', 'assignment_id', 'submitted_by_user_id', 'reviewed_by_user_id',
    'fee_snapshot_id', 'withdrawal_reservation_id', 'type', 'state', 'held', 'hold_reason',
    'held_at', 'hold_lifted_at', 'gross_amount_kobo', 'fee_amount_kobo', 'net_amount_kobo',
    'currency', 'method', 'destination_reference', 'destination_mask', 'reason', 'internal_notes',
    'customer_version', 'assignment_version', 'plan_version', 'business_version', 'method_version',
    'version', 'submitted_at', 'approved_at', 'deadline_at', 'terminal_at',
])]
class WithdrawalRequest extends Model
{
    protected static function booted(): void
    {
        static::updating(function (WithdrawalRequest $request): void {
            if ($request->isDirty([
                'withdrawal_id', 'customer_profile_id', 'thrift_plan_id', 'initiating_agent_profile_id',
                'assignment_id', 'submitted_by_user_id', 'fee_snapshot_id', 'withdrawal_reservation_id',
                'type', 'gross_amount_kobo', 'fee_amount_kobo', 'net_amount_kobo', 'currency',
                'method', 'destination_reference', 'destination_mask', 'reason', 'internal_notes',
                'customer_version', 'assignment_version', 'plan_version', 'business_version',
                'method_version', 'submitted_at',
            ])) {
                throw new RuntimeException('Submitted withdrawal terms are immutable.');
            }
        });
        static::deleting(function (): never {
            throw new RuntimeException('Withdrawal requests cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'held' => 'boolean', 'gross_amount_kobo' => 'integer', 'fee_amount_kobo' => 'integer',
            'net_amount_kobo' => 'integer', 'version' => 'integer',
            'destination_reference' => 'encrypted',
            'submitted_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime',
            'deadline_at' => 'immutable_datetime', 'terminal_at' => 'immutable_datetime',
            'held_at' => 'immutable_datetime', 'hold_lifted_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'withdrawal_id';
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<ThriftPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ThriftPlan::class, 'thrift_plan_id');
    }

    /** @return HasMany<WithdrawalEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(WithdrawalEvent::class)->orderBy('effective_at')->orderBy('id');
    }
}
