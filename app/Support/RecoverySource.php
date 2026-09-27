<?php

namespace App\Support;

readonly class RecoverySource
{
    public function __construct(
        public int $version,
        public string $hash,
        public string $operationKey,
        public string $state,
        public int $attempts,
        public ?string $availableAt = null,
        public ?string $deadline = null,
        public ?string $result = null,
        public bool $eligible = true,
    ) {}
}
