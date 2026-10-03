<?php

namespace App\Services;

use App\Enums\FeeObligationEntryType;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\BusinessProfile;
use App\Models\CollectionReceipt;
use App\Models\FeeObligationEntry;
use App\Models\FinancialWorkflowSupplement;
use App\Models\LedgerPostingGroup;
use App\Models\ReversalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CollectionNoMoneyCorrection
{
    /** @param array<string, mixed> $preview */
    public function record(ReversalRequest $request, array $preview, User $reviewer): FinancialWorkflowSupplement
    {
        $receipt = CollectionReceipt::query()->whereKey($preview['summary']['receipt_id'])->firstOrFail();
        $business = BusinessProfile::current();
        $effects = array_values(array_filter($preview['dependencies'], fn (array $effect): bool => $effect['kind'] === 'external_fee'));
        $proof = FinancialWorkflowSupplement::create([
            'operation_reference' => (string) Str::uuid(), 'payload_hash' => $preview['fingerprint'],
            'kind' => 'receipt_no_money_correction', 'customer_profile_id' => $receipt->customer_profile_id,
            'thrift_plan_id' => null, 'collection_batch_id' => $receipt->collection_batch_id,
            'reversal_request_id' => $request->id, 'actor_user_id' => $reviewer->id,
            'facts' => ['receipt_id' => $receipt->id, 'original_posting_group_id' => $request->original_posting_group_id,
                'controlled_kobo' => 0, 'gross_kobo' => $receipt->tender_amount_kobo, 'fee_effects' => $effects,
                'occurred_on' => now($business->timezone)->toDateString(), 'timezone' => $business->timezone,
                'source_group_watermark' => (int) LedgerPostingGroup::query()->max('id')],
            'evidence' => $request->internal_reason, 'created_at' => now(),
        ]);
        foreach ($effects as $effect) {
            FeeObligationEntry::create(['fee_obligation_id' => $effect['fee_obligation_id'],
                'entry_type' => FeeObligationEntryType::SettlementReversal, 'amount_kobo' => $effect['amount_kobo'],
                'currency' => 'NGN', 'source_type' => 'receipt_no_money', 'source_id' => $proof->id.'-'.$effect['fee_obligation_id'],
                'idempotency_key' => 'receipt-no-money-'.$request->id.'-'.$effect['settlement_entry_id'],
                'actor_user_id' => $reviewer->id, 'reason' => $request->internal_reason,
                'customer_description' => $request->customer_explanation, 'ledger_posting_reference' => null]);
        }

        return $proof;
    }

    public function assertOutcome(ReversalRequest $request, bool $approved = true): FinancialWorkflowSupplement
    {
        $proofs = FinancialWorkflowSupplement::query()->where('reversal_request_id', $request->id)
            ->where('kind', 'receipt_no_money_correction')->get();
        $proof = $proofs->first();
        $snapshot = $request->getAttribute('dependency_snapshot');
        if ($proofs->count() !== 1 || $proof === null || $request->currency !== 'NGN'
            || $request->compensation_posting_group_id !== null
            || $request->state !== ($approved ? 'approved_no_money' : 'pending_review')
            || $proof->customer_profile_id !== $request->customer_profile_id || $proof->thrift_plan_id !== null
            || $proof->actor_user_id === $request->requested_by_user_id
            || ! is_array($snapshot) || ($snapshot['summary']['receipt_id'] ?? null) !== ($proof->facts['receipt_id'] ?? null)
            || ($proof->facts['controlled_kobo'] ?? null) !== 0
            || ($proof->facts['gross_kobo'] ?? null) !== $request->original_amount_kobo
            || ($proof->facts['original_posting_group_id'] ?? null) !== $request->original_posting_group_id
            || LedgerPostingGroup::query()->where('source_type', 'reversal_request')->where('source_id', (string) $request->id)->exists()) {
            throw new ConflictHttpException('The no-money receipt correction proof is unavailable.');
        }
        $receipt = CollectionReceipt::query()->whereKey($proof->facts['receipt_id'])->firstOrFail();
        $effects = $proof->facts['fee_effects'] ?? null;
        $timezone = $proof->facts['timezone'] ?? null;
        if ($receipt->customer_profile_id !== $request->customer_profile_id || $receipt->savings_amount_kobo !== 0
            || $receipt->thrift_plan_id !== null || $receipt->fee_amount_kobo !== $request->original_amount_kobo
            || $receipt->tender_amount_kobo !== $request->original_amount_kobo || $receipt->tender_amount_kobo < 1
            || $receipt->collection_batch_id !== $proof->collection_batch_id || ! is_array($effects) || $effects === []
            || ! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(), true)
            || $proof->created_at === null || ($proof->facts['occurred_on'] ?? null) !== $proof->created_at->setTimezone($timezone)->toDateString()) {
            throw new ConflictHttpException('The no-money correction does not identify a complete fee-only receipt.');
        }
        $custody = $receipt->replacement_reversal_id === null
            ? app(CollectionReceiptMethod::class)->assertReceipt($receipt) : LedgerAccountCode::UnappliedFunds;
        if ($receipt->replacement_reversal_id !== null) {
            app(CollectionReceiptMethod::class)->assertReceipt($receipt);
            $source = ReversalRequest::query()->whereKey($receipt->replacement_reversal_id)->firstOrFail();
            $sourceSnapshot = $source->getAttribute('dependency_snapshot');
            $sourceReceipt = CollectionReceipt::query()->whereKey(is_array($sourceSnapshot) ? ($sourceSnapshot['summary']['receipt_id'] ?? null) : null)->first();
            if ($sourceReceipt === null || app(CollectionReplacementService::class)->controlledAmount($source, $sourceReceipt) !== $receipt->tender_amount_kobo
                || $sourceReceipt->collection_batch_id !== $receipt->collection_batch_id
                || $sourceReceipt->recording_agent_profile_id !== $receipt->recording_agent_profile_id) {
                throw new ConflictHttpException('The no-money correction replacement source is unavailable.');
            }
        }
        $components = DB::table('collection_fee_components')->where('collection_receipt_id', $receipt->id)->orderBy('id')->get();
        if ((int) $components->sum('amount_kobo') !== $receipt->fee_amount_kobo
            || $components->count() !== count($effects) || $components->first()?->ledger_posting_group_id !== $request->original_posting_group_id
            || FeeObligationEntry::query()->where('source_type', 'receipt_no_money')->where('source_id', 'like', $proof->id.'-%')->count() !== $components->count()) {
            throw new ConflictHttpException('The no-money correction fee component graph is incomplete.');
        }
        foreach ($components as $index => $component) {
            $effect = $effects[$index] ?? null;
            $group = LedgerPostingGroup::query()->whereKey($component->ledger_posting_group_id)->with('entries.account')->firstOrFail();
            $settlement = FeeObligationEntry::query()->whereKey(is_array($effect) ? ($effect['settlement_entry_id'] ?? null) : null)->first();
            $entry = FeeObligationEntry::query()->where('source_type', 'receipt_no_money')
                ->where('source_id', $proof->id.'-'.$component->fee_obligation_id)->first();
            if (! is_array($effect) || ($effect['fee_obligation_id'] ?? null) !== $component->fee_obligation_id
                || ($effect['group_id'] ?? null) !== $group->id || ($effect['amount_kobo'] ?? null) !== (int) $component->amount_kobo
                || ($effect['consumed_external_concession_kobo'] ?? null) !== (int) $component->amount_kobo
                || ($effect['consumed_savings_concession_kobo'] ?? null) !== 0 || ($effect['fee_income_reversal_kobo'] ?? null) !== 0
                || $group->customer_profile_id !== $request->customer_profile_id || $group->source_type !== 'collection_receipt'
                || $group->source_id !== $receipt->id.'-'.$component->fee_obligation_id
                || $settlement?->entry_type !== FeeObligationEntryType::Settlement
                || $settlement->fee_obligation_id !== $component->fee_obligation_id || $settlement->amount_kobo !== (int) $component->amount_kobo
                || $settlement->ledger_posting_reference !== $group->posting_reference
                || $entry?->entry_type !== FeeObligationEntryType::SettlementReversal || $entry->fee_obligation_id !== $component->fee_obligation_id
                || $entry->amount_kobo !== (int) $component->amount_kobo || $entry->ledger_posting_reference !== null
                || $entry->actor_user_id !== $proof->actor_user_id || $entry->currency !== 'NGN') {
                throw new ConflictHttpException('The no-money correction settlement provenance is invalid.');
            }
            if ($group->entries->count() !== 2 || $group->entries->where('side', LedgerEntrySide::Debit)->count() !== 1
                || $group->currency !== 'NGN' || $group->occurred_on === null || $group->business_timezone === null || $group->schema_version < 1
                || $group->event_type !== ($receipt->replacement_reversal_id === null ? 'external_fee_receipt' : 'unapplied_fee_application')
                || $settlement->obligation->customer_profile_id !== $request->customer_profile_id) {
                throw new ConflictHttpException('The no-money correction original fee posting is invalid.');
            }
            foreach ($group->entries as $line) {
                if ($line->account->currency !== 'NGN' || $line->account->code !== ($line->side === LedgerEntrySide::Credit ? LedgerAccountCode::FeeIncome : $custody)
                    || $line->amount_kobo !== (int) $component->amount_kobo || $line->fee_obligation_id !== $component->fee_obligation_id
                    || $line->customer_profile_id !== $request->customer_profile_id
                    || ($line->side === LedgerEntrySide::Debit && $line->agent_profile_id !== ($custody === LedgerAccountCode::AgentReceivable ? $receipt->recording_agent_profile_id : null))) {
                    throw new ConflictHttpException('The no-money correction original fee journal does not reconcile.');
                }
            }
        }
        $watermark = $proof->facts['source_group_watermark'] ?? null;
        if (! is_int($watermark) || $watermark < (int) $components->max('ledger_posting_group_id')
            || ! LedgerPostingGroup::query()->whereKey($watermark)->exists()) {
            throw new ConflictHttpException('The no-money correction ledger cutoff is unavailable.');
        }
        if ($approved) {
            $events = $request->events()->where('event_type', 'approved_no_money')->get();
            $event = $events->first();
            if ($request->posted_original_posting_group_id !== $request->original_posting_group_id
                || $request->live_original_posting_group_id !== null || $request->reviewed_at === null
                || $request->reviewed_by_user_id !== $proof->actor_user_id || $events->count() !== 1
                || ($event?->metadata['no_money_supplement_id'] ?? null) !== $proof->id
                || ($event?->metadata['owner_fingerprint'] ?? null) !== $proof->payload_hash) {
                throw new ConflictHttpException('The no-money correction has no immutable independent approval.');
            }
        }

        return $proof;
    }

    public function consumedConcession(FeeObligationEntry $entry): int
    {
        $parts = explode('-', $entry->source_id, 2);
        $id = filter_var($parts[0], FILTER_VALIDATE_INT);
        $proof = $id === false ? null : FinancialWorkflowSupplement::query()->whereKey($id)->first();
        if ($proof === null || $entry->source_id !== $proof->id.'-'.$entry->fee_obligation_id) {
            throw new ConflictHttpException('The no-money fee correction source is unavailable.');
        }
        $request = ReversalRequest::query()->whereKey($proof->reversal_request_id)->firstOrFail();
        $this->assertOutcome($request);

        return $entry->amount_kobo;
    }
}
