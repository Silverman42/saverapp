<?php

namespace App\Support;

interface RecoveryOwner
{
    public function operation(): string;

    public function snapshot(int $sourceId): RecoverySource;

    public function execute(int $sourceId): void;

    public function failed(int $sourceId, string $state, string $code, ?string $availableAt, bool $countAttempt = true): void;
}
