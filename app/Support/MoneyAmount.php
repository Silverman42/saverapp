<?php

namespace App\Support;

class MoneyAmount
{
    public static function parseNairaToKobo(string $amount): int
    {
        $normalizedAmount = trim($amount);

        if (! preg_match('/\A(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?\z/', $normalizedAmount, $matches)) {
            throw new \InvalidArgumentException('Enter a non-negative amount with no more than two decimal places.');
        }

        if (strlen($matches[1]) > 10) {
            throw new \InvalidArgumentException('The amount exceeds the maximum supported single fee.');
        }

        $naira = (int) $matches[1];
        if ($naira > 9_999_999_999) {
            throw new \InvalidArgumentException('The amount exceeds the maximum supported single fee.');
        }

        $fraction = str_pad($matches[2] ?? '', 2, '0');
        $amountKobo = ($naira * 100) + (int) $fraction;

        if ($amountKobo > 999_999_999_999) {
            throw new \InvalidArgumentException('The amount exceeds the maximum supported single fee.');
        }

        return $amountKobo;
    }
}
