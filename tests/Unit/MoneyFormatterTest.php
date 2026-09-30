<?php

use App\Support\MoneyFormatter;

test('signed kobo decimal formatting preserves every digit at integer boundaries', function (): void {
    expect(MoneyFormatter::decimal(-1))->toBe('-0.01')
        ->and(MoneyFormatter::decimal(999999999999))->toBe('9999999999.99')
        ->and(MoneyFormatter::decimal(PHP_INT_MIN))->toBe('-92233720368547758.08')
        ->and(MoneyFormatter::decimal(PHP_INT_MAX))->toBe('92233720368547758.07');
});
