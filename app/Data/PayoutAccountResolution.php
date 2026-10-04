<?php

namespace App\Data;

final readonly class PayoutAccountResolution
{
    public function __construct(
        public string $token,
        public string $accountName,
        public string $lastFour,
        public string $fingerprint,
        public string $bankName,
    ) {}
}
