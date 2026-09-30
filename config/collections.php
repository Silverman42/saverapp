<?php

return [
    'receipt_corrections_enabled' => (bool) env('RECEIPT_CORRECTIONS_ENABLED', false),
    'enabled' => (bool) env('COLLECTIONS_ENABLED', false),
    'local_certified' => (bool) env('COLLECTIONS_LOCAL_CERTIFIED', false),
];
