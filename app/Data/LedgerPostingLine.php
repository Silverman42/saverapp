<?php

namespace App\Data;

use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;

class LedgerPostingLine
{
    public function __construct(
        public readonly LedgerAccountCode $accountCode,
        public readonly LedgerEntrySide $side,
        public readonly int $amountKobo,
        public readonly ?int $customerProfileId = null,
        public readonly ?int $agentProfileId = null,
        public readonly ?int $feeObligationId = null,
    ) {}
}
