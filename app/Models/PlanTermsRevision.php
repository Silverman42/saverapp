<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * @property int $thrift_plan_id
 * @property int $revision
 * @property string $name
 * @property int $contribution_amount_kobo
 * @property string $currency
 * @property string $start_date
 * @property int $contribution_days
 * @property string $frequency
 * @property string $timezone
 * @property int $business_version
 * @property int $expected_gross_kobo
 * @property int $fee_snapshot_id
 * @property string|null $customer_visible_notes
 */
#[Fillable([
    'thrift_plan_id', 'revision', 'name', 'contribution_amount_kobo', 'currency', 'start_date',
    'contribution_days', 'frequency', 'timezone', 'business_version', 'expected_gross_kobo',
    'fee_snapshot_id', 'customer_visible_notes', 'reason', 'attested_by_user_id', 'attested_at',
])]
class PlanTermsRevision extends Model
{
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'contribution_amount_kobo' => 'integer',
            'contribution_days' => 'integer',
            'business_version' => 'integer',
            'expected_gross_kobo' => 'integer',
            'attested_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Plan terms revisions are immutable.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Plan terms revisions cannot be deleted.');
        });
    }

    /** @return BelongsTo<ThriftPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ThriftPlan::class, 'thrift_plan_id');
    }

    /** @return BelongsTo<FeeSnapshot, $this> */
    public function feeSnapshot(): BelongsTo
    {
        return $this->belongsTo(FeeSnapshot::class);
    }

    /** @return HasMany<ContributionSlot, $this> */
    public function slots(): HasMany
    {
        return $this->hasMany(ContributionSlot::class, 'plan_terms_revision_id');
    }
}
