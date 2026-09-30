<?php

namespace App\Services;

use App\Models\LedgerPostingGroup;

class ReversalCapabilityRegistry
{
    /**
     * Resolve only explicitly enabled and supported owning contracts.
     */
    public function resolve(LedgerPostingGroup $original): ?ReversalOwnerContract
    {
        if (config('fees.deduction_corrections_enabled', false) && $original->source_type === 'manual_charge' && $original->event_type === 'other_deduction') {
            return app(DeductionReversalOwner::class);
        }
        if (config('withdrawals.cash_compensation_enabled', false) && $original->source_type === 'withdrawal') {
            return app(WithdrawalReversalOwner::class);
        }

        return config('collections.receipt_corrections_enabled', false) && $original->source_type === 'collection_receipt'
            && in_array($original->event_type, ['cash_contribution', 'external_fee_receipt'], true)
            ? app(CollectionReversalOwner::class) : null;
    }
}
