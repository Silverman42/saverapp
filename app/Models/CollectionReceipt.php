<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $receipt_reference
 * @property int $customer_profile_id
 * @property int|null $thrift_plan_id
 * @property int $recording_agent_profile_id
 * @property int|null $savings_posting_group_id
 * @property string $received_date
 * @property string $timezone
 * @property int $savings_amount_kobo
 * @property int $fee_amount_kobo
 * @property int $tender_amount_kobo
 * @property CarbonImmutable $recorded_at
 */
#[Fillable([
    'receipt_reference', 'attempt_reference', 'payload_hash', 'customer_profile_id', 'thrift_plan_id',
    'recording_agent_profile_id', 'assignment_id', 'collection_batch_id', 'recorded_by_user_id',
    'savings_posting_group_id', 'received_date', 'timezone', 'business_version', 'tender_amount_kobo',
    'savings_amount_kobo', 'fee_amount_kobo', 'late_reason', 'notes', 'recorded_at',
])]
class CollectionReceipt extends Model
{
    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_datetime',
            'tender_amount_kobo' => 'integer',
            'savings_amount_kobo' => 'integer',
            'fee_amount_kobo' => 'integer',
            'business_version' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'receipt_reference';
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

    /** @return BelongsTo<CollectionBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(CollectionBatch::class, 'collection_batch_id');
    }

    /** @return HasMany<CollectionAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(CollectionAllocation::class);
    }
}
