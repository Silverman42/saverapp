<?php

namespace App\Services;

use App\Support\MoneyFormatter;

class MetricDefinitionService
{
    public const VERSION = 1;

    /** @var array<string, array{title: string, source: string, date_basis: string, definition: string}> */
    private const DEFINITIONS = [
        'customer_liability' => ['title' => 'Customer savings liability', 'source' => 'ledger', 'date_basis' => 'at cutoff', 'definition' => 'Posted Customer savings liability, including archived Customers within the selected authorized scope.'],
        'live_payout_reservations' => ['title' => 'Live payout reservations', 'source' => 'withdrawals', 'date_basis' => 'at cutoff', 'definition' => 'Live gross reservations; not posted savings debits.'],
        'available_savings' => ['title' => 'Available savings', 'source' => 'ledger + withdrawals', 'date_basis' => 'at cutoff', 'definition' => 'Verified liability less live gross reservations, subtracted once.'],
        'received_savings' => ['title' => 'Savings received', 'source' => 'verified collection receipts', 'date_basis' => 'received date', 'definition' => 'Posted receipt savings component; excludes external fees and remittances.'],
        'received_fees' => ['title' => 'External fees received', 'source' => 'verified collection receipts', 'date_basis' => 'received date', 'definition' => 'External fee tender; separate from savings and recognized earnings.'],
        'cash_received' => ['title' => 'Cash tender received', 'source' => 'verified collection receipts', 'date_basis' => 'received date', 'definition' => 'Savings plus external fee tender, counted once; cash is the only enabled receipt method.'],
        'posted_receipt_count' => ['title' => 'Posted receipts', 'source' => 'verified collection receipts', 'date_basis' => 'received date', 'definition' => 'Distinct verified receipts, not slot allocations.'],
        'agent_receivable' => ['title' => 'Agent cash responsibility', 'source' => 'ledger', 'date_basis' => 'at cutoff', 'definition' => 'Original recording-Agent responsibility after posted remittances; reassignment does not transfer it.'],
        'business_cash' => ['title' => 'Business cash custody', 'source' => 'ledger', 'date_basis' => 'at cutoff', 'definition' => 'Posted business cash asset; separate from profit and Customer savings.'],
    ];

    /** @return array<string, mixed> */
    public function make(string $code, string $title, ?int $value, string $unit, string $source, string $dateBasis, string $definition): array
    {
        $metadata = self::DEFINITIONS[$code] ?? ['title' => $title, 'source' => $source, 'date_basis' => $dateBasis, 'definition' => $definition];

        return ['code' => $code, ...$metadata, 'definition_version' => self::VERSION, 'value' => $value, 'unit' => $unit,
            'display' => $value === null ? 'Not available' : ($unit === 'NGN' ? MoneyFormatter::formatNaira($value) : number_format($value)),
            'scope_note' => $definition, 'drill_down' => null,
            'drill_down_reason' => 'Owner links reauthorize at their current cutoff; matching historical aggregate drill-down is unavailable.'];
    }
}
