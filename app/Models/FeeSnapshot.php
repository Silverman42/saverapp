<?php

namespace App\Models;

use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

/**
 * @property int $id
 * @property int $customer_profile_id
 * @property int $fee_rule_id
 * @property int $fee_rule_version
 * @property string $name
 * @property FeeRuleKind $kind
 * @property FeeRuleModel $model
 * @property string $currency
 * @property int $amount_kobo
 * @property string $customer_description
 * @property CarbonImmutable|null $acknowledged_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'customer_profile_id',
    'fee_rule_id',
    'fee_rule_version',
    'name',
    'kind',
    'model',
    'currency',
    'amount_kobo',
    'customer_description',
    'acknowledged_at',
])]
class FeeSnapshot extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fee_rule_version' => 'integer',
            'kind' => FeeRuleKind::class,
            'model' => FeeRuleModel::class,
            'amount_kobo' => 'integer',
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::updating(function (FeeSnapshot $snapshot): void {
            if ($snapshot->isDirty(['customer_profile_id', 'fee_rule_id', 'fee_rule_version', 'name', 'kind', 'model', 'currency', 'amount_kobo', 'customer_description'])) {
                throw new RuntimeException('Fee snapshot terms are immutable and cannot be modified.');
            }
        });

        static::deleting(function (FeeSnapshot $snapshot): void {
            throw new RuntimeException('Fee snapshots cannot be deleted.');
        });
    }

    /**
     * Get the customer profile associated with this snapshot.
     *
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /**
     * Get the fee rule associated with this snapshot.
     *
     * @return BelongsTo<FeeRule, $this>
     */
    public function feeRule(): BelongsTo
    {
        return $this->belongsTo(FeeRule::class);
    }

    /**
     * Get the fee obligation resulting from this snapshot (if non-zero).
     *
     * @return HasOne<FeeObligation, $this>
     */
    public function obligation(): HasOne
    {
        return $this->hasOne(FeeObligation::class);
    }

    /**
     * Check if terms have zero amount.
     */
    public function isZero(): bool
    {
        return $this->model === FeeRuleModel::NoFee || $this->amount_kobo === 0;
    }

    /**
     * Format amount in standard Nigerian Naira (e.g. ₦2,000.00).
     */
    public function formattedAmount(): string
    {
        if ($this->isZero()) {
            return 'Free';
        }

        $naira = $this->amount_kobo / 100;

        return '₦'.number_format($naira, 2);
    }

    /**
     * Check if customer has acknowledged the fee.
     */
    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }
}
