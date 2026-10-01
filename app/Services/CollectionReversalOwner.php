<?php

namespace App\Services;

use App\Enums\FeeObligationEntryType;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Enums\ThriftPlanStatus;
use App\Models\BusinessProfile;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeObligationEntry;
use App\Models\FeeRefund;
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
        if ($receipt === null && $original->event_type === 'external_fee_receipt') {
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
        $custody = $original->entries->where('side', LedgerEntrySide::Debit)->filter(fn (LedgerEntry $entry): bool => $entry->account->code === LedgerAccountCode::AgentReceivable);
        if ($receipt->savings_amount_kobo > 0 && ($original->entries->count() !== 2 || $principal->sum('amount_kobo') !== $receipt->savings_amount_kobo
            || $custody->sum('amount_kobo') !== $receipt->savings_amount_kobo
            || $custody->first()?->agent_profile_id !== $receipt->recording_agent_profile_id)) {
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
        foreach ($feeComponents as $component) {
            $feeGroup = LedgerPostingGroup::query()->whereKey($component->ledger_posting_group_id)->with('entries.account')->firstOrFail();
            $obligation = FeeObligation::query()->whereKey($component->fee_obligation_id)->when($forUpdate, fn ($query) => $query->lockForUpdate())->firstOrFail();
            $settlement = $obligation->entries()->where('entry_type', FeeObligationEntryType::Settlement)->where('ledger_posting_reference', $feeGroup->posting_reference)->sole();
            if ($obligation->customer_profile_id !== $customer->id || $feeGroup->source_type !== 'collection_receipt'
                || $feeGroup->source_id !== $receipt->id.'-'.$obligation->id || $feeGroup->event_type !== 'external_fee_receipt'
                || $feeGroup->entries->count() !== 2 || $settlement->amount_kobo !== (int) $component->amount_kobo
                || FeeRefund::query()->where('fee_obligation_id', $obligation->id)->exists()
                || $obligation->entries()->where('entry_type', FeeObligationEntryType::SettlementReversal)->exists()) {
                throw new ConflictHttpException('The receipt fee graph has an unresolved concession or correction dependency.');
            }
            foreach ($feeGroup->entries as $line) {
                $expected = $line->side === LedgerEntrySide::Debit ? LedgerAccountCode::AgentReceivable : LedgerAccountCode::FeeIncome;
                if ($line->account->code !== $expected || $line->amount_kobo !== (int) $component->amount_kobo
                    || $line->fee_obligation_id !== $obligation->id || $line->customer_profile_id !== $customer->id
                    || ($line->side === LedgerEntrySide::Debit && $line->agent_profile_id !== $receipt->recording_agent_profile_id)) {
                    throw new ConflictHttpException('The original fee tender and responsible Agent do not reconcile.');
                }
            }
            $feeTotal += (int) $component->amount_kobo;
            $fees[] = ['kind' => 'external_fee', 'classification' => 'compensable', 'group_id' => $feeGroup->id,
                'fee_obligation_id' => $obligation->id, 'settlement_entry_id' => $settlement->id, 'amount_kobo' => (int) $component->amount_kobo,
                'income_account_id' => $feeGroup->entries->firstWhere('side', LedgerEntrySide::Credit)->ledger_account_id,
                'history_ids' => $obligation->entries()->pluck('id')->all()];
        }
        if ($feeTotal !== $receipt->fee_amount_kobo || $receipt->savings_amount_kobo + $feeTotal !== $receipt->tender_amount_kobo) {
            throw new ConflictHttpException('The full receipt tender and fee components do not reconcile.');
        }
        if ($feeTotal > 0) {
            $income = app(FinancialCashPosition::class)->balance(LedgerAccountCode::FeeIncome);
            $drawn = (int) DB::table('ledger_entries')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
                ->where('ledger_accounts.code', LedgerAccountCode::BusinessDistributions->value)->where('side', 'debit')->sum('amount_kobo');
            $pendingDraws = (int) DB::table('cash_disbursements')->where('kind', 'earnings_draw')->whereIn('status', ['processing', 'outcome_unknown'])->sum('amount_kobo');
            if ($feeTotal > max(0, max(0, $income - $drawn) - $pendingDraws)) {
                throw new ConflictHttpException('Drawn or encumbered fee earnings require an owned recovery before compensation.');
            }
        }
        $mapping = LedgerAccount::query()->where('code', LedgerAccountCode::UnappliedFunds->value)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->sole();
        if ($mapping->mapping_status !== 'mapped' || $mapping->currency !== 'NGN'
            || $mapping->account_class !== LedgerAccountClass::UnappliedFunds || $mapping->normal_balance !== LedgerEntrySide::Credit) {
            throw new ConflictHttpException('The controlled unapplied-funds destination is unavailable.');
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
            'unapplied_mapping_id' => $mapping->id, 'unapplied_mapping_version' => $mapping->version];

        return ['gross_kobo' => $receipt->tender_amount_kobo, 'summary' => $summary, 'dependencies' => $dependencies,
            'fingerprint' => hash('sha256', json_encode([$original->payload_hash, $summary, $dependencies, $balance,
                LedgerPostingGroup::query()->max('id')], JSON_THROW_ON_ERROR))];
    }

    public function compensate(ReversalRequest $request, array $preview, User $reviewer): LedgerPostingGroup
    {
        $receipt = CollectionReceipt::query()->where('id', $preview['summary']['receipt_id'])->firstOrFail();
        $original = $request->originalPostingGroup;
        $business = BusinessProfile::current();
        $date = now($business->timezone)->toDateString();
        app(FinancialPeriodService::class)->assertOpen($date, $business->timezone, true);
        $group = LedgerPostingGroup::create([
            'posting_reference' => 'REV-'.Str::uuid(), 'idempotency_key' => 'receipt-compensation-'.$request->id,
            'payload_hash' => $preview['fingerprint'], 'source_type' => 'reversal_request', 'source_id' => (string) $request->id,
            'event_type' => 'receipt_reclassification', 'currency' => 'NGN', 'actor_user_id' => $reviewer->id,
            'customer_profile_id' => $receipt->customer_profile_id, 'occurred_at' => now(), 'occurred_on' => $date,
            'business_timezone' => $business->timezone, 'schema_version' => 1, 'correlation_id' => 'reversal-'.$request->id,
            'thrift_plan_id' => $receipt->thrift_plan_id, 'committed_at' => now(),
            'metadata' => ['original_posting_group_id' => $original->id, 'custody_disposition' => 'received_and_controlled'],
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
            LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $number++, 'ledger_account_id' => $dependency['income_account_id'],
                'side' => LedgerEntrySide::Debit, 'amount_kobo' => $dependency['amount_kobo'], 'customer_profile_id' => $receipt->customer_profile_id, 'fee_obligation_id' => $dependency['fee_obligation_id']]);
            FeeObligationEntry::create(['fee_obligation_id' => $dependency['fee_obligation_id'], 'entry_type' => FeeObligationEntryType::SettlementReversal,
                'amount_kobo' => $dependency['amount_kobo'], 'currency' => 'NGN', 'source_type' => 'receipt_compensation', 'source_id' => (string) $request->id,
                'idempotency_key' => 'receipt-compensation-'.$request->id.'-fee-'.$dependency['settlement_entry_id'], 'actor_user_id' => $reviewer->id,
                'reason' => $request->internal_reason, 'customer_description' => $request->customer_explanation, 'ledger_posting_reference' => $group->posting_reference]);
        }
        LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $number, 'ledger_account_id' => $preview['summary']['unapplied_mapping_id'],
            'side' => LedgerEntrySide::Credit, 'amount_kobo' => $receipt->tender_amount_kobo, 'customer_profile_id' => $receipt->customer_profile_id, 'agent_profile_id' => $receipt->recording_agent_profile_id]);
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
            'facts' => ['original_receipt_id' => $receipt->id, 'controlled_kobo' => $receipt->tender_amount_kobo], 'evidence' => $request->internal_reason, 'created_at' => now()]);
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
