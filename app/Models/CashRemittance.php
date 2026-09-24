<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

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
    protected function casts(): array
    {
        return ['amount_kobo' => 'integer'];
    }
}
