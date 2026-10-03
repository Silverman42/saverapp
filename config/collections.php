<?php

return [
    'noncash_enabled' => (bool) env('COLLECTIONS_NONCASH_ENABLED', false),
    'evidence_scanner_binary' => env('COLLECTION_EVIDENCE_SCANNER_BINARY'),
    'evidence_scanner_version' => env('COLLECTION_EVIDENCE_SCANNER_VERSION'),
    'evidence_scan_timeout' => 30,
    'settlement_enabled' => (bool) env('PLAN_SETTLEMENT_ENABLED', false),
    'receipt_corrections_enabled' => (bool) env('RECEIPT_CORRECTIONS_ENABLED', false),
    'enabled' => (bool) env('COLLECTIONS_ENABLED', false),
    'local_certified' => (bool) env('COLLECTIONS_LOCAL_CERTIFIED', false),
];
