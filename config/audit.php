<?php

return [
    'enabled' => env('AUDIT_ENABLED', false),
    'payload_key_id' => env('AUDIT_PAYLOAD_KEY_ID', 'application-key-v1'),
    'retention_approved' => false,
    'expiry_enabled' => false,
    'archive_enabled' => false,
    'integrity_verified' => false,
    'exact_ip_enabled' => false,
    'search_limit_days' => 366,
    'drain_batch_size' => 100,
];
