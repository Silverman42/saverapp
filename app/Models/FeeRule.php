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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * @property int $id
 * @property int $version
 * @property string $name
 * @property FeeRuleKind $kind
 * @property FeeRuleModel $model
 * @property FeeRuleTiming $timing
 * @property FeeRuleBasis $basis
 * @property FeeSettlementSource $settlement_source
 * @property string $currency
 * @property int $amount_kobo
 * @property int|null $basis_points
 * @property string $customer_description
 * @property CarbonImmutable $effective_at
 * @property CarbonImmutable|null $retired_at
 * @property int $published_by_user_id
 * @property string $publication_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'version',
    'name',
    'kind',
    'rule_key',
    'model',
    'timing',
    'basis',
    'basis_points',
    'settlement_source',
    'currency',
    'amount_kobo',
    'customer_description',
    'effective_at',
    'retired_at',
    'published_by_user_id',
    'publication_reason',
])]
class FeeRule extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'kind' => FeeRuleKind::class,
            'model' => FeeRuleModel::class,
            'timing' => FeeRuleTiming::class,
            'basis' => FeeRuleBasis::class,
            'settlement_source' => FeeSettlementSource::class,
            'amount_kobo' => 'integer',
            'basis_points' => 'integer',
            'effective_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::updating(function (FeeRule $rule): void {
            if ($rule->isDirty(['version', 'name', 'kind', 'rule_key', 'model', 'timing', 'basis', 'basis_points', 'settlement_source', 'currency', 'amount_kobo', 'customer_description', 'effective_at', 'published_by_user_id', 'publication_reason'])) {
                throw new RuntimeException('Published fee rule terms are immutable and cannot be modified.');
            }
        });

        static::deleting(function (FeeRule $rule): void {
            throw new RuntimeException('Published fee rules cannot be deleted.');
        });
    }

    /**
     * Get the user who published this rule.
     *
     * @return BelongsTo<User, $this>
     */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /**
     * Get snapshots created under this rule.
     *
     * @return HasMany<FeeSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(FeeSnapshot::class);
    }

    /**
     * Scope to currently effective registration rule.
     *
     * @param  Builder<FeeRule>  $query
     * @return Builder<FeeRule>
     */
    public function scopeCurrentRegistration(Builder $query): Builder
    {
        return $query->where('kind', FeeRuleKind::Registration->value)
            ->where('effective_at', '<=', now())
            ->where(function (Builder $query): void {
                $query->whereNull('retired_at')->orWhere('retired_at', '>', now());
            })
            ->latest('version');
    }

    /**
     * Scope to currently selectable plan fee rules.
     *
     * @param  Builder<FeeRule>  $query
     * @return Builder<FeeRule>
     */
    public function scopeCurrentPlanOptions(Builder $query): Builder
    {
        return $query->where('kind', FeeRuleKind::Plan->value)
            ->where('effective_at', '<=', now())
            ->where(function (Builder $query): void {
                $query->whereNull('retired_at')->orWhere('retired_at', '>', now());
            })
            ->orderBy('rule_key')
            ->orderByDesc('version');
    }

    /**
     * Check if this rule is an explicit zero/no-fee rule.
     */
    public function isZero(): bool
    {
        return $this->model === FeeRuleModel::NoFee
            || ($this->model === FeeRuleModel::Percentage && $this->basis_points === 0);
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
}
