<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Models\AgentProfile;
use App\Models\CustomerNameCorrection;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\CustomerNameCorrectionService;
use App\Services\PhoneChangeService;
use App\Services\ResourceScopeService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileIdentityController extends Controller
{
    public function storeNameCorrection(
        Request $request,
        string $customer,
        ResourceScopeService $scope,
        CustomerNameCorrectionService $service,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $profile = $this->customerProfile($scope, $actor, $customer);
        $this->assertOnlyFields($request, ['name', 'reason', 'version']);
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:150'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'version' => ['required', 'integer', 'min:1'],
        ]);

        if ($actor->user_type === UserType::Customer) {
            abort(403);
        }

        $correction = $service->proposeOrCorrectBeforeActivation(
            actor: $actor,
            profile: $profile,
            proposedName: $validated['name'],
            reason: $validated['reason'],
            expectedVersion: (int) $validated['version'],
        );
        Toast::success('Name updated', $correction === null
                ? 'Customer name corrected before activation.'
                : 'Name correction sent to the Customer for confirmation.');

        return to_route('customers.show', $profile->customer_id);
    }

    public function changeOwnName(
        Request $request,
        string $customer,
        ResourceScopeService $scope,
        CustomerNameCorrectionService $service,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        if ($actor->user_type !== UserType::Customer) {
            abort(403);
        }
        $profile = $this->customerProfile($scope, $actor, $customer);
        $this->assertOnlyFields($request, ['name', 'reason', 'version']);
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:150'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $service->changeOwnName($actor, $profile, $validated['name'], $validated['reason'], (int) $validated['version']);
        Toast::success('Name updated', 'Your Customer name was updated.');

        return to_route('customers.show', $profile->customer_id);
    }

    public function changeOwnCustomerPhone(
        Request $request,
        string $customer,
        ResourceScopeService $scope,
        PhoneChangeService $service,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        if ($actor->user_type !== UserType::Customer) {
            abort(403);
        }

        return $this->updateCustomerPhone($request, $customer, $scope, $service, true);
    }

    public function correctCustomerPhone(
        Request $request,
        string $customer,
        ResourceScopeService $scope,
        PhoneChangeService $service,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        if ($actor->user_type === UserType::Customer) {
            abort(403);
        }

        return $this->updateCustomerPhone($request, $customer, $scope, $service, false);
    }

    public function changeOwnAgentPhone(
        Request $request,
        string $agent,
        ResourceScopeService $scope,
        PhoneChangeService $service,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        if ($actor->user_type !== UserType::Agent) {
            abort(403);
        }

        return $this->updateAgentPhone($request, $agent, $scope, $service, true);
    }

    public function correctAgentPhone(
        Request $request,
        string $agent,
        ResourceScopeService $scope,
        PhoneChangeService $service,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        if ($actor->user_type === UserType::Agent) {
            abort(403);
        }

        return $this->updateAgentPhone($request, $agent, $scope, $service, false);
    }

    public function showNameCorrection(
        Request $request,
        string $customer,
        int $correction,
        ResourceScopeService $scope,
    ): Response {
        /** @var User $actor */
        $actor = $request->user();
        $profile = $this->customerProfile($scope, $actor, $customer);
        $proposal = CustomerNameCorrection::query()
            ->whereKey($correction)
            ->where('customer_profile_id', $profile->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();

        if ($proposal === null || ($actor->id !== $profile->user_id && $actor->id !== $proposal->requested_by_user_id)) {
            abort(404, 'Record unavailable.');
        }

        return Inertia::render('customers/NameCorrection', [
            'customer' => ['id' => $profile->customer_id],
            'correction' => [
                'id' => $proposal->id,
                'proposed_name' => $proposal->proposed_name,
                'expires_at' => $proposal->expires_at->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                'can_review' => $actor->id === $profile->user_id,
                'can_cancel' => $actor->id === $proposal->requested_by_user_id,
            ],
        ]);
    }

    public function acceptNameCorrection(
        Request $request,
        string $customer,
        int $correction,
        ResourceScopeService $scope,
        CustomerNameCorrectionService $service,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $profile = $this->customerProfile($scope, $actor, $customer);
        $this->assertOnlyFields($request, []);
        $resolved = $service->resolve($actor, $profile, $correction, 'accepted');
        Toast::show($resolved->status === 'accepted' ? 'success' : 'error', 'Name correction', match ($resolved->status) {
            'accepted' => 'Your name was updated.',
            'expired' => 'This name correction has expired.',
            default => 'This name correction is no longer valid.',
        });

        return to_route('customers.show', $profile->customer_id);
    }

    public function rejectNameCorrection(
        Request $request,
        string $customer,
        int $correction,
        ResourceScopeService $scope,
        CustomerNameCorrectionService $service,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $profile = $this->customerProfile($scope, $actor, $customer);
        $this->assertOnlyFields($request, []);
        $service->resolve($actor, $profile, $correction, 'rejected');
        Toast::success('Correction rejected', 'The proposed name correction was rejected.');

        return to_route('customers.show', $profile->customer_id);
    }

    public function cancelNameCorrection(
        Request $request,
        string $customer,
        int $correction,
        ResourceScopeService $scope,
        CustomerNameCorrectionService $service,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $profile = $this->customerProfile($scope, $actor, $customer);
        $this->assertOnlyFields($request, []);
        $service->cancel($actor, $profile, $correction);
        Toast::success('Correction cancelled', 'The name correction was cancelled.');

        return to_route('customers.show', $profile->customer_id);
    }

    protected function customerProfile(ResourceScopeService $scope, User $actor, string $customer): CustomerProfile
    {
        $profile = $scope->forCustomers($actor)->where('customer_id', $customer)->first();
        if ($profile === null) {
            abort(404, 'Record unavailable.');
        }

        return $profile;
    }

    protected function updateCustomerPhone(Request $request, string $customer, ResourceScopeService $scope, PhoneChangeService $service, bool $self): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $profile = $this->customerProfile($scope, $actor, $customer);
        $this->assertOnlyFields($request, ['phone', 'reason', 'version']);
        $validated = $request->validate([
            'phone' => ['required', 'string', 'min:7', 'max:50'],
            'reason' => [$self ? 'nullable' : 'required', 'string', 'min:'.($self ? '0' : '3'), 'max:500'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $service->changeCustomerPhone($actor, $profile, $validated['phone'], (string) ($validated['reason'] ?? ''), (int) $validated['version']);
        Toast::success('Phone updated', 'Customer phone number updated.');

        return to_route('customers.show', $profile->customer_id);
    }

    protected function updateAgentPhone(Request $request, string $agent, ResourceScopeService $scope, PhoneChangeService $service, bool $self): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $profile = $scope->forAgents($actor)->where('agent_id', $agent)->first();
        if (! $profile instanceof AgentProfile) {
            abort(404, 'Record unavailable.');
        }
        $this->assertOnlyFields($request, ['phone', 'reason', 'version']);
        $validated = $request->validate([
            'phone' => ['required', 'string', 'min:7', 'max:50'],
            'reason' => [$self ? 'nullable' : 'required', 'string', 'min:'.($self ? '0' : '3'), 'max:500'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $service->changeAgentPhone($actor, $profile, $validated['phone'], (string) ($validated['reason'] ?? ''), (int) $validated['version']);
        Toast::success('Phone updated', 'Agent phone number updated.');

        return to_route('agents.show', $profile->agent_id);
    }

    /** @param array<int, string> $allowed */
    protected function assertOnlyFields(Request $request, array $allowed): void
    {
        $unexpected = array_diff(array_keys($request->except(['_token', '_method'])), $allowed);
        if ($unexpected !== []) {
            throw ValidationException::withMessages(['profile' => ['The request contains fields that cannot be changed here.']]);
        }
    }
}
