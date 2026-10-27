<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Staff evidence that an approved withdrawal was paid outside the platform (cash in hand or a manual bank transfer).
 *
 * @property int $id
 * @property int $withdrawal_request_id
 * @property string $channel
 * @property string|null $external_reference
 * @property CarbonImmutable $paid_on
 * @property string $note
 * @property int $amount_kobo
 * @property int $recorded_by_user_id
 * @property string $attempt_reference
 * @property string $payload_hash
 * @property int $ledger_posting_group_id
 */
#[Fillable(['withdrawal_request_id', 'channel', 'external_reference', 'paid_on', 'note', 'amount_kobo', 'recorded_by_user_id', 'attempt_reference', 'payload_hash', 'ledger_posting_group_id'])]
class OfflinePayout extends Model
{
    /** @var list<string> */
    public const CHANNELS = ['cash', 'bank_transfer'];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['amount_kobo' => 'integer', 'paid_on' => 'immutable_date', 'note' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Offline payout evidence is immutable.');
        });
        static::deleting(function (): never {
            throw new RuntimeException('Offline payout evidence cannot be deleted.');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
