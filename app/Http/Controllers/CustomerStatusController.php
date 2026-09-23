<?php

namespace App\Http\Controllers;

use App\Enums\AgentEligibilityCapability;
use App\Enums\CustomerStatus;
use App\Http\Requests\CustomerStatusTransitionRequest;
use App\Models\CustomerStatusHistory;
use App\Models\CustomerStatusNotificationIntent;
use App\Models\User;
use App\Services\AgentEligibilityService;
use App\Services\CustomerStatusManagementService;
use App\Services\ResourceScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CustomerStatusController extends Controller
{
    public function edit(
        Request $request,
        string $customer,
        ResourceScopeService $resourceScopeService,
        AgentEligibilityService $eligibilityService,
    ): Response {
        /** @var User $actor */
        $actor = $request->user();
        $profile = $resourceScopeService->forCustomers($actor)
            ->where('customer_id', $customer)
            ->with(['user', 'currentAssignment.agentProfile.user', 'statusHistories.changedBy', 'statusHistories.notificationIntents'])
            ->first();
        if ($profile === null) {
            abort(404, 'Record unavailable.');
        }

        Gate::authorize('manageOperationalStatus', $profile);

        $assignedAgent = $profile->currentAssignment?->agentProfile;
        $currentAgent = null;
        if ($assignedAgent !== null) {
            $eligibility = $eligibilityService->evaluate($assignedAgent, AgentEligibilityCapability::PerformAssignedCustomerWork);
            $currentAgent = [
                'name' => $assignedAgent->user->name,
                'operational_status' => $assignedAgent->operational_status->value,
                'account_state' => $assignedAgent->user->account_state->value,
                'is_eligible' => $eligibility->isEligible,
                'eligibility_message' => $eligibility->message,
            ];
        }

        return Inertia::render('customers/Status', [
            'customer' => [
                'id' => $profile->customer_id,
                'name' => $profile->user->name,
                'operational_status' => $profile->operational_status->value,
                'operational_status_label' => $profile->operational_status->displayName(),
                'account_state' => $profile->user?->account_state?->value,
                'account_state_label' => $profile->user?->account_state
                    ? ucfirst(str_replace('_', ' ', $profile->user->account_state->value))
                    : 'Unknown',
                'version' => $profile->version,
                'current_agent' => $currentAgent,
            ],
            'allowed_targets' => $this->allowedTargets($profile->operational_status),
            'financial_sections' => [
                'summary' => 'Financial summary is unavailable until Module 10.',
                'plans' => 'Plan details are unavailable until Module 06.',
                'collections' => 'Collection details are unavailable until Module 07.',
                'withdrawals' => 'Withdrawal details are unavailable until Module 08.',
            ],
            'history' => $profile->statusHistories->map(fn (CustomerStatusHistory $entry): array => [
                'from_status' => $entry->from_status?->displayName() ?? 'Not set',
                'to_status' => $entry->to_status->displayName(),
                'reason' => $entry->reason,
                'customer_explanation' => $entry->customer_facing_explanation,
                'changed_by' => $entry->changedBy->name,
                'effective_at' => $entry->created_at->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                'notifications' => $entry->notificationIntents->map(fn (CustomerStatusNotificationIntent $intent): array => [
                    'channel' => $intent->channel,
                    'audience' => $intent->audience_type,
                    'status' => $intent->status,
                    'failure_reason' => $intent->failure_reason,
                ])->values(),
            ])->values(),
        ]);
    }

    public function update(
        CustomerStatusTransitionRequest $request,
        string $customer,
        ResourceScopeService $resourceScopeService,
        CustomerStatusManagementService $statusService,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $profile = $resourceScopeService->forCustomers($actor)
            ->where('customer_id', $customer)
            ->first();
        if ($profile === null) {
            abort(404, 'Record unavailable.');
        }

        Gate::authorize('manageOperationalStatus', $profile);

        $data = $request->validated();
        $statusService->transition(
            actor: $actor,
            customer: $profile,
            targetStatus: CustomerStatus::from($data['target_status']),
            expectedVersion: (int) $data['version'],
            reason: $data['reason'],
            customerExplanation: $data['customer_explanation'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Customer status updated.']);

        return to_route('customers.status.edit', $profile->customer_id);
    }

    /** @return array<int, array{value: string, label: string}> */
    protected function allowedTargets(CustomerStatus $current): array
    {
        $targets = match ($current) {
            CustomerStatus::Active => [CustomerStatus::Active, CustomerStatus::Inactive, CustomerStatus::Restricted],
            CustomerStatus::Inactive => [CustomerStatus::Inactive, CustomerStatus::Active, CustomerStatus::Restricted],
            CustomerStatus::Restricted => [CustomerStatus::Restricted, CustomerStatus::Active, CustomerStatus::Inactive],
            CustomerStatus::Archived => throw new AuthorizationException('Archived Customers must use the restoration workflow.'),
        };

        return array_map(static fn (CustomerStatus $status): array => [
            'value' => $status->value,
            'label' => $status->displayName(),
        ], $targets);
    }
}
