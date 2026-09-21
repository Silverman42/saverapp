<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AgentEligibilityCapability;
use App\Enums\AgentStatus;
use App\Enums\UserType;
use App\Models\AgentProfile;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ResourceScopeService
{
    public function __construct(
        protected AgentEligibilityService $agentEligibilityService,
    ) {}

    /**
     * Get a scope-safe query builder for CustomerProfile records based on viewer authority.
     *
     * Invariants enforced:
     * - Customers see only their own profile.
     * - Agents see only Customers with a current assignment to them; historical assignment or attribution grants no access.
     * - Active synchronized Admins receive baseline business-wide read scope.
     * - Invalid roles, unusable accounts, and missing relationships produce an empty query rather than leaking record existence.
     *
     * @return Builder<CustomerProfile>
     */
    public function forCustomers(User $viewer): Builder
    {
        // Unusable account states produce an empty query
        if (in_array($viewer->account_state, [AccountState::Suspended, AccountState::Deactivated], true)) {
            return $this->emptyCustomerQuery();
        }

        return match ($viewer->user_type) {
            UserType::Customer => CustomerProfile::query()->where('user_id', $viewer->id),
            UserType::Agent => $this->agentCustomerQuery($viewer),
            UserType::Admin => $this->adminCustomerQuery($viewer),
        };
    }

    /**
     * Get a scope-safe query builder for AgentProfile records based on viewer authority.
     *
     * Invariants enforced:
     * - Agents see only their own Agent profile.
     * - Active Admins see Agent records business-wide.
     * - Customers receive no full Agent-directory scope.
     * - Invalid roles and unusable accounts produce an empty query.
     *
     * @return Builder<AgentProfile>
     */
    public function forAgents(User $viewer): Builder
    {
        if (in_array($viewer->account_state, [AccountState::Suspended, AccountState::Deactivated], true)) {
            return $this->emptyAgentQuery();
        }

        return match ($viewer->user_type) {
            UserType::Agent => $viewer->account_state === AccountState::Active
                ? AgentProfile::query()->where('user_id', $viewer->id)
                : $this->emptyAgentQuery(),
            UserType::Admin => ($viewer->account_state === AccountState::Active && $viewer->getRoleNames()->count() === 1 && $viewer->getRoleNames()->first() === UserType::Admin->value)
                ? AgentProfile::query()
                : $this->emptyAgentQuery(),
            UserType::Customer => $this->emptyAgentQuery(),
        };
    }

    /**
     * Scope query for an Agent viewer.
     *
     * @return Builder<CustomerProfile>
     */
    protected function agentCustomerQuery(User $viewer): Builder
    {
        $eligibility = $this->agentEligibilityService->evaluate($viewer, AgentEligibilityCapability::ReadAssignedCustomers);
        if (! $eligibility->isEligible()) {
            return $this->emptyCustomerQuery();
        }

        $agentProfile = $viewer->agentProfile;
        if ($agentProfile === null) {
            return $this->emptyCustomerQuery();
        }

        return CustomerProfile::query()->whereHas('currentAssignment', function (Builder $query) use ($agentProfile): void {
            $query->where('agent_profile_id', $agentProfile->id);
        });
    }

    /**
     * Scope query for an Admin viewer.
     *
     * @return Builder<CustomerProfile>
     */
    protected function adminCustomerQuery(User $viewer): Builder
    {
        if ($viewer->account_state !== AccountState::Active) {
            return $this->emptyCustomerQuery();
        }

        $roles = $viewer->getRoleNames();
        if ($roles->count() !== 1 || $roles->first() !== UserType::Admin->value) {
            return $this->emptyCustomerQuery();
        }

        return CustomerProfile::query();
    }

    /**
     * Query for eligible assignment recipients using database-level coarse filters.
     *
     * Must be followed by the authoritative AgentEligibilityService under lock at commit.
     *
     * @return Builder<AgentProfile>
     */
    public function eligibleAssignmentRecipients(): Builder
    {
        return AgentProfile::query()
            ->where('operational_status', AgentStatus::Active)
            ->whereHas('user', function (Builder $query): void {
                $query->where('user_type', UserType::Agent)
                    ->where('account_state', AccountState::Active)
                    ->whereNotNull('two_factor_confirmed_at')
                    ->where(function (Builder $sub): void {
                        $sub->whereNull('locked_until')
                            ->orWhere('locked_until', '<=', Carbon::now());
                    });
            });
    }

    /**
     * Authoritative filtered collection of eligible assignment recipient AgentProfiles.
     *
     * @return Collection<int, AgentProfile>
     */
    public function getAuthoritativeEligibleRecipients(): Collection
    {
        return $this->eligibleAssignmentRecipients()
            ->with(['user', 'user.roles'])
            ->get()
            ->filter(fn (AgentProfile $agent): bool => $this->agentEligibilityService->canReceiveAssignment($agent))
            ->values();
    }

    /**
     * Return an empty CustomerProfile query.
     *
     * @return Builder<CustomerProfile>
     */
    protected function emptyCustomerQuery(): Builder
    {
        return CustomerProfile::query()->whereRaw('1 = 0');
    }

    /**
     * Return an empty AgentProfile query.
     *
     * @return Builder<AgentProfile>
     */
    protected function emptyAgentQuery(): Builder
    {
        return AgentProfile::query()->whereRaw('1 = 0');
    }
}
