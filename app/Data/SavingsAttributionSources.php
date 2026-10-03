<?php

namespace App\Data;

use App\Models\LedgerPostingGroup;
use Illuminate\Support\Collection;
use stdClass;

final readonly class SavingsAttributionSources
{
    /**
     * @param  Collection<int|string, stdClass>  $receipts
     * @param  Collection<int|string, stdClass>  $obligations
     * @param  Collection<int|string, stdClass>  $charges
     * @param  Collection<int|string, stdClass>  $reversals
     * @param  Collection<int|string, stdClass>  $originalGroups
     * @param  Collection<int|string, stdClass>  $applications
     * @param  Collection<int|string, stdClass>  $withdrawals
     * @param  Collection<int|string, stdClass>  $refunds
     * @param  Collection<int, LedgerPostingGroup>  $feeCompensations
     * @param  Collection<int, int>  $registrationRefundCycles
     */
    public function __construct(
        public bool $prepared,
        public Collection $receipts,
        public Collection $obligations,
        public Collection $charges = new Collection,
        public Collection $reversals = new Collection,
        public Collection $originalGroups = new Collection,
        public Collection $withdrawals = new Collection,
        public Collection $applications = new Collection,
        public Collection $refunds = new Collection,
        public Collection $registrationRefundCycles = new Collection,
        public Collection $feeCompensations = new Collection,
    ) {}
}
