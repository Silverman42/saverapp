<?php

namespace App\Services;

use App\Support\MoneyFormatter;

class MetricDefinitionService
{
    public const VERSION = 3;

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
        'batch_count' => ['title' => 'Cash batches', 'source' => 'collection batches', 'date_basis' => 'current batch state', 'definition' => 'Distinct original-Agent cash batch versions, including linked supplements.'],
        'receipt_count' => ['title' => 'Batch receipts', 'source' => 'verified collection receipts', 'date_basis' => 'current batch state', 'definition' => 'Distinct posted receipts in the selected cash batches.'],
        'gross_tender' => ['title' => 'Batch gross tender', 'source' => 'verified collection receipts', 'date_basis' => 'current batch state', 'definition' => 'Savings plus external fee tender counted once; not Customer liability or fee earnings.'],
        'savings_component' => ['title' => 'Batch savings component', 'source' => 'verified collection receipts', 'date_basis' => 'current batch state', 'definition' => 'Savings principal within batch gross tender.'],
        'external_fee_component' => ['title' => 'Batch external fee component', 'source' => 'verified collection receipts', 'date_basis' => 'current batch state', 'definition' => 'External fee tender within batch gross tender; not an additional cash receipt.'],
        'confirmed_remittances' => ['title' => 'Confirmed remittances', 'source' => 'verified cash remittances', 'date_basis' => 'current batch state', 'definition' => 'Posted transfers from original-Agent responsibility to business custody; not new Customer contributions.'],
        'unremitted' => ['title' => 'Unremitted cash', 'source' => 'verified cash batches', 'date_basis' => 'current batch state', 'definition' => 'Batch gross tender less confirmed remittances; separate from unexplained variance and Customer liability.'],
        'open_exceptions' => ['title' => 'Open batch exceptions', 'source' => 'collection exceptions', 'date_basis' => 'current batch state', 'definition' => 'Unresolved owner exceptions in selected cash batches; no financial write-off is implied.'],
        'gross_assessed' => ['title' => 'Gross fees assessed', 'source' => 'fee obligation entries', 'date_basis' => 'entry recorded time', 'definition' => 'Original fee assessments recorded in the selected period; not cash or recognized income.'],
        'assessment_increase' => ['title' => 'Assessment increases', 'source' => 'fee obligation entries', 'date_basis' => 'entry recorded time', 'definition' => 'Approved increases to fee assessments recorded in the selected period; not cash.'],
        'assessment_reduction' => ['title' => 'Assessment reductions', 'source' => 'fee obligation entries', 'date_basis' => 'entry recorded time', 'definition' => 'Approved reductions to fee assessments recorded in the selected period; not cash.'],
        'waived_fees' => ['title' => 'Fees waived', 'source' => 'fee obligation entries', 'date_basis' => 'entry recorded time', 'definition' => 'Waivers recorded in the selected period; no cash was received.'],
        'external_fees_received' => ['title' => 'External fees received', 'source' => 'verified collection fee components', 'date_basis' => 'receipt received date', 'definition' => 'Posted external fee component only; excludes savings principal and does not count gross tender again.'],
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
