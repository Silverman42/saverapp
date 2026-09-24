<?php

namespace App\Policies;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Enums\UserType;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\AgentEligibilityService;
use App\Services\AuthorizationService;

class CustomerProfilePolicy
{
    public function __construct(
        protected AgentEligibilityService $agentEligibilityService,
        protected AuthorizationService $authorizationService,
    ) {}

    /**
     * Determine whether the user can view any customer profiles (directory access).
     */
    public function viewAny(User $user): bool
    {
        if (in_array($user->account_state, [AccountState::Suspended, AccountState::Deactivated], true)) {
            return false;
        }

        if ($user->user_type === UserType::Admin) {
            return $user->account_state === AccountState::Active
                && $user->getRoleNames()->count() === 1
                && $user->getRoleNames()->first() === UserType::Admin->value;
        }

        if ($user->user_type === UserType::Agent) {
            return $this->agentEligibilityService->canReadAssignedCustomers($user);
        }

        return false;
    }

    /**
     * Determine whether the user can view the customer profile.
     * Follows own/current-Agent/Admin scope.
     */
    public function view(User $user, CustomerProfile $customerProfile): bool
    {
        if (in_array($user->account_state, [AccountState::Suspended, AccountState::Deactivated], true)) {
            return false;
        }

        return match ($user->user_type) {
            UserType::Customer => $user->id === $customerProfile->user_id,
            UserType::Agent => $this->agentEligibilityService->canReadAssignedCustomers($user)
                && $user->agentProfile !== null
                && $customerProfile->currentAssignment !== null
                && $customerProfile->currentAssignment->agent_profile_id === $user->agentProfile->id,
            UserType::Admin => $user->account_state === AccountState::Active
                && $user->getRoleNames()->count() === 1
                && $user->getRoleNames()->first() === UserType::Admin->value,
        };
    }

    /**
     * Determine whether the user can create a customer profile.
     * Customer creation is Agent-only and requires assignment-recipient eligibility.
     * Admin creation is always prohibited.
     */
    public function create(User $user): bool
    {
        if ($user->user_type !== UserType::Agent) {
            return false;
        }

        return $this->agentEligibilityService->canReceiveAssignment($user);
    }

    /**
     * Determine whether the user can update the customer profile.
     * Customer updates allow only the Customer, an eligible current Agent, or an Admin with customers.manage.
     * Archived customer records remain read-only.
     */
    public function update(User $user, CustomerProfile $customerProfile): bool
    {
        if (in_array($user->account_state, [AccountState::Suspended, AccountState::Deactivated], true)) {
            return false;
        }

        // Archived customers are read-only: restore first
        if ($customerProfile->operational_status === CustomerStatus::Archived) {
            return false;
        }

        return match ($user->user_type) {
            UserType::Customer => $user->id === $customerProfile->user_id,
            UserType::Agent => $this->agentEligibilityService->canPerformAssignedCustomerWork($user)
                && $user->agentProfile !== null
                && $customerProfile->currentAssignment !== null
                && $customerProfile->currentAssignment->agent_profile_id === $user->agentProfile->id,
            UserType::Admin => $this->authorizationService->allows($user, AdminPermission::CustomersManage),
        };
    }

    /**
     * Determine whether the current assigned Agent can manage plans for this Customer.
     * Admin customer-management permissions do not grant plan-management capability.
     */
    public function managePlan(User $user, CustomerProfile $customerProfile): bool
    {
        return $user->user_type === UserType::Agent
            && $this->agentEligibilityService->canPerformAssignedCustomerWork($user)
            && $user->agentProfile !== null
            && $customerProfile->currentAssignment !== null
            && $customerProfile->currentAssignment->agent_profile_id === $user->agentProfile->id;
    }

    /** Only the eligible current Agent may attest to money actually received. */
    public function recordCollection(User $user, CustomerProfile $customerProfile): bool
    {
        return $this->managePlan($user, $customerProfile);
    }

    /** The current eligible Agent may request settlement of an Active or Inactive Customer's existing savings. */
    public function initiateWithdrawal(User $user, CustomerProfile $customerProfile): bool
    {
        return $this->managePlan($user, $customerProfile)
            && $customerProfile->operational_status->allowsWithdrawalOfExistingFunds();
    }

    /** An assigned eligible Agent may correct posted history for a non-Archived Customer. */
    public function initiateReversal(User $user, CustomerProfile $customerProfile): bool
    {
        return $this->managePlan($user, $customerProfile)
            && $customerProfile->operational_status !== CustomerStatus::Archived;
    }

    /**
     * Determine whether the user can reassign the customer.
     * Reassignment requires customers.reassign permission.
     */
    public function reassign(User $user, CustomerProfile $customerProfile): bool
    {
        return $this->authorizationService->allows($user, AdminPermission::CustomersReassign);
    }

    /** Determine whether the Admin can change the Customer's operational status. */
    public function manageOperationalStatus(User $user, CustomerProfile $customerProfile): bool
    {
        return $customerProfile->operational_status !== CustomerStatus::Archived
            && $this->authorizationService->allows($user, AdminPermission::CustomersManage);
    }

    /**
     * Destructive deletion is strictly denied.
     */
    public function delete(User $user, CustomerProfile $customerProfile): bool
    {
        return false;
    }

    /**
     * Restore is strictly denied via policy (uses specialized restoration service).
     */
    public function restore(User $user, CustomerProfile $customerProfile): bool
    {
        return false;
    }

    /**
     * Force delete is strictly denied.
     */
    public function forceDelete(User $user, CustomerProfile $customerProfile): bool
    {
        return false;
    }
}
