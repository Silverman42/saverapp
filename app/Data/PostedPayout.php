<?php

namespace App\Data;

use App\Enums\LedgerAccountCode;

/**
 * Proof that one withdrawal was paid through one rail and posted to the ledger in one posting group.
 */
final readonly class PostedPayout
{
    public function __construct(
        public int $withdrawalRequestId,
        public string $rail,
        public int $attemptId,
        public int $groupId,
        public int $amountKobo,
        public LedgerAccountCode $payoutAccount,
        public string $eventType,
        public bool $finalityProven,
    ) {}
}
