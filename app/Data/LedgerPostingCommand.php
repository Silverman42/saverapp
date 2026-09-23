<?php

namespace App\Data;

use App\Enums\FeeLedgerPostingType;
use App\Models\User;
use DateTimeImmutable;

class LedgerPostingCommand
{
    /**
     * @param  list<LedgerPostingLine>  $lines
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public readonly FeeLedgerPostingType $eventType,
        public readonly string $idempotencyKey,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly string $currency,
        public readonly ?User $actor,
        public readonly ?int $customerProfileId,
        public readonly ?DateTimeImmutable $occurredAt,
        public readonly array $lines,
        public readonly string $customerDescription,
        public readonly array $metadata = [],
    ) {}
}
