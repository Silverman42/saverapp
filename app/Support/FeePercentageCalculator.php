<?php

namespace App\Support;

class FeePercentageCalculator
{
    public static function calculate(int $basisKobo, int $basisPoints): int
    {
        if ($basisKobo < 0 || $basisPoints < 0 || $basisPoints > 10_000) {
            throw new \InvalidArgumentException('Fee percentage inputs are outside the supported range.');
        }

        if ($basisPoints !== 0 && $basisKobo > intdiv(PHP_INT_MAX - 5_000, $basisPoints)) {
            throw new \OverflowException('Fee percentage calculation exceeds the supported integer range.');
        }

        return intdiv(($basisKobo * $basisPoints) + 5_000, 10_000);
    }
}
