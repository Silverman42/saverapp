<?php

use App\Support\FeePercentageCalculator;

test('percentage fees round half up in integer kobo', function (int $basisKobo, int $basisPoints, int $expectedKobo): void {
    expect(FeePercentageCalculator::calculate($basisKobo, $basisPoints))->toBe($expectedKobo);
})->with([
    'two percent of one hundred thousand naira' => [10_000_000, 200, 200_000],
    'below half a kobo' => [149, 100, 1],
    'exactly half a kobo' => [150, 100, 2],
    'above half a kobo' => [151, 100, 2],
    'rounds down to zero' => [49, 100, 0],
    'half kobo minimum basis' => [1, 5000, 1],
    'zero basis' => [0, 200, 0],
    'zero rate' => [12345, 0, 0],
    'full rate' => [12345, 10000, 12345],
    'largest safe full rate basis' => [922337203685477, 10000, 922337203685477],
    'maximum integer with zero rate' => [PHP_INT_MAX, 0, 0],
]);

test('percentage fees reject negative or excessive rates and bases', function (int $basisKobo, int $basisPoints): void {
    expect(fn (): int => FeePercentageCalculator::calculate($basisKobo, $basisPoints))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'negative basis' => [-1, 200],
    'negative rate' => [100, -1],
    'rate over one hundred percent' => [100, 10001],
]);

test('percentage fees reject multiplication overflow before calculation', function (): void {
    expect(fn (): int => FeePercentageCalculator::calculate(922337203685478, 10000))
        ->toThrow(OverflowException::class);
});
