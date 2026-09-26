<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Enums\ThriftPlanStatus;
use App\Enums\UserType;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DashboardReadService
{
    public function __construct(
        private ResourceScopeService $scope,
        private AgentEligibilityService $eligibility,
        private AuthorizationService $authorization,
        private CollectionReadService $balances,
        private LedgerTransactionReadService $transactions,
    ) {}

    /** @return array{fingerprint: string, can_collect: bool} */
    public function scopeSummary(User $viewer): array
    {
        if ($viewer->user_type === UserType::Agent && ! $this->eligibility->canReadAssignedCustomers($viewer)) {
            abort(403);
        }
        $digest = hash_init('sha256');
        hash_update($digest, json_encode([$viewer->id, $viewer->user_type->value, $viewer->account_state->value,
            $viewer->permission_version, $viewer->agentProfile?->version, $viewer->agentProfile?->operational_status->value,
            config('collections.enabled'), BusinessProfile::current()->version], JSON_THROW_ON_ERROR));
        foreach ($this->scope->forCustomers($viewer)->orderBy('id')->select(['id', 'version'])->cursor() as $customer) {
            hash_update($digest, $customer->id.':'.$customer->version.';');
        }
        foreach ([AdminPermission::WithdrawalsReview, AdminPermission::ReversalsReview, AdminPermission::ReconciliationManage] as $permission) {
            hash_update($digest, $permission->value.':'.(int) $this->authorization->allows($viewer, $permission));
        }

        return ['fingerprint' => hash_final($digest), 'can_collect' => $viewer->user_type === UserType::Agent
            && (bool) config('collections.enabled') && $this->eligibility->canPerformAssignedCustomerWork($viewer)];
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function read(User $viewer, array $filters): array
    {
        if (DB::transactionLevel() === 0 && DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return DB::transaction(function () use ($viewer, $filters): array {
            $business = BusinessProfile::current();
            $cutoff = CarbonImmutable::now('UTC');
            $scope = $this->scope->forCustomers($viewer);
            if ($viewer->user_type === UserType::Agent && ! $this->eligibility->canReadAssignedCustomers($viewer)) {
                abort(403);
            }
            if (filled($filters['agent'] ?? null)) {
                $agentId = $this->scope->forAgents($viewer)->where('agent_id', $filters['agent'])->value('id');
                abort_if($agentId === null, 404, 'Record unavailable.');
                $filters['agent_profile_id'] = (int) $agentId;
            }
            $operational = $this->operationalScope($scope, $filters, $viewer);
            $manifest = ['cutoff' => $cutoff->toIso8601String(), 'generated_at' => $cutoff->toIso8601String(),
                'timezone' => $business->timezone, 'timezone_version' => $business->version,
                'scope_hash' => hash('sha256', json_encode([$viewer->id, $filters, $this->scopeSummary($viewer)], JSON_THROW_ON_ERROR)),
                'filters' => array_diff_key($filters, ['agent_profile_id' => true])];
            try {
                $state = $this->transactions->state();
            } catch (Throwable) {
                $state = ['status' => 'unavailable', 'watermark' => 0, 'version' => 0];
            }
            $manifest['ledger_watermark'] = $state['watermark'];
            $manifest['projection_version'] = $state['version'];
            $sections = [];
            $sections['portfolio'] = $this->section('portfolio', $manifest, function () use ($operational, $filters, $scope, $viewer): array {
                if ($viewer->user_type === UserType::Customer && ! (clone $scope)->exists()) {
                    throw new RuntimeException('Customer profile unavailable.');
                }

                return $this->portfolio($operational, $filters);
            });
            $sections['savings'] = $this->section('savings', $manifest, function () use ($viewer, $scope, $state): array {
                $this->requireLedger($state);
                if ($viewer->user_type === UserType::Customer && ! (clone $scope)->exists()) {
                    throw new RuntimeException('Customer profile unavailable.');
                }
                $liability = $this->balances->scopedLiability($scope);
                try {
                    $position = $this->balances->scopedPosition($scope);
                } catch (Throwable) {
                    return ['status' => 'Partial', 'reason' => 'Reservations could not be verified. Available savings is unavailable.', 'metrics' => [
                        $this->metric('customer_liability', 'Customer savings liability', $liability, 'NGN', 'ledger', 'at cutoff', 'Posted Customer savings liabilities, including every operational status.'),
                        $this->metric('live_payout_reservations', 'Live payout reservations', null, 'NGN', 'withdrawals', 'at cutoff', 'Reservation source unavailable or inconsistent.'),
                        $this->metric('available_savings', 'Available savings', null, 'NGN', 'ledger + withdrawals', 'at cutoff', 'Requires compatible verified liability and reservation sources.'),
                    ]];
                }

                return ['note' => 'Full permitted savings scope. Activity, operational status, plan and Agent filters do not change these headline positions.', 'metrics' => [
                    $this->metric('customer_liability', 'Customer savings liability', $position['liability_kobo'], 'NGN', 'ledger', 'at cutoff', 'Posted Customer savings liabilities, including every operational status; operational filters do not change this total.'),
                    $this->metric('live_payout_reservations', 'Live payout reservations', $position['reservations_kobo'], 'NGN', 'withdrawals', 'at cutoff', 'Live gross payout reservations; not posted savings debits.'),
                    $this->metric('available_savings', 'Available savings', $position['available_kobo'], 'NGN', 'ledger + withdrawals', 'at cutoff', 'Authoritative liability less live gross reservations, subtracted once.'),
                ]];
            });
            $sections['collections'] = $this->section('collections', $manifest, function () use ($viewer, $operational, $filters, $state, $business): array {
                $this->requireLedger($state);

                return $this->receipts($viewer, $operational, $filters, $business->timezone);
            });
            $sections['schedule'] = $this->section('schedule', $manifest, function () use ($viewer, $operational, $filters, $state, $business): array {
                $this->requireLedger($state);

                return $this->schedule($viewer, $operational, $filters, $business->timezone);
            });
            $sections['requests'] = $this->section('requests', $manifest, fn (): array => $this->requests($viewer, $operational));
            $sections['activity'] = $this->section('activity', $manifest, function () use ($viewer, $filters, $state): array {
                $this->requireLedger($state);
                if (filled($filters['customer_status'] ?? null) || filled($filters['plan_status'] ?? null) || filled($filters['agent'] ?? null)) {
                    throw new RuntimeException('Filtered transaction owner contract unavailable.');
                }
                $result = $this->transactions->search($viewer, ['from' => $filters['from'], 'to' => $filters['to'], 'page_size' => $filters['page_size']]);

                return ['rows' => array_map(fn (array $row): array => [
                    'reference' => $row['reference'], 'type' => $row['type'], 'occurred_on' => $row['occurred_on'],
                    'committed_at' => $row['committed_at'], 'amount' => MoneyFormatter::formatNaira($row['gross_amount_kobo']),
                    'href' => route('transactions.show', $row['reference']),
                ], $result['data']), 'total' => $result['total'], 'href' => route('transactions.index', [
                    'from' => $filters['from'], 'to' => $filters['to'], 'page_size' => $filters['page_size'],
                ]), 'link_note' => 'Transaction history reauthorizes at its current verified watermark.'];
            });
            if ($viewer->user_type !== UserType::Customer) {
                $sections['custody'] = $this->section('custody', $manifest, function () use ($viewer, $filters, $state): array {
                    $this->requireLedger($state);

                    return $this->custody($viewer, $filters);
                });
            }
            $sections['gated'] = [...$manifest, 'status' => 'Unavailable', 'metrics' => [], 'reason' => 'Successful payouts, gross withdrawal debits, compensation totals, fee obligation/recognition/drawable earnings, historical eligibility and governed exports await complete owner contracts.'];

            return ['role' => $viewer->user_type->value, 'manifest' => $manifest, 'sections' => $sections];
        });
    }

    /** @param Builder<CustomerProfile> $scope
     * @param  array<string, mixed>  $filters
     * @return Builder<CustomerProfile>
     */
    private function operationalScope(Builder $scope, array $filters, User $viewer): Builder
    {
        $query = clone $scope;
        if (filled($filters['customer_status'] ?? null)) {
            $query->where('operational_status', $filters['customer_status']);
        } elseif ($viewer->user_type !== UserType::Customer) {
            $query->where('operational_status', '!=', 'archived');
        }
        if (($filters['agent_basis'] ?? null) === 'current') {
            $query->whereHas('currentAssignment', fn (Builder $assignment) => $assignment->where('agent_profile_id', $filters['agent_profile_id']));
        }

        return $query;
    }

    /** @param Builder<CustomerProfile> $customers
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function portfolio(Builder $customers, array $filters): array
    {
        $counts = (clone $customers)->selectRaw('operational_status, COUNT(*) AS total')->groupBy('operational_status')->toBase()->get();
        $metrics = [$this->metric('customers_total', 'Customers in operational scope', (clone $customers)->count(), 'count', 'customer management', 'at cutoff', 'Distinct Customers in the selected operational status scope. Recording-Agent filters apply to receipt activity, not this portfolio.')];
        foreach ($counts as $count) {
            if (CustomerStatus::tryFrom($count->operational_status) === null) {
                throw new RuntimeException('Customer status integrity unavailable.');
            }
        }
        $customerCounts = $counts->keyBy('operational_status');
        foreach (CustomerStatus::cases() as $status) {
            $metrics[] = $this->metric('customers_'.$status->value, $status->displayName().' customers', (int) ($customerCounts->get($status->value)->total ?? 0), 'count', 'customer management', 'at cutoff', 'Current operational status; separate from account state.');
        }
        $plans = DB::table('thrift_plans')->whereIn('customer_profile_id', (clone $customers)->select('id'));
        if (filled($filters['plan_status'] ?? null)) {
            $plans->where('status', $filters['plan_status']);
        }
        $counts = (clone $plans)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->get();
        $open = 0;
        foreach ($counts as $count) {
            if (ThriftPlanStatus::tryFrom($count->status) === null) {
                throw new RuntimeException('Plan lifecycle integrity unavailable.');
            }
        }
        $planCounts = $counts->keyBy('status');
        foreach (ThriftPlanStatus::cases() as $status) {
            $total = (int) ($planCounts->get($status->value)->total ?? 0);
            $metrics[] = $this->metric('plans_'.$status->value, $status->displayName().' plans', $total, 'count', 'thrift plans', 'at cutoff', 'Explicit lifecycle state; never inferred from dates or balance.');
            if ($status->isOpen()) {
                $open += $total;
            }
        }
        $metrics[] = $this->metric('open_plans', 'Open plans', $open, 'count', 'thrift plans', 'at cutoff', 'Active, Paused and Completed plans; Completed remains open until Closed.');

        return ['metrics' => $metrics, 'note' => 'Counts describe current state, irrespective of the selected activity period.'];
    }

    /** @param Builder<CustomerProfile> $customers
     * @param  array<string, mixed>  $filters
     */
    private function receiptQuery(User $viewer, Builder $customers, array $filters): QueryBuilder
    {
        $query = DB::table('collection_receipts')->where('recorded_at', '<=', CarbonImmutable::now('UTC'))
            ->whereExists(function (QueryBuilder $posted): void {
                $posted->selectRaw('1')->from('ledger_transaction_references as receipt_refs')
                    ->join('ledger_transaction_projections as receipt_projection', 'receipt_projection.ledger_transaction_reference_id', '=', 'receipt_refs.id')
                    ->where('receipt_refs.root_type', 'collection_receipt')
                    ->whereColumn('receipt_refs.root_id', 'collection_receipts.id')
                    ->where('receipt_projection.projection_version', DB::table('ledger_projection_state')->where('id', 1)->value('active_version'));
            });
        if ($viewer->user_type === UserType::Agent) {
            $query->where('recording_agent_profile_id', $viewer->agentProfile?->id);
            if (filled($filters['customer_status'] ?? null)) {
                throw new RuntimeException('Historical actor/status contract unavailable.');
            }
        } else {
            $query->whereIn('customer_profile_id', (clone $customers)->select('id'));
        }
        if (($filters['agent_basis'] ?? null) === 'recording') {
            $query->where('recording_agent_profile_id', $filters['agent_profile_id']);
        }
        if (filled($filters['plan_status'] ?? null)) {
            $query->whereIn('thrift_plan_id', DB::table('thrift_plans')->where('status', $filters['plan_status'])->select('id'));
        }

        return $query;
    }

    /** @param Builder<CustomerProfile> $customers
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function receipts(User $viewer, Builder $customers, array $filters, string $timezone): array
    {
        $query = $this->receiptQuery($viewer, $customers, $filters);
        $period = (clone $query)->whereBetween('received_date', [$filters['from'], $filters['to']]);
        $totals = (clone $period)->selectRaw('COUNT(*) AS receipt_count, COALESCE(SUM(savings_amount_kobo), 0) AS savings, COALESCE(SUM(fee_amount_kobo), 0) AS fees, COALESCE(SUM(tender_amount_kobo), 0) AS tender')->first();
        $savings = $this->integer($totals->savings);
        $fees = $this->integer($totals->fees);
        $tender = $this->integer($totals->tender);
        if ($savings > PHP_INT_MAX - $fees || $savings + $fees !== $tender) {
            throw new RuntimeException('Receipt components do not reconcile.');
        }
        $scopeLabel = $viewer->user_type === UserType::Agent ? 'Recorded by you, including former assignments; no former-Customer detail is returned.' : 'Selected current Customer scope and explicit Agent attribution.';
        $start = CarbonImmutable::now($timezone)->subDays(29)->toDateString();
        $end = CarbonImmutable::now($timezone)->toDateString();
        $trend = (clone $query)->whereBetween('received_date', [$start, $end])
            ->selectRaw('received_date, SUM(savings_amount_kobo) AS savings')->groupBy('received_date')->orderBy('received_date')->get();

        return ['metrics' => [
            $this->metric('received_savings', 'Savings received', $savings, 'NGN', 'verified collection receipts', 'received date', $scopeLabel.' Savings component only; excludes fees and remittances.'),
            $this->metric('received_fees', 'External fees received', $fees, 'NGN', 'verified collection receipts', 'received date', 'External fee tender; separate from savings and fee earnings.'),
            $this->metric('cash_received', 'Cash tender received', $tender, 'NGN', 'verified collection receipts', 'received date', 'Savings plus external fee tender; cash is the only enabled receipt method.'),
            $this->metric('posted_receipt_count', 'Posted receipts', (int) $totals->receipt_count, 'count', 'verified collection receipts', 'received date', 'Distinct verified receipts, not funded slots.'),
        ], 'trend' => $trend->map(fn (object $row): array => ['date' => $row->received_date, 'display' => MoneyFormatter::formatNaira($this->integer($row->savings))])->all(),
            'trend_from' => $start, 'trend_to' => $end, 'note' => $scopeLabel];
    }

    /** @param Builder<CustomerProfile> $customers
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function schedule(User $viewer, Builder $customers, array $filters, string $timezone): array
    {
        $query = DB::table('contribution_slots as slots')->join('thrift_plans as plans', 'plans.id', '=', 'slots.thrift_plan_id')
            ->join('plan_terms_revisions as terms', 'terms.id', '=', 'slots.plan_terms_revision_id')
            ->whereIn('plans.customer_profile_id', (clone $customers)->select('id'))->whereNotNull('slots.active_ordinal')
            ->whereNotIn('plans.status', ['cancelled', 'closed']);
        if (filled($filters['plan_status'] ?? null)) {
            $query->where('plans.status', $filters['plan_status']);
        }
        $allocations = DB::table('collection_allocations')->selectRaw('contribution_slot_id, SUM(amount_kobo) AS funded')->groupBy('contribution_slot_id');
        $query->leftJoinSub($allocations, 'funding', 'funding.contribution_slot_id', '=', 'slots.id');
        if ((clone $query)->whereRaw('COALESCE(funding.funded, 0) > slots.expected_amount_kobo')->exists()) {
            throw new RuntimeException('Slot funding integrity unavailable.');
        }
        $totalSlots = (clone $query)->count();
        $fundedSlots = (clone $query)->whereRaw('COALESCE(funding.funded, 0) = slots.expected_amount_kobo')->count();
        $todayQuery = (clone $query)->where(function (QueryBuilder $dates) use ($query): void {
            foreach ((clone $query)->distinct()->pluck('terms.timezone') as $planTimezone) {
                $dates->orWhere(fn (QueryBuilder $date) => $date->where('terms.timezone', $planTimezone)
                    ->where('slots.due_date', CarbonImmutable::now($planTimezone)->toDateString()));
            }
        });
        $target = $this->integer((clone $todayQuery)->sum('slots.expected_amount_kobo'));
        $covered = $this->integer((clone $todayQuery)->sum(DB::raw('COALESCE(funding.funded, 0)')));
        $work = [];
        if ($viewer->user_type === UserType::Agent) {
            $work = (clone $todayQuery)->join('customer_profiles as customers', 'customers.id', '=', 'plans.customer_profile_id')
                ->join('users as customer_users', 'customer_users.id', '=', 'customers.user_id')
                ->whereRaw('COALESCE(funding.funded, 0) < slots.expected_amount_kobo')
                ->orderByDesc(DB::raw('slots.expected_amount_kobo - COALESCE(funding.funded, 0)'))
                ->orderBy('customer_users.name')->orderBy('slots.id')->limit($filters['page_size'])
                ->get(['plans.plan_id', 'plans.status', 'customers.customer_id', 'customer_users.name', 'slots.expected_amount_kobo', DB::raw('COALESCE(funding.funded, 0) AS funded')])
                ->map(fn (object $row): array => ['customer_id' => $row->customer_id, 'name' => $row->name,
                    'plan_id' => $row->plan_id, 'status' => $row->status,
                    'remaining' => MoneyFormatter::formatNaira($this->integer($row->expected_amount_kobo) - $this->integer($row->funded)),
                    'href' => route('plans.show', $row->plan_id)])->all();
        }

        return ['status' => 'Partial', 'metrics' => [
            $this->metric('scheduled_target', 'Scheduled today', $target, 'NGN', 'plan slots', 'slot local due date', 'Original targets due today in each plan’s captured timezone, including paused or blocked plans.'),
            $this->metric('covered_scheduled_slots', 'Funded toward today’s schedule', $covered, 'NGN', 'collection allocations', 'slot local due date', 'Funding of scheduled slots from any receipt date; this is not money received today or eligible-target coverage.'),
            $this->metric('funded_slots', 'Fully funded slots', $fundedSlots, 'count', 'collection allocations', 'at cutoff', 'Fully funded required slots across open scoped plans.'),
            $this->metric('remaining_slots', 'Remaining slots', $totalSlots - $fundedSlots, 'count', 'plan slots + allocations', 'at cutoff', 'Required slots not fully funded; not days until plan end.'),
        ], 'rows' => $work, 'reason' => 'Collectible/eligible targets, eligible coverage, outstanding due and missed/blocked breakdown await verified Customer/Agent eligibility intervals. Listed scheduled shortfalls are read-only; plan pages recheck action eligibility.',
            'note' => 'Today uses each plan’s timezone. Activity period and Recording-Agent filters do not change the current schedule.'];
    }

    /** @param Builder<CustomerProfile> $customers
     * @return array<string, mixed>
     */
    private function requests(User $viewer, Builder $customers): array
    {
        $withdrawals = DB::table('withdrawal_requests')->whereIn('customer_profile_id', (clone $customers)->select('id'))
            ->whereNull('terminal_at')->where('state', 'pending_review');
        $reversals = DB::table('reversal_requests')->whereIn('customer_profile_id', (clone $customers)->select('id'))->where('state', 'pending_review');
        $tasks = [];
        foreach ([['withdrawal', $withdrawals, AdminPermission::WithdrawalsReview], ['reversal', $reversals, AdminPermission::ReversalsReview]] as [$type, $query, $permission]) {
            if ($viewer->user_type === UserType::Admin && ! $this->authorization->allows($viewer, $permission)) {
                continue;
            }
            foreach ((clone $query)->orderBy('created_at')->orderBy('id')->limit(5)->get() as $row) {
                $publicId = $type === 'withdrawal' ? $row->withdrawal_id : $row->reversal_id;
                $tasks[] = ['type' => $type, 'reference' => $publicId, 'state' => $row->state,
                    'version' => (int) $row->version, 'created_at' => $row->created_at,
                    'href' => route($type === 'withdrawal' ? 'withdrawals.show' : 'reversals.show', $publicId)];
            }
        }
        usort($tasks, fn (array $left, array $right): int => [$left['created_at'], $left['reference']] <=> [$right['created_at'], $right['reference']]);

        return ['metrics' => [
            $this->metric('pending_withdrawal_reviews', 'Pending withdrawal reviews', (clone $withdrawals)->count(), 'count', 'withdrawals', 'current state', 'Distinct pending-review requests; not posted payouts.'),
            $this->metric('pending_reversal_reviews', 'Pending reversal reviews', (clone $reversals)->count(), 'count', 'reversals', 'current state', 'Distinct pending-review requests; no financial effect until the owning workflow posts.'),
        ], 'rows' => $tasks, 'note' => 'Current owner states. Admin detail links require the relevant review permission. Other operational queues remain dependency-gated.'];
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function custody(User $viewer, array $filters): array
    {
        foreach (['agent_receivable_ngn', 'business_cash_ngn'] as $code) {
            if (! DB::table('ledger_accounts')->where('code', $code)->where('mapping_status', 'mapped')->where('currency', 'NGN')->exists()) {
                throw new RuntimeException('Custody mapping unavailable.');
            }
        }
        $query = DB::table('ledger_entries')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id');
        $agentId = $viewer->user_type === UserType::Agent ? $viewer->agentProfile?->id : ($filters['agent_profile_id'] ?? null);
        if ($agentId !== null) {
            $query->where('agent_profile_id', $agentId);
        }
        $receivable = $this->integer((clone $query)->where('code', 'agent_receivable_ngn')
            ->sum(DB::raw("CASE WHEN side = 'debit' THEN amount_kobo ELSE -amount_kobo END")));
        $metrics = [$this->metric('agent_receivable', 'Agent cash responsibility', $receivable, 'NGN', 'ledger', 'at cutoff', 'Original recording-Agent custody responsibility after posted remittances; reassignment does not transfer it. Customer and plan filters do not change custody.')];
        if ($viewer->user_type === UserType::Admin && $agentId === null) {
            $cash = $this->integer((clone $query)->where('code', 'business_cash_ngn')
                ->sum(DB::raw("CASE WHEN side = 'debit' THEN amount_kobo ELSE -amount_kobo END")));
            $metrics[] = $this->metric('business_cash', 'Business cash custody', $cash, 'NGN', 'ledger', 'at cutoff', 'Posted business cash asset; not available profit or Customer savings.');
        }
        $exceptions = DB::table('collection_exceptions')->join('collection_batches', 'collection_batches.id', '=', 'collection_exceptions.collection_batch_id')
            ->where('collection_exceptions.status', 'open');
        if ($agentId !== null) {
            $exceptions->where('collection_batches.agent_profile_id', $agentId);
        }
        $metrics[] = $this->metric('reconciliation_exceptions', 'Open reconciliation exceptions', $exceptions->count(), 'count', 'reconciliation', 'current state', 'Distinct open owner exceptions; amounts do not change Customer credit or forgive Agent responsibility.');

        return ['metrics' => $metrics, 'note' => 'Custody follows the original Agent. Reconciliation evidence and decisions remain in the owning workflow.'];
    }

    /** @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function section(string $code, array $manifest, Closure $read): array
    {
        $started = hrtime(true);
        try {
            $data = ['status' => 'Current', ...$read()];
        } catch (Throwable) {
            $data = ['status' => 'Unavailable', 'metrics' => [], 'rows' => [], 'reason' => 'Authoritative '.$code.' data is unavailable. Retry after its source is verified.'];
        }
        Log::debug('dashboard.section', ['section' => $code, 'status' => $data['status'],
            'latency_ms' => (hrtime(true) - $started) / 1_000_000]);

        return [...$manifest, ...$data];
    }

    /** @param array<string, mixed> $state */
    private function requireLedger(array $state): void
    {
        if ($state['status'] !== 'ready' || DB::table('ledger_integrity_incidents')->where('status', 'open')->exists()) {
            throw new RuntimeException('Ledger verification unavailable.');
        }
    }

    private function integer(mixed $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 0) {
            throw new RuntimeException('Financial aggregate integrity unavailable.');
        }

        return $integer;
    }

    /** @return array<string, mixed> */
    private function metric(string $code, string $title, ?int $value, string $unit, string $source, string $dateBasis, string $definition): array
    {
        return ['code' => $code, 'title' => $title, 'value' => $value, 'unit' => $unit,
            'display' => $value === null ? 'Not available' : ($unit === 'NGN' ? MoneyFormatter::formatNaira($value) : number_format($value)),
            'source' => $source, 'date_basis' => $dateBasis, 'definition' => $definition,
            'drill_down' => null, 'drill_down_reason' => 'Matching owner aggregate drill-down is not yet available.'];
    }
}
