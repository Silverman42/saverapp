<?php

namespace App\Http\Controllers;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Http\Requests\AgentLifecycleRequest;
use App\Models\AgentLifecycleHistory;
use App\Models\AgentProfile;
use App\Models\User;
use App\Services\AgentAccountAccessService;
use App\Services\AgentLifecycleService;
use App\Services\AgentOffboardingEligibility;
use App\Services\AuthorizationService;
use App\Services\FreshAuthenticationService;
use App\Services\ResourceScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AgentLifecycleController extends Controller
{
    public function show(Request $request, string $agent, AgentOffboardingEligibility $eligibility, AgentAccountAccessService $access, AuthorizationService $authorization): Response|RedirectResponse
    {
        $profile = $this->resolve($request, $agent)->load('user');
        $fresh = app(FreshAuthenticationService::class)->isFresh($request->user(), $request);
        if ($request->boolean('verify') && ! $fresh) {
            $request->session()->put('url.intended', route('agents.lifecycle.show', $agent));

            return redirect()->route('fresh-authentication');
        }
        $case = $profile->offboardingCases()->latest('id')->first();
        $open = $case !== null && (int) $case->is_open === 1;
        $restoration = null;
        $restorationBlocker = null;
        try {
            $restoration = $access->restorationState($profile->user)->value;
        } catch (ValidationException $exception) {
            $restorationBlocker = $exception->errors()['lifecycle'][0];
        }
        $allowed = [];
        if ($profile->user->account_state !== AccountState::Deactivated) {
            $allowed[] = 'suspend';
            if (! $open) {
                $allowed[] = 'start-offboarding';
            }
        }
        if ($profile->user->account_state === AccountState::Suspended && ! $open && $restorationBlocker === null) {
            $allowed[] = 'restore';
        }
        if ($profile->user->account_state === AccountState::Deactivated && $case?->status === 'completed' && ! $open && $restorationBlocker === null) {
            $allowed[] = 'return';
        }
        if ($open) {
            $allowed = [...$allowed, 'transfer-owner', 'cancel-offboarding', 'complete-offboarding'];
        }
        $owners = User::query()->where('user_type', UserType::Admin)->where('account_state', AccountState::Active)->orderBy('id')->get()
            ->filter(fn (User $user): bool => $authorization->allows($user, AdminPermission::AgentsManage))
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->values();
        $counts = $profile->currentAssignments()->join('customer_profiles', 'customer_profiles.id', '=', 'customer_assignments.customer_profile_id')
            ->selectRaw('customer_profiles.operational_status, count(*) as total')->groupBy('customer_profiles.operational_status')->pluck('total', 'operational_status');
        $history = AgentLifecycleHistory::query()->where('agent_profile_id', $profile->id)->latest('id')->limit(50)->get()
            ->map(function (AgentLifecycleHistory $entry): array {
                return ['id' => $entry->id, 'event_type' => $entry->event_type, 'from_account_state' => $entry->from_account_state,
                    'to_account_state' => $entry->to_account_state, 'from_operational_status' => $entry->from_operational_status,
                    'to_operational_status' => $entry->to_operational_status, 'reason' => $entry->reason,
                    'agent_explanation' => $entry->agent_facing_explanation, 'case_id' => $entry->agent_offboarding_case_id,
                    'effective_at' => $entry->created_at->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                    'notifications' => DB::table('agent_lifecycle_notification_intents')->where('agent_lifecycle_history_id', $entry->id)
                        ->get(['audience_type', 'channel', 'status', 'failure_reason'])];
            });

        return Inertia::render('agents/Lifecycle', [
            'agent' => ['id' => $profile->agent_id, 'name' => $profile->user->name, 'version' => $profile->version,
                'account_state' => $profile->user->account_state->value, 'operational_status' => $profile->operational_status->value,
                'assignment_counts' => $counts],
            'case' => $case === null ? null : ['id' => $case->id, 'version' => $case->version, 'status' => $case->status,
                'owner_user_id' => $case->owner_user_id, 'started_at' => $case->started_at?->toIso8601String(),
                'completed_at' => $case->completed_at?->toIso8601String(), 'cancelled_at' => $case->cancelled_at?->toIso8601String()],
            'completion' => DB::transaction(fn (): array => $eligibility->preview($request->user(), $profile, $case)),
            'restoration_state' => $restoration, 'restoration_blocker' => $restorationBlocker,
            'fresh_authentication' => $fresh, 'allowed_actions' => $allowed, 'owners' => $owners, 'history' => $history,
        ]);
    }

    public function suspend(AgentLifecycleRequest $request, string $agent, AgentLifecycleService $service): JsonResponse|RedirectResponse
    {
        return $this->commit($request, $agent, 'suspend', $service);
    }

    public function restore(AgentLifecycleRequest $request, string $agent, AgentLifecycleService $service): JsonResponse|RedirectResponse
    {
        return $this->commit($request, $agent, 'restore', $service);
    }

    public function startOffboarding(AgentLifecycleRequest $request, string $agent, AgentLifecycleService $service): JsonResponse|RedirectResponse
    {
        return $this->commit($request, $agent, 'start-offboarding', $service);
    }

    public function transferOwner(AgentLifecycleRequest $request, string $agent, AgentLifecycleService $service): JsonResponse|RedirectResponse
    {
        return $this->commit($request, $agent, 'transfer-owner', $service);
    }

    public function cancelOffboarding(AgentLifecycleRequest $request, string $agent, AgentLifecycleService $service): JsonResponse|RedirectResponse
    {
        return $this->commit($request, $agent, 'cancel-offboarding', $service);
    }

    public function completeOffboarding(AgentLifecycleRequest $request, string $agent, AgentLifecycleService $service): JsonResponse|RedirectResponse
    {
        return $this->commit($request, $agent, 'complete-offboarding', $service);
    }

    public function returnToService(AgentLifecycleRequest $request, string $agent, AgentLifecycleService $service): JsonResponse|RedirectResponse
    {
        return $this->commit($request, $agent, 'return', $service);
    }

    public function operation(Request $request, string $agent, string $attempt_reference, AgentLifecycleService $service): JsonResponse
    {
        $result = $service->lookup($request->user(), $this->resolve($request, $agent), $attempt_reference);
        abort_if($result === null, 404, 'Operation unavailable.');

        return response()->json($result);
    }

    private function commit(AgentLifecycleRequest $request, string $agent, string $action, AgentLifecycleService $service): JsonResponse|RedirectResponse
    {
        $result = $service->execute($request->user(), $this->resolve($request, $agent), $action, $request->validated(), $request);
        if ($request->expectsJson()) {
            return response()->json($result);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Agent lifecycle action recorded.']);

        return to_route('agents.lifecycle.show', $agent);
    }

    private function resolve(Request $request, string $agent): AgentProfile
    {
        $profile = app(ResourceScopeService::class)->forAgents($request->user())->where('agent_id', $agent)->firstOrFail();
        Gate::authorize('manage', $profile);

        return $profile;
    }
}
