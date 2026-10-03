<?php

return [
    'savings_applications_enabled' => (bool) env('FEE_SAVINGS_APPLICATIONS_ENABLED', false),
    'savings_application_corrections_enabled' => false,
    'deduction_corrections_enabled' => false,
    'refunds_enabled' => (bool) env('FEE_REFUNDS_ENABLED', false),
    'cash_disbursements_enabled' => (bool) env('CASH_DISBURSEMENTS_ENABLED', false),
    'manual_charges_enabled' => (bool) env('MANUAL_CHARGES_ENABLED', false),
];
