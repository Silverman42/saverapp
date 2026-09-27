<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerReassignmentRequest;
use App\Models\AgentProfile;
use App\Models\CustomerProfile;
use App\Services\AgentEligibilityService;
use App\Services\CustomerReassignmentService;
use App\Services\ResourceScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CustomerReassignmentController extends Controller
{
    public function edit(Request $request, string $customer, AgentEligibilityService $eligibility): Response
    {
        $profile = $this->resolve($request, $customer);
        Gate::authorize('reassign', $profile);
        $current = $profile->currentAssignment;
        $agents = AgentProfile::query()->with('user')->where(fn ($query) => $query->where('operational_status', 'active')->orWhere('id', $current?->agent_profile_id))->orderBy('agent_id')->get()
            ->filter(fn (AgentProfile $agent): bool => $agent->id === $current?->agent_profile_id || $eligibility->canReceiveAssignment($agent->user))
            ->map(fn (AgentProfile $agent): array => ['id' => $agent->id, 'reference' => $agent->agent_id, 'name' => $agent->user->name])->values();

        return Inertia::render('customers/Reassign', ['customer' => ['reference' => $profile->customer_id, 'name' => $profile->user->name,
            'status' => $profile->operational_status->value, 'account_state' => $profile->user->account_state->value,
            'version' => $profile->version, 'assignment_version' => $current?->version,
            'agent_name' => $current?->agentProfile?->user?->name], 'agents' => $agents]);
    }

    public function preview(Request $request, string $customer, CustomerReassignmentService $service): JsonResponse
    {
        $data = $request->validate(['target_agent_id' => ['required', 'integer', 'min:1']]);

        return response()->json($service->preview($request->user(), $this->resolve($request, $customer), (int) $data['target_agent_id']));
    }

    public function store(CustomerReassignmentRequest $request, string $customer, CustomerReassignmentService $service): JsonResponse
    {
        return response()->json($service->execute($request->user(), $this->resolve($request, $customer), $request->validated()));
    }

    public function operation(Request $request, string $customer, string $attempt_reference, CustomerReassignmentService $service): JsonResponse
    {
        return response()->json($service->lookup($request->user(), $this->resolve($request, $customer), $attempt_reference));
    }

    private function resolve(Request $request, string $reference): CustomerProfile
    {
        return app(ResourceScopeService::class)->forCustomers($request->user())->where('customer_id', $reference)->firstOrFail();
    }
}
