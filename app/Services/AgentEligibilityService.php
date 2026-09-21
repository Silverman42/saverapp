<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AgentEligibilityCapability;
use App\Enums\AgentEligibilityReason;
use App\Enums\AgentStatus;
use App\Enums\UserType;
use App\Models\AgentProfile;
use App\Models\User;
use App\Support\AgentEligibilityResult;

class AgentEligibilityService
{
    /**
     * Authoritatively evaluate whether an Agent satisfies an eligibility capability.
     */
    public function evaluate(User|AgentProfile $agent, AgentEligibilityCapability $capability): AgentEligibilityResult
    {
        if ($agent instanceof AgentProfile) {
            $agentProfile = $agent;
            $user = $agent->user ?? User::query()->find($agent->user_id);

            if ($user === null) {
                return AgentEligibilityResult::ineligible(
                    $capability,
                    AgentEligibilityReason::UnusableAccount,
                    'Agent user account is missing.',
                );
            }
        } else {
            $user = $agent;

            // Non-agent users are classified as role drift rather than missing agent profile
            if ($user->user_type !== UserType::Agent) {
                return AgentEligibilityResult::ineligible(
                    $capability,
                    AgentEligibilityReason::RoleDrift,
                    'User type does not match Agent classification.',
                );
            }

            $agentProfile = $user->agentProfile ?? AgentProfile::query()->where('user_id', $user->id)->first();

            if ($agentProfile === null) {
                return AgentEligibilityResult::ineligible(
                    $capability,
                    AgentEligibilityReason::MissingProfile,
                    'Agent operational profile is missing.',
                );
            }
        }

        // 1. Exact Agent role and classification check (no role drift)
        if ($user->user_type !== UserType::Agent) {
            return AgentEligibilityResult::ineligible(
                $capability,
                AgentEligibilityReason::RoleDrift,
                'User type does not match Agent classification.',
            );
        }

        $roles = $user->getRoleNames();
        if ($roles->count() !== 1 || $roles->first() !== UserType::Agent->value) {
            return AgentEligibilityResult::ineligible(
                $capability,
                AgentEligibilityReason::RoleDrift,
                'User does not have exactly one synchronized agent role.',
            );
        }

        // 2. Usable account state check
        if ($user->account_state !== AccountState::Active) {
            return AgentEligibilityResult::ineligible(
                $capability,
                AgentEligibilityReason::UnusableAccount,
                'Agent user account is not active.',
            );
        }

        // 3. Completed MFA onboarding check
        if (! $user->hasConfirmedTwoFactor()) {
            return AgentEligibilityResult::ineligible(
                $capability,
                AgentEligibilityReason::IncompleteMfa,
                'Agent has not completed required MFA setup.',
            );
        }

        // Up to here satisfies ReadAssignedCustomers
        if ($capability === AgentEligibilityCapability::ReadAssignedCustomers) {
            return AgentEligibilityResult::eligible($capability);
        }

        // 4. Operational status Active check (required for customer work & receiving assignment)
        if ($agentProfile->operational_status !== AgentStatus::Active) {
            return AgentEligibilityResult::ineligible(
                $capability,
                AgentEligibilityReason::OperationallyInactive,
                'Agent is operationally inactive.',
            );
        }

        // Up to here satisfies PerformAssignedCustomerWork
        if ($capability === AgentEligibilityCapability::PerformAssignedCustomerWork) {
            return AgentEligibilityResult::eligible($capability);
        }

        // 5. Temporary authentication lock check (blocks receiving new assignments)
        if ($user->isTemporarilyLocked()) {
            return AgentEligibilityResult::ineligible(
                $capability,
                AgentEligibilityReason::TemporarilyLocked,
                'Agent authentication account is temporarily locked.',
            );
        }

        return AgentEligibilityResult::eligible($capability);
    }

    /**
     * Alias for evaluate.
     */
    public function check(User|AgentProfile $agent, AgentEligibilityCapability $capability): AgentEligibilityResult
    {
        return $this->evaluate($agent, $capability);
    }

    /**
     * Determine if the agent is eligible to read assigned customers.
     */
    public function canReadAssignedCustomers(User|AgentProfile $agent): bool
    {
        return $this->evaluate($agent, AgentEligibilityCapability::ReadAssignedCustomers)->isEligible();
    }

    /**
     * Determine if the agent is eligible to perform work on assigned customers.
     */
    public function canPerformAssignedCustomerWork(User|AgentProfile $agent): bool
    {
        return $this->evaluate($agent, AgentEligibilityCapability::PerformAssignedCustomerWork)->isEligible();
    }

    /**
     * Determine if the agent is eligible to receive a customer assignment.
     */
    public function canReceiveAssignment(User|AgentProfile $agent): bool
    {
        return $this->evaluate($agent, AgentEligibilityCapability::ReceiveAssignment)->isEligible();
    }
}
