<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class CustomerMasterProfileService
{
    private const CANDIDATE_LIMIT = 20;

    public function __construct(protected ResourceScopeService $resourceScopeService) {}

    /**
     * Master profiles the Agent may attach a Customer to: their own non-archived Customers without a parent.
     *
     * @return Collection<int, CustomerProfile>
     */
    public function candidatesFor(User $agent, ?string $search = null, ?string $excludeCustomerId = null): Collection
    {
        $query = $this->assignableMastersQuery($agent)
            ->with('user')
            ->when(filled($excludeCustomerId), fn (Builder $query) => $query->where('customer_profiles.customer_id', '!=', $excludeCustomerId));

        $search = trim((string) $search);
        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('customer_profiles.customer_id', 'like', "%{$search}%")
                    ->orWhereHas('user', fn (Builder $userQuery) => $userQuery->where('name', 'like', "%{$search}%"))
                    ->orWhere('customer_profiles.phone', 'like', "%{$search}%");

                $normalizedPhone = PhoneNormalizer::normalize($search);
                if ($normalizedPhone !== null) {
                    $searchQuery->orWhere('customer_profiles.phone_normalized', $normalizedPhone);
                }

                $digitsOnly = preg_replace('/[^\d]/', '', $search);
                if ($digitsOnly !== null && strlen($digitsOnly) >= 3) {
                    $searchQuery->orWhere('customer_profiles.phone_normalized', 'like', "%{$digitsOnly}%");
                }
            });
        }

        return $query->orderBy('customer_profiles.id', 'desc')->limit(self::CANDIDATE_LIMIT)->get();
    }

    /**
     * Resolve and lock the master profile for a slave, enforcing a single level of nesting.
     *
     * @throws ValidationException
     */
    public function resolveMaster(User $agent, string $masterCustomerId, ?CustomerProfile $slave = null): CustomerProfile
    {
        if ($slave !== null && $slave->customer_id === $masterCustomerId) {
            throw $this->invalid('A customer cannot be its own master account.');
        }

        if ($slave !== null && $slave->slaves()->exists()) {
            throw $this->invalid('This customer has sub-accounts, so it must remain a master account.');
        }

        /** @var CustomerProfile|null $master */
        $master = $this->resourceScopeService->forCustomers($agent)
            ->where('customer_profiles.customer_id', $masterCustomerId)
            ->lockForUpdate()
            ->first();

        if ($master === null) {
            throw $this->invalid('Select a master account from your assigned customers.');
        }

        if ($master->operational_status === CustomerStatus::Archived) {
            throw $this->invalid('An archived customer cannot be used as a master account.');
        }

        if (! $master->isMaster()) {
            throw $this->invalid('The selected customer is already a sub-account and cannot be a master account.');
        }

        return $master;
    }

    /**
     * @return Builder<CustomerProfile>
     */
    private function assignableMastersQuery(User $agent): Builder
    {
        return $this->resourceScopeService->forCustomers($agent)
            ->whereNull('customer_profiles.master_customer_profile_id')
            ->where('customer_profiles.operational_status', '!=', CustomerStatus::Archived->value);
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['master_customer_id' => [$message]]);
    }
}
