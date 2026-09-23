<?php

namespace App\Services;

use App\Enums\LedgerAccountCode;
use App\Models\CustomerProfile;
use App\Models\ThriftPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CollectionReadService
{
    /** @return array{liability_kobo: int, reservations_kobo: int, available_kobo: int} */
    public function position(CustomerProfile $customer, bool $forUpdate = false): array
    {
        $entries = DB::table('ledger_entries')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->where('ledger_entries.customer_profile_id', $customer->id)
            ->where('ledger_accounts.code', LedgerAccountCode::CustomerSavingsLiability->value)
            ->select('ledger_entries.side', 'ledger_entries.amount_kobo');
        $reservations = DB::table('withdrawal_reservations')->where('customer_profile_id', $customer->id)
            ->where('status', 'live')->select('gross_amount_kobo');
        if ($forUpdate) {
            $entries->lockForUpdate();
            $reservations->lockForUpdate();
        }

        $liability = 0;
        foreach ($entries->get() as $entry) {
            $amount = (int) $entry->amount_kobo;
            $liability = $entry->side === 'credit' ? $this->checkedAdd($liability, $amount) : $liability - $amount;
        }
        $reserved = 0;
        foreach ($reservations->get() as $reservation) {
            $reserved = $this->checkedAdd($reserved, (int) $reservation->gross_amount_kobo);
        }
        if ($liability < 0 || $reserved > $liability) {
            throw new RuntimeException('Customer savings or reservation integrity is unavailable.');
        }

        return ['liability_kobo' => $liability, 'reservations_kobo' => $reserved, 'available_kobo' => $liability - $reserved];
    }

    /** @return array<string, mixed> */
    public function card(ThriftPlan $plan): array
    {
        $revision = $plan->currentTermsRevision();
        if ($revision === null) {
            throw new RuntimeException('Plan terms are unavailable.');
        }
        $today = CarbonImmutable::now($revision->timezone)->toDateString();
        $blockedIntervals = [];
        $pauseDate = null;
        foreach ($plan->lifecycleEvents()->get() as $event) {
            $eventDate = $event->effective_at->setTimezone($revision->timezone)->toDateString();
            if ($event->event_type === 'pause') {
                $pauseDate = $eventDate;
            } elseif ($event->event_type === 'resume' && $pauseDate !== null) {
                $blockedIntervals[] = [$pauseDate, $eventDate];
                $pauseDate = null;
            }
        }
        if ($pauseDate !== null) {
            $blockedIntervals[] = [$pauseDate, null];
        }
        $slots = $plan->slots()->whereNotNull('active_ordinal')->orderBy('active_ordinal')->get();
        $totals = DB::table('collection_allocations')
            ->whereIn('contribution_slot_id', $slots->pluck('id'))
            ->selectRaw('contribution_slot_id, SUM(amount_kobo) as funded_kobo, MAX(is_advance) as has_advance')
            ->groupBy('contribution_slot_id')->get()->keyBy('contribution_slot_id');
        $annotations = DB::table('collection_annotations')
            ->whereIn('contribution_slot_id', $slots->pluck('id'))
            ->orderByDesc('version')->get()->unique('contribution_slot_id')->keyBy('contribution_slot_id');
        $fundedTotal = 0;
        $paidCount = 0;
        $rows = [];
        foreach ($slots as $slot) {
            $funded = (int) ($totals[$slot->id]->funded_kobo ?? 0);
            if ($funded > $slot->expected_amount_kobo) {
                throw new RuntimeException('Slot funding integrity is unavailable.');
            }
            $fundedTotal = $this->checkedAdd($fundedTotal, $funded);
            $paidCount += (int) ($funded === $slot->expected_amount_kobo);
            $annotation = $annotations[$slot->id] ?? null;
            $blocked = false;
            foreach ($blockedIntervals as [$start, $end]) {
                if ($slot->due_date >= $start && ($end === null || $slot->due_date < $end)) {
                    $blocked = true;
                    break;
                }
            }
            $status = match (true) {
                $funded === $slot->expected_amount_kobo => 'paid',
                $funded > 0 => 'partial',
                $blocked => 'blocked',
                $annotation !== null && $annotation->kind === 'skipped' => 'skipped',
                $annotation !== null && $annotation->kind === 'missed' => 'missed',
                $slot->due_date < $today && $plan->status->value === 'active' => 'missed',
                default => 'pending',
            };
            $rows[] = [
                'id' => $slot->id, 'ordinal' => $slot->active_ordinal, 'due_date' => $slot->due_date,
                'target_kobo' => $slot->expected_amount_kobo, 'funded_kobo' => $funded,
                'remaining_kobo' => $slot->expected_amount_kobo - $funded,
                'status' => $status, 'advance' => (bool) ($totals[$slot->id]->has_advance ?? false),
                'blocked' => $blocked,
                'annotation_reason' => $annotation === null ? null : $annotation->reason,
                'annotation_version' => $annotation === null ? 0 : $annotation->version,
            ];
        }

        return [
            'plan_id' => $plan->plan_id, 'status' => $plan->status->value, 'timezone' => $revision->timezone,
            'target_kobo' => $revision->expected_gross_kobo, 'funded_kobo' => $fundedTotal,
            'paid_slots' => $paidCount, 'slot_count' => $slots->count(), 'slots' => $rows,
            'position' => $this->position($plan->customerProfile),
        ];
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($right < 0 || $left > PHP_INT_MAX - $right) {
            throw new RuntimeException('Financial total exceeds the supported integer range.');
        }

        return $left + $right;
    }
}
