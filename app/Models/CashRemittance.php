<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

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
