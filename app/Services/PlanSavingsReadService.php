<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Support\MoneyFormatter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlanSavingsReadService
{
    public function __construct(
        private ResourceScopeService $scope,
        private LedgerTransactionReadService $ledger,
        private WithdrawalBalanceService $withdrawals,
    ) {}

    /** @return array{as_of: ?string, source_version: ?string, customer: array<string, ?string>, cycle: array<string, ?string>} */
    public function read(User $viewer, ThriftPlan $plan): array
    {
        if (! $this->scope->forCustomers($viewer)->whereKey($plan->customer_profile_id)->exists()) {
            throw new NotFoundHttpException('Record unavailable.');
        }
        $unavailable = ['as_of' => null, 'source_version' => null, 'customer' => $this->unavailable(), 'cycle' => $this->unavailable()];
        if (DB::transactionLevel() === 0 && DB::getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        try {
            return DB::transaction(function () use ($viewer, $plan, $unavailable): array {
                $current = $plan->fresh();
                if ($current === null || $current->customer_profile_id !== $plan->customer_profile_id || $current->version !== $plan->version || $current->current_terms_revision !== $plan->current_terms_revision) {
                    return $unavailable;
                }
                $state = $this->ledger->state();
                $balance = $this->ledger->balance($viewer, $current->customerProfile);
                if ($state['status'] !== 'ready' || $balance['status'] !== 'ready') {
                    return $unavailable;
                }
                $cycle = $this->unavailable();
                try {
                    $position = $this->withdrawals->position($current->customerProfile, $current);
                    if ($position['liability_kobo'] !== $balance['liability_kobo']
                        || $position['reservations_kobo'] !== $balance['reservations_kobo']
                        || $position['available_kobo'] !== $balance['available_kobo']) {
                        return $unavailable;
                    }
                    $cycle = $this->values($position['cycle_liability_kobo'], $position['cycle_reservations_kobo'], $position['cycle_available_kobo']);
                } catch (RuntimeException|QueryException) {
                    $cycle = $this->unavailable();
                }

                return ['as_of' => now()->toIso8601String(), 'source_version' => $state['version'].':'.$state['watermark'],
                    'customer' => $this->values($balance['liability_kobo'], $balance['reservations_kobo'], $balance['available_kobo']),
                    'cycle' => $cycle];
            });
        } catch (RuntimeException|QueryException) {
            return $unavailable;
        }
    }

    /** @return array<string, ?string> */
    private function values(int $liability, int $reserved, int $available): array
    {
        return ['status' => 'available', 'message' => 'Posted savings less live gross withdrawal reservations. A withdrawal needs its own eligibility checks and quote.',
            'liability' => MoneyFormatter::formatNaira($liability), 'reserved' => MoneyFormatter::formatNaira($reserved), 'available' => MoneyFormatter::formatNaira($available)];
    }

    /**
     * @param  list<ThriftPlan>  $plans
     * @return array<string, array{as_of: ?string, source_version: ?string, customer: array<string, ?string>, cycle: array<string, ?string>}>
     */
    public function readMany(User $viewer, array $plans): array
    {
        $ids = array_map(fn (ThriftPlan $plan): int => $plan->id, $plans);
        $publicIds = array_map(fn (ThriftPlan $plan): string => $plan->plan_id, $plans);
        if (count($ids) > 100 || count(array_unique($ids)) !== count($ids) || count(array_unique($publicIds)) !== count($ids)) {
            throw new \InvalidArgumentException('Read at most 100 distinct cycle savings summaries.');
        }
        if ($plans === []) {
            return [];
        }
        $unavailable = array_fill_keys($publicIds, $this->unavailableSummary());
        if (DB::transactionLevel() === 0 && DB::getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        try {
            return DB::transaction(function () use ($viewer, $plans, $ids, $unavailable): array {
                $records = ThriftPlan::query()->whereIn('id', $ids)
                    ->whereIn('customer_profile_id', $this->scope->forCustomers($viewer)->select('customer_profiles.id'))
                    ->with('customerProfile')->get();
                $state = $this->ledger->state();
                if ($records->isEmpty() || $state['status'] !== 'ready') {
                    return $unavailable;
                }
                $customers = $records->map(fn (ThriftPlan $plan): CustomerProfile => $plan->customerProfile)->unique('id')->all();
                $balances = $this->ledger->balances($viewer, array_values($customers));
                try {
                    $positions = $this->withdrawals->positions(array_values($records->all()));
                } catch (RuntimeException|QueryException) {
                    $positions = [];
                }
                $result = $unavailable;
                $asOf = now()->toIso8601String();
                foreach ($plans as $plan) {
                    $current = $records->firstWhere('id', $plan->id);
                    if ($current === null || $current->customer_profile_id !== $plan->customer_profile_id
                        || $current->version !== $plan->version || $current->current_terms_revision !== $plan->current_terms_revision
                        || $current->plan_id !== $plan->plan_id) {
                        continue;
                    }
                    $balance = $balances[$current->customer_profile_id] ?? ['status' => 'unavailable'];
                    if ($balance['status'] !== 'ready') {
                        continue;
                    }
                    $position = $positions[$current->id] ?? null;
                    $cycle = $this->unavailable();
                    if ($position !== null) {
                        if ($position['liability_kobo'] !== $balance['liability_kobo']
                            || $position['reservations_kobo'] !== $balance['reservations_kobo']
                            || $position['available_kobo'] !== $balance['available_kobo']) {
                            continue;
                        }
                        $cycle = $this->values($position['cycle_liability_kobo'], $position['cycle_reservations_kobo'], $position['cycle_available_kobo']);
                    }
                    $result[$plan->plan_id] = ['as_of' => $asOf, 'source_version' => $state['version'].':'.$state['watermark'],
                        'customer' => $this->values($balance['liability_kobo'], $balance['reservations_kobo'], $balance['available_kobo']), 'cycle' => $cycle];
                }

                return $result;
            });
        } catch (RuntimeException|QueryException) {
            return $unavailable;
        }
    }

    /** @return array{as_of: ?string, source_version: ?string, customer: array<string, ?string>, cycle: array<string, ?string>} */
    public function unavailableSummary(): array
    {
        return ['as_of' => null, 'source_version' => null, 'customer' => $this->unavailable(), 'cycle' => $this->unavailable()];
    }

    /** @return array<string, ?string> */
    private function unavailable(): array
    {
        return ['status' => 'unavailable', 'message' => 'Verified savings and reservation values are unavailable.', 'liability' => null, 'reserved' => null, 'available' => null];
    }
}
