<?php

return [
    'cash_compensation_enabled' => (bool) env('CASH_COMPENSATION_ENABLED', false),
    'cash_enabled' => false,
    'cash_certified' => false,
    'cash_method_version' => 1,
    'deduction_enabled' => false,
    'deduction_category_key' => null,
    'bank_enabled' => false,
    'bank_certified' => false,
    'bank_method_version' => 1,
    'bank_compensation_enabled' => false,
    'bank' => [
        'provider' => env('PAYOUT_PROVIDER', 'unavailable'),
        'callback_secret' => env('PAYOUT_CALLBACK_SECRET'),
        'callback_tolerance_seconds' => 300,
        'not_found_definitive_after_minutes' => 30,
        'first_check_after_seconds' => 60,
        'recheck_after_seconds' => 120,
        'unknown_alert_minutes' => 60,
        'fake_store' => env('PAYOUT_FAKE_STORE'),
    ],
    'quote_minutes' => 10,
    'review_days' => 7,
    'restored_review_hours' => 24,
];
