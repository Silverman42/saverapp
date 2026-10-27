<?php

namespace App\Http\Controllers;

use App\Models\CustomerProfile;
use App\Services\RegistrationFeeChargeService;
use App\Services\ResourceScopeService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CustomerRegistrationFeeController extends Controller
{
    /**
     * Raise a registration fee again for an existing customer.
     */
    public function store(
        Request $request,
        string $customer,
        RegistrationFeeChargeService $service,
        ResourceScopeService $scopeService,
    ): RedirectResponse {
        $viewer = $request->user();

        /** @var CustomerProfile|null $customerProfile */
        $customerProfile = $scopeService->forCustomers($viewer)
            ->where('customer_id', $customer)
            ->with(['user', 'currentAssignment'])
            ->first();

        if (! $customerProfile) {
            abort(404, 'Record unavailable.');
        }

        Gate::forUser($viewer)->authorize('chargeRegistrationFee', $customerProfile);

        $validated = $request->validate([
            'attempt_reference' => ['required', 'uuid'],
            'fee_rule_version' => ['required', 'integer'],
            'registration_fee_payment' => ['required', 'string', 'in:paid,part,pending'],
            'registration_fee_amount_paid' => ['nullable', 'required_if:registration_fee_payment,part', 'string', 'max:20'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $result = $service->charge($viewer, $customerProfile, $validated['attempt_reference'], $validated);

        Toast::success(
            'Registration fee charged',
            $result['replayed'] ? 'This registration fee was already recorded.' : 'The registration fee was added to the customer\'s account.',
        );

        return redirect()->route('customers.show', $customerProfile->customer_id);
    }
}
