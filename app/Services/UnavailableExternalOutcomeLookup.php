<?php

namespace App\Services;

use App\Enums\ExternalOutcome;
use App\Support\ExternalOutcomeLookup;

class UnavailableExternalOutcomeLookup implements ExternalOutcomeLookup
{
    public function lookup(string $owner, string $operationKey, string $payloadHash): ExternalOutcome
    {
        return ExternalOutcome::Unknown;
    }
}
