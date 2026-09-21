<?php

namespace App\Services;

use App\Enums\AgentEligibilityCapability;
use App\Enums\UserType;
use App\Models\AgentProfile;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Support\LockedCustomerActionContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CustomerActionAuthorizationGuard
{
    public function __construct(
        protected AgentEligibilityService $agentEligibilityService,
        protected AuthorizationService $authorizationService,
    ) {}

    /**
     * Lock and deterministically re-authorize a customer action within an active transaction.
     *
     * Invariants enforced:
     * 1. Must run inside an active database transaction.
     * 2. Deterministic lock ordering: Actor -> Customer -> Current Assignment -> Current Agent -> Target Agent.
     * 3. Validates expected customer profile version and assignment version (stale versions throw ValidationException).
     * 4. Authoritatively evaluates policy, permission, role, account, MFA, operational status, and assignment under lock.
     * 5. Lost authority throws AuthorizationException.
     *
     * @throws RuntimeException
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function lockAndAuthorize(
        User $actor,
        int $customerProfileId,
        string $ability,
        ?int $expectedCustomerVersion = null,
        ?int $expectedAssignmentVersion = null,
        ?int $targetAgentProfileId = null,
    ): LockedCustomerActionContext {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('CustomerActionAuthorizationGuard must be executed within an active database transaction.');
        }

        // 1. Deterministic lock ordering
        // Actor
        $lockedActor = User::query()->where('id', $actor->id)->lockForUpdate()->first();
        if ($lockedActor === null) {
            throw new AuthorizationException('Acting user account no longer exists.');
        }

        // Customer Profile
        $lockedCustomer = CustomerProfile::query()->where('id', $customerProfileId)->lockForUpdate()->first();
        if ($lockedCustomer === null) {
            throw new AuthorizationException('Customer profile not found.');
        }

        // Current Assignment
        $lockedCurrentAssignment = CustomerAssignment::query()
            ->where('customer_profile_id', $customerProfileId)
            ->where('is_current', 1)
            ->lockForUpdate()
            ->first();

        // Current Agent Profile (if assigned)
        $lockedCurrentAgent = null;
        if ($lockedCurrentAssignment !== null) {
            $lockedCurrentAgent = AgentProfile::query()
                ->where('id', $lockedCurrentAssignment->agent_profile_id)
                ->lockForUpdate()
                ->first();
        }

        // Target Agent Profile (if specified)
        $lockedTargetAgent = null;
        if ($targetAgentProfileId !== null) {
            $lockedTargetAgent = AgentProfile::query()
                ->where('id', $targetAgentProfileId)
                ->lockForUpdate()
                ->first();

            if ($lockedTargetAgent === null) {
                throw new AuthorizationException('Target agent profile not found.');
            }
        }

        // 2. Version Concurrency Checks
        if ($expectedCustomerVersion !== null && $lockedCustomer->version !== $expectedCustomerVersion) {
            throw ValidationException::withMessages([
                'version' => ['Customer profile has been modified by another process. Please refresh and retry.'],
            ]);
        }

        if ($expectedAssignmentVersion !== null) {
            $currentVersion = $lockedCurrentAssignment?->version;
            if ($currentVersion !== $expectedAssignmentVersion) {
                throw ValidationException::withMessages([
                    'assignment_version' => ['Customer assignment has been modified by another process. Please refresh and retry.'],
                ]);
            }
        }

        // 3. Authority and Policy Check
        if (Gate::forUser($lockedActor)->denies($ability, $lockedCustomer)) {
            throw new AuthorizationException("This action [{$ability}] is unauthorized for the current actor.");
        }

        // 4. Additional Actor Operational and Assignment Invariants
        if ($lockedActor->user_type === UserType::Agent) {
            $capability = in_array($ability, ['view', 'viewAny'], true)
                ? AgentEligibilityCapability::ReadAssignedCustomers
                : AgentEligibilityCapability::PerformAssignedCustomerWork;

            $eligibility = $this->agentEligibilityService->evaluate($lockedActor, $capability);
            if (! $eligibility->isEligible()) {
                throw new AuthorizationException("Agent is not currently eligible: {$eligibility->message}");
            }

            // Ensure the locked actor is the currently assigned agent
            $actorAgentProfile = $lockedActor->agentProfile;
            if ($actorAgentProfile === null || $lockedCurrentAssignment === null || $lockedCurrentAssignment->agent_profile_id !== $actorAgentProfile->id) {
                throw new AuthorizationException('Agent is not the currently assigned agent for this customer.');
            }
        }

        // 5. Target Agent Eligibility Check (if target agent is specified)
        if ($lockedTargetAgent !== null) {
            $targetEligibility = $this->agentEligibilityService->evaluate($lockedTargetAgent, AgentEligibilityCapability::ReceiveAssignment);
            if (! $targetEligibility->isEligible()) {
                throw new AuthorizationException("Target agent is not eligible to receive assignment: {$targetEligibility->message}");
            }
        }

        return new LockedCustomerActionContext(
            actor: $lockedActor,
            customerProfile: $lockedCustomer,
            currentAssignment: $lockedCurrentAssignment,
            currentAgentProfile: $lockedCurrentAgent,
            targetAgentProfile: $lockedTargetAgent,
        );
    }
}
