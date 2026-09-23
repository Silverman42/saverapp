<?php

namespace App\Http\Controllers;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\AgentEligibilityCapability;
use App\Enums\CustomerStatus;
use App\Enums\UserType;
use App\Models\CustomerAssignment;
use App\Models\Invitation;
use App\Models\User;
use App\Services\AgentEligibilityService;
use App\Services\AuthorizationService;
use App\Services\ResourceScopeService;
use Illuminate\Database\Eloquent\Builder;
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
        AuthorizationService $authorizationService,
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
        $assignmentFilters = $request->validate([
            'assignments_search' => ['nullable', 'string', 'max:100'],
            'assignments_operational_status' => ['nullable', 'string', 'in:active,inactive,restricted,archived,all'],
            'assignments_per_page' => ['nullable', 'integer', 'in:10,25,50'],
        ]);

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

        }

        $assignedCustomerQuery = CustomerAssignment::query()
            ->where('agent_profile_id', $agentProfile->id)
            ->where('is_current', 1)
            ->with(['customerProfile.user']);
        $assignmentsSearch = trim((string) ($assignmentFilters['assignments_search'] ?? ''));

        if ($assignmentsSearch !== '') {
            $assignedCustomerQuery->whereHas('customerProfile', function (Builder $customerQuery) use ($assignmentsSearch): void {
                $customerQuery->where('customer_id', 'like', "%{$assignmentsSearch}%")
                    ->orWhereHas('user', function (Builder $userQuery) use ($assignmentsSearch): void {
                        $userQuery->where('name', 'like', "%{$assignmentsSearch}%");
                    });
            });
        }

        $assignmentOperationalStatus = $assignmentFilters['assignments_operational_status'] ?? '';
        if ($assignmentOperationalStatus !== '' && $assignmentOperationalStatus !== 'all') {
            $assignedCustomerQuery->whereHas('customerProfile', function (Builder $customerQuery) use ($assignmentOperationalStatus): void {
                $customerQuery->where('operational_status', $assignmentOperationalStatus);
            });
        }

        $assignmentsPerPage = (int) ($assignmentFilters['assignments_per_page'] ?? 10);
        $assignedCustomers = $assignedCustomerQuery->latest('effective_at')
            ->paginate($assignmentsPerPage)
            ->withQueryString()
            ->through(function (CustomerAssignment $assignment): array {
                $customer = $assignment->customerProfile;

                return [
                    'id' => $customer?->customer_id ?? 'Unknown',
                    'name' => $customer?->user?->name ?? 'Unknown',
                    'operational_status' => $customer?->operational_status?->value,
                    'operational_status_label' => $customer?->operational_status?->displayName() ?? 'Unknown',
                    'account_state' => $customer?->user?->account_state?->value,
                    'assigned_since' => $assignment->effective_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                ];
            });

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
            'version' => $agentProfile->version,
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
                'can_edit' => Gate::forUser($viewer)->allows('update', $agentProfile),
                'edit_message' => Gate::forUser($viewer)->allows('update', $agentProfile)
                    ? null
                    : 'Your current access does not allow editing this Agent profile.',
                'can_reassign_customers' => false,
                'reassign_message' => 'Customer reassignment will be available in CAM-T12.',
                'can_manage_lifecycle' => false,
                'lifecycle_message' => 'Agent status & lifecycle management will be available in CAM-T10/T11.',
                'can_manage_invitation' => $viewer->user_type === UserType::Admin
                    && $authorizationService->allows($viewer, AdminPermission::AgentsManage)
                    && $user?->account_state === AccountState::Invited,
            ],
        ];

        // Internal notes and invitation controls: STRICTLY OMITTED from Agent self-service responses
        if ($viewer->user_type === UserType::Admin) {
            $latestInvitation = $user
                ? Invitation::query()->where('user_id', $user->id)->latest('generation')->first()
                : null;

            if ($latestInvitation) {
                $profileData['invitation'] = [
                    'status' => $latestInvitation->status->value,
                    'status_label' => $latestInvitation->status->displayName(),
                    'delivery_status' => $latestInvitation->delivery_status->value,
                    'delivery_status_label' => $latestInvitation->delivery_status->displayName(),
                    'generation' => $latestInvitation->generation,
                    'can_resend' => $latestInvitation->canResend() && $user?->account_state === AccountState::Invited,
                    'sent_at' => $latestInvitation->sent_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                    'opened_at' => $latestInvitation->opened_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                    'expires_at' => $latestInvitation->expires_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                    'delivery_error' => $latestInvitation->delivery_error,
                ];
            }

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
            'assigned_customers' => $assignedCustomers,
            'assignment_filters' => [
                'search' => $assignmentsSearch,
                'operational_status' => $assignmentOperationalStatus,
                'per_page' => $assignmentsPerPage,
            ],
        ]);
    }
}
