<?php

namespace App\Models;

use App\Services\CollectionRemittanceProof;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * @property int $id
 * @property int $collection_batch_id
 * @property int $agent_profile_id
 * @property int $confirmed_by_user_id
 * @property int|null $ledger_posting_group_id
 * @property string $handoff_reference
 * @property int $amount_kobo
 * @property string $handoff_date
 */
#[Fillable([
    'collection_batch_id', 'agent_profile_id', 'confirmed_by_user_id', 'ledger_posting_group_id',
    'handoff_reference', 'amount_kobo', 'handoff_date', 'receiving_location', 'source_attestation',
])]
class CashRemittance extends Model
{
    /** @return BelongsTo<LedgerPostingGroup, $this> */
    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(LedgerPostingGroup::class, 'ledger_posting_group_id');
    }

    protected static function booted(): void
    {
        static::updating(function (CashRemittance $remittance): void {
            if ($remittance->isDirty(['collection_batch_id', 'agent_profile_id', 'confirmed_by_user_id', 'handoff_reference',
                'amount_kobo', 'handoff_date', 'receiving_location', 'source_attestation'])
                || ($remittance->isDirty('ledger_posting_group_id')
                    && ($remittance->getOriginal('ledger_posting_group_id') !== null || $remittance->ledger_posting_group_id === null))) {
                throw new RuntimeException('Confirmed cash handoff evidence is immutable.');
            }
            if ($remittance->isDirty('ledger_posting_group_id')) {
                $remittance->unsetRelation('postingGroup');
                app(CollectionRemittanceProof::class)->assertPosted($remittance);
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Cash handoff history cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return ['amount_kobo' => 'integer'];
    }
}
