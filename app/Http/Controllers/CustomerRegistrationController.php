<?php

namespace App\Http\Controllers;

use App\Models\CreationAttempt;
use App\Models\CustomerProfile;
use App\Services\CustomerRegistrationService;
use App\Services\RegistrationFeeService;
use App\Services\ResourceScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerRegistrationController extends Controller
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
        'customer_id',
        'id',
        'created_by',
        'created_by_user_id',
        'updated_by',
        'updated_by_user_id',
        'fee',
        'fees',
        'fee_snapshot',
        'assignment',
        'assignments',
        'permission_version',
    ];

    /**
     * Display the customer registration form.
     */
    public function create(RegistrationFeeService $feeService): Response
    {
        Gate::authorize('create', CustomerProfile::class);
        $attemptReference = (string) Str::uuid();

        return Inertia::render('customers/Create', [
            'attempt_reference' => $attemptReference,
            'fee_preview' => $feeService->previewFee('customer_registration', $attemptReference),
        ]);
    }

    /**
     * Return live registration fee preview for client validation.
     */
    public function feePreview(Request $request, RegistrationFeeService $feeService): JsonResponse
    {
        Gate::authorize('create', CustomerProfile::class);

        $validated = $request->validate(['attempt_reference' => ['nullable', 'uuid']]);

        return response()->json($feeService->previewFee(
            'customer_registration',
            $validated['attempt_reference'] ?? (string) Str::uuid(),
        ));
    }

    /**
     * Store a newly registered customer.
     */
    public function store(Request $request, CustomerRegistrationService $service): RedirectResponse
    {
        Gate::authorize('create', CustomerProfile::class);

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
            'fee_rule_version' => ['required', 'integer'],
            'name' => ['required', 'string', 'min:1', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'phone' => ['required', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'gender' => ['nullable', 'string', 'in:female,male,other,prefer_not_to_say'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'internal_reference' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
            'next_of_kin' => ['nullable', 'array'],
            'next_of_kin.full_name' => ['nullable', 'string', 'max:150'],
            'next_of_kin.relationship' => ['nullable', 'string', 'max:50'],
            'next_of_kin.phone' => ['nullable', 'string', 'max:50'],
            'next_of_kin.address' => ['nullable', 'string', 'max:500'],
        ]);

        $attemptReference = $validated['attempt_reference'];
        $photo = $request->file('photo');

        $result = $service->register(
            agent: $request->user(),
            attemptReference: $attemptReference,
            data: $validated,
            photo: $photo,
        );

        $message = $result['replayed']
            ? 'Existing Customer registration resolved.'
            : 'Customer registered successfully. Invitation queued for delivery.';

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $message,
        ]);

        return redirect()->route('customers.show', $result['customer']->customer_id);
    }

    /**
     * Lookup an existing customer creation attempt reference.
     */
    public function showAttempt(Request $request, string $reference, ResourceScopeService $scopeService): JsonResponse
    {
        $viewer = $request->user();

        /** @var CreationAttempt|null $attempt */
        $attempt = CreationAttempt::query()
            ->where('attempt_reference', $reference)
            ->where('user_id', $viewer->id)
            ->where('operation_type', 'customer_registration')
            ->first();

        if (! $attempt) {
            abort(404, 'Record unavailable.');
        }

        if ($attempt->isCommitted()) {
            /** @var CustomerProfile|null $customer */
            $customer = $scopeService->forCustomers($viewer)
                ->where('id', $attempt->record_id)
                ->first();

            if (! $customer) {
                abort(404, 'Record unavailable.');
            }

            return response()->json([
                'status' => 'committed',
                'customer_id' => $customer->customer_id,
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
