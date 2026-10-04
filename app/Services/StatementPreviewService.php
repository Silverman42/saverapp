<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StatementPreviewService
{
    public function __construct(
        private ResourceScopeService $scope,
        private LedgerTransactionReadService $transactions,
        private CollectionReadService $balances,
    ) {}

    /** @return array<string, mixed> */
    public function preview(User $viewer, CustomerProfile $customer, string $from, string $to, string $timezone, bool $forUpdate = false): array
    {
        if (! $this->scope->forCustomers($viewer)->whereKey($customer->id)->exists()) {
            throw new NotFoundHttpException('Record unavailable.');
        }

        try {
            return $this->build($customer, $from, $to, $timezone, $forUpdate);
        } catch (RuntimeException) {
            return ['status' => 'unavailable', 'message' => 'Statement totals exceed the supported range.'];
        }
    }

    /** @return array<string, mixed> */
    private function build(CustomerProfile $customer, string $from, string $to, string $timezone, bool $forUpdate): array
    {
        return DB::transaction(function () use ($customer, $from, $to, $timezone, $forUpdate): array {
            if ($forUpdate) {
                CustomerProfile::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            }
            $state = $this->transactions->state();
            if ($state['status'] !== 'ready' || $this->transactions->frozenCustomers([$customer->id]) !== []) {
                return ['status' => 'unavailable'];
            }
            $query = DB::table('ledger_transaction_projections as transactions')
                ->join('ledger_transaction_references as refs', 'refs.id', '=', 'transactions.ledger_transaction_reference_id')
                ->where('transactions.projection_version', $state['version'])
                ->where('transactions.customer_profile_id', $customer->id);

            $opening = 0;
            $activity = 0;
            $allTime = 0;
            $lines = [];
            foreach ((clone $query)->orderBy('transactions.occurred_on')
                ->orderBy('transactions.committed_at')->orderBy('transactions.id')
                ->select('transactions.*', 'refs.transaction_reference')->cursor() as $row) {
                $data = (array) $row;
                $effect = (int) $data['savings_effect_kobo'];
                $allTime = $this->checkedAdd($allTime, $effect);
                if ($data['occurred_on'] < $from) {
                    $opening = $this->checkedAdd($opening, $effect);
                } elseif ($data['occurred_on'] <= $to) {
                    $activity = $this->checkedAdd($activity, $effect);
                    if (count($lines) >= 2000) {
                        return ['status' => 'unavailable', 'message' => 'This period exceeds the interactive statement limit.'];
                    }
                    $lines[] = [
                        'reference' => $data['transaction_reference'],
                        'type' => $data['type'], 'occurred_on' => $data['occurred_on'],
                        'committed_at' => $data['committed_at'],
                        'gross_amount_kobo' => (int) $data['gross_amount_kobo'],
                        'savings_effect_kobo' => $effect,
                        'fee_amount_kobo' => (int) $data['fee_amount_kobo'],
                    ];
                }
            }
            try {
                $liability = $this->balances->liability($customer, $forUpdate);
            } catch (RuntimeException) {
                return ['status' => 'unavailable'];
            }
            if ($allTime !== $liability) {
                return ['status' => 'unavailable'];
            }
            $closing = $this->checkedAdd($opening, $activity);
            $typeTotals = [];
            foreach ($lines as $line) {
                $typeTotals[$line['type']]['type'] = $line['type'];
                $typeTotals[$line['type']]['count'] = ($typeTotals[$line['type']]['count'] ?? 0) + 1;
                $typeTotals[$line['type']]['savings_effect_kobo'] = $this->checkedAdd($typeTotals[$line['type']]['savings_effect_kobo'] ?? 0, $line['savings_effect_kobo']);
                $typeTotals[$line['type']]['fee_amount_kobo'] = $this->checkedAdd($typeTotals[$line['type']]['fee_amount_kobo'] ?? 0, $line['fee_amount_kobo']);
            }
            ksort($typeTotals);
            if ($this->sumEffects($typeTotals) !== $activity) {
                return ['status' => 'unavailable'];
            }
            $reserved = null;
            $available = null;
            try {
                $position = $this->balances->position($customer, $forUpdate);
                $reserved = $position['reservations_kobo'];
                $available = $position['available_kobo'];
            } catch (RuntimeException) {
                // Only the availability figure is withheld. It is never shown as zero.
            }
            $unpaidFees = $this->unpaidFees($customer);

            $preview = [
                'status' => 'ready', 'customer_id' => $customer->customer_id,
                'from' => $from, 'to' => $to, 'timezone' => $timezone, 'currency' => 'NGN',
                'cutoff_at' => now()->utc()->toIso8601String(),
                'ledger_watermark' => $state['watermark'], 'projection_version' => $state['version'],
                'opening_kobo' => $opening, 'activity_kobo' => $activity, 'closing_kobo' => $closing,
                'current_reserved_kobo' => $reserved,
                'current_available_kobo' => $available,
                'unpaid_fees_kobo' => $unpaidFees,
                'type_totals' => array_values($typeTotals),
                'lines' => $lines,
            ];
            $confirmed = $preview;
            unset($confirmed['cutoff_at']);
            $preview['preview_fingerprint'] = hash('sha256', json_encode([$confirmed, $customer->version, BusinessProfile::current()->version], JSON_THROW_ON_ERROR));

            return $preview;
        }, attempts: 3);
    }

    /** @param array<string, array<string, mixed>> $typeTotals */
    private function sumEffects(array $typeTotals): int
    {
        $sum = 0;
        foreach ($typeTotals as $total) {
            $sum = $this->checkedAdd($sum, (int) $total['savings_effect_kobo']);
        }

        return $sum;
    }

    /** Outstanding fee obligations are a separate position and never part of savings. Null means the figure is unavailable. */
    private function unpaidFees(CustomerProfile $customer): ?int
    {
        try {
            $total = 0;
            foreach (FeeObligation::query()->where('customer_profile_id', $customer->id)->with('entries')->get() as $obligation) {
                $total = $this->checkedAdd($total, $obligation->outstandingAmountKobo());
            }

            return $total;
        } catch (RuntimeException) {
            return null;
        }
    }

    private function checkedAdd(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            throw new RuntimeException('Statement totals exceed the supported integer range.');
        }

        return $left + $right;
    }
}
