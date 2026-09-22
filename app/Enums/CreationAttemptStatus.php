<?php

namespace App\Enums;

enum CreationAttemptStatus: string
{
    case InProgress = 'in_progress';
    case Committed = 'committed';
    case Failed = 'failed';
}
