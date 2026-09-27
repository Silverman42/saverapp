<?php

namespace App\Enums;

enum ExternalOutcome: string
{
    case ConfirmedSuccess = 'confirmed_success';
    case SafeToRetry = 'safe_to_retry';
    case Unknown = 'unknown';
}
