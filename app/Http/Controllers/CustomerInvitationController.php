<?php

namespace App\Http\Controllers;

use App\Models\CustomerProfile;
use App\Services\CustomerInvitationManagementService;
use App\Services\ResourceScopeService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerInvitationController extends Controller
{
    /**
     * Resend an invitation to an invited customer.
     */
    public function resend(
        Request $request,
        string $customer,
        CustomerInvitationManagementService $service,
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

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $service->resend(
            customerProfile: $customerProfile,
            actor: $viewer,
            reason: $validated['reason'] ?? null,
        );

        Toast::success('Invitation resent', 'Customer invitation resent successfully.');

        return redirect()->back();
    }

    /**
     * Correct an invited customer's email address and issue a new invitation.
     */
    public function correctEmail(
        Request $request,
        string $customer,
        CustomerInvitationManagementService $service,
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

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $service->correctEmail(
            customerProfile: $customerProfile,
            actor: $viewer,
            newEmail: $validated['email'],
            reason: $validated['reason'] ?? null,
        );

        Toast::success('Email corrected', 'Customer email corrected and new invitation dispatched.');

        return redirect()->back();
    }

    /**
     * Cancel an invited customer's invitation.
     */
    public function cancel(
        Request $request,
        string $customer,
        CustomerInvitationManagementService $service,
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

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:255'],
        ]);

        $service->cancel(
            customerProfile: $customerProfile,
            actor: $viewer,
            reason: $validated['reason'],
        );

        Toast::success('Invitation cancelled', 'Customer invitation cancelled successfully.');

        return redirect()->back();
    }
}
