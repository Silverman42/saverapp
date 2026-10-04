<?php

namespace App\Enums;

enum PayoutProviderOutcome: string
{
    case Accepted = 'accepted';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case NotFound = 'not_found';
}
