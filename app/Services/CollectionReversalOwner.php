<?php

namespace App\Services;

use App\Enums\FeeObligationEntryType;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Enums\ThriftPlanStatus;
use App\Models\BusinessProfile;
use App\Models\CollectionBatch;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeObligationEntry;
use App\Models\FinancialWorkflowSupplement;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\PlanLifecycleEvent;
use App\Models\ReversalRequest;
use App\Models\ThriftPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CollectionReversalOwner implements ReversalOwnerContract
{
    public function preview(LedgerPostingGroup $original, CustomerProfile $customer, bool $forUpdate): array
    {
        $receipt = CollectionReceipt::query()->where('savings_posting_group_id', $original->id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
        if ($receipt === null && in_array($original->event_type, ['external_fee_receipt', 'unapplied_fee_application'], true)) {
            $receiptId = DB::table('collection_fee_components')->where('ledger_posting_group_id', $original->id)->value('collection_receipt_id');
            $receipt = CollectionReceipt::query()->whereKey($receiptId)->whereNull('savings_posting_group_id')
                ->where('savings_amount_kobo', 0)->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
            if ($receipt !== null && (int) DB::table('collection_fee_components')->where('collection_receipt_id', $receipt->id)
                ->orderBy('id')->value('ledger_posting_group_id') !== $original->id) {
                throw new ConflictHttpException('Review the complete fee-only receipt from its first posting group.');
            }
        }
        if ($receipt === null || $receipt->customer_profile_id !== $customer->id
            || ($receipt->savings_amount_kobo > 0 && $original->source_id !== (string) $receipt->id)) {
            throw new ConflictHttpException('The complete authoritative receipt is unavailable.');
        }
        $replacement = $receipt->replacement_reversal_id !== null;
        if ($replacement) {
            $source = ReversalRequest::query()->whereKey($receipt->replacement_reversal_id)
                ->when($forUpdate, fn ($query) => $query->lockForUpdate())->firstOrFail();
            $sourceDependencies = $source->getAttribute('dependency_snapshot');
            if (! is_array($sourceDependencies) || ! is_array($sourceDependencies['summary'] ?? null)) {
                throw new ConflictHttpException('The original controlled-funds dependency snapshot is unavailable.');
            }
            $sourceReceiptId = $sourceDependencies['summary']['receipt_id'] ?? null;
            $sourceReceipt = CollectionReceipt::query()->whereKey($sourceReceiptId)->first();
            $sourceGroup = LedgerPostingGroup::query()->find($source->compensation_posting_group_id);
            if ($source->state !== 'approved_posted' || $source->customer_profile_id !== $customer->id
                || $sourceGroup?->event_type !== 'receipt_reclassification'
                || $sourceGroup->source_type !== 'reversal_request' || $sourceGroup->source_id !== (string) $source->id
                || $sourceReceipt === null || $sourceReceipt->customer_profile_id !== $customer->id
                || app(CollectionReplacementService::class)->controlledAmount($source, $sourceReceipt) !== $receipt->tender_amount_kobo
                || $sourceReceipt->collection_batch_id !== $receipt->collection_batch_id
                || $sourceReceipt->recording_agent_profile_id !== $receipt->recording_agent_profile_id) {
                throw new ConflictHttpException('The original controlled-funds correction is unavailable.');
            }
        }
        $batch = DB::table('collection_batches')->where('id', $receipt->collection_batch_id)->first();
        if ($batch === null || DB::table('collection_exceptions')->where('collection_batch_id', $receipt->collection_batch_id)->where('status', '!=', 'resolved')->exists()) {
            throw new ConflictHttpException('Resolve the original custody exception before asserting the full tender remains controlled.');
        }
        $plan = $receipt->thrift_plan_id === null ? null : ThriftPlan::query()->whereKey($receipt->thrift_plan_id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $feeEffect = $plan === null ? null : app(PlanFeeCorrectionService::class)->preview($plan, $receipt);
        $allocations = $receipt->allocations()->orderBy('id')->when($forUpdate, fn ($query) => $query->lockForUpdate())->get();
        if ($allocations->sum('amount_kobo') !== $receipt->savings_amount_kobo
            || DB::table('collection_allocation_releases')->whereIn('collection_allocation_id', $allocations->pluck('id'))->exists()) {
            throw new ConflictHttpException('The original allocation graph is incomplete or already released.');
        }
        $slotIds = $allocations->pluck('contribution_slot_id')->unique();
        $verifiedOriginalTerms = DB::table('contribution_slots as slots')
            ->join('plan_terms_revisions as terms', 'terms.id', '=', 'slots.plan_terms_revision_id')
            ->join('fee_snapshots as snapshots', 'snapshots.id', '=', 'terms.fee_snapshot_id')
            ->whereIn('slots.id', $slotIds)->where('slots.thrift_plan_id', $plan?->id)
            ->whereColumn('terms.thrift_plan_id', 'slots.thrift_plan_id')
            ->where('snapshots.customer_profile_id', $customer->id)->count();
        if ($verifiedOriginalTerms !== $slotIds->count()) {
            throw new ConflictHttpException('Historical allocation fee terms require their own dependent compensation contract.');
        }
        $original->load('entries.account');
        $principal = $original->entries->where('side', LedgerEntrySide::Credit)->filter(fn (LedgerEntry $entry): bool => $entry->account->code === LedgerAccountCode::CustomerSavingsLiability);
        $physicalCustody = app(CollectionReceiptMethod::class)->assertReceipt($receipt);
        $custodyCode = $replacement ? LedgerAccountCode::UnappliedFunds : $physicalCustody;
        $custodyAgentId = $custodyCode === LedgerAccountCode::AgentReceivable ? $receipt->recording_agent_profile_id : null;
        $custody = $original->entries->where('side', LedgerEntrySide::Debit)->filter(fn (LedgerEntry $entry): bool => $entry->account->code === $custodyCode);
        if ($receipt->savings_amount_kobo > 0 && ($original->entries->count() !== 2 || $principal->sum('amount_kobo') !== $receipt->savings_amount_kobo
            || $original->event_type !== ($replacement ? 'unapplied_replacement' : match ($receipt->method) {
                'cash' => 'cash_contribution', 'wallet' => 'wallet_contribution', default => 'noncash_contribution',
            })
            || $custody->sum('amount_kobo') !== $receipt->savings_amount_kobo
            || $custody->first()?->agent_profile_id !== $custodyAgentId)) {
            throw new ConflictHttpException('Receipt principal and original custodian do not reconcile.');
        }
        $balance = $plan === null ? app(CollectionReadService::class)->position($customer, $forUpdate)
            : app(WithdrawalBalanceService::class)->position($customer, $plan, $forUpdate);
        if ($receipt->savings_amount_kobo > 0 && ($balance['cycle_available_kobo'] ?? 0) < max(0, $receipt->savings_amount_kobo - ($feeEffect['savings_refund_kobo'] ?? 0))) {
            throw new ConflictHttpException('Resolve dependent reservations or payouts before removing this receipt.');
        }
        $feeComponents = DB::table('collection_fee_components')->where('collection_receipt_id', $receipt->id)->orderBy('id')->get();
        $fees = [];
        $feeTotal = 0;
        $feeIncomeReversal = ($feeEffect['savings_refund_kobo'] ?? 0) + ($feeEffect['external_refund_kobo'] ?? 0);
        $consumedExternal = 0;
        foreach ($feeComponents as $component) {
            $feeGroup = LedgerPostingGroup::query()->whereKey($component->ledger_posting_group_id)->with('entries.account')->firstOrFail();
            $obligation = FeeObligation::query()->whereKey($component->fee_obligation_id)->when($forUpdate, fn ($query) => $query->lockForUpdate())->firstOrFail();
            $settlement = $obligation->entries()->where('entry_type', FeeObligationEntryType::Settlement)->where('ledger_posting_reference', $feeGroup->posting_reference)->sole();
            if ($obligation->customer_profile_id !== $customer->id || $feeGroup->source_type !== 'collection_receipt'
                || $feeGroup->source_id !== $receipt->id.'-'.$obligation->id
                || $feeGroup->event_type !== ($replacement ? 'unapplied_fee_application' : 'external_fee_receipt')
                || $feeGroup->entries->count() !== 2 || $settlement->amount_kobo !== (int) $component->amount_kobo
                || ReversalRequest::query()->where('original_posting_group_id', $original->id)->whereIn('state', ['approved_posted', 'approved_no_money'])->exists()) {
                throw new ConflictHttpException('The receipt fee graph has an unresolved concession or correction dependency.');
            }
            foreach ($feeGroup->entries as $line) {
                $expected = $line->side === LedgerEntrySide::Debit ? $custodyCode : LedgerAccountCode::FeeIncome;
                if ($line->account->code !== $expected || $line->amount_kobo !== (int) $component->amount_kobo
                    || $line->fee_obligation_id !== $obligation->id || $line->customer_profile_id !== $customer->id
                    || ($line->side === LedgerEntrySide::Debit && $line->agent_profile_id !== $custodyAgentId)) {
                    throw new ConflictHttpException('The original fee tender and responsible Agent do not reconcile.');
                }
            }
            $retained = app(FeeConcessionPosition::class)->retainedSources($obligation, forUpdate: $forUpdate);
            $concessions = app(FeeConcessionPosition::class)->read($obligation, forUpdate: $forUpdate);
            $consumed = max(0, (int) $component->amount_kobo - $retained['external_kobo']);
            if ($consumed > $concessions['external_kobo']) {
                throw new ConflictHttpException('The receipt fee correction exceeds its original external settlement and concession.');
            }
            $feeIncomeReversal += (int) $component->amount_kobo - $consumed;
            $consumedExternal += $consumed;
            $feeTotal += (int) $component->amount_kobo;
            $fees[] = ['kind' => 'external_fee', 'classification' => 'compensable', 'group_id' => $feeGroup->id,
                'fee_obligation_id' => $obligation->id, 'settlement_entry_id' => $settlement->id, 'amount_kobo' => (int) $component->amount_kobo,
                'retained_external_kobo' => $retained['external_kobo'],
                'consumed_external_concession_kobo' => $consumed, 'consumed_savings_concession_kobo' => 0,
                'fee_income_reversal_kobo' => (int) $component->amount_kobo - $consumed,
                'income_account_id' => $feeGroup->entries->firstWhere('side', LedgerEntrySide::Credit)->ledger_account_id,
                'history_ids' => $obligation->entries()->pluck('id')->all()];
        }
        if ($feeTotal !== $receipt->fee_amount_kobo || $receipt->savings_amount_kobo + $feeTotal !== $receipt->tender_amount_kobo) {
            throw new ConflictHttpException('The full receipt tender and fee components do not reconcile.');
        }
        if ($feeIncomeReversal > 0) {
            if ($feeIncomeReversal > app(FinancialCashPosition::class)->undrawnEarningsKobo(forUpdate: $forUpdate)) {
                throw new ConflictHttpException('Drawn or encumbered fee earnings require an owned recovery before compensation.');
            }
        }
        $controlled = $receipt->tender_amount_kobo - $consumedExternal;
        if ($controlled < 0 || ($controlled === 0 && ($receipt->savings_amount_kobo !== 0 || $plan !== null || $feeIncomeReversal !== 0))) {
            throw new ConflictHttpException('The no-money correction requires a fully conceded fee-only receipt.');
        }
        // Wallet-funded tender returns to the Customer wallet; physical tender is held as unapplied funds for a replacement receipt.
        $toWallet = $receipt->method === 'wallet';
        [$destinationCode, $destinationClass] = $toWallet
            ? [LedgerAccountCode::CustomerWalletLiability, LedgerAccountClass::CustomerWalletLiability]
            : [LedgerAccountCode::UnappliedFunds, LedgerAccountClass::UnappliedFunds];
        $mapping = $controlled === 0 ? null : LedgerAccount::query()->where('code', $destinationCode->value)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->sole();
        if ($mapping !== null && ($mapping->mapping_status !== 'mapped' || $mapping->currency !== 'NGN'
            || $mapping->account_class !== $destinationClass || $mapping->normal_balance !== LedgerEntrySide::Credit)) {
            throw new ConflictHttpException('The controlled refund destination is unavailable.');
        }
        $dependencies = [
            ...$fees,
            ...($feeEffect === null ? [] : [$feeEffect]),
            ['kind' => 'allocations', 'classification' => 'compensable', 'ids' => $allocations->pluck('id')->all()],
            ['kind' => 'custody', 'classification' => 'retained', 'agent_profile_id' => $receipt->recording_agent_profile_id,
                'batch_id' => $receipt->collection_batch_id, 'batch_version' => $batch->version, 'batch_status' => $batch->status, 'amount_kobo' => $receipt->tender_amount_kobo],
            ...($plan === null ? [] : [['kind' => 'plan', 'classification' => 'compensable', 'id' => $plan->id, 'version' => $plan->version, 'status' => $plan->status->value]]),
        ];
        $summary = ['receipt_id' => $receipt->id, 'plan_id' => $plan?->id, 'custody_disposition' => 'received_and_controlled',
            'controlled_kobo' => $controlled, 'consumed_external_concession_kobo' => $consumedExternal,
            'no_money' => $controlled === 0, 'unapplied_mapping_id' => $mapping?->id, 'unapplied_mapping_version' => $mapping?->version,
            'controlled_destination' => $toWallet ? 'wallet' : 'unapplied'];

        return ['gross_kobo' => $receipt->tender_amount_kobo, 'summary' => $summary, 'dependencies' => $dependencies,
            'fingerprint' => hash('sha256', json_encode([$original->payload_hash, $summary, $dependencies, $balance,
                app(ReversalWatermark::class)->forCustomer($original->customer_profile_id)], JSON_THROW_ON_ERROR))];
    }

    /**
     * Whether the Agent may declare that only part of this receipt's cash was collected.
     *
     * @param  array{fingerprint: string, gross_kobo: int, summary: array<string, mixed>, dependencies: list<array<string, mixed>>}  $preview
     */
    public function supportsReceivedAmount(array $preview): bool
    {
        try {
            $this->partlyReceivedSource($preview, false);
        } catch (ConflictHttpException) {
            return false;
        }

        return true;
    }

    /**
     * Split a cash receipt reversal into the cash actually collected, which stays held as unapplied funds for a
     * replacement receipt, and the uncollected rest, which comes off the recording Agent's cash responsibility.
     *
     * @param  array{fingerprint: string, gross_kobo: int, summary: array<string, mixed>, dependencies: list<array<string, mixed>>}  $preview
     * @return array{fingerprint: string, gross_kobo: int, summary: array<string, mixed>, dependencies: list<array<string, mixed>>}
     */
    public function withReceivedAmount(array $preview, int $receivedKobo, bool $forUpdate): array
    {
        [$receipt, $batch] = $this->partlyReceivedSource($preview, $forUpdate);
        if ($receivedKobo < 1 || $receivedKobo >= $receipt->tender_amount_kobo) {
            throw new ConflictHttpException('The collected amount must be more than zero and less than the recorded receipt.');
        }
        $uncollected = $receipt->tender_amount_kobo - $receivedKobo;
        $position = app(CollectionBatchPosition::class)->read($batch);
        if ($position['received_kobo'] > $position['expected_kobo'] - $uncollected) {
            throw new ConflictHttpException('The Agent already handed over more cash than this correction leaves in the batch. Record the difference through reconciliation.');
        }
        $account = LedgerAccount::query()->where('code', LedgerAccountCode::AgentReceivable->value)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->sole();
        if ($account->mapping_status !== 'mapped' || $account->currency !== 'NGN'
            || $account->account_class !== LedgerAccountClass::AgentReceivable || $account->normal_balance !== LedgerEntrySide::Debit) {
            throw new ConflictHttpException('The Agent cash responsibility account is unavailable.');
        }
        $summary = [...$preview['summary'], 'custody_disposition' => 'partly_received', 'controlled_kobo' => $receivedKobo,
            'uncollected_kobo' => $uncollected, 'uncollected_account_id' => $account->id,
            'uncollected_agent_profile_id' => $receipt->recording_agent_profile_id];
        $dependencies = array_map(fn (array $dependency): array => $dependency['kind'] === 'custody'
            ? [...$dependency, 'uncollected_kobo' => $uncollected, 'remitted_kobo' => $position['received_kobo']] : $dependency, $preview['dependencies']);

        return ['gross_kobo' => $preview['gross_kobo'], 'summary' => $summary, 'dependencies' => $dependencies,
            'fingerprint' => hash('sha256', json_encode([$preview['fingerprint'], $receivedKobo, $summary, $position, $batch->version], JSON_THROW_ON_ERROR))];
    }

    /**
     * @param  array{fingerprint: string, gross_kobo: int, summary: array<string, mixed>, dependencies: list<array<string, mixed>>}  $preview
     * @return array{0: CollectionReceipt, 1: CollectionBatch}
     */
    private function partlyReceivedSource(array $preview, bool $forUpdate): array
    {
        $receipt = CollectionReceipt::query()->whereKey($preview['summary']['receipt_id'] ?? null)->first();
        if ($receipt === null || $receipt->method !== 'cash' || $receipt->custody_account_code !== LedgerAccountCode::AgentReceivable->value
            || $receipt->replacement_reversal_id !== null || $receipt->savings_posting_group_id === null
            || ($preview['summary']['custody_disposition'] ?? null) !== 'received_and_controlled'
            || ($preview['summary']['no_money'] ?? true) !== false || ($preview['summary']['consumed_external_concession_kobo'] ?? null) !== 0
            || ($preview['summary']['controlled_kobo'] ?? null) !== $receipt->tender_amount_kobo) {
            throw new ConflictHttpException('Only a full cash receipt can be corrected to a smaller collected amount.');
        }
        $batch = CollectionBatch::query()->whereKey($receipt->collection_batch_id)->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
        if ($batch === null || $batch->custody_account_code !== LedgerAccountCode::AgentReceivable->value || $batch->status === 'reconciled'
            || DB::table('collection_exceptions')->where('collection_batch_id', $batch->id)->exists()) {
            throw new ConflictHttpException('The receipt batch is already reconciled or has a reconciliation case. Record the difference through reconciliation.');
        }

        return [$receipt, $batch];
    }

    public function compensate(ReversalRequest $request, array $preview, User $reviewer): LedgerPostingGroup|FinancialWorkflowSupplement
    {
        $receipt = CollectionReceipt::query()->where('id', $preview['summary']['receipt_id'])->firstOrFail();
        $original = $request->originalPostingGroup;
        $business = BusinessProfile::current();
        $date = now($business->timezone)->toDateString();
        app(FinancialPeriodService::class)->assertOpen($date, $business->timezone, true);
        if ($preview['summary']['controlled_kobo'] === 0) {
            return app(CollectionNoMoneyCorrection::class)->record($request, $preview, $reviewer);
        }
        $uncollected = (int) ($preview['summary']['uncollected_kobo'] ?? 0);
        if ($uncollected !== 0 && ($request->received_amount_kobo !== $preview['summary']['controlled_kobo']
            || $preview['summary']['controlled_kobo'] + $uncollected !== $receipt->tender_amount_kobo)) {
            throw new ConflictHttpException('The collected amount and the correction do not reconcile.');
        }
        $group = LedgerPostingGroup::create([
            'posting_reference' => 'REV-'.Str::uuid(), 'idempotency_key' => 'receipt-compensation-'.$request->id,
            'payload_hash' => $preview['fingerprint'], 'source_type' => 'reversal_request', 'source_id' => (string) $request->id,
            'event_type' => 'receipt_reclassification', 'currency' => 'NGN', 'actor_user_id' => $reviewer->id, 'approver_user_id' => $reviewer->id,
            'customer_profile_id' => $receipt->customer_profile_id, 'occurred_at' => now(), 'occurred_on' => $date,
            'business_timezone' => $business->timezone, 'schema_version' => 1, 'correlation_id' => 'reversal-'.$request->id,
            'thrift_plan_id' => $receipt->thrift_plan_id, 'committed_at' => now(),
            'metadata' => ['original_posting_group_id' => $original->id, 'custody_disposition' => $preview['summary']['custody_disposition'],
                'receipt_id' => $receipt->id, 'controlled_kobo' => $preview['summary']['controlled_kobo'],
                'uncollected_kobo' => $uncollected,
                'consumed_external_concession_kobo' => $preview['summary']['consumed_external_concession_kobo'],
                'external_fee_effects' => collect($preview['dependencies'])->where('kind', 'external_fee')->values()->all(),
                'plan_fee_effect' => collect($preview['dependencies'])->firstWhere('kind', 'plan_fee') ?? []],
        ]);
        $number = 1;
        if ($receipt->savings_amount_kobo > 0) {
            $liability = $original->entries()->where('side', LedgerEntrySide::Credit)->sole();
            LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $number++, 'ledger_account_id' => $liability->ledger_account_id,
                'side' => LedgerEntrySide::Debit, 'amount_kobo' => $receipt->savings_amount_kobo, 'customer_profile_id' => $receipt->customer_profile_id, 'thrift_plan_id' => $receipt->thrift_plan_id]);
        }
        foreach ($preview['dependencies'] as $dependency) {
            if ($dependency['kind'] !== 'external_fee') {
                continue;
            }
            if ($dependency['fee_income_reversal_kobo'] > 0) {
                LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $number++, 'ledger_account_id' => $dependency['income_account_id'],
                    'side' => LedgerEntrySide::Debit, 'amount_kobo' => $dependency['fee_income_reversal_kobo'], 'customer_profile_id' => $receipt->customer_profile_id, 'fee_obligation_id' => $dependency['fee_obligation_id']]);
            }
            FeeObligationEntry::create(['fee_obligation_id' => $dependency['fee_obligation_id'], 'entry_type' => FeeObligationEntryType::SettlementReversal,
                'amount_kobo' => $dependency['amount_kobo'], 'currency' => 'NGN', 'source_type' => 'receipt_compensation', 'source_id' => (string) $request->id,
                'idempotency_key' => 'receipt-compensation-'.$request->id.'-fee-'.$dependency['settlement_entry_id'], 'actor_user_id' => $reviewer->id,
                'reason' => $request->internal_reason, 'customer_description' => $request->customer_explanation, 'ledger_posting_reference' => $group->posting_reference]);
        }
        LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $number++, 'ledger_account_id' => $preview['summary']['unapplied_mapping_id'],
            'side' => LedgerEntrySide::Credit, 'amount_kobo' => $preview['summary']['controlled_kobo'], 'customer_profile_id' => $receipt->customer_profile_id,
            'agent_profile_id' => ($preview['summary']['controlled_destination'] ?? 'unapplied') === 'wallet' ? null : $receipt->recording_agent_profile_id]);
        if ($uncollected > 0) {
            LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $number, 'ledger_account_id' => $preview['summary']['uncollected_account_id'],
                'side' => LedgerEntrySide::Credit, 'amount_kobo' => $uncollected, 'customer_profile_id' => $receipt->customer_profile_id,
                'agent_profile_id' => $preview['summary']['uncollected_agent_profile_id']]);
        }
        foreach ($receipt->allocations as $allocation) {
            DB::table('collection_allocation_releases')->insert(['collection_allocation_id' => $allocation->id,
                'reversal_request_id' => $request->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($preview['dependencies'] as $effect) {
            if ($effect['kind'] === 'plan_fee') {
                app(PlanFeeCorrectionService::class)->compensate($effect, $group, $reviewer);
            }
        }
        FinancialWorkflowSupplement::create(['operation_reference' => (string) Str::uuid(), 'payload_hash' => $preview['fingerprint'],
            'kind' => 'receipt_compensated', 'customer_profile_id' => $receipt->customer_profile_id, 'thrift_plan_id' => $receipt->thrift_plan_id,
            'collection_batch_id' => $receipt->collection_batch_id, 'reversal_request_id' => $request->id, 'actor_user_id' => $reviewer->id,
            'facts' => ['original_receipt_id' => $receipt->id, 'controlled_kobo' => $preview['summary']['controlled_kobo'], 'uncollected_kobo' => $uncollected,
                'consumed_external_concession_kobo' => $preview['summary']['consumed_external_concession_kobo']], 'evidence' => $request->internal_reason, 'created_at' => now()]);
        if ($receipt->thrift_plan_id === null) {
            return $group;
        }
        $plan = ThriftPlan::query()->findOrFail($receipt->thrift_plan_id);
        $prior = $plan->status;
        if ($prior === ThriftPlanStatus::Completed) {
            $plan->update(['status' => ThriftPlanStatus::Paused, 'version' => $plan->version + 1]);
        }
        PlanLifecycleEvent::create(['thrift_plan_id' => $plan->id, 'event_type' => 'receipt_compensated',
            'from_status' => $prior, 'to_status' => $plan->status, 'actor_user_id' => $reviewer->id,
            'plan_version' => $plan->version, 'reason' => $request->internal_reason,
            'customer_explanation' => $request->customer_explanation, 'payload' => ['reversal_request_id' => $request->id,
                'closed_plan_exception' => $prior === ThriftPlanStatus::Closed], 'effective_at' => now()]);

        return $group;
    }
}
