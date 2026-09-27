<?php

namespace App\Support;

use App\Enums\ExternalOutcome;

interface ExternalOutcomeLookup
{
    public function lookup(string $owner, string $operationKey, string $payloadHash): ExternalOutcome;
}
