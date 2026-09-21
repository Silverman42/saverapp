<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Models\User;
use App\Services\AgentEligibilityService;
use App\Services\ResourceScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CustomerProfileController extends Controller
{
    /**
     * Display the specified customer profile.
     */
    public function show(
        Request $request,
        string $customer,
        ResourceScopeService $resourceScopeService,
        AgentEligibilityService $agentEligibilityService,
    ): Response {
        /** @var User $viewer */
        $viewer = $request->user();

        // 1. Resolve through ResourceScopeService
        $customerProfile = $resourceScopeService->forCustomers($viewer)
            ->where('customer_id', $customer)
            ->with(['user', 'currentAssignment.agentProfile.user'])
            ->first();

        // Missing or unauthorized IDs return identical generic unavailable response
        if (! $customerProfile) {
            abort(404, 'Record unavailable.');
        }

        Gate::authorize('view', $customerProfile);

        $user = $customerProfile->user;
        $currentAssignment = $customerProfile->currentAssignment;
        $assignedAgent = $currentAssignment?->agentProfile;
        $assignedAgentUser = $assignedAgent?->user;

        // Assigned Agent data tailored to viewer role
        $assignedAgentData = null;
        if ($assignedAgent !== null) {
            if ($viewer->user_type === UserType::Customer) {
                // Customer receives permitted business contact only
                $assignedAgentData = [
                    'name' => $assignedAgentUser?->name ?? 'Unknown',
                    'phone' => $assignedAgent->phone,
                    'email' => $assignedAgentUser?->email,
                ];
            } else {
                // Staff / Admin receives full operational details
                $isEligible = $agentEligibilityService->canReceiveAssignment($assignedAgent);
                $assignedAgentData = [
                    'id' => $assignedAgent->agent_id,
                    'name' => $assignedAgentUser?->name ?? 'Unknown',
                    'phone' => $assignedAgent->phone,
                    'email' => $assignedAgentUser?->email,
                    'operational_status' => $assignedAgent->operational_status->value,
                    'is_eligible' => $isEligible,
                ];
            }
        }

        // Next of kin sanitization (omit phone_normalized)
        $nextOfKin = null;
        if ($customerProfile->next_of_kin !== null) {
            $nok = $customerProfile->next_of_kin;
            $nextOfKin = [
                'full_name' => $nok['full_name'] ?? null,
                'relationship' => $nok['relationship'] ?? null,
                'phone' => $nok['phone'] ?? null,
                'address' => $nok['address'] ?? null,
            ];
        }

        // Build viewer-specific profile data
        $profileData = [
            'id' => $customerProfile->customer_id,
            'name' => $user?->name ?? 'Unknown',
            'email' => $user?->email,
            'phone' => $customerProfile->phone,
            'address' => $customerProfile->address,
            'gender' => $customerProfile->gender?->value,
            'occupation' => $customerProfile->occupation,
            'internal_reference' => $customerProfile->internal_reference,
            'next_of_kin' => $nextOfKin,
            'photo_url' => $customerProfile->photo_path ? route('customers.photo', $customerProfile->customer_id) : null,
            'operational_status' => $customerProfile->operational_status->value,
            'operational_status_label' => ucfirst($customerProfile->operational_status->value),
            'account_state' => $user?->account_state?->value,
            'account_state_label' => $user?->account_state ? ucfirst(str_replace('_', ' ', $user->account_state->value)) : 'Unknown',
            'registered_at' => $customerProfile->created_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
            'registered_at_iso' => $customerProfile->created_at?->timezone('Africa/Lagos')->toIso8601String(),
            'assigned_agent' => $assignedAgentData,
            'relationship_history' => [
                'registered_at' => $customerProfile->created_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                'assignment_started_at' => $currentAssignment?->effective_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
            ],
            // Explicit unavailable sections
            'financial_summary' => [
                'status' => 'unavailable',
                'message' => 'Financial summary unavailable until Module 10',
            ],
            'plans' => [
                'status' => 'unavailable',
                'message' => 'Plan details unavailable until Module 06',
            ],
            'transactions' => [
                'status' => 'unavailable',
                'message' => 'Transaction ledger unavailable until Module 10',
            ],
            'statements' => [
                'status' => 'unavailable',
                'message' => 'Statements unavailable until Module 08/10',
            ],
            // Contextual actions
            'actions' => [
                'can_edit' => false,
                'edit_message' => 'Customer profile editing will be available in CAM-T07.',
                'can_reassign' => false,
                'reassign_message' => 'Customer reassignment will be available in CAM-T12.',
                'can_archive' => false,
                'archive_message' => 'Customer archival will be available in CAM-T09.',
            ],
        ];

        // Internal notes: STRICTLY OMITTED from Customer viewer responses
        if ($viewer->user_type !== UserType::Customer) {
            $profileData['notes'] = $customerProfile->notes;
        }

        return Inertia::render('customers/Show', [
            'customer' => $profileData,
            'viewer_type' => $viewer->user_type->value,
        ]);
    }
}
