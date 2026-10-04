<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * @property int $id
 * @property string $return_reference
 * @property int $bank_payout_attempt_id
 * @property int $amount_kobo
 * @property string $status
 * @property int|null $return_posting_group_id
 * @property int|null $ledger_posting_group_id
 * @property CarbonImmutable|null $consumed_at
 */
#[Fillable(['return_reference', 'bank_payout_attempt_id', 'provider_key', 'provider_return_reference', 'amount_kobo', 'status', 'evidence', 'payload_hash', 'return_posting_group_id', 'ledger_posting_group_id', 'consumed_at'])]
class BankPayoutReturn extends Model
{
    /** @var list<string> */
    protected $hidden = ['evidence', 'payload_hash'];

    protected function casts(): array
    {
        return ['amount_kobo' => 'integer', 'evidence' => 'encrypted', 'consumed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $return): void {
            if ($return->isDirty(['return_reference', 'bank_payout_attempt_id', 'provider_key', 'provider_return_reference', 'amount_kobo', 'evidence', 'payload_hash'])) {
                throw new RuntimeException('Recorded provider return evidence is immutable.');
            }
        });
        static::deleting(function (): never {
            throw new RuntimeException('Provider return evidence cannot be deleted.');
        });
    }

    /** @return BelongsTo<BankPayoutAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(BankPayoutAttempt::class, 'bank_payout_attempt_id');
    }
}
