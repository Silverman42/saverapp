<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Enums\ThriftPlanStatus;
use App\Enums\UserType;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ReportReadService
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private ResourceScopeService $scope,
        private DashboardReadService $dashboard,
        private ReportCatalogue $catalogue,
        private CollectionReadService $balances,
        private LedgerTransactionReadService $ledger,
        private AuthorizationService $authorization,
        private MetricDefinitionService $definitions,
    ) {}

    /** @return array{fingerprint: string, can_collect: bool} */
    public function scopeSummary(User $viewer): array
    {
        abort_unless($viewer->account_state === AccountState::Active
            && $viewer->getRoleNames()->all() === [$viewer->user_type->value], 403);

        return $this->dashboard->scopeSummary($viewer);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function read(User $viewer, string $code, array $filters): array
    {
        $definition = $this->catalogue->get($viewer, $code);
        if (DB::transactionLevel() === 0 && DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return DB::transaction(function () use ($viewer, $code, $filters, $definition): array {
            $scopeSummary = $this->scopeSummary($viewer);
            $business = BusinessProfile::current();
            $customers = $this->scope->forCustomers($viewer);
            $agentId = null;
            if (filled($filters['customer'] ?? null)) {
                abort_unless((clone $customers)->where('customer_id', $filters['customer'])->exists(), 404, 'Record unavailable.');
                $customers->where('customer_id', $filters['customer']);
            }
            if (filled($filters['agent'] ?? null)) {
                $agentId = $this->scope->forAgents($viewer)->where('agent_id', $filters['agent'])->value('id');
                abort_if($agentId === null, 404, 'Record unavailable.');
                if (($filters['agent_basis'] ?? '') === 'current' && $code !== 'agent-performance') {
                    $customers->whereHas('currentAssignment', fn (EloquentBuilder $query) => $query->where('agent_profile_id', $agentId));
                }
            }
            if (filled($filters['plan'] ?? null)) {
                abort_unless(DB::table('thrift_plans')->where('plan_id', $filters['plan'])
                    ->whereIn('customer_profile_id', (clone $customers)->select('id'))->exists(), 404, 'Record unavailable.');
            }
            if (filled($filters['customer_status'] ?? null)) {
                $customers->where('operational_status', $filters['customer_status']);
            }
            $queryFilters = $filters;
            unset($queryFilters['cursor']);
            ksort($queryFilters);
            $binding = hash('sha256', json_encode([$viewer->id, $code, $scopeSummary['fingerprint'], $queryFilters,
                self::SCHEMA_VERSION, MetricDefinitionService::VERSION, $business->version], JSON_THROW_ON_ERROR));
            $cursor = $this->decodeCursor($filters['cursor'] ?? null, $binding);
            $now = CarbonImmutable::now('UTC');
            $cutoff = $cursor === null ? $now : CarbonImmutable::parse($cursor['cutoff']);
            $timezone = $business->timezone;
            $manifest = ['schema_version' => self::SCHEMA_VERSION, 'definition_version' => MetricDefinitionService::VERSION,
                'role' => $viewer->user_type->value, 'scope_hash' => $scopeSummary['fingerprint'],
                'business_id' => $business->business_id, 'currency' => 'NGN', 'filters' => $queryFilters,
                'timezone' => $timezone, 'timezone_version' => $business->version,
                'cutoff' => $cutoff->toIso8601String(), 'generated_at' => $now->toIso8601String(),
                'utc_start' => isset($filters['from']) ? CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc()->toIso8601String() : null,
                'utc_end_exclusive' => isset($filters['to']) ? CarbonImmutable::parse($filters['to'], $timezone)->addDay()->startOfDay()->utc()->min($cutoff)->toIso8601String() : null,
                'owner_watermarks' => [],
                'drill_down_note' => 'Owner screens reauthorize current access and may show a newer cutoff. Historical aggregate drill-down is unavailable.'];
            try {
                $state = $this->ledger->state();
            } catch (Throwable) {
                $state = ['status' => 'unavailable', 'version' => 0, 'watermark' => 0, 'verified_at' => null];
            }
            $manifest['owner_watermarks']['ledger'] = $state;
            $result = $this->unavailable($definition['reason']);
            try {
                if (in_array($code, ['customer-summary', 'contributions', 'collection-performance', 'reconciliation'], true)) {
                    $this->requireLedger($state);
                }
                if (! in_array($code, ['reconciliation'], true) && (clone $customers)->whereNotIn('operational_status', array_column(CustomerStatus::cases(), 'value'))->exists()) {
                    throw new RuntimeException('Customer status unavailable.');
                }
                $spec = $this->query($viewer, $code, $customers, $filters, $agentId, $state, $cutoff);
                if ($spec !== null) {
                    $result = $this->consume($spec, $filters, $cursor, $binding, $manifest, $definition['reason'], $state);
                }
            } catch (RuntimeException|QueryException $exception) {
                $result = $this->unavailable('Authoritative report data is unavailable or inconsistent. Retry after its owner is verified.');
                if ($exception->getMessage() === 'Group result exceeds interactive capacity.') {
                    $result['status'] = 'Too large';
                    $result['reason'] = 'This grouping exceeds 1,000 groups. Narrow the filters or remove grouping; no partial totals are displayed.';
                }
            }
            $sections = ['primary' => $result];
            if ($code === 'agent-performance') {
                try {
                    $this->requireLedger($state);
                    if (! config('collections.enabled')) {
                        throw new RuntimeException('Collections are disabled.');
                    }
                    $ownAgent = $viewer->user_type === UserType::Agent ? $viewer->agentProfile?->id : $agentId;
                    $receipts = $this->verifiedReceipts($state, $cutoff)->whereBetween('receipts.received_date', [$filters['from'], $filters['to']]);
                    if ($ownAgent !== null) {
                        $receipts->where('receipts.recording_agent_profile_id', $ownAgent);
                    }
                    $totals = $receipts->selectRaw('COUNT(*) AS count, COALESCE(SUM(receipts.savings_amount_kobo), 0) AS savings')->first();
                    $sections['recorded_activity'] = ['status' => 'Current', 'metrics' => [
                        $this->metric('posted_receipt_count', (int) $totals->count, 'count'),
                        $this->metric('received_savings', $this->integer($totals->savings), 'NGN'),
                    ], 'rows' => [], 'columns' => [], 'groups' => [], 'total' => (int) $totals->count,
                        'next_cursor' => null, 'reason' => 'Immutable recording actor within the selected received-date period; former-Customer identities and receipt detail are excluded.'];
                } catch (RuntimeException|QueryException) {
                    $sections['recorded_activity'] = $this->unavailable('Verified recording-actor receipt activity is unavailable.');
                }
            }
            if ($code === 'reconciliation' && $viewer->user_type === UserType::Admin && $agentId === null) {
                try {
                    $this->requireLedger($state);
                    if (! config('collections.enabled')) {
                        throw new RuntimeException('Collections are disabled.');
                    }
                    $account = DB::table('ledger_accounts')->where('code', 'business_cash_ngn')
                        ->where('mapping_status', 'mapped')->where('currency', 'NGN')->where('normal_balance', 'debit')->first();
                    if ($account === null) {
                        throw new RuntimeException('Business cash mapping unavailable.');
                    }
                    $cash = DB::table('ledger_entries')->where('ledger_account_id', $account->id)
                        ->sum(DB::raw("CASE WHEN side = 'debit' THEN amount_kobo ELSE -amount_kobo END"));
                    $sections['business_cash'] = ['status' => 'Current', 'metrics' => [$this->metric('business_cash', $this->integer($cash), 'NGN')],
                        'rows' => [], 'columns' => [], 'groups' => [], 'total' => null, 'next_cursor' => null,
                        'reason' => 'Business cash is separate from Agent responsibility, Customer liability and fee earnings.'];
                } catch (RuntimeException|QueryException) {
                    $sections['business_cash'] = $this->unavailable('Verified business cash custody is unavailable.');
                }
            }
            if ($cursor !== null && in_array($result['status'], ['Unavailable', 'Too large'], true)) {
                abort(422, 'The report source changed or is unavailable. Refresh the report.');
            }
            Log::debug('reports.read', ['report' => $code, 'status' => $result['status'], 'schema_version' => self::SCHEMA_VERSION]);

            return ['definition' => $definition, 'manifest' => $manifest, 'sections' => $sections];
        });
    }

    /** @param array<string, mixed> $state */
    private function requireLedger(array $state): void
    {
        if ($state['status'] !== 'ready' || DB::table('ledger_integrity_incidents')->where('status', 'open')->exists()) {
            throw new RuntimeException('Ledger verification unavailable.');
        }
    }

    /** @param EloquentBuilder<CustomerProfile> $customers */
    private function customerQuery(EloquentBuilder $customers): Builder
    {
        return DB::table('customer_profiles as customers')->join('users as customer_users', 'customer_users.id', '=', 'customers.user_id')
            ->leftJoin('customer_assignments as assignment', function (JoinClause $join): void {
                $join->on('assignment.customer_profile_id', '=', 'customers.id')->where('assignment.is_current', 1)->where('assignment.status', 'current');
            })->leftJoin('agent_profiles as current_agents', 'current_agents.id', '=', 'assignment.agent_profile_id')
            ->whereIn('customers.id', (clone $customers)->select('id'));
    }

    /** @param array<string, mixed> $state */
    private function verifiedReceipts(array $state, CarbonImmutable $cutoff): Builder
    {
        return DB::table('collection_receipts as receipts')
            ->join('ledger_transaction_references as refs', function (JoinClause $join): void {
                $join->on('refs.root_id', '=', 'receipts.id')->where('refs.root_type', 'collection_receipt');
            })->join('ledger_transaction_projections as projection', 'projection.ledger_transaction_reference_id', '=', 'refs.id')
            ->where('projection.projection_version', $state['version'])->where('projection.source_max_group_id', '<=', $state['watermark'])
            ->where('projection.committed_at', '<=', $cutoff)->where('receipts.recorded_at', '<=', $cutoff);
    }

    /** @param EloquentBuilder<CustomerProfile> $customers
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    private function query(User $viewer, string $code, EloquentBuilder $customers, array $filters, ?int $agentId, array $state, CarbonImmutable $cutoff): ?array
    {
        $identities = ['customers.customer_id as customer', 'customer_users.name as name', 'current_agents.agent_id as current_agent'];
        $query = $this->customerQuery($customers);
        $money = [];
        $counts = [];
        $date = 'customers.created_at';
        $id = 'customers.id';
        $ascending = false;
        $link = 'customers.show';
        $reference = 'customer';
        $columns = ['customer' => 'Customer ID', 'name' => 'Customer', 'current_agent' => 'Current Agent'];
        if ($code === 'customer-summary') {
            $this->balances->scopedLiability($customers);
            $liabilities = DB::table('ledger_entries')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
                ->where('ledger_accounts.code', 'customer_savings_liability_ngn')
                ->selectRaw("customer_profile_id, SUM(CASE WHEN side = 'credit' THEN amount_kobo ELSE -amount_kobo END) AS liability")
                ->groupBy('customer_profile_id');
            $reservations = DB::table('withdrawal_reservations')->where('status', 'live')
                ->selectRaw('customer_profile_id, SUM(gross_amount_kobo) AS reserved')->groupBy('customer_profile_id');
            $query->leftJoinSub($liabilities, 'liabilities', 'liabilities.customer_profile_id', '=', 'customers.id')
                ->leftJoinSub($reservations, 'reservations', 'reservations.customer_profile_id', '=', 'customers.id')
                ->select($identities)->addSelect('customers.operational_status as status', 'customers.version as _version')
                ->selectRaw('COALESCE(liability, 0) AS customer_liability, COALESCE(reserved, 0) AS live_payout_reservations, COALESCE(liability, 0) - COALESCE(reserved, 0) AS available_savings');
            $money = ['customer_liability', 'live_payout_reservations', 'available_savings'];
            $columns += ['status' => 'Current status'];
        } elseif (in_array($code, ['contributions', 'collection-performance'], true)) {
            if (! config('collections.enabled')) {
                throw new RuntimeException('Collections are disabled.');
            }
            $query = $this->verifiedReceipts($state, $cutoff)
                ->joinSub($this->customerQuery($customers)->select('customers.id', ...$identities), 'scope', 'scope.id', '=', 'receipts.customer_profile_id')
                ->leftJoin('agent_profiles as recording_agents', 'recording_agents.id', '=', 'receipts.recording_agent_profile_id')
                ->leftJoin('thrift_plans as plans', 'plans.id', '=', 'receipts.thrift_plan_id')
                ->whereBetween('receipts.received_date', [$filters['from'], $filters['to']])
                ->select('scope.customer', 'scope.name', 'scope.current_agent', 'receipts.receipt_reference as reference',
                    'refs.transaction_reference as transaction', 'receipts.received_date as date', 'projection.committed_at as committed_at',
                    'receipts.timezone as receipt_timezone', 'recording_agents.agent_id as recording_agent', 'plans.plan_id as plan',
                    'projection.status as correction_state', 'projection.gross_amount_kobo as _projected_tender', 'receipts.savings_amount_kobo as received_savings',
                    'receipts.fee_amount_kobo as received_fees', 'receipts.tender_amount_kobo as cash_received');
            if (($filters['agent_basis'] ?? '') === 'recording') {
                $query->where('receipts.recording_agent_profile_id', $agentId);
            }
            if (filled($filters['plan'] ?? null)) {
                $query->where('plans.plan_id', $filters['plan']);
            }
            $allocations = DB::table('collection_allocations')->selectRaw('collection_receipt_id, COUNT(*) AS slots')->groupBy('collection_receipt_id');
            $query->leftJoinSub($allocations, 'allocations', 'allocations.collection_receipt_id', '=', 'receipts.id')
                ->selectRaw('COALESCE(allocations.slots, 0) AS allocation_count');
            $date = 'receipts.received_date';
            $id = 'receipts.id';
            $money = ['received_savings', 'received_fees', 'cash_received'];
            $counts = ['posted_receipt_count' => null];
            $columns = ['reference' => 'Receipt', 'customer' => 'Customer ID', 'name' => 'Customer', 'date' => 'Received date',
                'committed_at' => 'Committed (UTC)', 'receipt_timezone' => 'Receipt timezone', 'plan' => 'Plan',
                'recording_agent' => 'Recording Agent', 'current_agent' => 'Current Agent', 'correction_state' => 'Correction state', 'allocation_count' => 'Allocated slots'];
            $link = 'collections.show';
            $reference = 'reference';
        } elseif ($code === 'withdrawals') {
            $query->join('withdrawal_requests as requests', 'requests.customer_profile_id', '=', 'customers.id')
                ->join('thrift_plans as plans', 'plans.id', '=', 'requests.thrift_plan_id')
                ->leftJoin('withdrawal_reservations as reservations', 'reservations.id', '=', 'requests.withdrawal_reservation_id')
                ->where('requests.submitted_at', '>=', CarbonImmutable::parse($filters['from'], BusinessProfile::current()->timezone)->startOfDay()->utc())
                ->where('requests.submitted_at', '<', CarbonImmutable::parse($filters['to'], BusinessProfile::current()->timezone)->addDay()->startOfDay()->utc())
                ->where('requests.submitted_at', '<=', $cutoff)->select($identities)
                ->addSelect('requests.withdrawal_id as reference', 'requests.state', 'requests.held', 'requests.submitted_at as date',
                    'requests.deadline_at as deadline', 'plans.plan_id as plan', 'requests.version as _version')
                ->selectRaw("CASE WHEN reservations.status = 'live' THEN reservations.gross_amount_kobo ELSE 0 END AS live_payout_reservations");
            if (filled($filters['state'] ?? null)) {
                $query->where('requests.state', $filters['state']);
            }
            if (filled($filters['plan'] ?? null)) {
                $query->where('plans.plan_id', $filters['plan']);
            }
            $money = ['live_payout_reservations'];
            $counts = ['workflow_requests' => null];
            $columns += ['reference' => 'Request', 'state' => 'Primary state', 'held' => 'Hold overlay', 'date' => 'Submitted (UTC)', 'deadline' => 'Deadline (UTC)', 'plan' => 'Plan'];
            $date = 'requests.submitted_at';
            $id = 'requests.id';
            $link = $viewer->user_type !== UserType::Admin || $this->authorization->allows($viewer, AdminPermission::WithdrawalsReview) ? 'withdrawals.show' : null;
            $reference = 'reference';
        } elseif ($code === 'plans') {
            if (DB::table('thrift_plans')->whereIn('customer_profile_id', (clone $customers)->select('id'))
                ->whereNotExists(function (Builder $terms): void {
                    $terms->selectRaw('1')->from('plan_terms_revisions')->whereColumn('plan_terms_revisions.thrift_plan_id', 'thrift_plans.id')
                        ->whereColumn('plan_terms_revisions.revision', 'thrift_plans.current_terms_revision');
                })->exists()) {
                throw new RuntimeException('Current plan terms unavailable.');
            }
            $query->join('thrift_plans as plans', 'plans.customer_profile_id', '=', 'customers.id')
                ->join('plan_terms_revisions as terms', function (JoinClause $join): void {
                    $join->on('terms.thrift_plan_id', '=', 'plans.id')->on('terms.revision', '=', 'plans.current_terms_revision');
                })->select($identities)->addSelect('plans.plan_id as reference', 'plans.status as state', 'plans.version as _version',
                    'terms.name as plan_name', 'terms.start_date', 'terms.timezone as plan_timezone', 'terms.contribution_days as required_slots',
                    'terms.contribution_amount_kobo as daily_target', 'terms.expected_gross_kobo as agreed_target');
            $slots = DB::table('contribution_slots')->whereNotNull('active_ordinal')->selectRaw('thrift_plan_id, MAX(due_date) AS scheduled_end')->groupBy('thrift_plan_id');
            $query->leftJoinSub($slots, 'schedule', 'schedule.thrift_plan_id', '=', 'plans.id')->addSelect('schedule.scheduled_end');
            if (filled($filters['plan_status'] ?? null)) {
                $query->where('plans.status', $filters['plan_status']);
            }
            if (filled($filters['plan'] ?? null)) {
                $query->where('plans.plan_id', $filters['plan']);
            }
            $counts = ['plans_total' => null, 'open_plans' => 'open'];
            $money = ['agreed_target'];
            $columns += ['reference' => 'Plan', 'plan_name' => 'Name', 'state' => 'Lifecycle', 'start_date' => 'Start', 'scheduled_end' => 'Scheduled end',
                'plan_timezone' => 'Plan timezone', 'required_slots' => 'Required slots', 'daily_target' => 'Daily target'];
            $date = 'plans.created_at';
            $id = 'plans.id';
            $link = 'plans.show';
            $reference = 'reference';
        } elseif (in_array($code, ['reconciliation', 'agent-performance'], true)) {
            $query = DB::table('agent_profiles as agents')->join('users as agent_users', 'agent_users.id', '=', 'agents.user_id')
                ->whereIn('agents.id', $this->scope->forAgents($viewer)->select('id'))
                ->select('agents.agent_id as reference', 'agent_users.name as name', 'agents.operational_status as state', 'agents.version as _version');
            if ($agentId !== null) {
                $query->where('agents.id', $agentId);
            }
            $date = 'agents.created_at';
            $id = 'agents.id';
            $link = null;
            $columns = ['reference' => 'Agent ID', 'name' => 'Agent', 'state' => 'Current operational status'];
            if ($code === 'reconciliation') {
                if (! config('collections.enabled')) {
                    throw new RuntimeException('Collections are disabled.');
                }
                $account = DB::table('ledger_accounts')->where('code', 'agent_receivable_ngn')->where('mapping_status', 'mapped')->where('currency', 'NGN')->where('normal_balance', 'debit')->first();
                if ($account === null) {
                    throw new RuntimeException('Custody account mapping unavailable.');
                }
                $custody = DB::table('ledger_entries')->where('ledger_account_id', $account->id)
                    ->selectRaw("agent_profile_id, SUM(CASE WHEN side = 'debit' THEN amount_kobo ELSE -amount_kobo END) AS responsibility")->groupBy('agent_profile_id');
                $query->leftJoinSub($custody, 'custody', 'custody.agent_profile_id', '=', 'agents.id')->selectRaw('COALESCE(responsibility, 0) AS agent_receivable');
                $money = ['agent_receivable'];
            } else {
                $portfolio = DB::table('customer_assignments as current_assignment')->join('customer_profiles as portfolio', 'portfolio.id', '=', 'current_assignment.customer_profile_id')
                    ->where('current_assignment.is_current', 1)->where('current_assignment.status', 'current')
                    ->whereIn('portfolio.id', (clone $customers)->select('id'))
                    ->selectRaw("agent_profile_id, SUM(CASE WHEN portfolio.operational_status = 'active' THEN 1 ELSE 0 END) AS active_customers, SUM(CASE WHEN portfolio.operational_status = 'inactive' THEN 1 ELSE 0 END) AS inactive_customers, SUM(CASE WHEN portfolio.operational_status = 'restricted' THEN 1 ELSE 0 END) AS restricted_customers, SUM(CASE WHEN portfolio.operational_status <> 'archived' THEN 1 ELSE 0 END) AS current_customers")
                    ->groupBy('agent_profile_id');
                $query->leftJoinSub($portfolio, 'portfolio', 'portfolio.agent_profile_id', '=', 'agents.id')
                    ->selectRaw('COALESCE(active_customers, 0) AS active_customers, COALESCE(inactive_customers, 0) AS inactive_customers, COALESCE(restricted_customers, 0) AS restricted_customers, COALESCE(current_customers, 0) AS current_customers');
                $counts = ['current_customers' => 'current_customers'];
                $columns += ['active_customers' => 'Active Customers', 'inactive_customers' => 'Inactive Customers', 'restricted_customers' => 'Restricted Customers', 'current_customers' => 'Non-archived portfolio'];
            }
        } elseif ($code === 'exceptions') {
            $withdrawals = $this->customerQuery($customers)->join('withdrawal_requests as requests', 'requests.customer_profile_id', '=', 'customers.id')
                ->where(function (Builder $query): void {
                    $query->where('requests.state', 'pending_review')->orWhere('requests.held', true);
                })
                ->whereNull('requests.terminal_at')->select($identities)
                ->addSelect('requests.withdrawal_id as reference', 'requests.state', 'requests.created_at as _date', 'requests.id as _id', 'requests.version as _version')
                ->selectRaw("'withdrawal' AS category");
            $reversals = $this->customerQuery($customers)->join('reversal_requests as requests', 'requests.customer_profile_id', '=', 'customers.id')
                ->where('requests.state', 'pending_review')->select($identities)
                ->addSelect('requests.reversal_id as reference', 'requests.state', 'requests.created_at as _date', 'requests.id as _id', 'requests.version as _version')
                ->selectRaw("'reversal' AS category");
            $query = DB::query()->fromSub($withdrawals->unionAll($reversals), 'exceptions')->select('exceptions.*');
            $date = 'exceptions._date';
            $id = 'exceptions.reference';
            $ascending = true;
            $columns += ['reference' => 'Reference', 'category' => 'Category', 'state' => 'Current state'];
            $counts = ['supported_exceptions' => null];
            $link = null;
        } else {
            return null;
        }
        $query->where($date, '<=', $cutoff)->addSelect($date.' as _date', $id.' as _key')
            ->orderBy($date, $ascending ? 'asc' : 'desc')->orderBy($id, $ascending ? 'asc' : 'desc');
        foreach ($money as $field) {
            $columns[$field] = $this->metric($field, 0, 'NGN')['title'];
        }

        return compact('query', 'columns', 'money', 'counts', 'link', 'reference', 'code', 'viewer');
    }

    /** @param array<string, mixed> $spec
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>|null  $cursor
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function consume(array $spec, array $filters, ?array $cursor, string $binding, array $manifest, string $reason, array $state): array
    {
        $digest = hash_init('sha256');
        $tables = match ($spec['code']) {
            'customer-summary' => ['withdrawal_reservations'],
            'withdrawals' => ['withdrawal_requests', 'withdrawal_reservations'],
            'plans' => ['thrift_plans', 'plan_terms_revisions'],
            'exceptions' => ['withdrawal_requests', 'reversal_requests'],
            default => [],
        };
        $ownerVersions = [];
        foreach ($tables as $table) {
            $ownerVersions[$table] = DB::table($table)->selectRaw('COUNT(*) AS rows_count, MAX(id) AS watermark, MAX(updated_at) AS updated_at')->first();
        }
        hash_update($digest, json_encode([$state, $binding, $ownerVersions], JSON_THROW_ON_ERROR));
        $totals = array_fill_keys([...$spec['money'], ...array_keys($spec['counts'])], 0);
        $groups = [];
        $rows = [];
        $total = 0;
        $offset = $cursor['offset'] ?? 0;
        foreach ($spec['query']->lazy(500) as $object) {
            $row = (array) $object;
            hash_update($digest, json_encode($row, JSON_THROW_ON_ERROR));
            if (isset($row['status']) && CustomerStatus::tryFrom($row['status']) === null) {
                throw new RuntimeException('Customer status unavailable.');
            }
            if ($spec['code'] === 'plans' && ThriftPlanStatus::tryFrom($row['state']) === null) {
                throw new RuntimeException('Plan status unavailable.');
            }
            if (isset($row['received_savings'])) {
                $savings = $this->integer($row['received_savings']);
                $fees = $this->integer($row['received_fees']);
                if ($row['correction_state'] !== 'posted' || $this->integer($row['_projected_tender']) !== $this->integer($row['cash_received'])
                    || $this->add($savings, $fees) !== $this->integer($row['cash_received'])) {
                    throw new RuntimeException('Receipt components disagree.');
                }
            }
            if ($spec['code'] === 'customer-summary') {
                $liability = $this->integer($row['customer_liability']);
                $reserved = $this->integer($row['live_payout_reservations']);
                if ($reserved > $liability) {
                    $row['live_payout_reservations'] = null;
                    $row['available_savings'] = null;
                }
            }
            $values = [];
            foreach ($spec['money'] as $field) {
                $values[$field] = $row[$field] === null ? null : $this->integer($row[$field]);
                $row[$field] = $values[$field] === null ? 'Not available' : MoneyFormatter::formatNaira($values[$field]);
            }
            if (isset($row['daily_target'])) {
                $row['daily_target'] = MoneyFormatter::formatNaira($this->integer($row['daily_target']));
            }
            foreach ($spec['counts'] as $field => $source) {
                $values[$field] = match ($source) {
                    null => 1,
                    'open' => (int) ThriftPlanStatus::from($row['state'])->isOpen(),
                    default => $this->integer($row[$source]),
                };
            }
            foreach ($values as $field => $value) {
                $totals[$field] = $totals[$field] === null || $value === null ? null : $this->add($totals[$field], $value);
            }
            $groupField = $filters['group'] ?? '';
            if ($groupField !== '') {
                $label = (string) ($row[$groupField] ?? 'Unassigned');
                if (! isset($groups[$label])) {
                    if (count($groups) >= 1000) {
                        throw new RuntimeException('Group result exceeds interactive capacity.');
                    }
                    $groups[$label] = ['label' => $label, 'count' => 0, 'values' => array_fill_keys(array_keys($totals), 0)];
                }
                $groups[$label]['count']++;
                foreach ($values as $field => $value) {
                    $old = $groups[$label]['values'][$field];
                    $groups[$label]['values'][$field] = $old === null || $value === null ? null : $this->add($old, $value);
                }
            }
            if ($total >= $offset && count($rows) < $filters['page_size']) {
                $row['href'] = $spec['link'] === null ? null : route($spec['link'], $row[$spec['reference']]);
                if ($spec['code'] === 'exceptions') {
                    $permission = $row['category'] === 'withdrawal' ? AdminPermission::WithdrawalsReview : AdminPermission::ReversalsReview;
                    if ($spec['viewer']->user_type !== UserType::Admin || $this->authorization->allows($spec['viewer'], $permission)) {
                        $row['href'] = route($row['category'] === 'withdrawal' ? 'withdrawals.show' : 'reversals.show', $row['reference']);
                    }
                }
                $rows[] = ['key' => (string) $row['_key'], ...array_intersect_key($row, $spec['columns']), 'href' => $row['href']];
            }
            $total++;
        }
        $fingerprint = hash_final($digest);
        if ($cursor !== null && ! hash_equals($cursor['source'], $fingerprint)) {
            abort(422, 'The report source changed. Refresh the report before continuing.');
        }
        $metrics = [];
        foreach ($totals as $field => $value) {
            $metrics[] = $this->metric($field, $value, in_array($field, $spec['money'], true) ? 'NGN' : 'count');
        }
        ksort($groups);
        foreach ($groups as &$group) {
            $group['metrics'] = [];
            foreach ($group['values'] as $field => $value) {
                $group['metrics'][] = $this->metric($field, $value, in_array($field, $spec['money'], true) ? 'NGN' : 'count');
            }
            unset($group['values']);
        }

        $reservationFailure = in_array(null, $totals, true) ? ' Some financial values are unavailable because their owner controls do not reconcile.' : '';

        return ['status' => 'Partial', 'reason' => $reason.$reservationFailure, 'metrics' => $metrics, 'columns' => $spec['columns'], 'rows' => $rows,
            'total' => $total, 'groups' => array_values($groups), 'source_version' => $fingerprint,
            'next_cursor' => $offset + count($rows) < $total ? Crypt::encryptString(json_encode([
                'binding' => $binding, 'source' => $fingerprint, 'cutoff' => $manifest['cutoff'], 'offset' => $offset + count($rows),
            ], JSON_THROW_ON_ERROR)) : null];
    }

    /** @return array<string, mixed>|null */
    private function decodeCursor(?string $token, string $binding): ?array
    {
        if ($token === null) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['binding'] ?? null) !== $binding || ! is_int($data['offset'] ?? null)
                || $data['offset'] < 0 || ! is_string($data['source'] ?? null) || ! is_string($data['cutoff'] ?? null)
                || CarbonImmutable::parse($data['cutoff'])->isFuture()) {
                throw new RuntimeException('Invalid report cursor.');
            }

            return $data;
        } catch (Throwable) {
            abort(422, 'Invalid or expired report cursor. Refresh the report.');
        }
    }

    private function integer(mixed $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 0) {
            throw new RuntimeException('Exact financial aggregate unavailable.');
        }

        return $integer;
    }

    private function add(int $left, int $right): int
    {
        if ($right < 0 || $left > PHP_INT_MAX - $right) {
            throw new RuntimeException('Exact aggregate overflow.');
        }

        return $left + $right;
    }

    /** @return array<string, mixed> */
    private function metric(string $code, ?int $value, string $unit): array
    {
        $titles = ['agreed_target' => 'Agreed gross target (estimate)', 'workflow_requests' => 'Workflow requests',
            'plans_total' => 'Plans', 'open_plans' => 'Open plans', 'current_customers' => 'Current non-archived Customers',
            'supported_exceptions' => 'Supported exceptions'];

        return $this->definitions->make($code, $titles[$code] ?? $code, $value, $unit, 'authoritative owner', 'declared report basis',
            match ($code) {
                'agreed_target' => 'Agreed contractual target; not actual contributions or liability.',
                'open_plans' => 'Active, Paused and Completed plans; Completed remains open until Closed.',
                'workflow_requests' => 'Distinct submitted requests; not posted payouts.',
                'supported_exceptions' => 'Only integrated withdrawal and reversal queues; zero does not establish no exceptions across all owners.',
                default => 'Full authorized filtered result at the report cutoff; independent from displayed page size.',
            });
    }

    /** @return array<string, mixed> */
    private function unavailable(string $reason): array
    {
        return ['status' => 'Unavailable', 'reason' => $reason, 'metrics' => [], 'rows' => [], 'columns' => [],
            'groups' => [], 'total' => null, 'next_cursor' => null];
    }
}
