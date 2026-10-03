<?php

namespace App\Data;

use App\Models\CashExecution;
use App\Models\ChargeCategoryVersion;
use App\Models\FeeObligation;
use App\Models\FeeRefund;
use App\Models\LedgerPostingGroup;
use App\Models\ManualCharge;
use App\Models\ReversalRequest;
use App\Models\ThriftPlan;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Collection;

final readonly class PlanFeeReadSnapshot
{
    /**
     * @param  Collection<int, ThriftPlan>  $plans
     * @param  Collection<int, WithdrawalRequest>  $withdrawals
     * @param  Collection<int, FeeObligation>  $obligations
     * @param  Collection<int, ManualCharge>  $charges
     * @param  Collection<int, ChargeCategoryVersion>  $categories
     * @param  Collection<int, LedgerPostingGroup>  $groups
     * @param  Collection<int, CashExecution>  $executions
     * @param  Collection<int, ReversalRequest>  $reversals
     * @param  Collection<int, FeeRefund>  $refunds
     * @param  Collection<int, mixed>  $receiptPlanIds
     * @param  Collection<int, mixed>  $completedPlanIds
     * @param  Collection<int, LedgerPostingGroup>  $feeCompensations
     * @param  Collection<string, LedgerPostingGroup>  $savingsApplications
     */
    public function __construct(
        public Collection $plans,
        public Collection $withdrawals,
        public Collection $obligations,
        public Collection $charges,
        public Collection $categories,
        public Collection $groups,
        public Collection $executions,
        public Collection $reversals,
        public Collection $refunds,
        public Collection $receiptPlanIds,
        public Collection $completedPlanIds,
        public Collection $savingsApplications = new Collection,
        public Collection $feeCompensations = new Collection,
    ) {}
}
