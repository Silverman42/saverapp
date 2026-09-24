<?php

namespace App\Services;

use App\Enums\LedgerAccountCode;
use App\Models\CustomerProfile;
use App\Models\ThriftPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class WithdrawalBalanceService
{
    public function __construct(private CollectionReadService $customerBalances) {}

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
            ->select('ledger_entries.side', 'ledger_entries.amount_kobo', 'ledger_entries.fee_obligation_id', 'ledger_posting_groups.source_type', 'ledger_posting_groups.source_id');
        if ($forUpdate) {
            $entries->lockForUpdate();
        }

        $rows = $entries->get();
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
            ->whereIn('fee_obligations.id', $obligationIds)
            ->select('fee_obligations.id', 'fee_obligations.customer_profile_id', 'thrift_plans.id as thrift_plan_id')
            ->get()->keyBy('id');

        $cycleLiability = 0;
        $attributedTotal = 0;
        foreach ($rows as $entry) {
            $sourcePlanId = $this->planForEntry($entry, $receipts, $obligations, $customer->id);
            if ($sourcePlanId === null) {
                throw new RuntimeException('A savings entry has no authoritative source cycle.');
            }
            $signedAmount = $entry->side === 'credit' ? (int) $entry->amount_kobo : -((int) $entry->amount_kobo);
            $attributedTotal = $this->checkedAdd($attributedTotal, $signedAmount);
            if ($sourcePlanId === $plan->id) {
                $cycleLiability = $this->checkedAdd($cycleLiability, $signedAmount);
            }
        }

        $cycleReservations = 0;
        $reservationQuery = DB::table('withdrawal_reservations')
            ->where('customer_profile_id', $customer->id)->where('status', 'live')
            ->select('thrift_plan_id', 'gross_amount_kobo');
        if ($forUpdate) {
            $reservationQuery->lockForUpdate();
        }
        foreach ($reservationQuery->get() as $reservation) {
            if ($reservation->thrift_plan_id === null) {
                throw new RuntimeException('A live reservation has no authoritative source cycle.');
            }
            if ((int) $reservation->thrift_plan_id === $plan->id) {
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

    /** @param Collection<int|string, stdClass> $receipts
     * @param  Collection<int|string, stdClass>  $obligations
     */
    private function planForEntry(stdClass $entry, Collection $receipts, Collection $obligations, int $customerId): ?int
    {
        if (in_array($entry->source_type, ['collection_receipt', 'fee_application'], true) && ctype_digit((string) $entry->source_id)) {
            $receipt = $receipts->get((int) $entry->source_id);
            $obligation = $entry->fee_obligation_id === null ? null : $obligations->get((int) $entry->fee_obligation_id);
            if ($receipt === null || (int) $receipt->customer_profile_id !== $customerId
                || ($obligation !== null && ((int) $obligation->customer_profile_id !== $customerId
                    || (int) $obligation->thrift_plan_id !== (int) $receipt->thrift_plan_id))) {
                return null;
            }

            return $receipt->thrift_plan_id === null ? null : (int) $receipt->thrift_plan_id;
        }

        if ($entry->fee_obligation_id !== null) {
            $obligation = $obligations->get((int) $entry->fee_obligation_id);

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
