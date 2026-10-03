<?php

namespace App\Services;

use App\Data\SavingsAttributionSources;
use App\Enums\LedgerAccountCode;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\PlanTermsRevision;
use App\Models\ThriftPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use stdClass;
use ValueError;

class WithdrawalBalanceService
{
    public function __construct(private CollectionReadService $customerBalances, private PlanFeeSnapshotBinding $snapshotBinding) {}

    /** @return array{liability_kobo: int, reservations_kobo: int, available_kobo: int, cycle_liability_kobo: int, cycle_reservations_kobo: int, cycle_available_kobo: int} */
    public function position(CustomerProfile $customer, ThriftPlan $plan, bool $forUpdate = false): array
    {
        if ($plan->customer_profile_id !== $customer->id) {
            throw new RuntimeException('The selected cycle does not belong to this Customer.');
        }
        if ($forUpdate) {
            if (DB::transactionLevel() === 0) {
                throw new RuntimeException('A transaction is required for an authoritative withdrawal balance.');
            }
            CustomerProfile::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
        }

        $total = $this->customerBalances->position($customer, $forUpdate);
        $entries = DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->join('ledger_posting_groups', 'ledger_posting_groups.id', '=', 'ledger_entries.ledger_posting_group_id')
            ->where('ledger_entries.customer_profile_id', $customer->id)
            ->where('ledger_accounts.code', LedgerAccountCode::CustomerSavingsLiability->value)
            ->select('ledger_entries.side', 'ledger_entries.amount_kobo', 'ledger_entries.fee_obligation_id', 'ledger_entries.thrift_plan_id', 'ledger_entries.ledger_posting_group_id', 'ledger_posting_groups.source_type', 'ledger_posting_groups.source_id');
        if ($forUpdate) {
            $entries->lockForUpdate();
        }

        $rows = $entries->get();
        $sources = $this->sources($rows, current: $forUpdate);
        $reservationQuery = DB::table('withdrawal_reservations')
            ->where('customer_profile_id', $customer->id)->where('status', 'live')
            ->select('thrift_plan_id', 'gross_amount_kobo');
        if ($forUpdate) {
            $reservationQuery->lockForUpdate();
        }

        return $this->positionFromRows($customer->id, $plan->id, $total, $rows, $reservationQuery->get(), $sources);
    }

    /**
     * @param  list<ThriftPlan>  $plans
     * @return array<int, array{liability_kobo: int, reservations_kobo: int, available_kobo: int, cycle_liability_kobo: int, cycle_reservations_kobo: int, cycle_available_kobo: int}|null>
     */
    public function positions(array $plans): array
    {
        $ids = array_map(fn (ThriftPlan $plan): int => $plan->id, $plans);
        if (count($plans) > 100 || count(array_unique($ids)) !== count($ids)) {
            throw new \InvalidArgumentException('Read at most 100 distinct cycle savings positions.');
        }
        if ($plans === []) {
            return [];
        }
        $customerIds = array_values(array_unique(array_map(fn (ThriftPlan $plan): int => $plan->customer_profile_id, $plans)));
        $totals = $this->customerBalances->positions($customerIds);
        $rows = DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->join('ledger_posting_groups', 'ledger_posting_groups.id', '=', 'ledger_entries.ledger_posting_group_id')
            ->whereIn('ledger_entries.customer_profile_id', $customerIds)
            ->where('ledger_accounts.code', LedgerAccountCode::CustomerSavingsLiability->value)
            ->select('ledger_entries.customer_profile_id', 'ledger_entries.side', 'ledger_entries.amount_kobo',
                'ledger_entries.fee_obligation_id', 'ledger_entries.thrift_plan_id', 'ledger_entries.ledger_posting_group_id', 'ledger_posting_groups.source_type', 'ledger_posting_groups.source_id')
            ->orderBy('ledger_entries.id')->get();
        $sources = $this->sources($rows, true);
        $entries = $rows->groupBy('customer_profile_id');
        $reservations = DB::table('withdrawal_reservations')->whereIn('customer_profile_id', $customerIds)->where('status', 'live')
            ->select('customer_profile_id', 'thrift_plan_id', 'gross_amount_kobo')->get()->groupBy('customer_profile_id');
        $result = array_fill_keys($ids, null);
        foreach ($plans as $plan) {
            $total = $totals[$plan->customer_profile_id] ?? null;
            if ($total === null) {
                continue;
            }
            try {
                $result[$plan->id] = $this->positionFromRows($plan->customer_profile_id, $plan->id, $total,
                    $entries->get($plan->customer_profile_id, collect()), $reservations->get($plan->customer_profile_id, collect()), $sources);
            } catch (RuntimeException) {
                $result[$plan->id] = null;
            }
        }

        return $result;
    }

    /** @param Collection<int, stdClass> $rows */
    private function sources(Collection $rows, bool $prepared = false, bool $current = false): SavingsAttributionSources
    {
        $receiptIds = $rows->filter(fn (stdClass $entry): bool => in_array($entry->source_type, ['collection_receipt', 'fee_application'], true)
            && ctype_digit((string) $entry->source_id))->pluck('source_id')->unique()->all();
        $receipts = DB::table('collection_receipts')->whereIn('id', $receiptIds)
            ->select('id', 'customer_profile_id', 'thrift_plan_id')->get()->keyBy('id');
        $obligationIds = $rows->pluck('fee_obligation_id')->filter()->unique()->all();
        $obligations = DB::table('fee_obligations')
            ->join('fee_snapshots', 'fee_snapshots.id', '=', 'fee_obligations.fee_snapshot_id')
            ->leftJoin('thrift_plans', function ($join): void {
                $join->on('thrift_plans.plan_id', '=', 'fee_snapshots.source_id')
                    ->where('fee_snapshots.source_type', 'plan');
            })
            ->leftJoin('withdrawal_requests as fee_withdrawals', fn ($join) => $join->on('fee_withdrawals.id', '=', 'fee_snapshots.source_id')->where('fee_snapshots.source_type', 'withdrawal'))
            ->leftJoin('manual_charges as fee_charges', fn ($join) => $join->on('fee_charges.operation_reference', '=', 'fee_snapshots.source_id')->where('fee_snapshots.source_type', 'manual_charge'))
            ->whereIn('fee_obligations.id', $obligationIds)
            ->select('fee_obligations.id', 'fee_obligations.customer_profile_id')->selectRaw('COALESCE(thrift_plans.id, fee_withdrawals.thrift_plan_id, fee_charges.thrift_plan_id) AS thrift_plan_id')
            ->get()->keyBy('id');

        $revisionObligations = FeeObligation::query()->whereIn('id', $obligationIds)
            ->whereHas('feeSnapshot', fn ($query) => $query->where('source_type', 'plan_terms_revision'))
            ->with('feeSnapshot')->get();
        $origins = PlanTermsRevision::query()->whereIn('fee_snapshot_id', $revisionObligations->pluck('fee_snapshot_id'))
            ->with($prepared ? 'plan.termsRevisions' : 'plan')->orderBy('revision')->get()->unique('fee_snapshot_id')->keyBy('fee_snapshot_id');
        foreach ($revisionObligations as $obligation) {
            $snapshot = $obligation->feeSnapshot;
            $origin = $origins->get($obligation->fee_snapshot_id);
            try {
                $valid = $snapshot !== null && $origin !== null && $origin->plan !== null
                    && $obligation->customer_profile_id === $snapshot->customer_profile_id
                    && $this->snapshotBinding->isValid($origin->plan, $origin, $snapshot, $prepared);
            } catch (ValueError) {
                $valid = false;
            }
            if (! $valid) {
                if ($prepared) {
                    $obligations->forget($obligation->id);

                    continue;
                }
                throw new RuntimeException('A savings fee has no authoritative source cycle.');
            }
            $obligations->put($obligation->id, (object) ['id' => $obligation->id,
                'customer_profile_id' => $obligation->customer_profile_id, 'thrift_plan_id' => $origin->thrift_plan_id]);
        }

        $registrationRefundFeeIds = FeeObligation::query()->whereIn('id', $rows->where('source_type', 'fee_refund')->pluck('fee_obligation_id'))
            ->where('kind', 'registration')->when($current, fn ($query) => $query->sharedLock())->pluck('id');
        $applicationReferences = $rows->where('source_type', 'fee_savings_application')->pluck('source_id');
        if ($registrationRefundFeeIds->isNotEmpty() && Schema::hasTable('fee_savings_applications')) {
            $applicationReferences = $applicationReferences->merge(DB::table('fee_savings_applications')
                ->whereIn('fee_obligation_id', $registrationRefundFeeIds)->when($current, fn ($query) => $query->sharedLock())->pluck('operation_reference'))->unique();
        }
        $applications = $applicationReferences->isEmpty() ? collect() : DB::table('fee_savings_applications as applications')
            ->join('ledger_posting_groups as groups', fn ($join) => $join->on('groups.source_id', '=', 'applications.operation_reference')->where('groups.source_type', 'fee_savings_application'))
            ->whereIn('applications.operation_reference', $applicationReferences)
            ->select('applications.*', 'groups.id as posting_group_id', 'groups.actor_user_id as posting_actor_id', 'groups.customer_profile_id as posting_customer_id', 'groups.thrift_plan_id as posting_plan_id', 'groups.event_type as posting_event_type')
            ->when($current, fn ($query) => $query->sharedLock())->get()->keyBy('operation_reference');
        $references = $applicationReferences->map(function (mixed $reference): string {
            if (! is_string($reference)) {
                throw new RuntimeException('A savings application identity is invalid.');
            }

            return $reference;
        })->values()->all();
        $verified = app(FeeSavingsApplicationService::class)->verifiedPostedSources(array_values($references), $current);
        $registrationRefundCycles = collect();
        foreach ($registrationRefundFeeIds as $feeId) {
            $originals = $applications->where('fee_obligation_id', $feeId);
            $verifiedOriginals = $originals->filter(fn (stdClass $application): bool => $verified->has($application->operation_reference));
            $cycles = $verifiedOriginals->pluck('thrift_plan_id')->unique();
            if ($originals->isNotEmpty() && $verifiedOriginals->count() === $originals->count() && $cycles->count() === 1) {
                $registrationRefundCycles->put($feeId, (int) $cycles->first());
            }
        }
        $applications = $applications->filter(fn (stdClass $application): bool => $verified->has($application->operation_reference));
        $refunds = DB::table('fee_refunds as refunds')->join('ledger_posting_groups as groups', 'groups.id', '=', 'refunds.ledger_posting_group_id')
            ->whereIn('refunds.refund_reference', $rows->where('source_type', 'fee_refund')->pluck('source_id'))
            ->select('refunds.*', 'groups.thrift_plan_id as posting_plan_id', 'groups.customer_profile_id as posting_customer_id',
                'groups.actor_user_id as posting_actor_id', 'groups.source_type as posting_source_type', 'groups.source_id as posting_source_id',
                'groups.event_type as posting_event_type', 'groups.currency as posting_currency')
            ->when($current, fn ($query) => $query->sharedLock())->get()->keyBy('refund_reference');

        $feeCorrectionIds = DB::table('ledger_posting_groups')->whereIn('id', $rows->pluck('ledger_posting_group_id'))
            ->where('event_type', 'fee_application_compensation')->pluck('source_id')->map(fn ($id): int => (int) $id)->all();
        $feeCompensations = app(FeeSavingsApplicationReversalOwner::class)->verifiedPostedSources(array_values($feeCorrectionIds), $current);
        if (! $prepared) {
            return new SavingsAttributionSources(false, $receipts, $obligations, applications: $applications,
                refunds: $refunds, registrationRefundCycles: $registrationRefundCycles, feeCompensations: $feeCompensations);
        }
        $charges = DB::table('manual_charges')->whereIn('operation_reference', $rows->where('source_type', 'manual_charge')->pluck('source_id'))
            ->get()->keyBy('operation_reference');
        $reversals = DB::table('reversal_requests')->whereIn('id', $rows->where('source_type', 'reversal_request')->pluck('source_id'))->get()->keyBy('id');
        $originalGroups = DB::table('ledger_posting_groups')->whereIn('id', $reversals->pluck('original_posting_group_id'))->get()->keyBy('id');
        $withdrawals = DB::table('withdrawal_requests')->whereIn('id', $rows->where('source_type', 'withdrawal')->pluck('source_id'))->get()->keyBy('id');

        return new SavingsAttributionSources(true, $receipts, $obligations, $charges, $reversals, $originalGroups, $withdrawals, $applications, $refunds, $registrationRefundCycles, $feeCompensations);
    }

    /**
     * @param  array{liability_kobo: int, reservations_kobo: int, available_kobo: int}  $total
     * @param  Collection<int, stdClass>  $rows
     * @param  Collection<int, stdClass>  $reservations
     * @return array{liability_kobo: int, reservations_kobo: int, available_kobo: int, cycle_liability_kobo: int, cycle_reservations_kobo: int, cycle_available_kobo: int}
     */
    private function positionFromRows(int $customerId, int $planId, array $total, Collection $rows, Collection $reservations, SavingsAttributionSources $sources): array
    {
        $cycleLiability = 0;
        $attributedTotal = 0;
        foreach ($rows as $entry) {
            $sourcePlanId = $this->planForEntry($entry, $sources, $customerId);
            if ($sourcePlanId === null) {
                throw new RuntimeException('A savings entry has no authoritative source cycle.');
            }
            $signedAmount = $entry->side === 'credit' ? (int) $entry->amount_kobo : -((int) $entry->amount_kobo);
            $attributedTotal = $this->checkedAdd($attributedTotal, $signedAmount);
            if ($sourcePlanId === $planId) {
                $cycleLiability = $this->checkedAdd($cycleLiability, $signedAmount);
            }
        }

        $cycleReservations = 0;
        foreach ($reservations as $reservation) {
            if ($reservation->thrift_plan_id === null) {
                throw new RuntimeException('A live reservation has no authoritative source cycle.');
            }
            if ((int) $reservation->thrift_plan_id === $planId) {
                $cycleReservations = $this->checkedAdd($cycleReservations, (int) $reservation->gross_amount_kobo);
            }
        }

        if ($attributedTotal !== $total['liability_kobo'] || $cycleLiability < 0 || $cycleReservations > $cycleLiability) {
            throw new RuntimeException('Cycle savings and Customer ledger balances do not reconcile.');
        }

        return [
            ...$total,
            'cycle_liability_kobo' => $cycleLiability,
            'cycle_reservations_kobo' => $cycleReservations,
            'cycle_available_kobo' => $cycleLiability - $cycleReservations,
        ];
    }

    private function planForEntry(stdClass $entry, SavingsAttributionSources $sources, int $customerId): ?int
    {
        if ($entry->source_type === 'fee_refund') {
            $refund = $sources->refunds->get($entry->source_id);
            $obligation = $sources->obligations->get((int) $entry->fee_obligation_id);
            if ($obligation === null) {
                return null;
            }
            $cycle = $obligation->thrift_plan_id ?? $sources->registrationRefundCycles->get((int) $entry->fee_obligation_id);

            return $refund !== null && $cycle !== null && $refund->kind === 'savings'
                && (int) $refund->customer_profile_id === $customerId && (int) $refund->posting_customer_id === $customerId
                && (int) $obligation->customer_profile_id === $customerId && (int) $refund->fee_obligation_id === (int) $entry->fee_obligation_id
                && (int) $refund->ledger_posting_group_id === (int) $entry->ledger_posting_group_id
                && $refund->posting_source_type === 'fee_refund' && $refund->posting_source_id === $entry->source_id
                && $refund->posting_event_type === 'savings_fee_refund' && $refund->posting_currency === 'NGN'
                && (int) $refund->posting_actor_id === (int) $refund->actor_user_id && $entry->side === 'credit'
                && (int) $refund->amount_kobo === (int) $entry->amount_kobo
                && (int) $refund->posting_plan_id === (int) $cycle && (int) $entry->thrift_plan_id === (int) $cycle
                ? (int) $cycle : null;
        }
        if ($entry->source_type === 'fee_savings_application') {
            $application = $sources->applications->get($entry->source_id);

            return $application !== null && (int) $application->customer_profile_id === $customerId
                && (int) $application->posting_customer_id === $customerId
                && (int) $application->fee_obligation_id === (int) $entry->fee_obligation_id
                && (int) $application->actor_user_id === (int) $application->posting_actor_id
                && (int) $application->posting_group_id === (int) $entry->ledger_posting_group_id
                && (int) $application->thrift_plan_id === (int) $entry->thrift_plan_id
                && (int) $application->posting_plan_id === (int) $entry->thrift_plan_id
                && $application->posting_event_type === 'savings_fee_application'
                && $application->currency === 'NGN' && $entry->side === 'debit'
                && (int) $application->amount_kobo === (int) $entry->amount_kobo
                ? (int) $application->thrift_plan_id : null;
        }
        if ($entry->source_type === 'manual_charge') {
            $charge = $sources->prepared ? $sources->charges->get($entry->source_id)
                : DB::table('manual_charges')->where('operation_reference', $entry->source_id)->where('customer_profile_id', $customerId)->first();

            return $charge !== null && (int) $charge->customer_profile_id === $customerId && (int) $charge->thrift_plan_id === (int) $entry->thrift_plan_id ? (int) $charge->thrift_plan_id : null;
        }
        if ($entry->source_type === 'reversal_request') {
            $reversal = $sources->prepared ? $sources->reversals->get($entry->source_id)
                : DB::table('reversal_requests')->where('id', $entry->source_id)->where('customer_profile_id', $customerId)->first();
            $original = $reversal === null ? null : ($sources->prepared ? $sources->originalGroups->get($reversal->original_posting_group_id)
                : DB::table('ledger_posting_groups')->where('id', $reversal->original_posting_group_id)->first());

            if ($original !== null && $original->source_type === 'fee_savings_application') {
                $compensation = $sources->feeCompensations->get((int) $entry->source_id);
                if ($compensation === null || $compensation->id !== (int) $entry->ledger_posting_group_id) {
                    return null;
                }
            }

            return $reversal !== null && (int) $reversal->customer_profile_id === $customerId && $original !== null && (int) $original->thrift_plan_id === (int) $entry->thrift_plan_id ? (int) $original->thrift_plan_id : null;
        }
        if ($entry->source_type === 'withdrawal') {
            $withdrawal = $sources->prepared ? $sources->withdrawals->get($entry->source_id)
                : DB::table('withdrawal_requests')->where('id', $entry->source_id)->first();

            return $withdrawal !== null && (int) $withdrawal->customer_profile_id === $customerId
                && (int) $withdrawal->thrift_plan_id === (int) $entry->thrift_plan_id
                ? (int) $withdrawal->thrift_plan_id : null;
        }
        if (in_array($entry->source_type, ['collection_receipt', 'fee_application'], true) && ctype_digit((string) $entry->source_id)) {
            $receipt = $sources->receipts->get((int) $entry->source_id);
            $obligation = $entry->fee_obligation_id === null ? null : $sources->obligations->get((int) $entry->fee_obligation_id);
            if ($receipt === null || (int) $receipt->customer_profile_id !== $customerId
                || ($entry->fee_obligation_id !== null && $obligation === null)
                || ($obligation !== null && ((int) $obligation->customer_profile_id !== $customerId
                    || (int) $obligation->thrift_plan_id !== (int) $receipt->thrift_plan_id))) {
                return null;
            }

            return $receipt->thrift_plan_id === null ? null : (int) $receipt->thrift_plan_id;
        }

        if ($entry->fee_obligation_id !== null) {
            $obligation = $sources->obligations->get((int) $entry->fee_obligation_id);

            return $obligation !== null && (int) $obligation->customer_profile_id === $customerId
                && $obligation->thrift_plan_id !== null ? (int) $obligation->thrift_plan_id : null;
        }

        return null;
    }

    private function checkedAdd(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right) || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            throw new RuntimeException('Savings total exceeds the supported range.');
        }

        return $left + $right;
    }
}
