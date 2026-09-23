<?php

namespace App\Enums;

enum LedgerEntrySide: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
