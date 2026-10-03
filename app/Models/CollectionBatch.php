<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['method_identity', 'custody_account_code', 'collection_method_version_id', 'business_version', 'agent_profile_id', 'received_date', 'timezone', 'revision', 'predecessor_batch_id', 'status', 'version', 'frozen_at'])]
class CollectionBatch extends Model
{
    protected function casts(): array
    {
        return ['collection_method_version_id' => 'integer', 'business_version' => 'integer', 'revision' => 'integer', 'version' => 'integer', 'frozen_at' => 'immutable_datetime'];
    }

    /** @return HasMany<CollectionReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(CollectionReceipt::class)->whereNull('replacement_reversal_id');
    }

    /** @return HasMany<CashRemittance, $this> */
    public function remittances(): HasMany
    {
        return $this->hasMany(CashRemittance::class);
    }
}
