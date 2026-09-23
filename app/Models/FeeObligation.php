<?php

namespace App\Models;

use App\Enums\FeeObligationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * @property int $id
 * @property int $customer_profile_id
 * @property int $fee_snapshot_id
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
    'kind',
    'amount_kobo',
    'currency',
    'status',
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
            'status' => FeeObligationStatus::class,
        ];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::updating(function (FeeObligation $obligation): void {
            if ($obligation->isDirty(['customer_profile_id', 'fee_snapshot_id', 'kind', 'amount_kobo', 'currency', 'due_condition', 'created_by_user_id'])) {
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
        return $this->status === FeeObligationStatus::Pending;
    }

    public function isSettled(): bool
    {
        return $this->status === FeeObligationStatus::Settled;
    }

    public function formattedAmount(): string
    {
        $naira = $this->amount_kobo / 100;

        return '₦'.number_format($naira, 2);
    }
}
