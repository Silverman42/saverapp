<?php

namespace App\Data;

use App\Enums\PayoutProviderOutcome;
use Carbon\CarbonImmutable;

final readonly class PayoutProviderResult
{
    /**
     * @param  array<string, scalar|null>  $evidence  Sanitized proof only: never account numbers, credentials or raw provider payloads.
     */
    public function __construct(
        public PayoutProviderOutcome $outcome,
        public ?string $providerReference = null,
        public ?int $amountKobo = null,
        public ?string $currency = null,
        public ?string $destinationToken = null,
        public ?CarbonImmutable $occurredAt = null,
        public ?string $failureCode = null,
        public array $evidence = [],
    ) {}
}
