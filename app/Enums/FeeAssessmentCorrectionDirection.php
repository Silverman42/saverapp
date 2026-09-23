<?php

namespace App\Enums;

enum FeeAssessmentCorrectionDirection: string
{
    case Reduce = 'reduce';
    case Increase = 'increase';
}
