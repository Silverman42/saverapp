<?php

namespace App\Support;

class MoneyFormatter
{
    public static function formatNaira(int $amountKobo): string
    {
        if ($amountKobo < 0) {
            throw new \InvalidArgumentException('Monetary amounts must not be negative.');
        }

        $naira = intdiv($amountKobo, 100);
        $kobo = $amountKobo % 100;

        return '₦'.number_format($naira).'.'.str_pad((string) $kobo, 2, '0', STR_PAD_LEFT);
    }
}
