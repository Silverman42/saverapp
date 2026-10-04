<?php

namespace App\Http\Controllers;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\CustomerPayoutDestination;
use App\Models\CustomerProfile;
use App\Services\AuthorizationService;
use App\Services\BankPayoutDestinationService;
use App\Services\WithdrawalMethodRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BankPayoutDestinationController extends Controller
{
    public function index(string $customer, Request $request, AuthorizationService $authorization, WithdrawalMethodRegistry $methods): Response
    {
        $profile = CustomerProfile::query()->where('customer_id', $customer)->firstOrFail();
        Gate::authorize('view', $profile);
        $user = $request->user();

        return Inertia::render('customers/PayoutDestinations', [
            'customer' => ['id' => $profile->customer_id, 'name' => $profile->user?->name],
            'destinations' => CustomerPayoutDestination::query()->where('customer_profile_id', $profile->id)->orderByDesc('version')->get()
                ->map(fn (CustomerPayoutDestination $destination): array => [
                    'reference' => $destination->destination_reference, 'version' => $destination->version, 'status' => $destination->status,
                    'bank_name' => $destination->bank_name, 'account_mask' => $destination->account_mask, 'name_match' => $destination->name_match,
                    'payee_name' => $user->user_type === UserType::Customer ? null : $destination->verified_payee_name,
                    'verified_at' => $destination->verified_at?->toIso8601String(),
                ])->all(),
            'can_register' => Gate::forUser($user)->allows('initiateWithdrawal', $profile),
            'can_review' => $user->user_type === UserType::Admin && $authorization->allows($user, AdminPermission::CustomersManage),
            'bank_available' => in_array('bank_transfer', $methods->availableMethods(), true),
        ]);
    }

    public function store(string $customer, Request $request, BankPayoutDestinationService $service): RedirectResponse
    {
        $this->rejectUnknown($request, ['bank_code', 'account_number', 'attestation', 'registration_reference']);
        $data = $request->validate([
            'bank_code' => ['required', 'string', 'regex:/\A[0-9]{3}\z/'],
            'account_number' => ['required', 'string', 'regex:/\A[0-9]{10}\z/'],
            'attestation' => ['required', 'string', 'min:1', 'max:500', 'not_regex:/[<>\x00-\x08\x0B\x0C\x0E-\x1F]/'],
            'registration_reference' => ['required', 'uuid'],
        ]);
        $profile = CustomerProfile::query()->where('customer_id', $customer)->firstOrFail();
        $service->register($request->user(), $profile, $data);

        return redirect()->route('customers.payout-destinations.index', $profile->customer_id);
    }

    public function verify(CustomerPayoutDestination $destination, Request $request, BankPayoutDestinationService $service): RedirectResponse
    {
        $service->verify($request->user(), $destination, $this->note($request), $request);

        return $this->back($destination);
    }

    public function reject(CustomerPayoutDestination $destination, Request $request, BankPayoutDestinationService $service): RedirectResponse
    {
        $service->reject($request->user(), $destination, $this->note($request), $request);

        return $this->back($destination);
    }

    public function revoke(CustomerPayoutDestination $destination, Request $request, BankPayoutDestinationService $service): RedirectResponse
    {
        $service->revoke($request->user(), $destination, $this->note($request), $request);

        return $this->back($destination);
    }

    private function note(Request $request): string
    {
        $this->rejectUnknown($request, ['note', 'confirmed']);

        return $request->validate(['note' => ['required', 'string', 'min:1', 'max:500', 'not_regex:/[<>\x00-\x08\x0B\x0C\x0E-\x1F]/'],
            'confirmed' => ['required', 'accepted']])['note'];
    }

    private function back(CustomerPayoutDestination $destination): RedirectResponse
    {
        return redirect()->route('customers.payout-destinations.index', CustomerProfile::query()->findOrFail($destination->customer_profile_id)->customer_id);
    }

    /** @param list<string> $allowed */
    private function rejectUnknown(Request $request, array $allowed): void
    {
        $unexpected = array_values(array_diff(array_keys($request->except('_token')), $allowed));
        if ($unexpected !== []) {
            throw ValidationException::withMessages([$unexpected[0] => ['Unknown payout destination field.']]);
        }
    }
}
