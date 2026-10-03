<?php

namespace App\Services;

use App\Models\CollectionReceipt;
use App\Models\ReversalRequest;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class CollectionAllocationReleaseProof
{
    public function assertForPlans(Builder $planIds): void
    {
        $released = DB::table('collection_allocation_releases as releases')
            ->join('collection_allocations as allocations', 'allocations.id', '=', 'releases.collection_allocation_id')
            ->join('collection_receipts as receipts', 'receipts.id', '=', 'allocations.collection_receipt_id')
            ->whereIn('receipts.thrift_plan_id', clone $planIds)
            ->select('releases.reversal_request_id', 'receipts.id as receipt_id')->distinct();
        $approved = DB::table('reversal_requests as corrections')
            ->join('collection_receipts as receipts', 'receipts.savings_posting_group_id', '=', 'corrections.posted_original_posting_group_id')
            ->where('corrections.state', 'approved_posted')->whereIn('receipts.thrift_plan_id', clone $planIds)
            ->select('corrections.id as reversal_request_id', 'receipts.id as receipt_id');

        foreach ($released->union($approved)->orderBy('reversal_request_id')->orderBy('receipt_id')->lazy(100)->chunk(100) as $pairs) {
            $receipts = CollectionReceipt::query()->with('allocations')->whereKey($pairs->pluck('receipt_id'))->get()->keyBy('id');
            $requests = ReversalRequest::query()->whereKey($pairs->pluck('reversal_request_id'))->get()->keyBy('id');
            $releases = DB::table('collection_allocation_releases')->whereIn('collection_allocation_id', $receipts
                ->flatMap(fn (CollectionReceipt $receipt) => $receipt->allocations->pluck('id')))->get()->keyBy('collection_allocation_id');
            foreach ($pairs as $pair) {
                $receipt = $receipts->get((int) $pair->receipt_id);
                $request = $requests->get((int) $pair->reversal_request_id);
                if ($receipt === null || $request === null || $request->state !== 'approved_posted' || $receipt->allocations->isEmpty()) {
                    throw new RuntimeException('Allocation release has no complete approved original receipt.');
                }
                try {
                    app(ReversalService::class)->assertApprovedReceiptCorrection($request, $receipt);
                } catch (ConflictHttpException|ServiceUnavailableHttpException|\ValueError|\InvalidArgumentException $exception) {
                    throw new RuntimeException('Allocation release approval provenance is unavailable.', previous: $exception);
                }
                foreach ($receipt->allocations as $allocation) {
                    $release = $releases->get($allocation->id);
                    if ($release === null || (int) $release->reversal_request_id !== $request->id) {
                        throw new RuntimeException('Approved receipt correction has an incomplete allocation release set.');
                    }
                }
            }
        }
    }
}
