<?php

namespace App\Http\Controllers;

use App\Enums\AgentEligibilityCapability;
use App\Enums\AgentStatus;
use App\Enums\CustomerStatus;
use App\Http\Requests\AgentStatusTransitionRequest;
use App\Models\AgentStatusHistory;
use App\Models\AgentStatusNotificationIntent;
use App\Models\User;
use App\Services\AgentEligibilityService;
use App\Services\AgentStatusManagementService;
use App\Services\ResourceScopeService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AgentStatusController extends Controller
{
    public function edit(Request $request, string $agent, ResourceScopeService $resourceScopeService, AgentEligibilityService $eligibilityService): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $profile = $resourceScopeService->forAgents($actor)
            ->where('agent_id', $agent)
            ->with(['user', 'statusHistories.changedBy', 'statusHistories.notificationIntents'])
            ->first();
        if ($profile === null) {
            abort(404, 'Record unavailable.');
        }

        Gate::authorize('manage', $profile);

        $user = $profile->user;
        $readiness = $eligibilityService->evaluate($profile, AgentEligibilityCapability::PerformAssignedCustomerWork);
        $assignmentReadiness = $eligibilityService->evaluate($profile, AgentEligibilityCapability::ReceiveAssignment);
        $assignments = $profile->currentAssignments()->with('customerProfile')->get();
        $counts = ['active' => 0, 'inactive' => 0, 'restricted' => 0, 'archived' => 0];
        foreach ($assignments as $assignment) {
            $status = $assignment->customerProfile?->operational_status;
            if ($status instanceof CustomerStatus) {
                $counts[$status->value]++;
            }
        }

        return Inertia::render('agents/Status', [
            'agent' => [
                'id' => $profile->agent_id,
                'name' => $user->name,
                'operational_status' => $profile->operational_status->value,
                'account_state' => $user->effectiveAccountState()->value,
                'version' => $profile->version,
                'email_verified' => $user->email_verified_at !== null,
                'mfa_confirmed' => $user->hasConfirmedTwoFactor(),
                'has_open_offboarding_case' => $profile->offboardingCases()->where('is_open', 1)->exists(),
                'readiness' => ['eligible' => $readiness->isEligible(), 'reason' => $readiness->message],
                'assignment_readiness' => ['eligible' => $assignmentReadiness->isEligible(), 'reason' => $assignmentReadiness->message],
                'assignment_counts' => $counts,
            ],
            'allowed_targets' => array_map(static fn (AgentStatus $status): array => [
                'value' => $status->value,
                'label' => $status->displayName(),
            ], AgentStatus::cases()),
            'financial_responsibilities' => [
                'collections' => 'Collections and reconciliation are unavailable until Module 07.',
                'requests' => 'Withdrawal and reversal responsibilities are unavailable until Modules 08–09.',
            ],
            'history' => $profile->statusHistories->sortByDesc('id')->map(static fn (AgentStatusHistory $entry): array => [
                'from_status' => $entry->from_status ?? 'Not set',
                'to_status' => $entry->to_status,
                'reason' => $entry->reason,
                'agent_explanation' => $entry->agent_facing_explanation,
                'changed_by' => $entry->changedBy->name,
                'effective_at' => $entry->created_at->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                'notifications' => $entry->notificationIntents->map(static fn (AgentStatusNotificationIntent $intent): array => [
                    'audience' => $intent->audience_type,
                    'channel' => $intent->channel,
                    'status' => $intent->status,
                    'failure_reason' => $intent->failure_reason,
                ])->values(),
            ])->values(),
        ]);
    }

    public function update(
        AgentStatusTransitionRequest $request,
        string $agent,
        ResourceScopeService $resourceScopeService,
        AgentStatusManagementService $statusService,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $profile = $resourceScopeService->forAgents($actor)->where('agent_id', $agent)->first();
        if ($profile === null) {
            abort(404, 'Record unavailable.');
        }

        Gate::authorize('manage', $profile);
        $data = $request->validated();
        $statusService->transition(
            actor: $actor,
            agent: $profile,
            targetStatus: AgentStatus::from($data['target_status']),
            expectedVersion: (int) $data['version'],
            reason: $data['reason'],
            agentExplanation: $data['agent_explanation'],
        );

        Toast::success('Status reviewed', 'Agent status reviewed.');

        return to_route('agents.status.edit', $profile->agent_id);
    }
}
