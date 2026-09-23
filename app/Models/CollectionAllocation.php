<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['collection_receipt_id', 'contribution_slot_id', 'amount_kobo', 'is_advance'])]
class CollectionAllocation extends Model
{
    protected function casts(): array
    {
        return ['amount_kobo' => 'integer', 'is_advance' => 'boolean'];
    }

    /** @return BelongsTo<ContributionSlot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(ContributionSlot::class, 'contribution_slot_id');
    }
}
