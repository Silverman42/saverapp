<?php

namespace App\Http\Controllers;

use App\Services\InvitationManagementService;
use App\Services\ResourceScopeService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AgentInvitationController extends Controller
{
    /**
     * Resend an invitation for the specified agent.
     */
    public function resend(
        Request $request,
        string $agent,
        ResourceScopeService $scopeService,
        InvitationManagementService $service,
    ): RedirectResponse {
        $agentProfile = $scopeService->forAgents($request->user())
            ->where('agent_id', $agent)
            ->first();

        if (! $agentProfile) {
            abort(404, 'Record unavailable.');
        }

        Gate::authorize('manage', $agentProfile);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $service->resend($agentProfile, $request->user(), $validated['reason'] ?? null);

        Toast::success('Invitation resent', 'Agent invitation resent successfully.');

        return back();
    }

    /**
     * Correct the email address for an invited agent and issue a new invitation.
     */
    public function correctEmail(
        Request $request,
        string $agent,
        ResourceScopeService $scopeService,
        InvitationManagementService $service,
    ): RedirectResponse {
        $agentProfile = $scopeService->forAgents($request->user())
            ->where('agent_id', $agent)
            ->first();

        if (! $agentProfile) {
            abort(404, 'Record unavailable.');
        }

        Gate::authorize('manage', $agentProfile);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $service->correctEmail(
            $agentProfile,
            $request->user(),
            $validated['email'],
            $validated['reason'],
        );

        Toast::success('Email corrected', 'Agent email corrected and new invitation issued.');

        return back();
    }

    /**
     * Cancel the active invitation for the specified agent.
     */
    public function cancel(
        Request $request,
        string $agent,
        ResourceScopeService $scopeService,
        InvitationManagementService $service,
    ): RedirectResponse {
        $agentProfile = $scopeService->forAgents($request->user())
            ->where('agent_id', $agent)
            ->first();

        if (! $agentProfile) {
            abort(404, 'Record unavailable.');
        }

        Gate::authorize('manage', $agentProfile);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $service->cancel($agentProfile, $request->user(), $validated['reason']);

        Toast::success('Invitation cancelled', 'Agent invitation cancelled.');

        return back();
    }
}
