<?php

namespace App\Support;

readonly class RecoveryLease
{
    public function __construct(public int $workId, public int $token, public string $owner) {}
}
