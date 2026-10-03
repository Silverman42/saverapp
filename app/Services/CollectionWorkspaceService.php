<?php

namespace App\Services;

use App\Enums\UserType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class CollectionWorkspaceService
{
    public function __construct(
        private ResourceScopeService $scope,
        private AgentEligibilityService $eligibility,
    ) {}

    /**
     * @return array{slots: LengthAwarePaginator<int|string, array{id: int, customer_id: string, customer_name: string, plan_id: string, ordinal: int, target_kobo: int, funded_kobo: int, advance_kobo: int, status: string}>|null, totals: array<string, int>}
     */
    public function dueWork(User $viewer, string $date, string $today, string $search, string $status): array
    {
        if ($viewer->user_type === UserType::Customer) {
            return ['slots' => null, 'totals' => []];
        }

        $funding = DB::table('collection_allocations as allocations')->whereNotIn('allocations.id', DB::table('collection_allocation_releases')->select('collection_allocation_id'))
            ->join('collection_receipts as receipts', 'receipts.id', '=', 'allocations.collection_receipt_id')
            ->selectRaw('allocations.contribution_slot_id, SUM(allocations.amount_kobo) as funded_kobo')
            ->selectRaw('SUM(CASE WHEN receipts.received_date < ? THEN allocations.amount_kobo ELSE 0 END) as advance_kobo', [$date])
            ->groupBy('allocations.contribution_slot_id');
        $actorReady = $viewer->user_type === UserType::Admin
            || $this->eligibility->canPerformAssignedCustomerWork($viewer);
        $work = DB::table('contribution_slots as slots')
            ->join('thrift_plans as plans', 'plans.id', '=', 'slots.thrift_plan_id')
            ->leftJoin('plan_terms_revisions as terms', static function (JoinClause $join): void {
                $join->on('terms.thrift_plan_id', '=', 'plans.id')->on('terms.revision', '=', 'plans.current_terms_revision');
            })
            ->join('customer_profiles as customers', 'customers.id', '=', 'plans.customer_profile_id')
            ->join('users', 'users.id', '=', 'customers.user_id')
            ->leftJoin('customer_assignments as current_assignment', static function (JoinClause $join): void {
                $join->on('current_assignment.customer_profile_id', '=', 'customers.id')->where('current_assignment.is_current', 1);
            })
            ->leftJoin('agent_profiles as assigned_agent', 'assigned_agent.id', '=', 'current_assignment.agent_profile_id')
            ->leftJoin('users as assigned_agent_user', 'assigned_agent_user.id', '=', 'assigned_agent.user_id')
            ->leftJoinSub($funding, 'funding', 'funding.contribution_slot_id', '=', 'slots.id')
            ->where('slots.due_date', $date)
            ->whereNotNull('slots.active_ordinal')
            ->whereIn('customers.id', $this->scope->forCustomers($viewer)->select('customer_profiles.id'));
        $timezoneWork = clone $work;
        if ($search !== '') {
            $work->where(static function (Builder $query) use ($search): void {
                $query->where('customers.customer_id', 'like', "%{$search}%")
                    ->orWhere('users.name', 'like', "%{$search}%");
            });
        }
        $work->select('slots.id', 'slots.active_ordinal as ordinal', 'slots.expected_amount_kobo as target_kobo',
            'slots.due_date', 'plans.plan_id', 'plans.status as plan_status', 'customers.operational_status as customer_status',
            'assigned_agent.operational_status as assigned_agent_status',
            'assigned_agent_user.account_state as assigned_agent_account_state',
            'customers.customer_id', 'users.name as customer_name')
            ->selectRaw('COALESCE(funding.funded_kobo, 0) as funded_kobo')
            ->selectRaw('COALESCE(funding.advance_kobo, 0) as advance_kobo');
        $readyAgentIds = [];
        if ($viewer->user_type === UserType::Admin) {
            $assignedUsers = User::query()->select('users.id', 'users.user_type', 'users.account_state', 'users.two_factor_secret', 'users.two_factor_confirmed_at')
                ->whereIn('users.id', (clone $work)->select('assigned_agent_user.id'))
                ->with(['roles', 'agentProfile'])->get();
            foreach ($assignedUsers as $assignedUser) {
                if ($this->eligibility->canPerformAssignedCustomerWork($assignedUser) && $assignedUser->agentProfile !== null) {
                    $readyAgentIds[] = $assignedUser->agentProfile->id;
                }
            }
        } elseif ($actorReady && $viewer->agentProfile !== null) {
            $readyAgentIds[] = $viewer->agentProfile->id;
        }
        $serviceReady = DB::table('agent_profiles as ready_agent')->selectRaw('1')
            ->whereColumn('ready_agent.id', 'assigned_agent.id')->whereIntegerInRaw('ready_agent.id', $readyAgentIds)->limit(1);
        $work->selectSub($serviceReady, 'assigned_agent_ready');
        $work = DB::query()->fromSub($this->historicalWork($work, $date, $timezoneWork), 'dated_work')
            ->select('dated_work.*')
            ->selectRaw("CASE WHEN funded_kobo >= target_kobo THEN 'paid' WHEN (historical_agent_status IS NOT NULL AND future_initial_agent_status IS NOT NULL AND historical_agent_status != future_initial_agent_status) OR (historical_agent_account_state IS NOT NULL AND future_initial_agent_account_state IS NOT NULL AND historical_agent_account_state != future_initial_agent_account_state) OR (historical_agent_status IS NOT NULL AND future_initial_agent_status IS NULL AND (dated_agent_current_status IS NULL OR historical_agent_status != dated_agent_current_status)) OR (historical_agent_account_state IS NOT NULL AND future_initial_agent_account_state IS NULL AND (dated_agent_current_account_state IS NULL OR historical_agent_account_state != dated_agent_current_account_state)) OR (historical_agent_status IS NULL AND future_initial_agent_status IS NOT NULL AND future_initial_agent_status != 'active') OR (historical_agent_account_state IS NULL AND future_initial_agent_account_state IS NOT NULL AND future_initial_agent_account_state != 'active') OR (historical_customer_status IS NULL AND future_initial_customer_status IN ('inactive', 'restricted', 'archived')) OR (historical_plan_status IS NULL AND future_initial_plan_status = 'paused') OR historical_customer_status != future_initial_customer_status OR historical_plan_status != future_initial_plan_status OR (future_initial_customer_status IS NULL AND historical_customer_status IS NOT NULL AND historical_customer_status != customer_status) OR (future_initial_plan_status IS NULL AND historical_plan_status IS NOT NULL AND historical_plan_status != plan_status) THEN 'unavailable' WHEN plan_status != 'active' OR customer_status != 'active' OR COALESCE(historical_customer_status, 'active') != 'active' OR COALESCE(historical_plan_status, 'active') = 'paused' THEN 'blocked' WHEN COALESCE(assigned_agent_ready, 0) = 0 OR COALESCE(assigned_agent_status, 'inactive') != 'active' OR COALESCE(assigned_agent_account_state, 'suspended') != 'active' OR COALESCE(historical_agent_status, 'active') != 'active' OR COALESCE(historical_agent_account_state, 'active') != 'active' THEN 'service-interrupted' WHEN ? = 0 THEN 'blocked' WHEN funded_kobo > 0 THEN 'partial' WHEN due_date < ? THEN 'missed' ELSE 'pending' END as work_status", [$actorReady ? 1 : 0, $today]);
        $rows = DB::query()->fromSub($work, 'work');
        if ($status === 'advance-covered') {
            $rows->where('advance_kobo', '>', 0);
        } elseif ($status !== 'all') {
            $rows->where('work_status', $status);
        }
        $summary = (clone $rows)->selectRaw('COUNT(*) as slot_count')
            ->selectRaw('COALESCE(SUM(target_kobo), 0) as scheduled_kobo')
            ->selectRaw('COALESCE(SUM(funded_kobo), 0) as covered_kobo')
            ->selectRaw('COALESCE(SUM(CASE WHEN work_status IN (?, ?, ?) THEN 0 ELSE target_kobo - funded_kobo END), 0) as outstanding_kobo', ['blocked', 'unavailable', 'service-interrupted'])
            ->selectRaw('COALESCE(SUM(CASE WHEN work_status = ? THEN target_kobo ELSE 0 END), 0) as blocked_target_kobo', ['blocked'])
            ->selectRaw('COALESCE(SUM(CASE WHEN work_status = ? THEN target_kobo ELSE 0 END), 0) as unavailable_target_kobo', ['unavailable'])
            ->selectRaw('COALESCE(SUM(CASE WHEN work_status = ? THEN target_kobo ELSE 0 END), 0) as service_interrupted_target_kobo', ['service-interrupted'])
            ->first();
        $slots = $rows->orderByRaw("CASE WHEN work_status IN ('pending', 'partial', 'missed') THEN 0 WHEN work_status IN ('blocked', 'unavailable', 'service-interrupted') THEN 2 ELSE 1 END")
            ->orderBy('customer_name')->orderBy('customer_id')->orderBy('id')
            ->paginate(25, ['*'], 'due_page', null, (int) $summary->slot_count)->withQueryString();
        $slots->through(static fn ($slot): array => [
            'id' => (int) $slot->id, 'customer_id' => (string) $slot->customer_id,
            'customer_name' => (string) $slot->customer_name, 'plan_id' => (string) $slot->plan_id,
            'ordinal' => (int) $slot->ordinal, 'target_kobo' => (int) $slot->target_kobo,
            'funded_kobo' => (int) $slot->funded_kobo, 'advance_kobo' => (int) $slot->advance_kobo,
            'status' => (string) $slot->work_status,
        ]);

        return ['slots' => $slots, 'totals' => [
            'slot_count' => (int) $summary->slot_count,
            'scheduled_kobo' => (int) $summary->scheduled_kobo,
            'covered_kobo' => (int) $summary->covered_kobo,
            'outstanding_kobo' => (int) $summary->outstanding_kobo,
            'blocked_target_kobo' => (int) $summary->blocked_target_kobo,
            'unavailable_target_kobo' => (int) $summary->unavailable_target_kobo,
            'service_interrupted_target_kobo' => (int) $summary->service_interrupted_target_kobo,
        ]];
    }

    private function historicalWork(Builder $work, string $date, Builder $timezoneWork): Builder
    {
        $timezones = $timezoneWork->select('terms.timezone')->distinct()->pluck('terms.timezone');
        $history = null;
        foreach ($timezones as $timezone) {
            if (! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(), true)) {
                throw new ServiceUnavailableHttpException(null, 'Schedule participation history is unavailable.');
            }
            $cutoff = CarbonImmutable::parse($date, $timezone)->startOfDay()->addDay()->utc()->format('Y-m-d H:i:s');
            $customerStatus = DB::table('customer_status_histories as participation_history')
                ->whereColumn('participation_history.customer_profile_id', 'customers.id')
                ->where('participation_history.created_at', '<', $cutoff)
                ->orderByDesc('participation_history.created_at')->orderByDesc('participation_history.id')
                ->select('participation_history.to_status')->limit(1);
            $planStatus = DB::table('plan_lifecycle_events as plan_history')
                ->whereColumn('plan_history.thrift_plan_id', 'plans.id')
                ->where('plan_history.effective_at', '<', $cutoff)
                ->orderByDesc('plan_history.effective_at')->orderByDesc('plan_history.id')
                ->select('plan_history.to_status')->limit(1);
            $futureCustomerStatus = DB::table('customer_status_histories as future_participation_history')
                ->whereColumn('future_participation_history.customer_profile_id', 'customers.id')
                ->where('future_participation_history.created_at', '>=', $cutoff)
                ->orderBy('future_participation_history.created_at')->orderBy('future_participation_history.id')
                ->select('future_participation_history.from_status')->limit(1);
            $futurePlanStatus = DB::table('plan_lifecycle_events as future_plan_history')
                ->whereColumn('future_plan_history.thrift_plan_id', 'plans.id')
                ->where('future_plan_history.effective_at', '>=', $cutoff)
                ->orderBy('future_plan_history.effective_at')->orderBy('future_plan_history.id')
                ->select('future_plan_history.from_status')->limit(1);
            $historicalAgent = DB::table('customer_assignments as dated_assignment')
                ->whereColumn('dated_assignment.customer_profile_id', 'customers.id')
                ->where('dated_assignment.effective_at', '<', $cutoff)
                ->where(static function (Builder $query) use ($cutoff): void {
                    $query->whereNull('dated_assignment.ended_at')->orWhere('dated_assignment.ended_at', '>=', $cutoff);
                })
                ->orderByDesc('dated_assignment.effective_at')->orderByDesc('dated_assignment.id')
                ->select('dated_assignment.agent_profile_id')->limit(1);
            $historicalAgentStatus = DB::table('agent_status_histories as agent_history')
                ->where('agent_history.agent_profile_id', '=', $historicalAgent)
                ->where('agent_history.created_at', '<', $cutoff)
                ->orderByDesc('agent_history.created_at')->orderByDesc('agent_history.id')
                ->select('agent_history.to_status')->limit(1);
            $historicalAgentAccount = DB::table('agent_lifecycle_histories as agent_account_history')
                ->where('agent_account_history.agent_profile_id', '=', $historicalAgent)
                ->where('agent_account_history.created_at', '<', $cutoff)
                ->orderByDesc('agent_account_history.created_at')->orderByDesc('agent_account_history.id')
                ->select('agent_account_history.to_account_state')->limit(1);
            $futureAgentStatus = DB::table('agent_status_histories as future_agent_history')
                ->where('future_agent_history.agent_profile_id', '=', $historicalAgent)
                ->where('future_agent_history.created_at', '>=', $cutoff)
                ->orderBy('future_agent_history.created_at')->orderBy('future_agent_history.id')
                ->select('future_agent_history.from_status')->limit(1);
            $futureAgentAccount = DB::table('agent_lifecycle_histories as future_agent_account_history')
                ->where('future_agent_account_history.agent_profile_id', '=', $historicalAgent)
                ->where('future_agent_account_history.created_at', '>=', $cutoff)
                ->orderBy('future_agent_account_history.created_at')->orderBy('future_agent_account_history.id')
                ->select('future_agent_account_history.from_account_state')->limit(1);
            $datedAgentCurrentStatus = DB::table('agent_profiles as dated_agent_current')
                ->where('dated_agent_current.id', '=', $historicalAgent)->select('dated_agent_current.operational_status')->limit(1);
            $datedAgentCurrentAccount = DB::table('agent_profiles as dated_agent_account')
                ->join('users as dated_agent_user', 'dated_agent_user.id', '=', 'dated_agent_account.user_id')
                ->where('dated_agent_account.id', '=', $historicalAgent)->select('dated_agent_user.account_state')->limit(1);
            $datedWork = (clone $work)->where('terms.timezone', $timezone)
                ->selectSub($customerStatus, 'historical_customer_status')->selectSub($planStatus, 'historical_plan_status')
                ->selectSub($futureCustomerStatus, 'future_initial_customer_status')->selectSub($futurePlanStatus, 'future_initial_plan_status')
                ->selectSub($historicalAgentStatus, 'historical_agent_status')
                ->selectSub($historicalAgentAccount, 'historical_agent_account_state')
                ->selectSub($futureAgentStatus, 'future_initial_agent_status')
                ->selectSub($futureAgentAccount, 'future_initial_agent_account_state')
                ->selectSub($datedAgentCurrentStatus, 'dated_agent_current_status')
                ->selectSub($datedAgentCurrentAccount, 'dated_agent_current_account_state');
            if ($history === null) {
                $history = $datedWork;
            } else {
                $history->unionAll($datedWork);
            }
        }

        return $history ?? (clone $work)->selectRaw("'active' as historical_customer_status, 'active' as historical_plan_status, NULL as future_initial_customer_status, NULL as future_initial_plan_status, 'active' as historical_agent_status, 'active' as historical_agent_account_state, NULL as future_initial_agent_status, NULL as future_initial_agent_account_state, NULL as dated_agent_current_status, NULL as dated_agent_current_account_state");
    }

    /** @return array{receipt_count: int, tender_kobo: int, cash_kobo: int, bank_kobo: int, clearing_kobo: int, other_kobo: int, savings_kobo: int, fees_kobo: int} */
    public function received(User $viewer, string $date): array
    {
        $summary = DB::table('collection_receipts')->whereNull('replacement_reversal_id')
            ->where('received_date', $date)
            ->whereIn('customer_profile_id', $this->scope->forCustomers($viewer)->select('customer_profiles.id'))
            ->selectRaw('COUNT(*) as receipt_count, COALESCE(SUM(tender_amount_kobo), 0) as tender_kobo')
            ->selectRaw('COALESCE(SUM(savings_amount_kobo), 0) as savings_kobo, COALESCE(SUM(fee_amount_kobo), 0) as fees_kobo')
            ->selectRaw("COALESCE(SUM(CASE WHEN method = 'cash' THEN tender_amount_kobo ELSE 0 END), 0) as cash_kobo,
                COALESCE(SUM(CASE WHEN custody_account_code = 'business_bank_ngn' THEN tender_amount_kobo ELSE 0 END), 0) as bank_kobo,
                COALESCE(SUM(CASE WHEN custody_account_code = 'payment_clearing_ngn' THEN tender_amount_kobo ELSE 0 END), 0) as clearing_kobo,
                COALESCE(SUM(CASE WHEN method <> 'cash' AND custody_account_code = 'agent_receivable_ngn' THEN tender_amount_kobo ELSE 0 END), 0) as other_kobo")
            ->first();

        return ['receipt_count' => (int) $summary->receipt_count,
            'tender_kobo' => (int) $summary->tender_kobo,
            'cash_kobo' => (int) $summary->cash_kobo,
            'bank_kobo' => (int) $summary->bank_kobo,
            'clearing_kobo' => (int) $summary->clearing_kobo,
            'other_kobo' => (int) $summary->other_kobo,
            'savings_kobo' => (int) $summary->savings_kobo,
            'fees_kobo' => (int) $summary->fees_kobo];
    }
}
