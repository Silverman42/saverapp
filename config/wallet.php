<?php

return [
    /*
     * Module 18 Customer wallet. Everything stays off until finance maps `customer_wallet_liability_ngn`
     * and the `wallet` / `collection_wallet` business features pass their release gates.
     */
    'enabled' => (bool) env('WALLET_ENABLED', false),
    'certified' => (bool) env('WALLET_CERTIFIED', false),
    'collection_enabled' => (bool) env('WALLET_COLLECTION_ENABLED', false),
    'sweep_retry_minutes' => 5,
    'sweep_alert_hours' => 24,
];
