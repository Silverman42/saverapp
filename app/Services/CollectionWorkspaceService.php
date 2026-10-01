<?php

namespace App\Services;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

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
            ->join('customer_profiles as customers', 'customers.id', '=', 'plans.customer_profile_id')
            ->join('users', 'users.id', '=', 'customers.user_id')
            ->leftJoinSub($funding, 'funding', 'funding.contribution_slot_id', '=', 'slots.id')
            ->where('slots.due_date', $date)
            ->whereNotNull('slots.active_ordinal')
            ->whereIn('customers.id', $this->scope->forCustomers($viewer)->select('customer_profiles.id'))
            ->select('slots.id', 'slots.active_ordinal as ordinal', 'slots.expected_amount_kobo as target_kobo',
                'plans.plan_id', 'customers.customer_id', 'users.name as customer_name')
            ->selectRaw('COALESCE(funding.funded_kobo, 0) as funded_kobo')
            ->selectRaw('COALESCE(funding.advance_kobo, 0) as advance_kobo')
            ->selectRaw("CASE WHEN COALESCE(funding.funded_kobo, 0) >= slots.expected_amount_kobo THEN 'paid' WHEN ? = 0 OR plans.status != 'active' OR customers.operational_status != 'active' THEN 'blocked' WHEN COALESCE(funding.funded_kobo, 0) > 0 THEN 'partial' WHEN slots.due_date < ? THEN 'missed' ELSE 'pending' END as work_status", [$actorReady ? 1 : 0, $today]);
        if ($search !== '') {
            $work->where(static function ($query) use ($search): void {
                $query->where('customers.customer_id', 'like', "%{$search}%")
                    ->orWhere('users.name', 'like', "%{$search}%");
            });
        }

        $rows = DB::query()->fromSub($work, 'work');
        if ($status === 'advance-covered') {
            $rows->where('advance_kobo', '>', 0);
        } elseif ($status !== 'all') {
            $rows->where('work_status', $status);
        }
        $summary = (clone $rows)->selectRaw('COUNT(*) as slot_count')
            ->selectRaw('COALESCE(SUM(target_kobo), 0) as scheduled_kobo')
            ->selectRaw('COALESCE(SUM(funded_kobo), 0) as covered_kobo')
            ->selectRaw('COALESCE(SUM(CASE WHEN work_status = ? THEN 0 ELSE target_kobo - funded_kobo END), 0) as outstanding_kobo', ['blocked'])
            ->selectRaw('COALESCE(SUM(CASE WHEN work_status = ? THEN target_kobo ELSE 0 END), 0) as blocked_target_kobo', ['blocked'])
            ->first();
        $slots = $rows->orderByRaw("CASE WHEN work_status IN ('pending', 'partial', 'missed') THEN 0 WHEN work_status = 'blocked' THEN 2 ELSE 1 END")
            ->orderBy('customer_name')->orderBy('customer_id')->orderBy('id')
            ->paginate(25, ['*'], 'due_page')->withQueryString();
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
        ]];
    }

    /** @return array{receipt_count: int, tender_kobo: int, savings_kobo: int, fees_kobo: int} */
    public function received(User $viewer, string $date): array
    {
        $summary = DB::table('collection_receipts')->whereNull('replacement_reversal_id')
            ->where('received_date', $date)
            ->whereIn('customer_profile_id', $this->scope->forCustomers($viewer)->select('customer_profiles.id'))
            ->selectRaw('COUNT(*) as receipt_count, COALESCE(SUM(tender_amount_kobo), 0) as tender_kobo')
            ->selectRaw('COALESCE(SUM(savings_amount_kobo), 0) as savings_kobo, COALESCE(SUM(fee_amount_kobo), 0) as fees_kobo')
            ->first();

        return ['receipt_count' => (int) $summary->receipt_count,
            'tender_kobo' => (int) $summary->tender_kobo,
            'savings_kobo' => (int) $summary->savings_kobo,
            'fees_kobo' => (int) $summary->fees_kobo];
    }
}
