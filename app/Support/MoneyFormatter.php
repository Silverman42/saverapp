<?php

namespace App\Support;

class MoneyFormatter
{
    public static function decimal(int $amountKobo): string
    {
        $whole = intdiv($amountKobo, 100);
        $fraction = abs($amountKobo % 100);

        return ($amountKobo < 0 ? '-' : '').ltrim((string) $whole, '-').'.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }

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
