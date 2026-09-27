<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\AgentEligibilityCapability;
use App\Enums\CustomerStatus;
use App\Models\CustomerProfile;
use App\Models\User;
use RuntimeException;
use ValueError;

class CustomerLifecycleEligibility
{
    /** @return array{eligible: bool, checks: list<array{key: string, label: string, status: string, message: string, url: ?string}>, transitions: array<string, mixed>} */
    public function preview(User $actor, CustomerProfile $customer, bool $forUpdate = false): array
    {
        $owners = [
            'plans' => ['Plans', fn (): string => app(ThriftPlanService::class)->archivalStatus($customer), 'Every cycle must be formally closed or cancelled.', route('plans.index', ['search' => $customer->customer_id])],
            'savings' => ['Savings and reservations', fn (): string => app(CollectionReadService::class)->archivalSavingsStatus($customer, $forUpdate), 'Savings liability and live reservations must be exactly zero.', route('customers.show', $customer->customer_id)],
            'withdrawals' => ['Withdrawals and payouts', fn (): string => app(WithdrawalService::class)->archivalStatus($customer), 'All requests and reservations must be finalized.', route('customers.show', $customer->customer_id)],
            'reversals' => ['Reversals and adjustments', fn (): string => app(ReversalService::class)->archivalStatus($customer), 'All corrective requests need a verified terminal outcome.', route('customers.show', $customer->customer_id)],
            'fees' => ['Fees and refunds', function () use ($customer, $forUpdate): string {
                $position = app(FeeObligationService::class)->authoritativePosition($customer, $forUpdate);

                return match ($position->lifecycleGateStatus) {
                    'available' => 'passed', 'blocked' => 'blocked', default => 'unavailable'
                };
            }, 'Every applicable fee and refund obligation must be settled.', route('customers.show', $customer->customer_id)],
            'collections' => ['Collections and reconciliation', fn (): string => app(CollectionReadService::class)->archivalStatus($customer), 'Receipts, allocations and relevant reconciliation must be verified.',
                app(AuthorizationService::class)->allows($actor, AdminPermission::ReconciliationManage) ? route('collection-batches.index') : null],
            'financial_work' => ['Pending financial work', fn (): string => app(PlatformCatalogue::class)->archivalWorkStatus($customer), 'No unclassified or unresolved financial background work may remain.', null],
        ];
        $checks = [];
        foreach ($owners as $key => [$label, $resolve, $message, $url]) {
            try {
                $status = $resolve();
            } catch (RuntimeException|ValueError) {
                $status = 'unavailable';
            }
            $checks[] = ['key' => $key, 'label' => $label, 'status' => $status,
                'message' => $status === 'passed' ? 'Verified clear.' : ($status === 'unavailable' ? 'Eligibility could not be verified. '.$message : $message),
                'url' => $url];
        }

        $eligible = collect($checks)->every(fn (array $check): bool => $check['status'] === 'passed');
        $agent = $customer->currentAssignment?->agentProfile;
        $canRestore = $customer->operational_status === CustomerStatus::Archived && $agent !== null
            && app(AgentEligibilityService::class)->evaluate($agent, AgentEligibilityCapability::PerformAssignedCustomerWork)->isEligible;

        return ['eligible' => $eligible, 'checks' => $checks, 'transitions' => [
            'version' => $customer->version, 'assignment_version' => $customer->currentAssignment?->version,
            'archive' => ['target_status' => 'archived', 'available' => $eligible && in_array($customer->operational_status, [CustomerStatus::Active, CustomerStatus::Inactive], true)],
            'restore' => ['target_status' => 'inactive', 'available' => $canRestore],
            'retained' => ['identity', 'account_access', 'assignment', 'registration_date', 'financial_history', 'archive_history'],
            'pending_name_proposals' => 'cancelled_on_archive',
        ]];
    }
}
