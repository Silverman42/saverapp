<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Enums\FeeObligationEntryType;
use App\Enums\ThriftPlanStatus;
use App\Enums\UserType;
use App\Models\BusinessProfile;
use App\Models\CollectionBatch;
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
    public const SCHEMA_VERSION = 7;

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
            $allowedSections = match ($code) {
                'fees' => ['primary', 'external_receipts'],
                'reconciliation' => ['primary', 'batch_reconciliation'],
                'exceptions' => ['primary', 'fee_obligations', 'custody_batches'],
                'plans' => ['primary', 'funding_progress'],
                default => ['primary'],
            };
            if ($cursor !== null && ! in_array($cursor['section'] ?? 'primary', $allowedSections, true)) {
                abort(422, 'Invalid report cursor. Refresh the report.');
            }
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
            $feeSections = null;
            try {
                if (in_array($code, ['customer-summary', 'contributions', 'collection-performance', 'reconciliation', 'fees'], true)) {
                    $this->requireLedger($state);
                }
                if (! in_array($code, ['reconciliation'], true) && (clone $customers)->whereNotIn('operational_status', array_column(CustomerStatus::cases(), 'value'))->exists()) {
                    throw new RuntimeException('Customer status unavailable.');
                }
                if ($code === 'fees') {
                    $feeSections = $this->feeSections($viewer, $customers, $filters, $cursor, $binding, $manifest, $state, $cutoff);
                } elseif ($code === 'plans' && isset($filters['_funding_plan_ids'])) {
                    $result = $this->unavailable('The plan directory reads only verified funding progress for its bounded page.');
                } else {
                    $spec = $this->query($viewer, $code, $customers, $filters, $agentId, $state, $cutoff);
                    if ($spec !== null) {
                        $result = $this->consume($spec, $filters, $cursor, $binding, $manifest, $definition['reason'], $state);
                    }
                }
            } catch (RuntimeException|QueryException $exception) {
                $result = $this->unavailable('Authoritative report data is unavailable or inconsistent. Retry after its owner is verified.');
                if ($exception->getMessage() === 'Group result exceeds interactive capacity.') {
                    $result['status'] = 'Too large';
                    $result['reason'] = 'This grouping exceeds 1,000 groups. Narrow the filters or remove grouping; no partial totals are displayed.';
                }
            }
            $sections = $feeSections ?? ['primary' => $result];
            if ($code === 'fees' && $feeSections === null) {
                $sections['external_receipts'] = $this->unavailable('Verified external fee receipts are unavailable.');
            }
            if ($code === 'plans') {
                try {
                    if (! config('collections.enabled')) {
                        throw new RuntimeException('Collections are disabled.');
                    }
                    $this->requireLedger($state);
                    $spec = $this->planFundingSpec($customers, $filters, $state, $cutoff);
                    $sections['funding_progress'] = $this->consume($spec, $filters, $cursor, $binding, $manifest,
                        'Current verified allocation coverage of agreed slots. Unfunded target is not an amount due, Customer liability or a missed-slot classification.',
                        $state, 'funding_progress');
                } catch (RuntimeException|QueryException) {
                    $sections['funding_progress'] = $this->unavailable('Verified plan funding is unavailable or inconsistent.');
                }
            }
            if ($code === 'reconciliation') {
                try {
                    $this->requireLedger($state);
                    if (! config('collections.enabled')) {
                        throw new RuntimeException('Collections are disabled.');
                    }
                    $spec = $this->batchReconciliationSpec($viewer, $agentId, $state, $cutoff);
                    $sections['batch_reconciliation'] = $this->consume($spec, $filters, $cursor, $binding, $manifest,
                        'Current verified method-custody batch snapshot. Historical opening, movement and closing balances and complete variance history remain unavailable.',
                        $state, 'batch_reconciliation');
                } catch (RuntimeException|QueryException) {
                    $sections['batch_reconciliation'] = $this->unavailable('Verified batch reconciliation is unavailable or inconsistent.');
                }
            }
            if ($code === 'exceptions') {
                try {
                    $spec = $this->feeExceptionSpec($viewer, $customers, $cutoff);
                    $sections['fee_obligations'] = $this->consume($spec, $filters, $cursor, $binding, $manifest,
                        'Current outstanding fee obligations only. An assessment is neither cash received nor fee income.',
                        $state, 'fee_obligations');
                } catch (RuntimeException|QueryException) {
                    $sections['fee_obligations'] = $this->unavailable('Fee obligation history is unavailable or inconsistent.');
                }
                if ($viewer->user_type !== UserType::Customer) {
                    try {
                        if (filled($filters['customer'] ?? null) || filled($filters['customer_status'] ?? null)) {
                            throw new RuntimeException('Customer filters do not identify original-Agent custody.');
                        }
                        if (! config('collections.enabled')) {
                            throw new RuntimeException('Collections are disabled.');
                        }
                        $this->requireLedger($state);
                        $spec = $this->batchReconciliationSpec($viewer, null, $state, $cutoff);
                        $spec['query']->where(function (Builder $query): void {
                            $query->where('batches.status', '<>', 'reconciled')
                                ->orWhereExists(function (Builder $exceptions): void {
                                    $exceptions->selectRaw('1')->from('collection_exceptions as unresolved')
                                        ->whereColumn('unresolved.collection_batch_id', 'batches.id')
                                        ->where('unresolved.status', '<>', 'resolved');
                                });
                        })->reorder()->orderBy('batches.received_date')->orderBy('batches.id');
                        $spec['columns'] = ['reference' => 'Batch', 'received_date' => 'Received date',
                            'original_agent' => 'Original Agent', 'method' => 'Method', 'revision' => 'Revision', 'predecessor' => 'Previous batch',
                            'state' => 'Current state', 'unremitted' => 'Unremitted cash', 'bank_received' => 'Confirmed bank custody',
                            'pending_settlement' => 'Pending clearing settlement', 'open_exceptions' => 'Open exceptions',
                            'latest_review' => 'Latest review'];
                        $spec['money'] = ['unremitted', 'bank_received', 'pending_settlement'];
                        $spec['counts'] = ['unreconciled_batches' => null, 'open_exceptions' => 'open_exceptions'];
                        $spec['link'] = 'collection-batches.show';
                        $sections['custody_batches'] = $this->consume($spec, $filters, $cursor, $binding, $manifest,
                            'Current original-Agent batches needing reconciliation. Amounts are custody responsibility, not Customer liability or fee income.',
                            $state, 'custody_batches');
                    } catch (RuntimeException|QueryException $exception) {
                        $reason = match ($exception->getMessage()) {
                            'Customer filters do not identify original-Agent custody.' => 'Clear the Customer filter to view original-Agent custody work.',
                            'Collections are disabled.' => 'The collection owner is disabled; custody exceptions are unavailable.',
                            default => 'Verified batch reconciliation is unavailable or inconsistent.',
                        };
                        $sections['custody_batches'] = $this->unavailable($reason);
                    }
                }
                $sections['refund_payables'] = $this->unavailable(
                    'Refund payable exceptions require an approved mapped ledger account and owner contract. No zero balance is inferred.'
                );
            }
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
            if (in_array($code, ['fees', 'withdrawals', 'contributions', 'reconciliation'], true)) {
                try {
                    $sections['posted_financial_movements'] = app(FinancialWorkflowReadService::class)->activity($viewer, $customers, $filters, $cutoff->toDateTimeString());
                } catch (RuntimeException|QueryException) {
                    $sections['posted_financial_movements'] = $this->unavailable('Verified financial movements for this scope and basis are unavailable.');
                }
            }
            if ($code === 'reconciliation' && $viewer->user_type === UserType::Admin && empty($filters['agent'])) {
                try {
                    $this->requireLedger($state);
                    $sections['financial_cash_position'] = app(FinancialWorkflowReadService::class)->businessPosition($viewer);
                } catch (RuntimeException|QueryException) {
                    $sections['financial_cash_position'] = $this->unavailable('Verified business-wide free cash and earnings mappings are unavailable.');
                }
            }
            if ($cursor !== null && in_array($sections[$cursor['section'] ?? 'primary']['status'], ['Unavailable', 'Too large'], true)) {
                abort(422, 'The report source changed or is unavailable. Refresh the report.');
            }
            Log::debug('reports.read', ['report' => $code, 'status' => $sections['primary']['status'], 'schema_version' => self::SCHEMA_VERSION]);

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
        return DB::table('collection_receipts as receipts')->whereNull('receipts.replacement_reversal_id')
            ->join('ledger_transaction_references as refs', function (JoinClause $join): void {
                $join->on('refs.root_id', '=', 'receipts.id')->where('refs.root_type', 'collection_receipt');
            })->join('ledger_transaction_projections as projection', 'projection.ledger_transaction_reference_id', '=', 'refs.id')
            ->where('projection.projection_version', $state['version'])->where('projection.source_max_group_id', '<=', $state['watermark'])
            ->where('projection.committed_at', '<=', $cutoff)->where('receipts.recorded_at', '<=', $cutoff);
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function batchReconciliationSpec(User $viewer, ?int $agentId, array $state, CarbonImmutable $cutoff): array
    {
        foreach (['agent_receivable_ngn', 'business_cash_ngn'] as $code) {
            if (! DB::table('ledger_accounts')->where('code', $code)->where('mapping_status', 'mapped')
                ->where('currency', 'NGN')->where('normal_balance', 'debit')->exists()) {
                throw new RuntimeException('Cash custody mapping unavailable.');
            }
        }

        $components = DB::table('collection_fee_components')->selectRaw('collection_receipt_id, SUM(amount_kobo) AS total')
            ->groupBy('collection_receipt_id');
        $receipts = DB::table('collection_receipts as receipts')->whereNull('receipts.replacement_reversal_id')
            ->join('collection_batches as receipt_batches', 'receipt_batches.id', '=', 'receipts.collection_batch_id')
            ->leftJoinSub($components, 'components', 'components.collection_receipt_id', '=', 'receipts.id')
            ->leftJoin('ledger_transaction_references as receipt_refs', function (JoinClause $join): void {
                $join->on('receipt_refs.root_id', '=', 'receipts.id')->where('receipt_refs.root_type', 'collection_receipt');
            })->leftJoin('ledger_transaction_projections as receipt_projection', function (JoinClause $join) use ($state): void {
                $join->on('receipt_projection.ledger_transaction_reference_id', '=', 'receipt_refs.id')
                    ->where('receipt_projection.projection_version', $state['version']);
            })->selectRaw('receipts.collection_batch_id, COUNT(*) AS receipt_count,
                COALESCE(SUM(receipts.tender_amount_kobo), 0) AS tender,
                COALESCE(SUM(receipts.savings_amount_kobo), 0) AS savings,
                COALESCE(SUM(receipts.fee_amount_kobo), 0) AS fees')
            ->selectRaw("SUM(CASE WHEN receipts.recording_agent_profile_id <> receipt_batches.agent_profile_id
                OR receipts.received_date <> receipt_batches.received_date OR receipts.timezone <> receipt_batches.timezone
                OR receipts.tender_amount_kobo <> receipts.savings_amount_kobo + receipts.fee_amount_kobo
                OR receipts.fee_amount_kobo <> COALESCE(components.total, 0)
                OR receipts.recorded_at > ? OR receipt_projection.id IS NULL
                OR receipt_projection.status NOT IN ('posted', 'reversed') OR receipt_projection.type <> 'contribution'
                OR receipt_projection.currency <> 'NGN' OR receipt_projection.customer_profile_id <> receipts.customer_profile_id
                OR receipt_projection.occurred_on <> receipts.received_date
                OR receipt_projection.gross_amount_kobo <> receipts.tender_amount_kobo
                OR receipt_projection.fee_amount_kobo < receipts.fee_amount_kobo
                OR receipt_projection.source_max_group_id > ? OR receipt_projection.committed_at > ?
                THEN 1 ELSE 0 END) AS invalid_receipts", [$cutoff, $state['watermark'], $cutoff])
            ->groupBy('receipts.collection_batch_id');

        $lines = DB::table('ledger_entries as entries')->join('ledger_accounts as accounts', 'accounts.id', '=', 'entries.ledger_account_id')
            ->selectRaw("entries.ledger_posting_group_id, COUNT(*) AS line_count,
                SUM(CASE WHEN accounts.code = 'business_cash_ngn' AND accounts.mapping_status = 'mapped'
                    AND accounts.currency = 'NGN' AND entries.side = 'debit' AND entries.agent_profile_id IS NULL
                    THEN entries.amount_kobo ELSE 0 END) AS cash_debit,
                SUM(CASE WHEN accounts.code = 'agent_receivable_ngn' AND accounts.mapping_status = 'mapped'
                    AND accounts.currency = 'NGN' AND entries.side = 'credit'
                    THEN entries.amount_kobo ELSE 0 END) AS receivable_credit,
                MAX(CASE WHEN accounts.code = 'agent_receivable_ngn' AND entries.side = 'credit'
                    THEN entries.agent_profile_id ELSE NULL END) AS receivable_agent")
            ->groupBy('entries.ledger_posting_group_id');
        $remittances = DB::table('cash_remittances as remittances')
            ->join('collection_batches as remittance_batches', 'remittance_batches.id', '=', 'remittances.collection_batch_id')
            ->leftJoin('ledger_posting_groups as posting', 'posting.id', '=', 'remittances.ledger_posting_group_id')
            ->leftJoinSub($lines, 'lines', 'lines.ledger_posting_group_id', '=', 'posting.id')
            ->leftJoin('ledger_transaction_references as remittance_refs', function (JoinClause $join): void {
                $join->on('remittance_refs.root_id', '=', 'remittances.id')->where('remittance_refs.root_type', 'cash_remittance');
            })->leftJoin('ledger_transaction_projections as remittance_projection', function (JoinClause $join) use ($state): void {
                $join->on('remittance_projection.ledger_transaction_reference_id', '=', 'remittance_refs.id')
                    ->where('remittance_projection.projection_version', $state['version']);
            })->selectRaw('remittances.collection_batch_id, COUNT(*) AS remittance_count,
                COALESCE(SUM(remittances.amount_kobo), 0) AS remitted')
            ->selectRaw("SUM(CASE WHEN remittances.agent_profile_id <> remittance_batches.agent_profile_id
                OR remittances.amount_kobo < 1 OR remittances.created_at > ?
                OR posting.id IS NULL OR posting.source_type <> 'cash_remittance'
                OR posting.source_id <> remittances.id OR posting.event_type <> 'cash_remittance'
                OR posting.currency <> 'NGN' OR posting.committed_at > ? OR posting.id > ?
                OR posting.actor_user_id IS NULL OR posting.actor_user_id <> remittances.confirmed_by_user_id
                OR posting.customer_profile_id IS NOT NULL OR posting.thrift_plan_id IS NOT NULL
                OR posting.occurred_on IS NULL OR DATE(posting.occurred_on) <> remittances.handoff_date
                OR posting.business_timezone IS NULL OR posting.business_timezone <> remittance_batches.timezone
                OR posting.occurred_at IS NULL OR posting.schema_version < 1
                OR lines.line_count <> 2 OR lines.cash_debit <> remittances.amount_kobo
                OR lines.receivable_credit <> remittances.amount_kobo
                OR lines.receivable_agent <> remittances.agent_profile_id
                OR remittance_projection.id IS NULL OR remittance_projection.status <> 'posted'
                OR remittance_projection.type <> 'remittance' OR remittance_projection.currency <> 'NGN'
                OR remittance_projection.gross_amount_kobo <> remittances.amount_kobo
                OR remittance_projection.occurred_on <> remittances.handoff_date
                OR remittance_projection.source_max_group_id <> posting.id
                OR remittance_projection.committed_at > ?
                THEN 1 ELSE 0 END) AS invalid_remittances", [$cutoff, $cutoff, $state['watermark'], $cutoff])
            ->groupBy('remittances.collection_batch_id');

        $settlements = DB::table('collection_settlements as settlements')
            ->leftJoin('ledger_posting_groups as settlement_posting', 'settlement_posting.id', '=', 'settlements.ledger_posting_group_id')
            ->leftJoin('ledger_transaction_references as settlement_refs', function (JoinClause $join): void {
                $join->on('settlement_refs.root_id', '=', 'settlements.settlement_reference')->where('settlement_refs.root_type', 'collection_settlement');
            })->leftJoin('ledger_transaction_projections as settlement_projection', function (JoinClause $join) use ($state): void {
                $join->on('settlement_projection.ledger_transaction_reference_id', '=', 'settlement_refs.id')
                    ->where('settlement_projection.projection_version', $state['version']);
            })->selectRaw('settlements.collection_batch_id, SUM(settlements.amount_kobo) AS settled')
            ->selectRaw("SUM(CASE WHEN settlements.created_at > ? OR settlement_posting.id IS NULL
                OR settlement_posting.id > ? OR settlement_posting.committed_at > ?
                OR settlement_projection.id IS NULL OR settlement_projection.status <> 'posted'
                OR settlement_projection.type <> 'remittance' OR settlement_projection.currency <> 'NGN'
                OR settlement_projection.gross_amount_kobo <> settlements.amount_kobo
                OR settlement_projection.occurred_on <> settlements.settled_date
                OR settlement_projection.source_max_group_id <> settlement_posting.id
                OR settlement_projection.committed_at > ? THEN 1 ELSE 0 END) AS invalid_settlements",
                [$cutoff, $state['watermark'], $cutoff, $cutoff])
            ->groupBy('settlements.collection_batch_id');

        $latestReviews = DB::table('collection_batch_reviews')->selectRaw('collection_batch_id, MAX(id) AS latest_id,
            COUNT(*) AS review_count, MAX(updated_at) AS latest_update')->groupBy('collection_batch_id');
        $exceptions = DB::table('collection_exceptions')->selectRaw("collection_batch_id,
            SUM(CASE WHEN status <> 'resolved' THEN 1 ELSE 0 END) AS open_exceptions,
            COUNT(*) AS exception_count, MAX(id) AS latest_id, MAX(updated_at) AS latest_update")
            ->groupBy('collection_batch_id');

        $query = DB::table('collection_batches as batches')
            ->join('agent_profiles as agents', 'agents.id', '=', 'batches.agent_profile_id')
            ->leftJoin('collection_batches as predecessor', 'predecessor.id', '=', 'batches.predecessor_batch_id')
            ->leftJoinSub($receipts, 'receipts', 'receipts.collection_batch_id', '=', 'batches.id')
            ->leftJoinSub($remittances, 'remittances', 'remittances.collection_batch_id', '=', 'batches.id')
            ->leftJoinSub($settlements, 'settlements', 'settlements.collection_batch_id', '=', 'batches.id')
            ->leftJoin('collection_method_versions as methods', 'methods.id', '=', 'batches.collection_method_version_id')
            ->leftJoinSub($latestReviews, 'review_versions', 'review_versions.collection_batch_id', '=', 'batches.id')
            ->leftJoin('collection_batch_reviews as review', 'review.id', '=', 'review_versions.latest_id')
            ->leftJoinSub($exceptions, 'exceptions', 'exceptions.collection_batch_id', '=', 'batches.id')
            ->where('batches.created_at', '<=', $cutoff)
            ->select('batches.id as reference', 'batches.id as _key', 'batches.received_date', 'agents.agent_id as original_agent',
                'batches.custody_account_code as _custody', 'batches.method_identity as _method_identity',
                'predecessor.method_identity as _predecessor_method', 'methods.label as method',
                'batches.revision', 'batches.predecessor_batch_id as predecessor', 'batches.status as state',
                'review.outcome as latest_review', 'review.batch_version as _review_version',
                'review.expected_kobo as _review_expected', 'review.remitted_kobo as _review_remitted',
                'review.outstanding_kobo as _review_outstanding', 'batches.version as _version',
                'batches.updated_at as _updated', 'batches.agent_profile_id as _agent_id', 'batches.timezone as _timezone',
                'predecessor.agent_profile_id as _predecessor_agent', 'predecessor.received_date as _predecessor_date',
                'predecessor.timezone as _predecessor_timezone', 'predecessor.revision as _predecessor_revision',
                'review_versions.review_count as _review_count', 'review_versions.latest_update as _review_update',
                'exceptions.exception_count as _exception_count', 'exceptions.latest_id as _exception_latest',
                'exceptions.latest_update as _exception_update', 'receipts.invalid_receipts as _invalid_receipts',
                'remittances.invalid_remittances as _invalid_remittances')
            ->selectRaw('COALESCE(settlements.invalid_settlements, 0) AS _invalid_settlements')
            ->selectRaw('COALESCE(receipts.receipt_count, 0) AS receipt_count,
                COALESCE(receipts.tender, 0) AS gross_tender, COALESCE(receipts.savings, 0) AS savings_component,
                COALESCE(receipts.fees, 0) AS external_fee_component,
                COALESCE(remittances.remitted, 0) AS confirmed_remittances,
                COALESCE(settlements.settled, 0) AS _settled,
                CASE WHEN batches.custody_account_code = \'agent_receivable_ngn\'
                    THEN COALESCE(receipts.tender, 0) - COALESCE(remittances.remitted, 0) ELSE 0 END AS unremitted,
                CASE WHEN batches.custody_account_code = \'business_bank_ngn\' THEN COALESCE(receipts.tender, 0)
                    ELSE COALESCE(settlements.settled, 0) END AS bank_received,
                CASE WHEN batches.custody_account_code = \'payment_clearing_ngn\'
                    THEN COALESCE(receipts.tender, 0) - COALESCE(settlements.settled, 0) ELSE 0 END AS pending_settlement,
                COALESCE(exceptions.open_exceptions, 0) AS open_exceptions')
            ->orderByDesc('batches.received_date')->orderByDesc('batches.id');
        if ($viewer->user_type === UserType::Agent) {
            $ownAgent = $viewer->agentProfile?->id;
            if ($ownAgent === null) {
                throw new RuntimeException('Agent custody scope unavailable.');
            }
            $query->where('batches.agent_profile_id', $ownAgent);
        } elseif ($agentId !== null) {
            $query->where('batches.agent_profile_id', $agentId);
        }

        return ['code' => 'batch_reconciliation', 'query' => $query,
            'columns' => ['reference' => 'Batch', 'received_date' => 'Received date', 'original_agent' => 'Original Agent',
                'method' => 'Method', 'revision' => 'Revision', 'predecessor' => 'Previous batch', 'state' => 'Current state',
                'receipt_count' => 'Receipts', 'gross_tender' => 'Gross tender', 'savings_component' => 'Savings component',
                'external_fee_component' => 'External fee component', 'confirmed_remittances' => 'Confirmed remittances',
                'unremitted' => 'Unremitted cash', 'bank_received' => 'Confirmed bank custody',
                'pending_settlement' => 'Pending clearing settlement', 'open_exceptions' => 'Open exceptions', 'latest_review' => 'Latest review'],
            'money' => ['gross_tender', 'savings_component', 'external_fee_component', 'confirmed_remittances', 'unremitted', 'bank_received', 'pending_settlement'],
            'counts' => ['batch_count' => null, 'receipt_count' => 'receipt_count', 'open_exceptions' => 'open_exceptions'],
            'link' => $viewer->user_type === UserType::Admin ? 'collection-batches.show' : null,
            'reference' => 'reference', 'viewer' => $viewer];
    }

    /** @param EloquentBuilder<CustomerProfile> $customers
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function planFundingSpec(EloquentBuilder $customers, array $filters, array $state, CarbonImmutable $cutoff): array
    {
        if (! DB::table('ledger_accounts')->where('code', 'customer_savings_liability_ngn')->where('currency', 'NGN')
            ->where('account_class', 'customer_savings_liability')->where('normal_balance', 'credit')
            ->where('mapping_status', 'mapped')->where('version', '>=', 1)->exists()) {
            throw new RuntimeException('The savings allocation account is unavailable.');
        }
        $expectedSlotDate = match (DB::getDriverName()) {
            'sqlite' => "date(current_slot_terms.start_date, '+' || (slots.active_ordinal - 1) || ' days')",
            'mysql', 'mariadb' => 'DATE_ADD(current_slot_terms.start_date, INTERVAL (slots.active_ordinal - 1) DAY)',
            default => throw new RuntimeException('Plan schedule verification is unavailable for this database.'),
        };
        $scopedPlans = DB::table('thrift_plans as scoped_plans')
            ->whereIn('scoped_plans.customer_profile_id', (clone $customers)->select('id'));
        if (filled($filters['plan'] ?? null)) {
            $scopedPlans->where('scoped_plans.plan_id', $filters['plan']);
        }
        if (filled($filters['plan_status'] ?? null)) {
            $scopedPlans->where('scoped_plans.status', $filters['plan_status']);
        }
        if (isset($filters['_funding_plan_ids'])) {
            $scopedPlans->whereIn('scoped_plans.plan_id', $filters['_funding_plan_ids']);
        }
        app(CollectionAllocationReleaseProof::class)->assertForPlans((clone $scopedPlans)->select('scoped_plans.id'));
        $receiptAllocations = DB::table('collection_allocations')
            ->selectRaw('collection_receipt_id, SUM(amount_kobo) AS allocated_savings')
            ->groupBy('collection_receipt_id');
        $invalidReceipts = DB::table('collection_receipts as receipts')
            ->joinSub((clone $scopedPlans)->select('scoped_plans.id', 'scoped_plans.customer_profile_id'),
                'scoped_plans', 'scoped_plans.id', '=', 'receipts.thrift_plan_id')
            ->leftJoinSub($receiptAllocations, 'receipt_allocations', 'receipt_allocations.collection_receipt_id', '=', 'receipts.id')
            ->leftJoin('ledger_transaction_references as refs', function (JoinClause $join): void {
                $join->on('refs.root_id', '=', 'receipts.id')->where('refs.root_type', 'collection_receipt');
            })->leftJoin('ledger_transaction_projections as projection', function (JoinClause $join) use ($state): void {
                $join->on('projection.ledger_transaction_reference_id', '=', 'refs.id')
                    ->where('projection.projection_version', $state['version']);
            })->where(function (Builder $query) use ($state, $cutoff): void {
                $query->whereColumn('receipts.customer_profile_id', '<>', 'scoped_plans.customer_profile_id')
                    ->orWhereRaw('COALESCE(receipt_allocations.allocated_savings, 0) <> receipts.savings_amount_kobo')
                    ->orWhereRaw('receipts.savings_amount_kobo + receipts.fee_amount_kobo <> receipts.tender_amount_kobo')
                    ->orWhere('receipts.recorded_at', '>', $cutoff)
                    ->orWhereNull('projection.id')->orWhereNotIn('projection.status', ['posted', 'reversed'])
                    ->orWhereRaw("projection.type <> CASE WHEN receipts.replacement_reversal_id IS NULL THEN 'contribution' ELSE 'replacement' END")
                    ->orWhere('projection.currency', '<>', 'NGN')
                    ->orWhereColumn('projection.customer_profile_id', '<>', 'receipts.customer_profile_id')
                    ->orWhereColumn('projection.gross_amount_kobo', '<>', 'receipts.tender_amount_kobo')
                    ->orWhereColumn('projection.fee_amount_kobo', '<', 'receipts.fee_amount_kobo')
                    ->orWhere('projection.source_max_group_id', '>', $state['watermark'])
                    ->orWhere('projection.committed_at', '>', $cutoff);
            })->exists();
        if ($invalidReceipts || (clone $scopedPlans)->whereNotExists(function (Builder $query): void {
            $query->selectRaw('1')->from('plan_terms_revisions as current_terms')
                ->whereColumn('current_terms.thrift_plan_id', 'scoped_plans.id')
                ->whereColumn('current_terms.revision', 'scoped_plans.current_terms_revision');
        })->exists()) {
            throw new RuntimeException('Plan or receipt owner is inconsistent.');
        }

        $allocations = DB::table('collection_allocations as allocations')->whereNotIn('allocations.id', DB::table('collection_allocation_releases')->select('collection_allocation_id'))
            ->join('contribution_slots as funded_slots', 'funded_slots.id', '=', 'allocations.contribution_slot_id')
            ->join('thrift_plans as funded_plans', 'funded_plans.id', '=', 'funded_slots.thrift_plan_id')
            ->join('collection_receipts as receipts', 'receipts.id', '=', 'allocations.collection_receipt_id')
            ->whereIn('funded_plans.id', (clone $scopedPlans)->select('scoped_plans.id'))
            ->selectRaw('allocations.contribution_slot_id, SUM(allocations.amount_kobo) AS funded')
            ->selectRaw('SUM(CASE WHEN allocations.amount_kobo < 1 OR receipts.thrift_plan_id <> funded_slots.thrift_plan_id
                OR receipts.customer_profile_id <> funded_plans.customer_profile_id OR receipts.recorded_at > ?
                THEN 1 ELSE 0 END) AS invalid_allocations', [$cutoff])
            ->groupBy('allocations.contribution_slot_id');
        $slots = DB::table('contribution_slots as slots')
            ->join('thrift_plans as slot_plans', 'slot_plans.id', '=', 'slots.thrift_plan_id')
            ->join('plan_terms_revisions as current_slot_terms', function (JoinClause $join): void {
                $join->on('current_slot_terms.thrift_plan_id', '=', 'slot_plans.id')
                    ->on('current_slot_terms.revision', '=', 'slot_plans.current_terms_revision');
            })
            ->leftJoin('plan_terms_revisions as originating_terms', 'originating_terms.id', '=', 'slots.plan_terms_revision_id')
            ->leftJoinSub($allocations, 'funding', 'funding.contribution_slot_id', '=', 'slots.id')
            ->whereNotNull('slots.active_ordinal')
            ->whereIn('slots.thrift_plan_id', (clone $scopedPlans)->select('scoped_plans.id'))
            ->selectRaw('slots.thrift_plan_id, COUNT(*) AS active_slots, MIN(slots.active_ordinal) AS first_ordinal,
                MAX(slots.active_ordinal) AS last_ordinal, MIN(slots.expected_amount_kobo) AS first_target,
                MAX(slots.expected_amount_kobo) AS last_target, SUM(slots.expected_amount_kobo) AS slot_target,
                COALESCE(SUM(funding.funded), 0) AS funded_principal,
                SUM(CASE WHEN COALESCE(funding.funded, 0) = slots.expected_amount_kobo THEN 1 ELSE 0 END) AS fully_funded_slots,
                SUM(CASE WHEN COALESCE(funding.funded, 0) > 0 AND COALESCE(funding.funded, 0) < slots.expected_amount_kobo THEN 1 ELSE 0 END) AS partially_funded_slots,
                SUM(CASE WHEN COALESCE(funding.funded, 0) = 0 THEN 1 ELSE 0 END) AS unfunded_slots,
                COALESCE(SUM(funding.invalid_allocations), 0) AS invalid_allocations')
            ->selectRaw("SUM(CASE WHEN originating_terms.id IS NULL OR originating_terms.thrift_plan_id <> slots.thrift_plan_id
                OR slots.ordinal <> slots.active_ordinal OR slots.expected_amount_kobo <> current_slot_terms.contribution_amount_kobo
                OR slots.due_date <> {$expectedSlotDate} THEN 1 ELSE 0 END) AS invalid_slots")
            ->groupBy('slots.thrift_plan_id');
        $inactiveAllocations = DB::table('contribution_slots as inactive_slots')
            ->join('collection_allocations as inactive_allocations', 'inactive_allocations.contribution_slot_id', '=', 'inactive_slots.id')
            ->whereNotIn('inactive_allocations.id', DB::table('collection_allocation_releases')->select('collection_allocation_id'))
            ->whereIn('inactive_slots.thrift_plan_id', (clone $scopedPlans)->select('scoped_plans.id'))
            ->whereNull('inactive_slots.active_ordinal')->selectRaw('inactive_slots.thrift_plan_id, COUNT(*) AS invalid_count')
            ->groupBy('inactive_slots.thrift_plan_id');
        $query = $this->customerQuery($customers)
            ->join('thrift_plans as plans', 'plans.customer_profile_id', '=', 'customers.id')
            ->join('plan_terms_revisions as terms', function (JoinClause $join): void {
                $join->on('terms.thrift_plan_id', '=', 'plans.id')->on('terms.revision', '=', 'plans.current_terms_revision');
            })->leftJoinSub($slots, 'slot_totals', 'slot_totals.thrift_plan_id', '=', 'plans.id')
            ->leftJoinSub($inactiveAllocations, 'inactive_funding', 'inactive_funding.thrift_plan_id', '=', 'plans.id')
            ->select('customers.customer_id as customer', 'customer_users.name as name',
                'current_agents.agent_id as current_agent', 'plans.plan_id as reference', 'plans.status as state',
                'terms.contribution_days as required_slots',
                'terms.contribution_amount_kobo as _daily_target', 'terms.expected_gross_kobo as _agreed_target',
                'terms.currency as _currency', 'terms.frequency as _frequency', 'plans.version as _version')
            ->selectRaw('COALESCE(slot_totals.active_slots, 0) AS _active_slots,
                COALESCE(slot_totals.first_ordinal, 0) AS _first_ordinal,
                COALESCE(slot_totals.last_ordinal, 0) AS _last_ordinal,
                COALESCE(slot_totals.first_target, 0) AS _first_target,
                COALESCE(slot_totals.last_target, 0) AS _last_target,
                COALESCE(slot_totals.slot_target, 0) AS _slot_target,
                COALESCE(slot_totals.invalid_slots, 0) AS _invalid_slots,
                COALESCE(slot_totals.funded_principal, 0) AS funded_principal,
                COALESCE(slot_totals.fully_funded_slots, 0) AS fully_funded_slots,
                COALESCE(slot_totals.partially_funded_slots, 0) AS partially_funded_slots,
                COALESCE(slot_totals.unfunded_slots, 0) AS unfunded_slots,
                COALESCE(slot_totals.invalid_allocations, 0) AS _invalid_allocations,
                COALESCE(inactive_funding.invalid_count, 0) AS _inactive_allocations');
        if (filled($filters['plan'] ?? null)) {
            $query->where('plans.plan_id', $filters['plan']);
        }
        if (isset($filters['_funding_plan_ids'])) {
            $query->whereIn('plans.plan_id', $filters['_funding_plan_ids']);
        }
        if (filled($filters['plan_status'] ?? null)) {
            $query->where('plans.status', $filters['plan_status']);
        }

        return ['code' => 'plan_funding', 'query' => $query->where('plans.created_at', '<=', $cutoff)
            ->addSelect('plans.created_at as _date', 'plans.id as _key')
            ->orderByDesc('plans.created_at')->orderByDesc('plans.id'),
            'columns' => ['reference' => 'Plan', 'customer' => 'Customer ID', 'name' => 'Customer',
                'current_agent' => 'Current Agent', 'state' => 'Lifecycle', 'required_slots' => 'Required slots',
                'fully_funded_slots' => 'Fully funded slots', 'partially_funded_slots' => 'Partially funded slots',
                'unfunded_slots' => 'Unfunded slots', 'funded_principal' => 'Funded principal',
                'remaining_scheduled_target' => 'Remaining scheduled target'],
            'money' => ['funded_principal', 'remaining_scheduled_target'],
            'counts' => ['required_slots' => 'required_slots', 'fully_funded_slots' => 'fully_funded_slots',
                'partially_funded_slots' => 'partially_funded_slots', 'unfunded_slots' => 'unfunded_slots'],
            'link' => 'plans.show', 'reference' => 'reference'];
    }

    /** @param EloquentBuilder<CustomerProfile> $customers
     * @return array<string, mixed>
     */
    private function feeExceptionSpec(User $viewer, EloquentBuilder $customers, CarbonImmutable $cutoff): array
    {
        $scope = $this->customerQuery($customers)->select('customers.id', 'customers.customer_id as customer',
            'customer_users.name as name', 'current_agents.agent_id as current_agent');
        $entries = DB::table('fee_obligation_entries')->where('created_at', '<=', $cutoff)
            ->selectRaw("fee_obligation_id,
                COALESCE(SUM(CASE WHEN entry_type = 'assessment' THEN amount_kobo ELSE 0 END), 0) AS assessed,
                COALESCE(SUM(CASE WHEN entry_type = 'assessment_correction_increase' THEN amount_kobo ELSE 0 END), 0) AS increased,
                COALESCE(SUM(CASE WHEN entry_type = 'assessment_correction' THEN amount_kobo ELSE 0 END), 0) AS reduced,
                COALESCE(SUM(CASE WHEN entry_type = 'settlement' THEN amount_kobo ELSE 0 END), 0) AS settled,
                COALESCE(SUM(CASE WHEN entry_type = 'settlement_reversal' THEN amount_kobo ELSE 0 END), 0) AS reversed,
                COALESCE(SUM(CASE WHEN entry_type = 'waiver' THEN amount_kobo ELSE 0 END), 0) AS waived,
                SUM(CASE WHEN amount_kobo < 1 OR currency <> 'NGN'
                    OR entry_type NOT IN ('assessment', 'assessment_correction_increase', 'assessment_correction',
                        'settlement', 'settlement_reversal', 'waiver', 'savings_refund', 'external_refund_entitlement')
                    THEN 1 ELSE 0 END) AS invalid_entries")
            ->groupBy('fee_obligation_id');
        $query = DB::table('fee_obligations as obligations')
            ->join('fee_snapshots as snapshots', 'snapshots.id', '=', 'obligations.fee_snapshot_id')
            ->joinSub($scope, 'scope', 'scope.id', '=', 'obligations.customer_profile_id')
            ->leftJoinSub($entries, 'entry_totals', 'entry_totals.fee_obligation_id', '=', 'obligations.id')
            ->where('obligations.created_at', '<=', $cutoff)
            ->select('obligations.id as reference', 'obligations.id as _key', 'scope.customer', 'scope.name',
                'scope.current_agent', 'snapshots.name as fee_name', 'obligations.kind',
                'obligations.created_at as assessed_at', 'obligations.amount_kobo as _original_amount',
                'obligations.currency as _obligation_currency', 'snapshots.currency as _snapshot_currency',
                'obligations.customer_profile_id as _obligation_customer',
                'snapshots.customer_profile_id as _snapshot_customer', 'snapshots.kind as _snapshot_kind')
            ->selectRaw('COALESCE(entry_totals.assessed, 0) AS _assessed,
                COALESCE(entry_totals.increased, 0) AS _increased,
                COALESCE(entry_totals.reduced, 0) AS _reduced,
                COALESCE(entry_totals.settled, 0) AS _settled,
                COALESCE(entry_totals.reversed, 0) AS _reversed,
                COALESCE(entry_totals.waived, 0) AS _waived,
                COALESCE(entry_totals.invalid_entries, 0) AS _invalid_entries')
            ->orderBy('obligations.created_at')->orderBy('obligations.id');

        return ['code' => 'fee_exceptions', 'query' => $query,
            'columns' => ['reference' => 'Fee obligation', 'customer' => 'Customer ID', 'name' => 'Customer',
                'current_agent' => 'Current Agent', 'fee_name' => 'Fee', 'kind' => 'Fee kind',
                'assessed_at' => 'Assessed (UTC)', 'outstanding_fees' => 'Outstanding fee'],
            'money' => ['outstanding_fees'], 'counts' => ['outstanding_fee_obligations' => null],
            'link' => 'customers.show', 'reference' => 'customer', 'viewer' => $viewer];
    }

    /** @param EloquentBuilder<CustomerProfile> $customers
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>|null  $cursor
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $state
     * @return array<string, array<string, mixed>>
     */
    private function feeSections(User $viewer, EloquentBuilder $customers, array $filters, ?array $cursor, string $binding, array $manifest, array $state, CarbonImmutable $cutoff): array
    {
        $scope = $this->customerQuery($customers)->select('customers.id', 'customers.customer_id as customer',
            'customer_users.name as name', 'current_agents.agent_id as current_agent');
        $start = CarbonImmutable::parse($manifest['utc_start'])->toDateTimeString();
        $end = CarbonImmutable::parse($filters['to'], $manifest['timezone'])->addDay()->startOfDay()->utc()->toDateTimeString();
        if (! config('collections.enabled')) {
            throw new RuntimeException('The fee receipt owner is disabled.');
        }

        $missingAssessment = DB::table('fee_obligations as obligations')
            ->whereIn('obligations.customer_profile_id', (clone $customers)->select('id'))
            ->where('obligations.created_at', '<=', $cutoff)->where('obligations.amount_kobo', '>', 0)
            ->whereNotExists(function (Builder $query) use ($cutoff): void {
                $query->selectRaw('1')->from('fee_obligation_entries as entries')
                    ->whereColumn('entries.fee_obligation_id', 'obligations.id')
                    ->where('entries.entry_type', FeeObligationEntryType::Assessment->value)
                    ->where('entries.created_at', '<=', $cutoff);
            })->exists();
        if ($missingAssessment) {
            throw new RuntimeException('Fee assessment history is incomplete.');
        }

        $activity = DB::table('fee_obligation_entries as entries')
            ->join('fee_obligations as obligations', 'obligations.id', '=', 'entries.fee_obligation_id')
            ->join('fee_snapshots as snapshots', 'snapshots.id', '=', 'obligations.fee_snapshot_id')
            ->joinSub($scope, 'scope', 'scope.id', '=', 'obligations.customer_profile_id')
            ->whereIn('entries.entry_type', [
                FeeObligationEntryType::Assessment->value,
                FeeObligationEntryType::AssessmentCorrectionIncrease->value,
                FeeObligationEntryType::AssessmentCorrection->value,
                FeeObligationEntryType::Waiver->value,
            ])->where('entries.created_at', '>=', $start)->where('entries.created_at', '<', $end)
            ->where('entries.created_at', '<=', $cutoff)
            ->select('scope.customer', 'scope.name', 'scope.current_agent', 'snapshots.name as fee_name',
                'obligations.kind', 'entries.entry_type as activity', 'entries.created_at as date',
                'entries.id as _key', 'entries.amount_kobo as _amount', 'entries.currency as _entry_currency',
                'obligations.currency as _obligation_currency', 'snapshots.currency as _snapshot_currency',
                'obligations.amount_kobo as _original_amount', 'obligations.customer_profile_id as _obligation_customer',
                'snapshots.customer_profile_id as _snapshot_customer', 'snapshots.kind as _snapshot_kind')
            ->selectRaw("CASE WHEN entries.entry_type = 'assessment' THEN entries.amount_kobo ELSE 0 END AS gross_assessed")
            ->selectRaw("CASE WHEN entries.entry_type = 'assessment_correction_increase' THEN entries.amount_kobo ELSE 0 END AS assessment_increase")
            ->selectRaw("CASE WHEN entries.entry_type = 'assessment_correction' THEN entries.amount_kobo ELSE 0 END AS assessment_reduction")
            ->selectRaw("CASE WHEN entries.entry_type = 'waiver' THEN entries.amount_kobo ELSE 0 END AS waived_fees")
            ->orderByDesc('entries.created_at')->orderByDesc('entries.id');
        $activitySpec = ['code' => 'fee_obligations', 'query' => $activity,
            'columns' => ['customer' => 'Customer ID', 'name' => 'Customer', 'current_agent' => 'Current Agent',
                'fee_name' => 'Fee', 'kind' => 'Fee kind', 'activity' => 'Activity', 'date' => 'Recorded (UTC)',
                'gross_assessed' => 'Gross assessed', 'assessment_increase' => 'Assessment increase',
                'assessment_reduction' => 'Assessment reduction', 'waived_fees' => 'Waived'],
            'money' => ['gross_assessed', 'assessment_increase', 'assessment_reduction', 'waived_fees'],
            'counts' => [], 'link' => 'customers.show', 'reference' => 'customer', 'viewer' => $viewer];

        $componentTotals = DB::table('collection_fee_components')
            ->selectRaw('collection_receipt_id, SUM(amount_kobo) AS component_total')->groupBy('collection_receipt_id');
        $ledgerTotals = DB::table('ledger_entries as lines')
            ->join('ledger_accounts as accounts', 'accounts.id', '=', 'lines.ledger_account_id')
            ->selectRaw("lines.ledger_posting_group_id, COUNT(*) AS line_count,
                SUM(CASE WHEN accounts.code IN ('agent_receivable_ngn', 'business_bank_ngn', 'payment_clearing_ngn') AND lines.side = 'debit' THEN lines.amount_kobo ELSE 0 END) AS custody_debit,
                MAX(CASE WHEN lines.side = 'debit' THEN accounts.code ELSE NULL END) AS debit_code,
                MAX(CASE WHEN lines.side = 'debit' THEN lines.agent_profile_id ELSE NULL END) AS debit_agent,
                SUM(CASE WHEN accounts.code = 'fee_income_ngn' AND lines.side = 'credit' THEN lines.amount_kobo ELSE 0 END) AS fee_credit")
            ->groupBy('lines.ledger_posting_group_id');
        $feeReceiptCount = DB::table('collection_receipts as receipts')
            ->whereIn('receipts.customer_profile_id', (clone $customers)->select('id'))
            ->where('receipts.recorded_at', '<=', $cutoff)
            ->whereBetween('receipts.received_date', [$filters['from'], $filters['to']])
            ->where('receipts.fee_amount_kobo', '>', 0)->count();
        $verifiedFeeReceiptCount = $this->verifiedReceipts($state, $cutoff)
            ->whereIn('receipts.customer_profile_id', (clone $customers)->select('id'))
            ->whereBetween('receipts.received_date', [$filters['from'], $filters['to']])
            ->where('receipts.fee_amount_kobo', '>', 0)->count();
        if ($feeReceiptCount !== $verifiedFeeReceiptCount) {
            throw new RuntimeException('Fee receipt projections are incomplete.');
        }
        $unmatchedReceipt = $this->verifiedReceipts($state, $cutoff)
            ->joinSub($this->customerQuery($customers)->select('customers.id'), 'scope', 'scope.id', '=', 'receipts.customer_profile_id')
            ->leftJoinSub($componentTotals, 'components', 'components.collection_receipt_id', '=', 'receipts.id')
            ->whereBetween('receipts.received_date', [$filters['from'], $filters['to']])
            ->where('receipts.fee_amount_kobo', '>', 0)
            ->whereRaw('receipts.fee_amount_kobo <> COALESCE(components.component_total, 0)')->exists();
        if ($unmatchedReceipt) {
            throw new RuntimeException('Fee receipt components are incomplete.');
        }

        $receipts = $this->verifiedReceipts($state, $cutoff)
            ->join('collection_fee_components as components', 'components.collection_receipt_id', '=', 'receipts.id')
            ->join('fee_obligations as obligations', 'obligations.id', '=', 'components.fee_obligation_id')
            ->join('fee_snapshots as snapshots', 'snapshots.id', '=', 'obligations.fee_snapshot_id')
            ->join('ledger_posting_groups as posting', 'posting.id', '=', 'components.ledger_posting_group_id')
            ->leftJoin('fee_obligation_entries as settlement', function (JoinClause $join): void {
                $join->on('settlement.fee_obligation_id', '=', 'obligations.id')
                    ->on('settlement.ledger_posting_reference', '=', 'posting.posting_reference')
                    ->where('settlement.entry_type', FeeObligationEntryType::Settlement->value);
            })->leftJoinSub($ledgerTotals, 'ledger_totals', 'ledger_totals.ledger_posting_group_id', '=', 'posting.id')
            ->joinSub($this->customerQuery($customers)->select('customers.id', 'customers.customer_id as customer',
                'customer_users.name as name', 'current_agents.agent_id as current_agent'), 'scope', 'scope.id', '=', 'receipts.customer_profile_id')
            ->whereBetween('receipts.received_date', [$filters['from'], $filters['to']])
            ->select('scope.customer', 'scope.name', 'scope.current_agent', 'snapshots.name as fee_name',
                'obligations.kind', 'receipts.receipt_reference as reference', 'receipts.received_date as date',
                'receipts.timezone as receipt_timezone', 'components.amount_kobo as external_fees_received',
                'components.id as _key', 'components.fee_obligation_id as _obligation_id',
                'obligations.customer_profile_id as _obligation_customer', 'snapshots.customer_profile_id as _snapshot_customer',
                'obligations.currency as _obligation_currency', 'snapshots.currency as _snapshot_currency',
                'snapshots.kind as _snapshot_kind',
                'components.amount_kobo as _component_amount', 'receipts.id as _receipt_id',
                'receipts.customer_profile_id as _receipt_customer', 'receipts.fee_amount_kobo as _receipt_fees',
                'receipts.savings_amount_kobo as _receipt_savings', 'receipts.tender_amount_kobo as _receipt_tender',
                'receipts.custody_account_code as _custody_code', 'receipts.recording_agent_profile_id as _recording_agent_id',
                'projection.status as _projection_status', 'projection.gross_amount_kobo as _projection_tender',
                'projection.fee_amount_kobo as _projection_fees', 'posting.event_type as _posting_type',
                'posting.source_type as _posting_source_type', 'posting.source_id as _posting_source_id',
                'posting.customer_profile_id as _posting_customer', 'posting.currency as _posting_currency',
                'posting.id as _posting_id',
                'posting.committed_at as _posting_committed', 'settlement.amount_kobo as _settlement_amount',
                'settlement.currency as _settlement_currency', 'ledger_totals.line_count as _line_count',
                'ledger_totals.custody_debit as _custody_debit', 'ledger_totals.debit_code as _debit_code',
                'ledger_totals.debit_agent as _debit_agent', 'ledger_totals.fee_credit as _fee_credit')
            ->orderByDesc('receipts.received_date')->orderByDesc('components.id');
        $receiptSpec = ['code' => 'fee_external_receipts', 'query' => $receipts,
            'columns' => ['reference' => 'Receipt', 'customer' => 'Customer ID', 'name' => 'Customer',
                'current_agent' => 'Current Agent', 'fee_name' => 'Fee', 'kind' => 'Fee kind',
                'date' => 'Received date', 'receipt_timezone' => 'Receipt timezone',
                'external_fees_received' => 'External fee received'],
            'money' => ['external_fees_received'], 'counts' => [], 'link' => 'collections.show', 'reference' => 'reference', 'viewer' => $viewer];

        return [
            'primary' => $this->consume($activitySpec, $filters, $cursor, $binding, $manifest,
                'Recorded fee obligation activity only. Assessment and waiver are not cash or income; unsupported fee families remain unavailable.', $state, 'primary'),
            'external_receipts' => $this->consume($receiptSpec, $filters, $cursor, $binding, $manifest,
                'Verified external fee components by receipt date. Tender is counted once in the collection owner; this section excludes savings principal.', $state, 'external_receipts'),
        ];
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
                    'receipts.fee_amount_kobo as received_fees', 'receipts.tender_amount_kobo as total_received', 'receipts.method_label as method')
                ->selectRaw("CASE WHEN receipts.method = 'cash' THEN receipts.tender_amount_kobo ELSE 0 END AS cash_received");
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
            $money = ['received_savings', 'received_fees', 'cash_received', 'total_received'];
            $counts = ['posted_receipt_count' => null];
            $columns = ['reference' => 'Receipt', 'customer' => 'Customer ID', 'name' => 'Customer', 'date' => 'Received date',
                'committed_at' => 'Committed (UTC)', 'receipt_timezone' => 'Receipt timezone', 'plan' => 'Plan', 'method' => 'Method',
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
    private function consume(array $spec, array $filters, ?array $cursor, string $binding, array $manifest, string $reason, array $state, string $section = 'primary'): array
    {
        $sectionCursor = ($cursor['section'] ?? 'primary') === $section ? $cursor : null;
        $digest = hash_init('sha256');
        $tables = match ($spec['code']) {
            'customer-summary' => ['withdrawal_reservations'],
            'withdrawals' => ['withdrawal_requests', 'withdrawal_reservations'],
            'plans' => ['thrift_plans', 'plan_terms_revisions'],
            'plan_funding' => ['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'collection_allocations', 'collection_allocation_releases',
                'collection_receipts', 'ledger_transaction_references', 'ledger_transaction_projections'],
            'exceptions' => ['withdrawal_requests', 'reversal_requests'],
            'fee_exceptions' => ['fee_obligations', 'fee_obligation_entries', 'fee_snapshots'],
            'batch_reconciliation' => ['collection_batches', 'collection_receipts', 'collection_fee_components',
                'cash_remittances', 'collection_batch_reviews', 'collection_exceptions', 'ledger_posting_groups',
                'ledger_entries', 'ledger_accounts', 'ledger_transaction_references', 'ledger_transaction_projections'],
            default => [],
        };
        $ownerVersions = [];
        foreach ($tables as $table) {
            $ownerVersions[$table] = DB::table($table)->selectRaw('COUNT(*) AS rows_count, MAX(id) AS watermark, MAX(updated_at) AS updated_at')->first();
        }
        if ($spec['code'] === 'batch_reconciliation') {
            foreach (['collection_settlements', 'collection_settlement_files', 'collection_bank_reference_claims', 'collection_method_versions', 'collection_evidence_reviews', 'collection_evidence_files', 'collection_payment_evidence'] as $table) {
                $ownerVersions[$table] = DB::table($table)->selectRaw('COUNT(*) AS rows_count, MAX(id) AS watermark, MAX(created_at) AS created_at')->first();
            }
        }
        hash_update($digest, json_encode([$state, $binding, $ownerVersions], JSON_THROW_ON_ERROR));
        $totals = array_fill_keys([...$spec['money'], ...array_keys($spec['counts'])], 0);
        $groups = [];
        $rows = [];
        $total = 0;
        $offset = $sectionCursor['offset'] ?? 0;
        foreach ($spec['query']->lazy(500) as $object) {
            $row = (array) $object;
            hash_update($digest, json_encode($row, JSON_THROW_ON_ERROR));
            if (isset($row['status']) && CustomerStatus::tryFrom($row['status']) === null) {
                throw new RuntimeException('Customer status unavailable.');
            }
            if (in_array($spec['code'], ['plans', 'plan_funding'], true) && ThriftPlanStatus::tryFrom($row['state']) === null) {
                throw new RuntimeException('Plan status unavailable.');
            }
            if ($spec['code'] === 'plan_funding') {
                $required = $this->integer($row['required_slots']);
                $active = $this->integer($row['_active_slots']);
                $funded = $this->integer($row['funded_principal']);
                $target = $this->integer($row['_agreed_target']);
                $fullyFunded = $this->integer($row['fully_funded_slots']);
                $partial = $this->integer($row['partially_funded_slots']);
                $unfunded = $this->integer($row['unfunded_slots']);
                if ($required < 1 || $active !== $required || $this->integer($row['_first_ordinal']) !== 1
                    || $this->integer($row['_last_ordinal']) !== $required
                    || $this->integer($row['_first_target']) !== $this->integer($row['_daily_target'])
                    || $this->integer($row['_last_target']) !== $this->integer($row['_daily_target'])
                    || $this->integer($row['_slot_target']) !== $target || $funded > $target
                    || $this->integer($row['_invalid_slots']) !== 0
                    || $this->integer($row['_invalid_allocations']) !== 0
                    || $this->integer($row['_inactive_allocations']) !== 0
                    || $this->add($fullyFunded, $this->add($partial, $unfunded)) !== $required
                    || $row['_currency'] !== 'NGN' || $row['_frequency'] !== 'daily') {
                    throw new RuntimeException('Plan funding disagrees with its owners.');
                }
                $row['remaining_scheduled_target'] = $target - $funded;
            }
            if ($spec['code'] === 'fee_obligations') {
                $amount = $this->integer($row['_amount']);
                if ($amount === 0 || $row['_entry_currency'] !== 'NGN' || $row['_obligation_currency'] !== 'NGN'
                    || $row['_snapshot_currency'] !== 'NGN' || $row['_obligation_customer'] !== $row['_snapshot_customer']
                    || $row['kind'] !== $row['_snapshot_kind']
                    || ($row['activity'] === FeeObligationEntryType::Assessment->value
                        && $amount !== $this->integer($row['_original_amount']))) {
                    throw new RuntimeException('Fee obligation activity disagrees with its owner.');
                }
            }
            if ($spec['code'] === 'fee_exceptions') {
                $assessed = $this->integer($row['_assessed']);
                $original = $this->integer($row['_original_amount']);
                $increased = $this->integer($row['_increased']);
                $reduced = $this->integer($row['_reduced']);
                $settled = $this->integer($row['_settled']);
                $reversed = $this->integer($row['_reversed']);
                $waived = $this->integer($row['_waived']);
                $gross = $this->add($assessed, $increased);
                if ($original < 1 || $assessed !== $original || $this->integer($row['_invalid_entries']) !== 0
                    || $row['_obligation_currency'] !== 'NGN' || $row['_snapshot_currency'] !== 'NGN'
                    || $row['_obligation_customer'] !== $row['_snapshot_customer']
                    || $row['kind'] !== $row['_snapshot_kind'] || $reduced > $gross || $reversed > $settled) {
                    throw new RuntimeException('Fee obligation history disagrees with its owner.');
                }
                $netAssessed = $gross - $reduced;
                $netSettled = $settled - $reversed;
                if ($this->add($netSettled, $waived) > $netAssessed) {
                    throw new RuntimeException('Fee obligation balance disagrees with its owner.');
                }
                $row['outstanding_fees'] = $netAssessed - $netSettled - $waived;
                if ($row['outstanding_fees'] === 0) {
                    continue;
                }
            }
            if ($spec['code'] === 'fee_external_receipts') {
                $amount = $this->integer($row['_component_amount']);
                if ($amount === 0 || $row['_obligation_currency'] !== 'NGN' || $row['_snapshot_currency'] !== 'NGN'
                    || $row['_settlement_currency'] !== 'NGN' || $row['_posting_currency'] !== 'NGN'
                    || $row['_obligation_customer'] !== $row['_receipt_customer']
                    || $row['_snapshot_customer'] !== $row['_receipt_customer'] || $row['kind'] !== $row['_snapshot_kind']
                    || $row['_posting_customer'] !== $row['_receipt_customer']
                    || $row['_posting_type'] !== 'external_fee_receipt' || $row['_posting_source_type'] !== 'collection_receipt'
                    || $row['_posting_source_id'] !== $row['_receipt_id'].'-'.$row['_obligation_id']
                    || ! in_array($row['_projection_status'], ['posted', 'reversed'], true) || $this->integer($row['_projection_fees']) !== $this->integer($row['_receipt_fees'])
                    || $this->integer($row['_projection_tender']) !== $this->integer($row['_receipt_tender'])
                    || $this->add($this->integer($row['_receipt_savings']), $this->integer($row['_receipt_fees'])) !== $this->integer($row['_receipt_tender'])
                    || $this->integer($row['_settlement_amount']) !== $amount || $this->integer($row['_line_count']) !== 2
                    || $this->integer($row['_custody_debit']) !== $amount || $this->integer($row['_fee_credit']) !== $amount
                    || $row['_debit_code'] !== $row['_custody_code']
                    || ($row['_custody_code'] === 'agent_receivable_ngn'
                        ? $row['_debit_agent'] !== $row['_recording_agent_id'] : $row['_debit_agent'] !== null)
                    || $this->integer($row['_posting_id']) > $state['watermark']
                    || CarbonImmutable::parse($row['_posting_committed'], 'UTC')->greaterThan(CarbonImmutable::parse($manifest['cutoff']))) {
                    throw new RuntimeException('External fee receipt disagrees with its posting.');
                }
            }
            if (isset($row['received_savings'])) {
                $savings = $this->integer($row['received_savings']);
                $fees = $this->integer($row['received_fees']);
                if ($row['correction_state'] !== 'posted' || $this->integer($row['_projected_tender']) !== $this->integer($row['total_received'])
                    || $this->add($savings, $fees) !== $this->integer($row['total_received'])) {
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
            if ($spec['code'] === 'batch_reconciliation') {
                $tender = $this->integer($row['gross_tender']);
                $savings = $this->integer($row['savings_component']);
                $fees = $this->integer($row['external_fee_component']);
                $remitted = $this->integer($row['confirmed_remittances']);
                $row['method'] ??= 'Cash';
                $received = $remitted;
                $outstanding = $this->integer($row['unremitted']);
                if ($row['_custody'] !== 'agent_receivable_ngn') {
                    try {
                        $batch = CollectionBatch::query()->whereKey($row['reference'])->sole();
                        $position = app(CollectionBatchPosition::class)->read($batch);
                    } catch (Throwable $exception) {
                        throw new RuntimeException('Batch custody verification unavailable.', previous: $exception);
                    }
                    $received = $this->integer($row['bank_received']);
                    $outstanding = $this->integer($row['pending_settlement']);
                    if ($remitted !== 0 || $position['expected_kobo'] !== $tender
                        || $position['received_kobo'] !== $received || $position['outstanding_kobo'] !== $outstanding
                        || $this->integer($row['_invalid_settlements']) !== 0) {
                        throw new RuntimeException('Bank custody disagrees with its verified owner.');
                    }
                } else {
                    $batch = CollectionBatch::query()->whereKey($row['reference'])->sole();
                    $position = app(CollectionBatchPosition::class)->read($batch);
                    if ($this->integer($row['_settled']) !== 0 || $position['expected_kobo'] !== $tender
                        || $position['received_kobo'] !== $received || $position['outstanding_kobo'] !== $outstanding) {
                        throw new RuntimeException('Agent custody disagrees with its verified owner.');
                    }
                }
                $receiptCount = $this->integer($row['receipt_count']);
                $openExceptions = $this->integer($row['open_exceptions']);
                $revision = $this->integer($row['revision']);
                $version = $this->integer($row['_version']);
                if ($receiptCount === 0 || $revision < 1 || $this->integer($row['_invalid_receipts']) !== 0
                    || ($row['_invalid_remittances'] !== null && $this->integer($row['_invalid_remittances']) !== 0)
                    || $this->add($savings, $fees) !== $tender || $remitted > $tender
                    || $received > $tender || $tender - $received !== $outstanding
                    || ! in_array($row['state'], ['open', 'ready_for_review', 'in_review', 'exception', 'reconciled'], true)
                    || ($revision === 1 && $row['predecessor'] !== null)
                    || ($revision > 1 && ($row['predecessor'] === null
                        || $row['_predecessor_agent'] !== $row['_agent_id']
                        || $row['_predecessor_date'] !== $row['received_date']
                        || $row['_predecessor_timezone'] !== $row['_timezone']
                        || $row['_predecessor_method'] !== $row['_method_identity']
                        || $this->integer($row['_predecessor_revision']) + 1 !== $revision))) {
                    throw new RuntimeException('Batch source disagrees with its owner.');
                }
                if ($row['latest_review'] !== null) {
                    $reviewExpected = $this->integer($row['_review_expected']);
                    $reviewRemitted = $this->integer($row['_review_remitted']);
                    if ($reviewExpected !== $tender || $reviewRemitted > $received
                        || $reviewRemitted > $reviewExpected
                        || $this->integer($row['_review_outstanding']) !== $reviewExpected - $reviewRemitted
                        || $this->integer($row['_review_version']) >= $version
                        || ! in_array($row['latest_review'], ['reconciled', 'exception'], true)) {
                        throw new RuntimeException('Cash batch review disagrees with its owner.');
                    }
                } elseif ($row['_review_count'] !== null) {
                    throw new RuntimeException('Cash batch review is missing.');
                }
                if ($row['state'] === 'reconciled' && ($row['latest_review'] !== 'reconciled'
                    || $this->integer($row['_review_version']) + 1 !== $version
                    || $outstanding !== 0 || $openExceptions !== 0)) {
                    throw new RuntimeException('Reconciled cash batch is inconsistent.');
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
        if ($sectionCursor !== null && ! hash_equals($sectionCursor['source'], $fingerprint)) {
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
                'section' => $section,
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
