<?php

namespace App\Http\Controllers;

use App\Enums\AgentEligibilityCapability;
use App\Enums\CustomerStatus;
use App\Enums\UserType;
use App\Models\CustomerAssignment;
use App\Models\User;
use App\Services\AgentEligibilityService;
use App\Services\ResourceScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AgentProfileController extends Controller
{
    /**
     * Display the specified agent profile.
     */
    public function show(
        Request $request,
        string $agent,
        ResourceScopeService $resourceScopeService,
        AgentEligibilityService $agentEligibilityService,
    ): Response {
        /** @var User $viewer */
        $viewer = $request->user();

        // 1. Resolve through ResourceScopeService
        $agentProfile = $resourceScopeService->forAgents($viewer)
            ->where('agent_id', $agent)
            ->with(['user', 'user.roles'])
            ->first();

        // Missing or unauthorized IDs return identical generic unavailable response
        if (! $agentProfile) {
            abort(404, 'Record unavailable.');
        }

        Gate::authorize('view', $agentProfile);

        $user = $agentProfile->user;

        // 2. Readiness evaluation
        $canRead = $agentEligibilityService->evaluate($agentProfile, AgentEligibilityCapability::ReadAssignedCustomers);
        $canPerformWork = $agentEligibilityService->evaluate($agentProfile, AgentEligibilityCapability::PerformAssignedCustomerWork);
        $canReceiveAssignment = $agentEligibilityService->evaluate($agentProfile, AgentEligibilityCapability::ReceiveAssignment);

        // 3. Current customer assignments breakdown
        $currentAssignments = CustomerAssignment::query()
            ->where('agent_profile_id', $agentProfile->id)
            ->where('is_current', 1)
            ->with(['customerProfile.user'])
            ->get();

        $activeCount = 0;
        $inactiveCount = 0;
        $restrictedCount = 0;
        $archivedCount = 0;
        $assignedCustomerList = [];

        foreach ($currentAssignments as $assignment) {
            $customer = $assignment->customerProfile;
            if (! $customer) {
                continue;
            }

            match ($customer->operational_status) {
                CustomerStatus::Active => $activeCount++,
                CustomerStatus::Inactive => $inactiveCount++,
                CustomerStatus::Restricted => $restrictedCount++,
                CustomerStatus::Archived => $archivedCount++,
            };

            $assignedCustomerList[] = [
                'id' => $customer->customer_id,
                'name' => $customer->user?->name ?? 'Unknown',
                'operational_status' => $customer->operational_status->value,
                'operational_status_label' => ucfirst($customer->operational_status->value),
                'account_state' => $customer->user?->account_state?->value,
                'assigned_since' => $assignment->effective_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
            ];
        }

        // 4. Build viewer-specific profile data
        $profileData = [
            'id' => $agentProfile->agent_id,
            'name' => $user?->name ?? 'Unknown',
            'email' => $user?->email,
            'phone' => $agentProfile->phone,
            'address' => $agentProfile->address,
            'employment_date' => $agentProfile->employment_date?->timezone('Africa/Lagos')->format('Y-m-d'),
            'photo_url' => $agentProfile->profile_photo_path ? route('agents.photo', $agentProfile->agent_id) : null,
            'operational_status' => $agentProfile->operational_status->value,
            'operational_status_label' => ucfirst($agentProfile->operational_status->value),
            'account_state' => $user?->account_state?->value,
            'account_state_label' => $user?->account_state ? ucfirst(str_replace('_', ' ', $user->account_state->value)) : 'Unknown',
            'registered_at' => $agentProfile->created_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
            'registered_at_iso' => $agentProfile->created_at?->timezone('Africa/Lagos')->toIso8601String(),
            'readiness' => [
                'can_read_assigned' => [
                    'eligible' => $canRead->isEligible(),
                    'reason' => $canRead->reasonCode?->value,
                    'explanation' => $canRead->message,
                ],
                'can_perform_work' => [
                    'eligible' => $canPerformWork->isEligible(),
                    'reason' => $canPerformWork->reasonCode?->value,
                    'explanation' => $canPerformWork->message,
                ],
                'can_receive_assignment' => [
                    'eligible' => $canReceiveAssignment->isEligible(),
                    'reason' => $canReceiveAssignment->reasonCode?->value,
                    'explanation' => $canReceiveAssignment->message,
                ],
            ],
            'assignments_summary' => [
                'active_count' => $activeCount,
                'inactive_count' => $inactiveCount,
                'restricted_count' => $restrictedCount,
                'archived_count' => $archivedCount,
                'total_active_workload' => $activeCount + $inactiveCount + $restrictedCount,
                'customers' => $assignedCustomerList,
            ],
            'invitation_and_access' => [
                'account_state' => $user?->account_state?->value,
                'mfa_confirmed' => $user?->hasConfirmedTwoFactor() ?? false,
            ],
            // Explicit unavailable sections
            'collections_and_reconciliation' => [
                'status' => 'unavailable',
                'message' => 'Collections and reconciliation data unavailable until Module 07',
            ],
            // Contextual actions
            'actions' => [
                'can_reassign_customers' => false,
                'reassign_message' => 'Customer reassignment will be available in CAM-T12.',
                'can_manage_lifecycle' => false,
                'lifecycle_message' => 'Agent status & lifecycle management will be available in CAM-T10/T11.',
            ],
        ];

        // Internal notes: STRICTLY OMITTED from Agent self-service responses
        if ($viewer->user_type === UserType::Admin) {
            $profileData['notes'] = $agentProfile->notes;
            $profileData['lifecycle'] = [
                'operational_status' => $agentProfile->operational_status->value,
                'created_at' => $agentProfile->created_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                'updated_at' => $agentProfile->updated_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
            ];
        }

        return Inertia::render('agents/Show', [
            'agent' => $profileData,
            'viewer_type' => $viewer->user_type->value,
        ]);
    }
}
