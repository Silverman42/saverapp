<?php

namespace App\Policies;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\AgentProfile;
use App\Models\User;
use App\Services\AuthorizationService;

class AgentProfilePolicy
{
    public function __construct(
        protected AuthorizationService $authorizationService,
    ) {}

    /**
     * Determine whether the user can view any agent profiles (directory access).
     * Directory is Admin-only; Agents and Customers have no cross-Agent directory access.
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

        return false;
    }

    /**
     * Determine whether the user can view the agent profile.
     */
    public function view(User $user, AgentProfile $agentProfile): bool
    {
        if (in_array($user->account_state, [AccountState::Suspended, AccountState::Deactivated], true)) {
            return false;
        }

        // Agent sees their own agent profile
        if ($user->user_type === UserType::Agent) {
            return $user->id === $agentProfile->user_id;
        }

        // Admin baseline read scope
        if ($user->user_type === UserType::Admin) {
            return $user->account_state === AccountState::Active
                && $user->getRoleNames()->count() === 1
                && $user->getRoleNames()->first() === UserType::Admin->value;
        }

        // Customers receive no full Agent-directory/profile scope
        return false;
    }

    /**
     * Determine whether the user can create an agent profile.
     * Requires Admin with agents.manage.
     */
    public function create(User $user): bool
    {
        return $this->authorizationService->allows($user, AdminPermission::AgentsManage);
    }

    /**
     * Determine whether the user can update the agent profile.
     * Agent self-service allows own profile updates for permitted fields;
     * Admin management requires agents.manage.
     */
    public function update(User $user, AgentProfile $agentProfile): bool
    {
        if (in_array($user->account_state, [AccountState::Suspended, AccountState::Deactivated], true)) {
            return false;
        }

        if ($user->user_type === UserType::Agent) {
            return $user->id === $agentProfile->user_id
                && $user->account_state === AccountState::Active;
        }

        if ($user->user_type === UserType::Admin) {
            return $this->authorizationService->allows($user, AdminPermission::AgentsManage);
        }

        return false;
    }

    /**
     * Determine whether the user can manage the agent profile (lifecycle/status/offboarding).
     */
    public function manage(User $user, AgentProfile $agentProfile): bool
    {
        return $this->authorizationService->allows($user, AdminPermission::AgentsManage);
    }

    /**
     * Destructive deletion is strictly denied.
     */
    public function delete(User $user, AgentProfile $agentProfile): bool
    {
        return false;
    }

    /**
     * Restore is strictly denied via policy.
     */
    public function restore(User $user, AgentProfile $agentProfile): bool
    {
        return false;
    }

    /**
     * Force delete is strictly denied.
     */
    public function forceDelete(User $user, AgentProfile $agentProfile): bool
    {
        return false;
    }
}
