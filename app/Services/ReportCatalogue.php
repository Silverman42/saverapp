<?php

namespace App\Services;

use App\Enums\UserType;
use App\Models\User;

class ReportCatalogue
{
    /** @return array<string, array{title: string, basis: string, activity: bool, groups: list<string>, filters: list<string>, reason: string}> */
    public function definitions(): array
    {
        return [
            'customer-summary' => ['title' => 'Customer savings summary', 'basis' => 'Current verified savings position', 'activity' => false, 'groups' => ['status', 'current_agent'], 'filters' => ['customer', 'customer_status', 'agent', 'agent_basis'], 'reason' => 'Historical opening, movements and closing reconciliation await cutoff-history contracts.'],
            'contributions' => ['title' => 'Contributions', 'basis' => 'Posted receipts by received local date', 'activity' => true, 'groups' => ['date', 'customer', 'recording_agent', 'current_agent', 'plan'], 'filters' => ['customer', 'customer_status', 'plan', 'agent', 'agent_basis'], 'reason' => 'Compensation and net correction reporting await the financial correction owner.'],
            'withdrawals' => ['title' => 'Withdrawals', 'basis' => 'Request workflow by submitted date; amounts are requested, not posted', 'activity' => true, 'groups' => ['state', 'customer'], 'filters' => ['customer', 'customer_status', 'plan', 'state', 'agent', 'agent_basis'], 'reason' => 'Posted payout G/P/F/D and compensation reporting await verified payout execution.'],
            'fees' => ['title' => 'Fees and deductions', 'basis' => 'Fee obligation activity by recorded date; external receipts by received date', 'activity' => true, 'groups' => [], 'filters' => ['customer'], 'reason' => 'Savings applications, refunds, non-fee deductions and drawable earnings remain unavailable.'],
            'collection-performance' => ['title' => 'Collection performance', 'basis' => 'Received activity only; eligible schedule fulfillment is unavailable', 'activity' => true, 'groups' => ['date', 'customer', 'recording_agent', 'current_agent', 'plan'], 'filters' => ['customer', 'customer_status', 'plan', 'agent', 'agent_basis'], 'reason' => 'Historical eligible targets and current/catch-up/advance fulfillment require verified eligibility intervals.'],
            'reconciliation' => ['title' => 'Reconciliation and custody', 'basis' => 'Current original-Agent responsibility, business cash and verified cash batches', 'activity' => false, 'groups' => [], 'filters' => ['agent', 'agent_basis'], 'reason' => 'Historical opening/movements/closing and complete batch variance history await owner contracts.'],
            'agent-performance' => ['title' => 'Agent operations', 'basis' => 'Current portfolio; recorded receipt activity is a separate section', 'activity' => true, 'groups' => [], 'filters' => ['agent', 'agent_basis'], 'reason' => 'Historical effective service, complete task coverage and quality metrics await owner contracts. No rankings or composite scores.'],
            'plans' => ['title' => 'Plans and savings cycles', 'basis' => 'Current lifecycle and agreed targets; targets are not actual money', 'activity' => false, 'groups' => ['state', 'customer', 'current_agent'], 'filters' => ['customer', 'customer_status', 'plan', 'plan_status', 'agent', 'agent_basis'], 'reason' => 'Historical eligibility, cycle-specific settlement, closure and complete fee positions await owner contracts.'],
            'exceptions' => ['title' => 'Operational exceptions', 'basis' => 'Current pending withdrawal and reversal queues, oldest first', 'activity' => false, 'groups' => ['category', 'state'], 'filters' => ['customer', 'customer_status'], 'reason' => 'Coverage is partial: payout incidents, custody variance, fees, closure, archival, audit and delivery owners are not fully integrated.'],
        ];
    }

    /** @return array<string, mixed> */
    public function get(User $viewer, string $report): array
    {
        $definition = $this->definitions()[$report] ?? null;
        abort_if($definition === null, 404);
        abort_if($viewer->user_type === UserType::Customer && in_array($report, ['reconciliation', 'agent-performance'], true), 403);

        return ['code' => $report, ...$definition, 'export_available' => false,
            'export_reason' => 'CSV/PDF jobs require verified cutoff history, private artifact storage, rendering, retention and canonical audit.'];
    }

    /** @return list<array<string, mixed>> */
    public function forViewer(User $viewer): array
    {
        $reports = [];
        foreach (array_keys($this->definitions()) as $code) {
            if ($viewer->user_type === UserType::Customer && in_array($code, ['reconciliation', 'agent-performance'], true)) {
                continue;
            }
            $reports[] = $this->get($viewer, $code);
        }

        return $reports;
    }
}
