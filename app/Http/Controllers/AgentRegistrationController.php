<?php

namespace App\Http\Controllers;

use App\Models\AgentProfile;
use App\Models\CreationAttempt;
use App\Services\AgentRegistrationService;
use App\Services\ResourceScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AgentRegistrationController extends Controller
{
    /**
     * Prohibited fields that must never be accepted from client payloads.
     */
    protected const array PROHIBITED_FIELDS = [
        'operational_status',
        'status',
        'account_state',
        'user_type',
        'role',
        'roles',
        'agent_id',
        'id',
        'created_by',
        'created_by_user_id',
        'updated_by',
        'updated_by_user_id',
        'fee',
        'fees',
        'assignment',
        'assignments',
        'permission_version',
    ];

    /**
     * Display the agent registration form.
     */
    public function create(): Response
    {
        Gate::authorize('create', AgentProfile::class);

        return Inertia::render('agents/Create', [
            'attempt_reference' => (string) Str::uuid(),
        ]);
    }

    /**
     * Store a newly created agent via idempotent registration.
     */
    public function store(Request $request, AgentRegistrationService $service): RedirectResponse
    {
        Gate::authorize('create', AgentProfile::class);

        // Reject client-supplied status, role, IDs, attribution, and financial fields
        foreach (self::PROHIBITED_FIELDS as $field) {
            if ($request->has($field)) {
                throw ValidationException::withMessages([
                    $field => ["Field [{$field}] is server-managed and cannot be supplied."],
                ]);
            }
        }

        $validated = $request->validate([
            'attempt_reference' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'min:1', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'phone' => ['required', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'employment_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        $attemptReference = $validated['attempt_reference'];
        $photo = $request->file('photo');

        $result = $service->register(
            admin: $request->user(),
            attemptReference: $attemptReference,
            data: $validated,
            photo: $photo,
        );

        $message = $result['replayed']
            ? 'Existing Agent registration resolved.'
            : 'Agent registered successfully. Invitation queued for delivery.';

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $message,
        ]);

        return redirect()->route('agents.show', $result['agent']->agent_id);
    }

    /**
     * Lookup an existing creation attempt reference.
     */
    public function showAttempt(Request $request, string $reference, ResourceScopeService $scopeService): JsonResponse
    {
        $viewer = $request->user();

        /** @var CreationAttempt|null $attempt */
        $attempt = CreationAttempt::query()
            ->where('attempt_reference', $reference)
            ->where('user_id', $viewer->id)
            ->where('operation_type', 'agent_registration')
            ->first();

        if (! $attempt) {
            abort(404, 'Record unavailable.');
        }

        if ($attempt->isCommitted()) {
            /** @var AgentProfile|null $agent */
            $agent = $scopeService->forAgents($viewer)
                ->where('id', $attempt->record_id)
                ->first();

            if (! $agent) {
                abort(404, 'Record unavailable.');
            }

            return response()->json([
                'status' => 'committed',
                'agent_id' => $agent->agent_id,
                'result' => $attempt->result_summary,
            ]);
        }

        return response()->json([
            'status' => $attempt->status->value,
            'result' => $attempt->result_summary,
            'error' => $attempt->error_message,
        ]);
    }
}
