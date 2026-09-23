<?php

namespace App\Http\Controllers;

use App\Enums\AccountState;
use App\Models\User;
use App\Services\ProfileManagementService;
use App\Services\ResourceScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProfileManagementController extends Controller
{
    public function editCustomer(Request $request, string $customer, ResourceScopeService $scope): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $profile = $scope->forCustomers($actor)->where('customer_id', $customer)->with('user')->first();
        if (! $profile) {
            abort(404, 'Record unavailable.');
        }

        Gate::authorize('update', $profile);
        $customer = $profile->user;

        $customerData = [
            'id' => $profile->customer_id,
            'name' => $customer->name,
            'phone' => $profile->phone,
            'address' => $profile->address,
            'gender' => $profile->gender?->value,
            'occupation' => $profile->occupation,
            'next_of_kin' => $this->visibleNextOfKin($profile->next_of_kin),
            'photo_url' => $profile->photo_path ? route('customers.photo', $profile->customer_id) : null,
            'version' => $profile->version,
            'account_state' => $customer->account_state->value,
            'can_change_phone' => $actor->id === $profile->user_id
                || $customer->email_verified_at === null
                || $customer->account_state === AccountState::Invited,
        ];
        if ($actor->user_type->value !== 'customer') {
            $customerData['notes'] = $profile->notes;
            $customerData['internal_reference'] = $profile->internal_reference;
        }

        return Inertia::render('customers/Edit', [
            'customer' => $customerData,
            'viewer_type' => $actor->user_type->value,
        ]);
    }

    public function updateCustomer(
        Request $request,
        string $customer,
        ResourceScopeService $scope,
        ProfileManagementService $service,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $profile = $scope->forCustomers($actor)->where('customer_id', $customer)->first();
        if (! $profile) {
            abort(404, 'Record unavailable.');
        }

        $service->updateCustomer($actor, $profile, $this->profileInput($request), $request->file('photo'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Customer profile updated.']);

        return to_route('customers.show', $profile->customer_id);
    }

    public function editAgent(Request $request, string $agent, ResourceScopeService $scope): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $profile = $scope->forAgents($actor)->where('agent_id', $agent)->with('user')->first();
        if (! $profile) {
            abort(404, 'Record unavailable.');
        }

        Gate::authorize('update', $profile);
        $agentUser = $profile->user;

        $agentData = [
            'id' => $profile->agent_id,
            'name' => $agentUser->name,
            'phone' => $profile->phone,
            'address' => $profile->address,
            'employment_date' => $profile->employment_date?->toDateString(),
            'photo_url' => $profile->profile_photo_path ? route('agents.photo', $profile->agent_id) : null,
            'version' => $profile->version,
            'account_state' => $agentUser->account_state->value,
            'can_change_phone' => $actor->id === $profile->user_id
                || $agentUser->email_verified_at === null
                || $agentUser->account_state === AccountState::Invited,
        ];
        if ($actor->user_type->value !== 'agent') {
            $agentData['notes'] = $profile->notes;
        }

        return Inertia::render('agents/Edit', [
            'agent' => $agentData,
            'viewer_type' => $actor->user_type->value,
        ]);
    }

    public function updateAgent(
        Request $request,
        string $agent,
        ResourceScopeService $scope,
        ProfileManagementService $service,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $profile = $scope->forAgents($actor)->where('agent_id', $agent)->first();
        if (! $profile) {
            abort(404, 'Record unavailable.');
        }

        $service->updateAgent($actor, $profile, $this->profileInput($request), $request->file('photo'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Agent profile updated.']);

        return to_route('agents.show', $profile->agent_id);
    }

    /** @return array<string, mixed> */
    protected function profileInput(Request $request): array
    {
        return $request->except(['_token', '_method']);
    }

    /** @param array<string, mixed>|null $contact
     * @return array<string, string|null>|null
     */
    protected function visibleNextOfKin(?array $contact): ?array
    {
        if ($contact === null) {
            return null;
        }

        return [
            'full_name' => $contact['full_name'] ?? null,
            'relationship' => $contact['relationship'] ?? null,
            'phone' => $contact['phone'] ?? null,
            'address' => $contact['address'] ?? null,
        ];
    }
}
