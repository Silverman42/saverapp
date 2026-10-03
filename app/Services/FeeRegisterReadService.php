<?php

namespace App\Services;

use App\Enums\FeeObligationStatus;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Models\FeeObligation;
use App\Models\FeeRefund;
use App\Models\ManualCharge;
use App\Models\PlanTermsRevision;
use App\Models\ThriftPlan;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ValueError;

class FeeRegisterReadService
{
    /** @param array<string, string|int> $filters
     * @return array{obligations: LengthAwarePaginator<int, FeeObligation>, summary: array<string, mixed>}
     */
    public function read(array $filters, int $page, string $path): array
    {
        return DB::transaction(fn (): array => $this->readSnapshot($filters, $page, $path));
    }

    /** @param array<string, string|int> $filters
     * @return array{obligations: LengthAwarePaginator<int, FeeObligation>, summary: array<string, mixed>}
     */
    private function readSnapshot(array $filters, int $page, string $path): array
    {
        $query = $this->query($filters)->with(['customerProfile.user', 'feeSnapshot', 'entries']);
        $direction = $filters['sort'] === 'oldest' ? 'asc' : 'desc';
        $query->orderBy('created_at', $direction)->orderBy('id', $direction);
        $items = [];
        $total = 0;
        $pending = 0;
        $outstanding = 0;
        $unavailable = 0;
        $size = (int) $filters['per_page'];
        $offset = ($page - 1) * $size;
        $query->chunk(500, function ($batch) use ($filters, $size, $offset, &$items, &$total, &$pending, &$outstanding, &$unavailable): void {
            foreach ($batch as $fee) {
                $valid = $this->available($fee);
                $status = $valid ? $fee->status->value : 'unavailable';
                if ($filters['status'] !== '' && $status !== $filters['status']) {
                    continue;
                }
                if ($total >= $offset && count($items) < $size) {
                    $items[] = $fee;
                }
                $total++;
                if (! $valid) {
                    $unavailable++;

                    continue;
                }
                $amount = $fee->outstandingAmountKobo();
                if ($amount > PHP_INT_MAX - $outstanding) {
                    throw new \OverflowException('Filtered fee total exceeds the supported integer range.');
                }
                $outstanding += $amount;
                $pending += $amount > 0 ? 1 : 0;
            }
        });

        return ['obligations' => new LengthAwarePaginator($items, $total, $size, $page, ['path' => $path, 'query' => $filters]),
            'summary' => ['obligation_count' => $total, 'pending_count' => $unavailable === 0 ? $pending : null,
                'outstanding_amount_kobo' => $unavailable === 0 ? $outstanding : null,
                'formatted_outstanding_amount' => $unavailable === 0 ? MoneyFormatter::formatNaira($outstanding) : null,
                'obligation_totals_status' => $unavailable === 0 ? 'available' : 'unavailable',
                'unavailable_count' => $unavailable, 'obligation_scope' => 'Complete filtered obligation register',
                'ledger_scope' => 'Business-wide committed ledger earnings and refund payable']];
    }

    public function available(FeeObligation $fee): bool
    {
        try {
            $snapshot = $fee->feeSnapshot;
            if ($snapshot === null || $snapshot->customer_profile_id !== $fee->customer_profile_id
                || $snapshot->kind->value !== $fee->kind || $snapshot->source_type !== $fee->source_type
                || $snapshot->source_id !== $fee->source_id || $snapshot->currency !== $fee->currency
                || $snapshot->amount_kobo !== $fee->amount_kobo) {
                return false;
            }
            $fee->outstandingAmountKobo();

            return true;
        } catch (RuntimeException|ValueError) {
            return false;
        }
    }

    /** @param array<string, string|int> $filters
     * @return Builder<FeeObligation>
     */
    private function query(array $filters): Builder
    {
        $query = FeeObligation::query();
        if ($filters['date_from'] !== '') {
            $query->where('created_at', '>=', CarbonImmutable::parse((string) $filters['date_from'], 'Africa/Lagos')->startOfDay()->utc());
        }
        if ($filters['date_to'] !== '') {
            $query->where('created_at', '<', CarbonImmutable::parse((string) $filters['date_to'], 'Africa/Lagos')->addDay()->startOfDay()->utc());
        }
        if ($filters['customer'] !== '') {
            $query->whereHas('customerProfile', fn (Builder $customer): Builder => $customer->where('customer_id', $filters['customer']));
        }
        if ($filters['current_agent'] !== '') {
            $query->whereHas('customerProfile.currentAssignment.agentProfile', fn (Builder $agent): Builder => $agent->where('agent_id', $filters['current_agent']));
        }
        if ($filters['original_agent'] !== '') {
            $query->whereHas('createdByUser.agentProfile', fn (Builder $agent): Builder => $agent->where('agent_id', $filters['original_agent']));
        }
        if ($filters['cycle'] !== '') {
            $planIds = ThriftPlan::query()->where('plan_id', $filters['cycle'])->select('id');
            $query->where(fn (Builder $cycle): Builder => $cycle
                ->whereIn('fee_snapshot_id', PlanTermsRevision::query()->whereIn('thrift_plan_id', clone $planIds)->select('fee_snapshot_id'))
                ->orWhereIn('id', ManualCharge::query()->whereIn('thrift_plan_id', clone $planIds)->select('fee_obligation_id')));
        }
        foreach (['kind', 'currency'] as $field) {
            if ($filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }
        if ($filters['source'] !== '') {
            $query->where('source_type', $filters['source']);
        }
        if ($filters['model'] !== '') {
            $query->whereHas('feeSnapshot', fn (Builder $snapshot): Builder => $snapshot->where('model', $filters['model']));
        }
        if ($filters['refund_status'] !== '') {
            $refunds = FeeRefund::query()->select('fee_obligation_id');
            if ($filters['refund_status'] === 'none') {
                $query->whereNotIn('id', $refunds);
            } else {
                $query->whereIn('id', $refunds->where('kind', $filters['refund_status'] === 'savings_returned' ? 'savings' : 'external'));
            }
        }
        if ($filters['reconciliation_status'] !== '') {
            $batches = DB::table('collection_fee_components as components')
                ->join('collection_receipts as receipts', 'receipts.id', '=', 'components.collection_receipt_id')
                ->join('collection_batches as batches', 'batches.id', '=', 'receipts.collection_batch_id')
                ->select('components.fee_obligation_id');
            if ($filters['reconciliation_status'] === 'none') {
                $query->whereNotIn('id', $batches);
            } else {
                $query->whereIn('id', $batches->where('batches.status', $filters['reconciliation_status']));
            }
        }

        return $query;
    }

    /** @return array<string, list<array{value: string, label: string}>> */
    public function options(): array
    {
        return [
            'kind' => array_map(fn (FeeRuleKind $kind): array => ['value' => $kind->value, 'label' => $kind->displayName()], FeeRuleKind::cases()),
            'model' => array_map(fn (FeeRuleModel $model): array => ['value' => $model->value, 'label' => $model->displayName()], FeeRuleModel::cases()),
            'status' => [...array_map(fn (FeeObligationStatus $status): array => ['value' => $status->value, 'label' => $status->displayName()], FeeObligationStatus::cases()), ['value' => 'unavailable', 'label' => 'History unavailable']],
            'source' => [['value' => 'registration', 'label' => 'Registration'], ['value' => 'plan', 'label' => 'Original plan'],
                ['value' => 'plan_terms_revision', 'label' => 'Plan terms revision'], ['value' => 'manual_charge', 'label' => 'Manual charge']],
            'currency' => [['value' => 'NGN', 'label' => 'NGN']],
            'refund_status' => [['value' => 'none', 'label' => 'No recorded refund'], ['value' => 'savings_returned', 'label' => 'Savings return recorded'],
                ['value' => 'external_entitlement', 'label' => 'External refund entitlement recorded']],
            'reconciliation_status' => [['value' => 'none', 'label' => 'No linked fee receipt batch'], ['value' => 'open', 'label' => 'Linked fee receipt batch open'],
                ['value' => 'ready_for_review', 'label' => 'Linked fee receipt batch ready for review'],
                ['value' => 'in_review', 'label' => 'Linked fee receipt batch in review'], ['value' => 'exception', 'label' => 'Linked fee receipt batch exception'],
                ['value' => 'reconciled', 'label' => 'Linked fee receipt batch reconciled']],
            'sort' => [['value' => 'newest', 'label' => 'Newest assessment first'], ['value' => 'oldest', 'label' => 'Oldest assessment first']],
        ];
    }
}
