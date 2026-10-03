<?php

namespace App\Models;

use App\Enums\FeeObligationEntryType;
use App\Enums\FeeObligationStatus;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * @property int $id
 * @property int $customer_profile_id
 * @property int $fee_snapshot_id
 * @property string $source_type
 * @property string $source_id
 * @property string $kind
 * @property int $amount_kobo
 * @property string $currency
 * @property FeeObligationStatus $status
 * @property string $due_condition
 * @property string $customer_description
 * @property int $created_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'customer_profile_id',
    'fee_snapshot_id',
    'source_type',
    'source_id',
    'kind',
    'amount_kobo',
    'currency',
    'due_condition',
    'customer_description',
    'created_by_user_id',
])]
class FeeObligation extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
        ];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::updating(function (FeeObligation $obligation): void {
            if ($obligation->isDirty(['customer_profile_id', 'fee_snapshot_id', 'source_type', 'source_id', 'kind', 'amount_kobo', 'currency', 'due_condition', 'created_by_user_id'])) {
                throw new RuntimeException('Fee obligation terms and attribution are immutable and cannot be modified.');
            }
        });

        static::deleting(function (FeeObligation $obligation): void {
            throw new RuntimeException('Fee obligations cannot be deleted.');
        });
    }

    /**
     * Get the customer profile associated with this obligation.
     *
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /**
     * Get the fee snapshot associated with this obligation.
     *
     * @return BelongsTo<FeeSnapshot, $this>
     */
    public function feeSnapshot(): BelongsTo
    {
        return $this->belongsTo(FeeSnapshot::class);
    }

    /**
     * Get the user who created this obligation.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->outstandingAmountKobo() > 0 && $this->settledAmountKobo() === 0 && $this->waivedAmountKobo() === 0;
    }

    public function isSettled(): bool
    {
        return $this->outstandingAmountKobo() === 0 && $this->settledAmountKobo() > 0 && $this->waivedAmountKobo() === 0;
    }

    /**
     * Get the immutable assessment and lifecycle entries for this obligation.
     *
     * @return HasMany<FeeObligationEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(FeeObligationEntry::class)->orderBy('id');
    }

    public function getStatusAttribute(): FeeObligationStatus
    {
        $totals = $this->entryTotals();
        if ($totals[FeeObligationEntryType::Assessment->value] === 0) {
            throw new RuntimeException('Fee obligation has no assessment entry; status is unavailable.');
        }

        $assessed = $this->assessedAmountKobo();
        if ($assessed === 0) {
            return FeeObligationStatus::Cancelled;
        }

        $settled = $this->settledAmountKobo();
        $waived = $this->waivedAmountKobo();
        $outstanding = $this->outstandingAmountKobo();

        if ($outstanding > 0) {
            if ($settled > 0 && $waived > 0) {
                return FeeObligationStatus::PartiallySettledAndWaived;
            }

            return $settled > 0 || $waived > 0 ? FeeObligationStatus::PartiallySettled : FeeObligationStatus::Pending;
        }

        if ($settled === 0 && $waived > 0) {
            return FeeObligationStatus::Waived;
        }

        return $waived > 0 ? FeeObligationStatus::PartiallySettledAndWaived : FeeObligationStatus::Settled;
    }

    public function outstandingAmountKobo(): int
    {
        $totals = $this->entryTotals();
        $assessed = $this->assessedAmountKoboFromTotals($totals);
        $settled = $totals[FeeObligationEntryType::Settlement->value];
        $reversed = $totals[FeeObligationEntryType::SettlementReversal->value];
        if ($reversed > $settled) {
            throw new RuntimeException('Settlement reversals exceed settled fee amounts.');
        }

        $netSettled = $settled - $reversed;
        $waived = $totals[FeeObligationEntryType::Waiver->value];
        if ($waived > PHP_INT_MAX - $netSettled || $netSettled + $waived > $assessed) {
            throw new RuntimeException('Fee obligation entries exceed the authoritative assessed balance.');
        }

        return $assessed - $netSettled - $waived;
    }

    public function assessedAmountKobo(): int
    {
        return $this->assessedAmountKoboFromTotals($this->entryTotals());
    }

    /**
     * @param  array<string, int>  $totals
     */
    private function assessedAmountKoboFromTotals(array $totals): int
    {
        $assessment = $totals[FeeObligationEntryType::Assessment->value];
        $increase = $totals[FeeObligationEntryType::AssessmentCorrectionIncrease->value];
        if ($increase > PHP_INT_MAX - $assessment) {
            throw new \OverflowException('Corrected fee assessment exceeds the supported integer range.');
        }

        $grossAssessment = $assessment + $increase;
        $reduction = $totals[FeeObligationEntryType::AssessmentCorrection->value];

        if ($reduction > $grossAssessment) {
            throw new RuntimeException('Fee assessment corrections exceed the original assessment.');
        }

        return $grossAssessment - $reduction;
    }

    public function settledAmountKobo(): int
    {
        $totals = $this->entryTotals();

        $settled = $totals[FeeObligationEntryType::Settlement->value] - $totals[FeeObligationEntryType::SettlementReversal->value];
        if ($settled < 0) {
            throw new RuntimeException('Settlement reversals exceed settled fee amounts.');
        }

        return $settled;
    }

    public function waivedAmountKobo(): int
    {
        return $this->entryTotals()[FeeObligationEntryType::Waiver->value];
    }

    /**
     * @return array<string, int>
     */
    private function entryTotals(): array
    {
        $originalAmount = filter_var($this->getRawOriginal('amount_kobo'), FILTER_VALIDATE_INT);
        if ($this->currency !== 'NGN' || $originalAmount === false || $originalAmount < 1) {
            throw new RuntimeException('Original fee obligation amount or currency is unavailable.');
        }
        $totals = array_fill_keys(array_column(FeeObligationEntryType::cases(), 'value'), 0);
        $entries = $this->relationLoaded('entries') ? $this->getRelation('entries') : $this->entries()->get();
        $assessmentCount = 0;

        foreach ($entries as $entry) {
            $rawType = $entry->getRawOriginal('entry_type');
            $type = is_string($rawType) ? FeeObligationEntryType::tryFrom($rawType) : null;
            if ($type === null) {
                throw new RuntimeException('Fee obligation entry type is unavailable.');
            }
            $entryType = $type->value;
            $amountKobo = filter_var($entry->getRawOriginal('amount_kobo'), FILTER_VALIDATE_INT);
            if ($entry->fee_obligation_id !== $this->id || $entry->currency !== 'NGN'
                || $amountKobo === false || $amountKobo < 1) {
                throw new RuntimeException('Fee obligation entry amount, currency or attribution is unavailable.');
            }
            if ($entryType === FeeObligationEntryType::Assessment->value) {
                $assessmentCount++;
            }

            if ($amountKobo > PHP_INT_MAX - $totals[$entryType]) {
                throw new \OverflowException('Fee obligation entry totals exceed the supported integer range.');
            }

            $totals[$entryType] += $amountKobo;
        }
        if ($assessmentCount !== 1 || $totals[FeeObligationEntryType::Assessment->value] !== $originalAmount) {
            throw new RuntimeException('Original fee assessment evidence is missing or inconsistent.');
        }

        return $totals;
    }

    public function formattedAmount(): string
    {
        return MoneyFormatter::formatNaira($this->assessedAmountKobo());
    }
}
