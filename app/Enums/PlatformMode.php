<?php

namespace App\Enums;

enum PlatformMode: string
{
    case Normal = 'normal';
    case Degraded = 'degraded';
    case FinancialFreeze = 'financial_freeze';
    case ReadOnly = 'read_only';
    case Unavailable = 'unavailable';

    public function message(): string
    {
        return match ($this) {
            self::Normal => '',
            self::Degraded => 'Some services are currently unavailable. Available actions still require their usual checks.',
            self::FinancialFreeze => 'Financial changes are temporarily paused. You can still view available records.',
            self::ReadOnly => 'Maintenance is in progress. Available records can be viewed, but changes are paused.',
            self::Unavailable => 'The service is temporarily unavailable. Please try again later.',
        };
    }
}
