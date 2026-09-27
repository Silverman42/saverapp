<?php

namespace App\Http\Controllers;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Enums\UserType;
use App\Models\Invitation;
use App\Models\User;
use App\Services\AgentEligibilityService;
use App\Services\AuthorizationService;
use App\Services\CustomerNameCorrectionService;
use App\Services\CustomerRecoveryService;
use App\Services\FeeObligationService;
use App\Services\LedgerTransactionReadService;
use App\Services\ResourceScopeService;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CustomerProfileController extends Controller
{
    public function access(Request $request, string $customer, ResourceScopeService $scope): JsonResponse
    {
        $profile = $scope->forCustomers($request->user())->where('customer_id', $customer)->firstOrFail();
        Gate::authorize('view', $profile);
        $context = $request->validate(['context' => ['sometimes', 'in:customer,reassignment,recovery']])['context'] ?? 'customer';
        if ($context === 'reassignment') {
            Gate::authorize('reassign', $profile);
        } elseif ($context === 'recovery') {
            app(CustomerRecoveryService::class)->authorizeView($request->user(), $profile);
        }

        return response()->json(['accessible' => true])->header('Cache-Control', 'no-store');
    }

    /**
     * Display the specified customer profile.
     */
    public function show(
        Request $request,
        string $customer,
        ResourceScopeService $resourceScopeService,
        AgentEligibilityService $agentEligibilityService,
        AuthorizationService $authorizationService,
        CustomerNameCorrectionService $nameCorrectionService,
        FeeObligationService $feeObligationService,
        LedgerTransactionReadService $transactions,
    ): Response {
        /** @var User $viewer */
        $viewer = $request->user();

        // 1. Resolve through ResourceScopeService
        $customerProfile = $resourceScopeService->forCustomers($viewer)
            ->where('customer_id', $customer)
            ->with(['user', 'currentAssignment.agentProfile.user', 'feeSnapshot', 'feeObligations.feeSnapshot', 'feeObligations.entries'])
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
        $latestStatusHistory = $customerProfile->statusHistories()->first();

        // Assigned Agent data tailored to viewer role
        $assignedAgentData = null;
        if ($assignedAgent !== null) {
            if ($viewer->user_type === UserType::Customer) {
                // Customer receives permitted business contact only
                $assignedAgentData = [
                    'name' => $assignedAgentUser->name,
                    'phone' => $assignedAgent->phone,
                    'email' => $assignedAgentUser?->email,
                ];
            } else {
                // Staff / Admin receives full operational details
                $isEligible = $agentEligibilityService->canReceiveAssignment($assignedAgent);
                $assignedAgentData = [
                    'id' => $assignedAgent->agent_id,
                    'name' => $assignedAgentUser->name,
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

        $canManageInvitation = $user?->account_state === AccountState::Invited
            && (
                ($viewer->user_type === UserType::Admin && $authorizationService->allows($viewer, AdminPermission::CustomersManage))
                || ($viewer->user_type === UserType::Agent
                    && $agentEligibilityService->canPerformAssignedCustomerWork($viewer)
                    && $currentAssignment !== null
                    && $currentAssignment->agent_profile_id === $viewer->agentProfile?->id
                )
            );

        $currentPlan = $customerProfile->thriftPlans()
            ->where('open_customer_profile_id', $customerProfile->id)
            ->with('termsRevisions')
            ->first();
        $currentPlanRevision = $currentPlan?->termsRevisions->firstWhere('revision', $currentPlan->current_terms_revision);
        $canManagePlan = Gate::forUser($viewer)->allows('managePlan', $customerProfile);
        $financialPosition = $transactions->balance($viewer, $customerProfile);

        $profileData = [
            'id' => $customerProfile->customer_id,
            'name' => $user->name,
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
            'version' => $customerProfile->version,
            'status_explanation' => $latestStatusHistory?->customer_facing_explanation === null ? null : [
                'status' => $latestStatusHistory->to_status->displayName(),
                'explanation' => $latestStatusHistory->customer_facing_explanation,
                'effective_at' => $latestStatusHistory->created_at->timezone('Africa/Lagos')->format('Y-m-d H:i'),
            ],
            'assigned_agent' => $assignedAgentData,
            'relationship_history' => [
                'registered_at' => $customerProfile->created_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                'assignment_started_at' => $currentAssignment?->effective_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
            ],
            // Explicit unavailable sections
            'financial_summary' => [
                'status' => $financialPosition['status'],
                'message' => $financialPosition['status'] === 'ready'
                    ? 'Posted savings and live withdrawal reservations.'
                    : 'The ledger projection needs verification before balances can be shown.',
                'liability' => $financialPosition['status'] === 'ready'
                    ? MoneyFormatter::formatNaira($financialPosition['liability_kobo']) : null,
                'reserved' => $financialPosition['status'] === 'ready'
                    ? MoneyFormatter::formatNaira($financialPosition['reservations_kobo']) : null,
                'available' => $financialPosition['status'] === 'ready'
                    ? MoneyFormatter::formatNaira($financialPosition['available_kobo']) : null,
            ],
            'plans' => [
                'status' => 'available',
                'message' => $currentPlan === null
                    ? 'There is no open thrift plan for this Customer.'
                    : 'Agreed schedule details are available. Actual collections and savings progress remain unavailable.',
                'current_plan' => $currentPlan === null || $currentPlanRevision === null ? null : [
                    'id' => $currentPlan->plan_id,
                    'name' => $currentPlanRevision->name,
                    'status' => $currentPlan->status->value,
                    'status_label' => $currentPlan->status->displayName(),
                    'formatted_contribution_amount' => MoneyFormatter::formatNaira($currentPlanRevision->contribution_amount_kobo),
                    'start_date' => $currentPlanRevision->start_date,
                    'scheduled_end_date' => CarbonImmutable::createFromFormat('!Y-m-d', $currentPlanRevision->start_date, $currentPlanRevision->timezone)
                        ->addDays($currentPlanRevision->contribution_days - 1)->toDateString(),
                    'show_url' => route('plans.show', $currentPlan->plan_id),
                ],
                'can_create' => $canManagePlan
                    && $customerProfile->operational_status->value === 'active'
                    && $currentPlan === null,
                'create_url' => route('customers.plans.create', $customerProfile->customer_id),
                'index_url' => route('plans.index'),
            ],
            'transactions' => [
                'status' => $financialPosition['status'],
                'message' => $financialPosition['status'] === 'ready'
                    ? 'View posted activity in your current scope.'
                    : 'Transaction history is unavailable pending ledger verification.',
            ],
            'statements' => [
                'status' => $financialPosition['status'],
                'message' => $financialPosition['status'] === 'ready'
                    ? 'Preview posted savings activity. Issued statements are not yet available.'
                    : 'Statement preview is unavailable pending ledger verification.',
            ],
            // Contextual actions
            'actions' => [
                'can_edit' => Gate::forUser($viewer)->allows('update', $customerProfile),
                'edit_message' => Gate::forUser($viewer)->allows('update', $customerProfile)
                    ? null
                    : 'Your current access does not allow editing this Customer profile.',
                'can_reassign' => Gate::forUser($viewer)->allows('reassign', $customerProfile),
                'can_recover' => ($viewer->user_type === UserType::Agent && Gate::forUser($viewer)->allows('managePlan', $customerProfile)) || ($viewer->user_type === UserType::Admin && $authorizationService->allows($viewer, AdminPermission::SecurityOperationsManage)),
                'reassign_message' => 'Customer reassignment requires customers.reassign.',
                'can_archive' => in_array($customerProfile->operational_status, [CustomerStatus::Active, CustomerStatus::Inactive], true)
                    && Gate::forUser($viewer)->allows('manageLifecycle', $customerProfile),
                'archive_message' => 'Review archival checks from Manage status.',
                'can_manage_invitation' => $canManageInvitation,
                'can_manage_status' => $viewer->user_type === UserType::Admin
                    && $authorizationService->allows($viewer, AdminPermission::CustomersManage),
            ],
        ];

        $snapshot = $customerProfile->feeSnapshot;
        if ($snapshot) {
            $profileData['fee_snapshot'] = [
                'name' => $snapshot->name,
                'model' => $snapshot->model->value,
                'rule_version' => $snapshot->fee_rule_version,
                'amount_kobo' => $snapshot->amount_kobo,
                'formatted_amount' => $snapshot->formattedAmount(),
                'currency' => $snapshot->currency,
                'customer_description' => $snapshot->customer_description,
                'is_zero' => $snapshot->isZero(),
                'acknowledged_at' => $snapshot->acknowledged_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                'obligation' => $feeObligationService->customerSummary($customerProfile),
            ];
        }

        $pendingNameCorrection = $nameCorrectionService->visiblePending($customerProfile, $viewer);
        if ($pendingNameCorrection !== null) {
            $profileData['pending_name_correction'] = [
                'id' => $pendingNameCorrection->id,
                'proposed_name' => $viewer->id === $customerProfile->user_id ? $pendingNameCorrection->proposed_name : null,
                'expires_at' => $pendingNameCorrection->expires_at->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                'can_review' => $viewer->id === $customerProfile->user_id,
                'can_cancel' => $viewer->id === $pendingNameCorrection->requested_by_user_id,
            ];
        }

        // Internal notes and invitation controls: STRICTLY OMITTED from Customer viewer responses
        if ($viewer->user_type !== UserType::Customer) {
            $profileData['notes'] = $customerProfile->notes;

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
                    'can_resend' => $latestInvitation->canResend(),
                    'sent_at' => $latestInvitation->sent_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                    'opened_at' => $latestInvitation->opened_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                    'expires_at' => $latestInvitation->expires_at->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                    'delivery_error' => $latestInvitation->delivery_error,
                ];
            }
        }

        return Inertia::render('customers/Show', [
            'customer' => $profileData,
            'viewer_type' => $viewer->user_type->value,
            'fee_obligations' => $feeObligationService->customerObligations($customerProfile),
        ]);
    }
}
