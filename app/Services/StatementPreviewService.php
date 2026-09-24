<?php

namespace App\Services;

use App\Models\CustomerProfile;
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
    public function preview(User $viewer, CustomerProfile $customer, string $from, string $to, string $timezone): array
    {
        if (! $this->scope->forCustomers($viewer)->whereKey($customer->id)->exists()) {
            throw new NotFoundHttpException('Record unavailable.');
        }

        return DB::transaction(function () use ($customer, $from, $to, $timezone): array {
            CustomerProfile::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $state = $this->transactions->state();
            if ($state['status'] !== 'ready') {
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
                $position = $this->balances->position($customer, true);
            } catch (RuntimeException) {
                return ['status' => 'unavailable'];
            }
            if ($allTime !== $position['liability_kobo']) {
                return ['status' => 'unavailable'];
            }
            $closing = $this->checkedAdd($opening, $activity);

            return [
                'status' => 'ready', 'customer_id' => $customer->customer_id,
                'from' => $from, 'to' => $to, 'timezone' => $timezone, 'currency' => 'NGN',
                'cutoff_at' => now()->utc()->toIso8601String(),
                'ledger_watermark' => $state['watermark'], 'projection_version' => $state['version'],
                'opening_kobo' => $opening, 'activity_kobo' => $activity, 'closing_kobo' => $closing,
                'current_reserved_kobo' => $position['reservations_kobo'],
                'current_available_kobo' => $position['available_kobo'],
                'lines' => $lines,
            ];
        }, attempts: 3);
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
