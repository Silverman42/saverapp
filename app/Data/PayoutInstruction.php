<?php

namespace App\Data;

final readonly class PayoutInstruction
{
    public function __construct(
        public string $idempotencyKey,
        public int $amountKobo,
        public string $currency,
        public string $destinationToken,
        public string $bankCode,
        public string $narration,
    ) {}
}
