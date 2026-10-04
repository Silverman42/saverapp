<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\UserType;
use App\Models\CustomerProfile;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class LedgerTransactionReadService
{
    public function __construct(
        private ResourceScopeService $scope,
        private CollectionReadService $balances,
    ) {}

    /**
     * ready: verified and current. stale: an earlier verified projection is intact but the ledger is ahead of it or the latest rebuild failed;
     * history stays readable up to its own watermark, while balances and every balance-sensitive action fail closed.
     * unavailable: nothing has ever been verified.
     *
     * @return array{status: string, version: int, watermark: int, verified_at: ?string}
     */
    public function state(): array
    {
        $state = DB::table('ledger_projection_state')->where('id', 1)->first();
        $actualWatermark = (int) (DB::table('ledger_posting_groups')->max('id') ?? 0);
        if ($state !== null && $state->status === 'ready' && (int) $state->ledger_group_watermark === $actualWatermark) {
            return ['status' => 'ready', 'version' => (int) $state->active_version,
                'watermark' => $actualWatermark, 'verified_at' => (string) $state->verified_at];
        }
        if ($state !== null && $state->verified_at !== null) {
            return ['status' => 'stale', 'version' => (int) $state->active_version,
                'watermark' => (int) $state->ledger_group_watermark, 'verified_at' => (string) $state->verified_at];
        }

        return ['status' => 'unavailable', 'version' => (int) ($state->active_version ?? 0),
            'watermark' => (int) ($state->ledger_group_watermark ?? 0), 'verified_at' => null];
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function search(User $viewer, array $filters): array
    {
        $state = $this->state();
        if (! in_array($state['status'], ['ready', 'stale'], true)) {
            return ['status' => 'unavailable', 'state' => $state, 'data' => [], 'total' => null,
                'savings_effect_kobo' => null, 'next_cursor' => null];
        }

        $pageSize = (int) ($filters['page_size'] ?? 25);
        $query = $this->scopedQuery($viewer, $state['version'])
            ->where('transactions.source_max_group_id', '<=', $state['watermark']);
        if (filled($filters['customer'] ?? null)) {
            $query->where('customers.customer_id', $filters['customer']);
        }
        if (filled($filters['type'] ?? null)) {
            $query->where('transactions.type', $filters['type']);
        }
        if (filled($filters['from'] ?? null)) {
            $query->where('transactions.occurred_on', '>=', $filters['from']);
        }
        if (filled($filters['to'] ?? null)) {
            $query->where('transactions.occurred_on', '<=', $filters['to']);
        }
        if (filled($filters['reference'] ?? null)) {
            $query->where('refs.transaction_reference', 'like', $filters['reference'].'%');
        }

        $total = (clone $query)->count();
        $effect = filter_var((clone $query)->sum('transactions.savings_effect_kobo'), FILTER_VALIDATE_INT);
        if ($effect === false) {
            return ['status' => 'unavailable', 'state' => $state, 'data' => [], 'total' => null, 'savings_effect_kobo' => null, 'next_cursor' => null];
        }
        $cursor = $this->decodeCursor($filters['cursor'] ?? null, $viewer, $filters, $state);
        if ($cursor !== null) {
            $query->where(function (Builder $query) use ($cursor): void {
                $query->where('transactions.committed_at', '<', $cursor['committed_at'])
                    ->orWhere(function (Builder $query) use ($cursor): void {
                        $query->where('transactions.committed_at', $cursor['committed_at'])
                            ->where('transactions.id', '<', $cursor['id']);
                    });
            });
        }
        $rows = $query->orderByDesc('transactions.committed_at')->orderByDesc('transactions.id')
            ->limit($pageSize + 1)->get();
        $hasMore = $rows->count() > $pageSize;
        $rows = $rows->take($pageSize);
        $last = $rows->last();

        return [
            'status' => 'ready', 'stale' => $state['status'] === 'stale', 'state' => $state, 'data' => $rows->map(fn ($row): array => $this->serialize($row))->all(),
            'total' => $total, 'savings_effect_kobo' => $effect,
            'next_cursor' => $hasMore && $last !== null
                ? $this->encodeCursor($viewer, $filters, $state, (string) $last->committed_at, (int) $last->id)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function detail(User $viewer, string $reference): array
    {
        $state = $this->state();
        if (! in_array($state['status'], ['ready', 'stale'], true)) {
            throw new UnprocessableEntityHttpException('Transaction history is unavailable pending ledger verification.');
        }
        $row = $this->scopedQuery($viewer, $state['version'])
            ->where('refs.transaction_reference', $reference)->first();
        if ($row === null) {
            throw new NotFoundHttpException('Record unavailable.');
        }

        return [...$this->serialize($row), ...$this->enrich($viewer, $row), 'state' => $state];
    }

    /**
     * Signed components, dated timeline, linked correction and (for staff only) the responsible actors of one transaction.
     *
     * @return array<string, mixed>
     */
    private function enrich(User $viewer, \stdClass $row): array
    {
        $staff = $viewer->user_type !== UserType::Customer;
        $compensation = $row->compensating_reference_id === null ? null
            : DB::table('ledger_transaction_references')->where('id', $row->compensating_reference_id)->value('transaction_reference');
        $original = $row->type === 'reversal'
            ? DB::table('ledger_transaction_projections as originals')->join('ledger_transaction_references as refs', 'refs.id', '=', 'originals.ledger_transaction_reference_id')
                ->where('originals.compensating_reference_id', $row->ledger_transaction_reference_id)->where('originals.projection_version', $row->projection_version)
                ->value('refs.transaction_reference') : null;
        $components = null;
        $timeline = [['label' => 'Occurred', 'on' => (string) $row->occurred_on], ['label' => 'Committed', 'at' => (string) $row->committed_at]];
        $actors = [];
        if ($row->root_type === 'withdrawal') {
            $withdrawal = WithdrawalRequest::query()->whereKey((int) $row->root_id)->first();
            if ($withdrawal !== null) {
                $components = ['gross_kobo' => $withdrawal->gross_amount_kobo, 'net_kobo' => $withdrawal->net_amount_kobo,
                    'fee_kobo' => $withdrawal->fee_amount_kobo, 'deduction_kobo' => $withdrawal->deduction_amount_kobo];
                $timeline = [['label' => 'Requested', 'at' => $withdrawal->submitted_at->toIso8601String()],
                    ['label' => 'Approved', 'at' => $withdrawal->approved_at?->toIso8601String()], ...$timeline];
                if ($staff) {
                    $actors = ['requested_by' => User::query()->whereKey($withdrawal->submitted_by_user_id)->value('name'),
                        'reviewed_by' => $withdrawal->reviewed_by_user_id === null ? null : User::query()->whereKey($withdrawal->reviewed_by_user_id)->value('name')];
                }
            }
        } elseif ($row->root_type === 'reversal_request') {
            $reversal = ReversalRequest::query()->whereKey((int) $row->root_id)->first();
            if ($reversal !== null) {
                $timeline = [['label' => 'Requested', 'at' => $reversal->created_at->toIso8601String()],
                    ['label' => 'Decided', 'at' => $reversal->reviewed_at?->toIso8601String()], ...$timeline];
                if ($staff) {
                    $actors = ['requested_by' => User::query()->whereKey($reversal->requested_by_user_id)->value('name'),
                        'reviewed_by' => $reversal->reviewed_by_user_id === null ? null : User::query()->whereKey($reversal->reviewed_by_user_id)->value('name')];
                }
            }
        }

        return ['compensation_reference' => $compensation, 'original_reference' => $original, 'components' => $components,
            'timeline' => $timeline, 'actors' => $actors];
    }

    /** @return array{status: 'unavailable'}|array{status: 'ready', liability_kobo: int, reservations_kobo: int, available_kobo: int} */
    public function balance(User $viewer, CustomerProfile $customer): array
    {
        if (! $this->scope->forCustomers($viewer)->whereKey($customer->id)->exists()) {
            throw new NotFoundHttpException('Record unavailable.');
        }
        if ($this->state()['status'] !== 'ready' || $this->frozenCustomers([$customer->id]) !== []) {
            return ['status' => 'unavailable'];
        }
        try {
            return ['status' => 'ready', ...$this->balances->position($customer)];
        } catch (\RuntimeException) {
            return ['status' => 'unavailable'];
        }
    }

    /**
     * @param  list<CustomerProfile>  $customers
     * @return array<int, array{status: 'unavailable'}|array{status: 'ready', liability_kobo: int, reservations_kobo: int, available_kobo: int}>
     */
    public function balances(User $viewer, array $customers): array
    {
        $ids = array_map(fn (CustomerProfile $customer): int => $customer->id, $customers);
        if (count($ids) > 100 || count(array_unique($ids)) !== count($ids)) {
            throw new \InvalidArgumentException('Read at most 100 distinct Customer balances.');
        }
        if ($ids === []) {
            return [];
        }
        $unavailable = array_fill_keys($ids, ['status' => 'unavailable']);
        if (DB::transactionLevel() === 0 && DB::getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        try {
            return DB::transaction(function () use ($viewer, $ids, $unavailable): array {
                $allowed = $this->scope->forCustomers($viewer)->whereIn('customer_profiles.id', $ids)
                    ->get(['customer_profiles.id'])->map(fn (CustomerProfile $customer): int => $customer->id)->values()->all();
                if ($allowed === [] || $this->state()['status'] !== 'ready') {
                    return $unavailable;
                }
                $result = $unavailable;
                $allowed = array_values(array_diff($allowed, $this->frozenCustomers($allowed)));
                if ($allowed === []) {
                    return $unavailable;
                }
                foreach ($this->balances->positions($allowed) as $customerId => $position) {
                    if ($position !== null) {
                        $result[$customerId] = ['status' => 'ready', ...$position];
                    }
                }

                return $result;
            });
        } catch (\RuntimeException|QueryException) {
            return $unavailable;
        }
    }

    /**
     * Customers frozen by an open scoped integrity incident; their derived reads fail closed while others stay readable.
     *
     * @param  array<int, int>  $customerIds
     * @return list<int>
     */
    public function frozenCustomers(array $customerIds): array
    {
        return array_values(array_map(intval(...), DB::table('ledger_integrity_incidents')->whereIn('customer_profile_id', $customerIds)
            ->where('status', 'open')->distinct()->pluck('customer_profile_id')->all()));
    }

    private function scopedQuery(User $viewer, int $version): Builder
    {
        $query = DB::table('ledger_transaction_projections as transactions')
            ->join('ledger_transaction_references as refs', 'refs.id', '=', 'transactions.ledger_transaction_reference_id')
            ->leftJoin('customer_profiles as customers', 'customers.id', '=', 'transactions.customer_profile_id')
            ->leftJoin('users as customer_users', 'customer_users.id', '=', 'customers.user_id')
            ->where('transactions.projection_version', $version)
            ->select('transactions.*', 'refs.transaction_reference', 'refs.root_type', 'refs.root_id',
                'customers.customer_id', 'customer_users.name as customer_name');
        $customerIds = $this->scope->forCustomers($viewer)->select('id');
        if ($viewer->user_type === UserType::Admin && $viewer->account_state === AccountState::Active
            && $viewer->getRoleNames()->count() === 1 && $viewer->getRoleNames()->first() === UserType::Admin->value) {
            $query->where(function (Builder $query) use ($customerIds): void {
                $query->whereIn('transactions.customer_profile_id', $customerIds)
                    ->orWhereNull('transactions.customer_profile_id');
            });
        } else {
            $query->whereIn('transactions.customer_profile_id', $customerIds);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function serialize(object $row): array
    {
        $data = (array) $row;

        return [
            'reference' => $data['transaction_reference'], 'type' => $data['type'], 'status' => $data['status'],
            'customer_id' => $data['customer_id'], 'customer_name' => $data['customer_name'],
            'occurred_on' => $data['occurred_on'], 'committed_at' => $data['committed_at'],
            'timezone' => $data['timezone'], 'currency' => $data['currency'],
            'gross_amount_kobo' => (int) $data['gross_amount_kobo'],
            'savings_effect_kobo' => (int) $data['savings_effect_kobo'],
            'fee_amount_kobo' => (int) $data['fee_amount_kobo'],
            'posting_group_count' => (int) $data['posting_group_count'],
            'source_type' => $data['root_type'],
        ];
    }

    /** @param array<string, mixed> $filters
     * @param  array<string, mixed>  $state
     */
    private function encodeCursor(User $viewer, array $filters, array $state, string $committedAt, int $id): string
    {
        $payload = json_encode([
            'viewer' => $viewer->id, 'scope' => $this->scopeHash($viewer),
            'filters' => $this->filterHash($filters),
            'version' => $state['version'], 'watermark' => $state['watermark'],
            'no_money_watermark' => $this->noMoneyWatermark(),
            'committed_at' => $committedAt, 'id' => $id,
        ], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, (string) config('app.key'));

        return rtrim(strtr(base64_encode($payload.'.'.$signature), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $filters
     * @param  array<string, mixed>  $state
     * @return array{committed_at: string, id: int}|null
     */
    private function decodeCursor(?string $cursor, User $viewer, array $filters, array $state): ?array
    {
        if ($cursor === null) {
            return null;
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false || ! str_contains($decoded, '.')) {
            throw new UnprocessableEntityHttpException('Invalid transaction cursor.');
        }
        $separator = strrpos($decoded, '.');
        if ($separator === false) {
            throw new UnprocessableEntityHttpException('Invalid transaction cursor.');
        }
        $payload = substr($decoded, 0, $separator);
        $signature = substr($decoded, $separator + 1);
        if (! hash_equals(hash_hmac('sha256', $payload, (string) config('app.key')), $signature)) {
            throw new UnprocessableEntityHttpException('Invalid transaction cursor.');
        }
        $data = json_decode($payload, true);
        if (! is_array($data) || ($data['viewer'] ?? null) !== $viewer->id
            || ($data['scope'] ?? null) !== $this->scopeHash($viewer)
            || ($data['filters'] ?? null) !== $this->filterHash($filters)
            || ($data['version'] ?? null) !== $state['version']
            || ($data['watermark'] ?? null) !== $state['watermark']
            || ($data['no_money_watermark'] ?? null) !== $this->noMoneyWatermark()
            || ! is_string($data['committed_at'] ?? null) || ! is_int($data['id'] ?? null)) {
            throw new UnprocessableEntityHttpException('Transaction cursor expired. Restart the search.');
        }

        return ['committed_at' => $data['committed_at'], 'id' => $data['id']];
    }

    private function noMoneyWatermark(): int
    {
        return (int) (DB::table('financial_workflow_supplements')->where('kind', 'receipt_no_money_correction')->max('id') ?? 0);
    }

    /** @param array<string, mixed> $filters */
    private function filterHash(array $filters): string
    {
        unset($filters['cursor']);
        ksort($filters);

        return hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR));
    }

    private function scopeHash(User $viewer): string
    {
        $customerIds = $viewer->user_type === UserType::Agent
            ? $this->scope->forCustomers($viewer)->orderBy('id')->pluck('id')->all()
            : [];

        return hash('sha256', json_encode([
            $viewer->id, $viewer->user_type->value, $viewer->account_state->value,
            $viewer->permission_version, $customerIds,
        ], JSON_THROW_ON_ERROR));
    }
}
