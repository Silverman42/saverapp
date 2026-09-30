<?php

return [
    'cash_compensation_enabled' => (bool) env('CASH_COMPENSATION_ENABLED', false),
    'cash_enabled' => false,
    'cash_certified' => false,
    'cash_method_version' => 1,
    'quote_minutes' => 10,
    'review_days' => 7,
    'restored_review_hours' => 24,
];
