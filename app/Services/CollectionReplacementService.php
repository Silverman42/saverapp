<?php

namespace App\Services;

use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\BusinessProfile;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\FinancialWorkflowSupplement;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CollectionReplacementService
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function preview(User $actor, ReversalRequest $reversal, array $data): array
    {
        $customer = CustomerProfile::query()->findOrFail($reversal->customer_profile_id);
        Gate::forUser($actor)->authorize('recordCollection', $customer);
        abort_unless(config('collections.receipt_corrections_enabled', false), 503);
        if (($data['received_date'] ?? '') !== now(BusinessProfile::current()->timezone)->toDateString()) {
            throw new ConflictHttpException('A replacement uses the current business date and a new transaction identity.');
        }
        $quote = app(CollectionService::class)->preview($actor, $customer, $data, $reversal);
        $source = $this->lockSource($reversal, $customer, $quote['tender_kobo'], false);
        $quote['replacement_fingerprint'] = hash('sha256', json_encode([$reversal->id, $reversal->compensation_posting_group_id,
            $source->id, $quote['preview_fingerprint']], JSON_THROW_ON_ERROR));

        return $quote;
    }

    /** @param array<string, mixed> $data */
    public function record(User $actor, ReversalRequest $reversal, array $data): CollectionReceipt
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $reversal, $data): CollectionReceipt {
            $customer = CustomerProfile::query()->whereKey($reversal->customer_profile_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('recordCollection', $customer);
            $existing = CollectionReceipt::query()->where('attempt_reference', $data['attempt_reference'])->first();
            if ($existing !== null) {
                if ($existing->replacement_reversal_id !== $reversal->id) {
                    throw new ConflictHttpException('This attempt belongs to another replacement.');
                }

                return app(CollectionService::class)->record($actor, $customer, $data, $reversal);
            }
            $quote = $this->preview($actor, $reversal, $data);
            if (! hash_equals($quote['replacement_fingerprint'], $data['replacement_fingerprint'])) {
                throw new ConflictHttpException('Replacement source changed. Review the current preview.');
            }
            $receipt = app(CollectionService::class)->record($actor, $customer, $data, $reversal);
            FinancialWorkflowSupplement::create(['operation_reference' => $data['attempt_reference'],
                'payload_hash' => $receipt->payload_hash, 'kind' => 'receipt_replaced', 'customer_profile_id' => $customer->id,
                'thrift_plan_id' => $receipt->thrift_plan_id, 'collection_batch_id' => $receipt->collection_batch_id,
                'reversal_request_id' => $reversal->id, 'actor_user_id' => $actor->id,
                'facts' => ['replacement_receipt_id' => $receipt->id, 'consumed_kobo' => $receipt->tender_amount_kobo,
                    'original_custodian_id' => $receipt->recording_agent_profile_id], 'evidence' => 'Separately confirmed controlled-funds allocation.', 'created_at' => now()]);

            return $receipt;
        }, attempts: 3);
    }

    public function lockSource(ReversalRequest $reversal, CustomerProfile $customer, int $amount, bool $forUpdate = true): CollectionReceipt
    {
        $reversal = ReversalRequest::query()->whereKey($reversal->id)->when($forUpdate, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $group = LedgerPostingGroup::query()->find($reversal->compensation_posting_group_id);
        if ($reversal->customer_profile_id !== $customer->id || $reversal->state !== 'approved_posted'
            || $group?->event_type !== 'receipt_reclassification'
            || CollectionReceipt::query()->where('replacement_reversal_id', $reversal->id)->exists()) {
            throw new ConflictHttpException('This correction has no unconsumed controlled receipt.');
        }
        $dependencies = $reversal->getAttribute('dependency_snapshot');
        if (! is_array($dependencies)) {
            throw new ConflictHttpException('Original receipt dependencies are unavailable.');
        }
        $sourceId = $dependencies['summary']['receipt_id'] ?? null;
        $receipt = CollectionReceipt::query()->whereKey($sourceId)->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
        if ($receipt === null || $receipt->customer_profile_id !== $customer->id || $this->controlledAmount($reversal, $receipt) !== $amount) {
            throw new ConflictHttpException('Consume the exact original controlled tender without recording new cash.');
        }

        return $receipt;
    }

    public function controlledAmount(ReversalRequest $reversal, CollectionReceipt $source): int
    {
        $group = LedgerPostingGroup::query()->with('entries.account')->find($reversal->compensation_posting_group_id);
        $dependencies = $reversal->getAttribute('dependency_snapshot');
        if ($reversal->state !== 'approved_posted' || $reversal->customer_profile_id !== $source->customer_profile_id
            || ! is_array($dependencies) || ($dependencies['summary']['receipt_id'] ?? null) !== $source->id
            || $group?->event_type !== 'receipt_reclassification' || $group->source_type !== 'reversal_request'
            || $group->source_id !== (string) $reversal->id || $group->customer_profile_id !== $source->customer_profile_id
            || ($group->metadata['receipt_id'] ?? $source->id) !== $source->id) {
            throw new ConflictHttpException('The approved controlled receipt provenance is unavailable.');
        }
        $amount = $group->metadata['controlled_kobo'] ?? $source->tender_amount_kobo;
        $credits = $group->entries->filter(fn ($entry): bool => $entry->account->code === LedgerAccountCode::UnappliedFunds
            && $entry->side === LedgerEntrySide::Credit);
        if (! is_int($amount) || $amount < 1 || $amount > $source->tender_amount_kobo || $credits->count() !== 1
            || $credits->first()?->amount_kobo !== $amount || $credits->first()->customer_profile_id !== $source->customer_profile_id
            || $credits->first()->agent_profile_id !== $source->recording_agent_profile_id) {
            throw new ConflictHttpException('The approved correction has no exact controlled tender.');
        }

        return $amount;
    }
}
