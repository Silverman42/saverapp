<?php

namespace App\Services;

use App\Support\MoneyFormatter;

class MetricDefinitionService
{
    public const VERSION = 9;

    /** @var array<string, string> Dashboard metric codes whose report section reproduces the same total at the same scope. */
    public const DRILL_DOWN_REPORTS = [
        'customer_liability' => 'customer-summary',
        'live_payout_reservations' => 'customer-summary',
        'available_savings' => 'customer-summary',
        'received_savings' => 'contributions',
        'received_fees' => 'contributions',
        'cash_received' => 'contributions',
        'posted_receipt_count' => 'contributions',
        'agent_receivable' => 'reconciliation',
        'business_cash' => 'reconciliation',
    ];

    /** @var array<string, array{title: string, source: string, date_basis: string, definition: string}> */
    private const DEFINITIONS = [
        'customer_liability' => ['title' => 'Customer savings liability', 'source' => 'ledger', 'date_basis' => 'at cutoff', 'definition' => 'Posted Customer savings liability, including archived Customers within the selected authorized scope.'],
        'live_payout_reservations' => ['title' => 'Live payout reservations', 'source' => 'withdrawals', 'date_basis' => 'at cutoff', 'definition' => 'Live gross reservations; not posted savings debits.'],
        'available_savings' => ['title' => 'Available savings', 'source' => 'ledger + withdrawals', 'date_basis' => 'at cutoff', 'definition' => 'Verified liability less live gross reservations, subtracted once.'],
        'received_savings' => ['title' => 'Savings received', 'source' => 'verified collection receipts', 'date_basis' => 'received date', 'definition' => 'Posted receipt savings component; excludes external fees and remittances.'],
        'received_fees' => ['title' => 'External fees received', 'source' => 'verified collection receipts', 'date_basis' => 'received date', 'definition' => 'External fee tender; separate from savings and recognized earnings.'],
        'cash_received' => ['title' => 'Cash tender received', 'source' => 'verified collection receipts', 'date_basis' => 'received date', 'definition' => 'Savings plus external fee tender on cash receipts only, counted once; excludes transfer, POS and configured Other methods.'],
        'total_received' => ['title' => 'Total tender received', 'source' => 'verified collection receipts', 'date_basis' => 'received date', 'definition' => 'Savings plus external fee tender across supported methods, counted once; excludes remittances and subsequent clearing settlements.'],
        'posted_receipt_count' => ['title' => 'Posted receipts', 'source' => 'verified collection receipts', 'date_basis' => 'received date', 'definition' => 'Distinct verified receipts, not slot allocations.'],
        'agent_receivable' => ['title' => 'Agent cash responsibility', 'source' => 'ledger', 'date_basis' => 'at cutoff', 'definition' => 'Original recording-Agent responsibility after posted remittances; reassignment does not transfer it.'],
        'business_cash' => ['title' => 'Business cash custody', 'source' => 'ledger', 'date_basis' => 'at cutoff', 'definition' => 'Posted business cash asset; separate from profit and Customer savings.'],
        'batch_count' => ['title' => 'Collection batches', 'source' => 'collection batches', 'date_basis' => 'current batch state', 'definition' => 'Distinct original-Agent method batch versions, including linked supplements.'],
        'receipt_count' => ['title' => 'Batch receipts', 'source' => 'verified collection receipts', 'date_basis' => 'current batch state', 'definition' => 'Distinct posted receipts in the selected method batches.'],
        'gross_tender' => ['title' => 'Batch gross tender', 'source' => 'verified collection receipts', 'date_basis' => 'current batch state', 'definition' => 'Savings plus external fee tender counted once; not Customer liability or fee earnings.'],
        'savings_component' => ['title' => 'Batch savings component', 'source' => 'verified collection receipts', 'date_basis' => 'current batch state', 'definition' => 'Savings principal within batch gross tender.'],
        'external_fee_component' => ['title' => 'Batch external fee component', 'source' => 'verified collection receipts', 'date_basis' => 'current batch state', 'definition' => 'External fee tender within batch gross tender; not an additional cash receipt.'],
        'confirmed_remittances' => ['title' => 'Confirmed remittances', 'source' => 'verified cash remittances', 'date_basis' => 'current batch state', 'definition' => 'Posted transfers from original-Agent responsibility to business custody; not new Customer contributions.'],
        'bank_received' => ['title' => 'Confirmed bank custody', 'source' => 'verified bank receipts and clearing settlements', 'date_basis' => 'current batch state', 'definition' => 'Direct bank tender plus posted clearing-to-bank settlements; settlement is not new Customer tender.'],
        'pending_settlement' => ['title' => 'Pending clearing settlement', 'source' => 'verified clearing receipts and settlements', 'date_basis' => 'current batch state', 'definition' => 'Original clearing tender less confirmed bank settlement, separate from Agent cash debt.'],
        'unremitted' => ['title' => 'Unremitted cash', 'source' => 'verified cash batches', 'date_basis' => 'current batch state', 'definition' => 'Agent-custody tender less confirmed cash remittances; separate from unexplained variance and Customer liability.'],
        'open_exceptions' => ['title' => 'Open batch exceptions', 'source' => 'collection exceptions', 'date_basis' => 'current batch state', 'definition' => 'Unresolved owner exceptions in selected method batches; no financial write-off is implied.'],
        'gross_assessed' => ['title' => 'Gross fees assessed', 'source' => 'fee obligation entries', 'date_basis' => 'entry recorded time', 'definition' => 'Original fee assessments recorded in the selected period; not cash or recognized income.'],
        'assessment_increase' => ['title' => 'Assessment increases', 'source' => 'fee obligation entries', 'date_basis' => 'entry recorded time', 'definition' => 'Approved increases to fee assessments recorded in the selected period; not cash.'],
        'assessment_reduction' => ['title' => 'Assessment reductions', 'source' => 'fee obligation entries', 'date_basis' => 'entry recorded time', 'definition' => 'Approved reductions to fee assessments recorded in the selected period; not cash.'],
        'waived_fees' => ['title' => 'Fees waived', 'source' => 'fee obligation entries', 'date_basis' => 'entry recorded time', 'definition' => 'Waivers recorded in the selected period; no cash was received.'],
        'external_fees_received' => ['title' => 'External fees received', 'source' => 'verified collection fee components', 'date_basis' => 'receipt received date', 'definition' => 'Posted external fee component only; excludes savings principal and does not count gross tender again.'],
        'outstanding_fee_obligations' => ['title' => 'Outstanding fee obligations', 'source' => 'fee obligation entries', 'date_basis' => 'current entry-derived balance', 'definition' => 'Distinct scoped obligations with a positive unpaid balance; not cash received or recognized income.'],
        'outstanding_fees' => ['title' => 'Outstanding fees', 'source' => 'fee obligation entries', 'date_basis' => 'current entry-derived balance', 'definition' => 'Assessed fees plus increases, less reductions, net settlement and waivers. Does not debit Customer savings by itself.'],
        'gross_withdrawal' => ['title' => 'Gross savings debit', 'source' => 'ledger', 'date_basis' => 'business occurrence date', 'definition' => 'Customer savings debited by posted payouts; equals amount paid plus withdrawal fees and deductions.'],
        'amount_paid' => ['title' => 'Amount paid', 'source' => 'ledger', 'date_basis' => 'business occurrence date', 'definition' => 'Cash delivered or bank transfer sent by posted payouts; returns and compensation are linked separately.'],
        'withdrawal_fee' => ['title' => 'Withdrawal fees', 'source' => 'ledger', 'date_basis' => 'business occurrence date', 'definition' => 'Fees recognized in original payout postings.'],
        'withdrawal_deduction' => ['title' => 'Withdrawal deductions', 'source' => 'ledger', 'date_basis' => 'business occurrence date', 'definition' => 'Non-fee deductions in original payout postings.'],
        'fee_activity_amount' => ['title' => 'Posted amount', 'source' => 'ledger', 'date_basis' => 'business occurrence date', 'definition' => 'Amounts of the posted fee activity in this section, by business occurrence date; compensation is linked, not netted.'],
        'fee_activity_count' => ['title' => 'Posted records', 'source' => 'ledger', 'date_basis' => 'business occurrence date', 'definition' => 'Distinct posted records in this section.'],
        'posted_payouts' => ['title' => 'Posted payouts', 'source' => 'ledger', 'date_basis' => 'business occurrence date', 'definition' => 'Distinct posted payout postings, including those later compensated.'],
        'refund_payable' => ['title' => 'Refund payable', 'source' => 'ledger', 'date_basis' => 'at cutoff', 'definition' => 'Posted refund amounts owed to Customers and not yet paid; not cash and not fee income.'],
        'customers_with_refund_payable' => ['title' => 'Customers owed refunds', 'source' => 'ledger', 'date_basis' => 'at cutoff', 'definition' => 'Distinct scoped Customers with a positive posted refund payable.'],
        'unreconciled_batches' => ['title' => 'Cash batches needing reconciliation', 'source' => 'verified collection batches', 'date_basis' => 'current batch state', 'definition' => 'Distinct original-Agent batch revisions that are not reconciled, including unresolved supplements.'],
        'required_slots' => ['title' => 'Agreed contribution slots', 'source' => 'current plan terms and slots', 'date_basis' => 'current plan revision', 'definition' => 'Agreed slots across the selected plans; a target, not received money.'],
        'fully_funded_slots' => ['title' => 'Fully funded slots', 'source' => 'verified collection allocations', 'date_basis' => 'current allocation state', 'definition' => 'Active slots whose net allocated principal equals the agreed slot amount.'],
        'partially_funded_slots' => ['title' => 'Partially funded slots', 'source' => 'verified collection allocations', 'date_basis' => 'current allocation state', 'definition' => 'Active slots with positive funding below their agreed amount.'],
        'unfunded_slots' => ['title' => 'Unfunded slots', 'source' => 'current plan slots and verified allocations', 'date_basis' => 'current allocation state', 'definition' => 'Active slots with no allocated principal; this does not classify them as due or missed.'],
        'funded_principal' => ['title' => 'Funded principal', 'source' => 'verified collection allocations', 'date_basis' => 'current allocation state', 'definition' => 'Principal allocated to active plan slots, separate from current savings liability.'],
        'remaining_scheduled_target' => ['title' => 'Remaining scheduled target', 'source' => 'current plan terms and verified allocations', 'date_basis' => 'current allocation state', 'definition' => 'Agreed target less allocated principal; not an amount due or an available savings balance.'],
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
