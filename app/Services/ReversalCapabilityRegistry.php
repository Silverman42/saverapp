<?php

namespace App\Services;

use App\Models\LedgerPostingGroup;

class ReversalCapabilityRegistry
{
    /**
     * No production source has a complete reviewed compensation contract yet.
     */
    public function resolve(LedgerPostingGroup $original): ?ReversalOwnerContract
    {
        return null;
    }
}
