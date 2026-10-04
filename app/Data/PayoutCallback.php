<?php

namespace App\Data;

use Carbon\CarbonImmutable;

final readonly class PayoutCallback
{
    public function __construct(
        public string $providerKey,
        public string $eventId,
        public string $eventType,
        public string $idempotencyKey,
        public CarbonImmutable $signatureTimestamp,
        public string $payloadHash,
        public ?string $providerReference = null,
        public ?int $amountKobo = null,
        public ?string $returnReference = null,
        public ?CarbonImmutable $occurredAt = null,
    ) {}
}
