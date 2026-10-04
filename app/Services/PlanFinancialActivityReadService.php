<?php

namespace App\Services;

use App\Models\ThriftPlan;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlanFinancialActivityReadService
{
    /** @var list<string> */
    private const METRICS = [
        'gross_withdrawals', 'net_cash_payouts', 'net_bank_payouts', 'withdrawal_fees', 'withdrawal_deductions', 'withdrawal_compensation',
        'effective_withdrawal_debits', 'withdrawal_cash_returns', 'effective_withdrawal_cash_paid',
        'other_deductions', 'deduction_compensation', 'effective_deductions',
    ];

    public function __construct(
        private ResourceScopeService $scope,
        private LedgerTransactionReadService $ledger,
        private FinancialWorkflowReadService $activity,
    ) {}

    /** @return array{status: string, message: string, as_of: ?string, source_version: ?string, metrics: list<array<string, mixed>>} */
    public function read(User $viewer, ThriftPlan $plan): array
    {
        $customers = $this->scope->forCustomers($viewer)->whereKey($plan->customer_profile_id);
        if (! $customers->exists()) {
            throw new NotFoundHttpException('Record unavailable.');
        }
        $unavailable = ['status' => 'unavailable', 'message' => 'Verified posted payout and deduction history is unavailable.',
            'as_of' => null, 'source_version' => null, 'metrics' => []];
        if (DB::transactionLevel() === 0 && DB::getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        try {
            return DB::transaction(function () use ($viewer, $plan, $customers, $unavailable): array {
                $current = $plan->fresh();
                if ($current === null || $current->customer_profile_id !== $plan->customer_profile_id
                    || $current->version !== $plan->version || $current->current_terms_revision !== $plan->current_terms_revision) {
                    return $unavailable;
                }
                $cutoff = now();
                $result = $this->activity->activity($viewer, $customers, ['plan' => $current->plan_id], $cutoff->toDateTimeString());
                $state = $this->ledger->state();
                if ($result['status'] !== 'Ready' || $state['status'] !== 'ready') {
                    return $unavailable;
                }
                $metrics = [];
                foreach ($result['metrics'] as $metric) {
                    if (in_array($metric['code'], self::METRICS, true)) {
                        $metrics[] = $metric;
                    }
                }
                if (count($metrics) !== count(self::METRICS)) {
                    return $unavailable;
                }

                return ['status' => 'available', 'message' => 'Posted movements for this cycle. Pending reservations are separate from paid withdrawals. Original payments, savings compensation and cash returns remain distinct.',
                    'as_of' => $cutoff->toIso8601String(), 'source_version' => $state['version'].':'.$state['watermark'], 'metrics' => $metrics];
            });
        } catch (RuntimeException|QueryException) {
            return $unavailable;
        }
    }

    /** @return array<string, mixed> */
    public function history(User $viewer, ThriftPlan $plan, int $page = 1, int $perPage = 25): array
    {
        if ($page < 1 || $page > 1000000 || ! in_array($perPage, [25, 50, 100], true)) {
            throw new \InvalidArgumentException('Unsupported posting history pagination.');
        }
        if (! $this->scope->forCustomers($viewer)->whereKey($plan->customer_profile_id)->exists()) {
            throw new NotFoundHttpException('Record unavailable.');
        }
        $unavailable = ['status' => 'unavailable', 'as_of' => null, 'source_version' => null, 'history' => null];
        if (DB::transactionLevel() === 0 && DB::getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        try {
            return DB::transaction(function () use ($viewer, $plan, $page, $perPage, $unavailable): array {
                $customers = $this->scope->forCustomers($viewer)->whereKey($plan->customer_profile_id);
                $current = ThriftPlan::query()->whereKey($plan->id)
                    ->whereIn('customer_profile_id', (clone $customers)->select('customer_profiles.id'))->first();
                if ($current === null || $current->customer_profile_id !== $plan->customer_profile_id
                    || $current->version !== $plan->version || $current->current_terms_revision !== $plan->current_terms_revision
                    || $current->plan_id !== $plan->plan_id) {
                    return $unavailable;
                }
                $cutoff = now();
                $history = $this->activity->postingHistory($customers, $current->id, $cutoff->toDateTimeString(), $page, $perPage);
                $state = $this->ledger->state();
                if ($state['status'] !== 'ready') {
                    return $unavailable;
                }

                return ['status' => 'available', 'as_of' => $cutoff->toIso8601String(),
                    'source_version' => $state['version'].':'.$state['watermark'], 'history' => $history];
            });
        } catch (RuntimeException|QueryException) {
            return $unavailable;
        }
    }

    /**
     * @param  list<ThriftPlan>  $plans
     * @return array<string, array{status: string, message: string, as_of: ?string, source_version: ?string, metrics: list<array<string, mixed>>}>
     */
    public function readMany(User $viewer, array $plans): array
    {
        $ids = array_map(fn (ThriftPlan $plan): int => $plan->id, $plans);
        $publicIds = array_map(fn (ThriftPlan $plan): string => $plan->plan_id, $plans);
        if (count($ids) > 100 || count(array_unique($ids)) !== count($ids) || count(array_unique($publicIds)) !== count($ids)) {
            throw new \InvalidArgumentException('Read at most 100 distinct cycle activity summaries.');
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
                $customers = $this->scope->forCustomers($viewer);
                $records = ThriftPlan::query()->whereIn('id', $ids)->whereIn('customer_profile_id', (clone $customers)->select('customer_profiles.id'))->get();
                $state = $this->ledger->state();
                if ($records->isEmpty() || $state['status'] !== 'ready') {
                    return $unavailable;
                }
                $cutoff = now();
                $summaries = $this->activity->activityMany($customers, array_values($records->modelKeys()), $cutoff->toDateTimeString());
                $result = $unavailable;
                foreach ($plans as $plan) {
                    $current = $records->firstWhere('id', $plan->id);
                    if ($current === null || $current->customer_profile_id !== $plan->customer_profile_id
                        || $current->version !== $plan->version || $current->current_terms_revision !== $plan->current_terms_revision
                        || $current->plan_id !== $plan->plan_id || ($summaries[$current->id] ?? null) === null) {
                        continue;
                    }
                    $metrics = array_values(array_filter($summaries[$current->id], fn (array $metric): bool => in_array($metric['code'], self::METRICS, true)));
                    if (count($metrics) !== count(self::METRICS)) {
                        continue;
                    }
                    $result[$plan->plan_id] = ['status' => 'available',
                        'message' => 'Posted movements for this cycle. Pending reservations are separate from paid withdrawals. Original payments, savings compensation and cash returns remain distinct.',
                        'as_of' => $cutoff->toIso8601String(), 'source_version' => $state['version'].':'.$state['watermark'], 'metrics' => $metrics];
                }

                return $result;
            });
        } catch (RuntimeException|QueryException) {
            return $unavailable;
        }
    }

    /** @return array{status: string, message: string, as_of: ?string, source_version: ?string, metrics: list<array<string, mixed>>} */
    public function unavailableSummary(): array
    {
        return ['status' => 'unavailable', 'message' => 'Verified posted payout and deduction history is unavailable.',
            'as_of' => null, 'source_version' => null, 'metrics' => []];
    }
}
