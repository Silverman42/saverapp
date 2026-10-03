<?php

namespace App\Models;

use App\Enums\FeeRuleBasis;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

/**
 * @property int $id
 * @property int $customer_profile_id
 * @property string $source_type
 * @property string $source_id
 * @property int $fee_rule_id
 * @property int $fee_rule_version
 * @property string $name
 * @property FeeRuleKind $kind
 * @property FeeRuleModel $model
 * @property FeeRuleTiming $timing
 * @property FeeRuleBasis $basis
 * @property FeeSettlementSource $settlement_source
 * @property string $currency
 * @property int $amount_kobo
 * @property int|null $basis_points
 * @property int $basis_amount_kobo
 * @property string $customer_description
 * @property int|null $early_termination_policy_version
 * @property string|null $early_termination_description
 * @property CarbonImmutable|null $acknowledged_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'customer_profile_id',
    'source_type',
    'source_id',
    'fee_rule_id',
    'fee_rule_version',
    'name',
    'kind',
    'model',
    'timing',
    'basis',
    'settlement_source',
    'currency',
    'amount_kobo',
    'basis_points',
    'basis_amount_kobo',
    'customer_description',
    'acknowledged_at',
    'early_termination_policy_version',
    'early_termination_description',
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
            'timing' => FeeRuleTiming::class,
            'basis' => FeeRuleBasis::class,
            'settlement_source' => FeeSettlementSource::class,
            'amount_kobo' => 'integer',
            'basis_points' => 'integer',
            'basis_amount_kobo' => 'integer',
            'acknowledged_at' => 'datetime',
            'early_termination_policy_version' => 'integer',
        ];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::updating(function (FeeSnapshot $snapshot): void {
            if ($snapshot->isDirty(['customer_profile_id', 'source_type', 'source_id', 'fee_rule_id', 'fee_rule_version', 'name', 'kind', 'model', 'timing', 'basis', 'settlement_source', 'currency', 'amount_kobo', 'basis_points', 'basis_amount_kobo', 'customer_description', 'early_termination_policy_version', 'early_termination_description'])) {
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
        return $this->model === FeeRuleModel::NoFee
            || ($this->model === FeeRuleModel::Fixed && $this->amount_kobo === 0)
            || ($this->model === FeeRuleModel::Percentage && ($this->basis_points ?? 0) === 0);
    }

    /**
     * Format amount in standard Nigerian Naira (e.g. ₦2,000.00).
     */
    public function formattedAmount(): string
    {
        return match ($this->model) {
            FeeRuleModel::NoFee => 'Free',
            FeeRuleModel::OneDay => 'One contractual day',
            FeeRuleModel::Percentage => $this->formattedPercentage(),
            FeeRuleModel::Fixed => MoneyFormatter::formatNaira($this->amount_kobo),
        };
    }

    private function formattedPercentage(): string
    {
        $basisPoints = $this->basis_points ?? 0;
        $wholePercent = intdiv($basisPoints, 100);
        $fraction = $basisPoints % 100;

        return rtrim(rtrim($wholePercent.'.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT), '0'), '.').'%';
    }

    /**
     * Check if customer has acknowledged the fee.
     */
    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }
}
