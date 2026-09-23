<?php

namespace App\Models;

use App\Enums\FeeObligationEntryType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * @property int $id
 * @property int $fee_obligation_id
 * @property FeeObligationEntryType $entry_type
 * @property int $amount_kobo
 * @property string $currency
 * @property string $source_type
 * @property string $source_id
 * @property string $idempotency_key
 * @property int|null $actor_user_id
 * @property string|null $reason
 * @property string|null $customer_description
 * @property string|null $ledger_posting_reference
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'fee_obligation_id',
    'entry_type',
    'amount_kobo',
    'currency',
    'source_type',
    'source_id',
    'idempotency_key',
    'actor_user_id',
    'reason',
    'customer_description',
    'ledger_posting_reference',
])]
class FeeObligationEntry extends Model
{
    protected function casts(): array
    {
        return [
            'entry_type' => FeeObligationEntryType::class,
            'amount_kobo' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Fee obligation entries are immutable.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Fee obligation entries cannot be deleted.');
        });
    }

    /**
     * Get the obligation affected by this entry.
     *
     * @return BelongsTo<FeeObligation, $this>
     */
    public function obligation(): BelongsTo
    {
        return $this->belongsTo(FeeObligation::class, 'fee_obligation_id');
    }

    /**
     * Get the user who recorded this entry.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
