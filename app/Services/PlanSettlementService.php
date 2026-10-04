<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\FeeObligationEntryType;
use App\Enums\FeeRuleTiming;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Enums\ThriftPlanStatus;
use App\Models\AuditEvent;
use App\Models\BankPayoutAttempt;
use App\Models\BankPayoutReturn;
use App\Models\CashExecution;
use App\Models\CashRecovery;
use App\Models\ChargeCategoryVersion;
use App\Models\CollectionBatch;
use App\Models\CollectionReceipt;
use App\Models\FeeObligation;
use App\Models\FeeSnapshot;
use App\Models\FinancialWorkflowSupplement;
use App\Models\LedgerPostingGroup;
use App\Models\ManualCharge;
use App\Models\PlanLifecycleEvent;
use App\Models\PlanTermsRevision;
use App\Models\ReversalRequest;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use ValueError;

class PlanSettlementService
{
    /** @return array<string, mixed> */
    public function preview(User $actor, ThriftPlan $plan, string $action = 'close'): array
    {
        Gate::forUser($actor)->authorize('managePlan', $plan->customerProfile);
        abort_unless(config('collections.settlement_enabled', false), 503, 'Plan settlement acceptance is not yet certified.');
        app(BusinessSettings::class)->ensureFeature('plan_creation');
        $customer = $plan->customerProfile;
        $position = app(WithdrawalBalanceService::class)->position($customer, $plan, DB::transactionLevel() > 0);
        $blockers = [];
        $forUpdate = DB::transactionLevel() > 0;
        $terms = $plan->termsRevisions()->when($forUpdate, fn ($query) => $query->lockForUpdate())->get();
        $obligations = new Collection;
        try {
            $obligations = $this->cycleObligations($plan, $terms, $forUpdate);
        } catch (RuntimeException|ConflictHttpException|ValueError) {
            $blockers[] = 'Financial obligation attribution is unavailable.';
        }
        foreach ($obligations as $obligation) {
            if ($obligation->outstandingAmountKobo() > 0) {
                $blockers[] = 'Outstanding cycle fee '.$obligation->id;
            }
        }
        $currentTerms = $terms->firstWhere('revision', $plan->current_terms_revision);
        $snapshot = $currentTerms === null ? null : FeeSnapshot::query()->whereKey($currentTerms->fee_snapshot_id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
        if ($snapshot !== null) {
            $snapshot->setRelation('obligation', $obligations->firstWhere('fee_snapshot_id', $snapshot->id));
        }
        abort_if($action === 'prepare_termination' && ($snapshot === null
            || (! $snapshot->isZero() && ! app(EarlyTerminationPolicy::class)->supports($snapshot))),
            503, 'Approved early termination fee policy is unavailable.');
        if (! $this->hasVerifiedFeeAgreements($plan, $terms, $forUpdate)
            || $snapshot === null || (! $snapshot->isZero() && $snapshot->timing === FeeRuleTiming::CycleCompletion && $snapshot->obligation === null)) {
            $blockers[] = 'Cycle fee disposition is unavailable.';
        }
        if ($position['cycle_liability_kobo'] !== 0 || $position['cycle_reservations_kobo'] !== 0) {
            $blockers[] = 'Cycle savings or reservations remain.';
        }
        $unappliedIds = DB::table('reversal_requests as r')->join('ledger_posting_groups as g', 'g.id', '=', 'r.compensation_posting_group_id')
            ->where('g.thrift_plan_id', $plan->id)->where('g.event_type', 'receipt_reclassification')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('collection_receipts as replacements')->whereColumn('replacements.replacement_reversal_id', 'r.id'))
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->pluck('r.id')->all();
        if ($unappliedIds !== []) {
            $blockers[] = 'Controlled unapplied cycle tender remains.';
        }
        $batchIds = CollectionReceipt::query()->where('thrift_plan_id', $plan->id)->whereNull('replacement_reversal_id')
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->pluck('collection_batch_id');
        try {
            $batchIds = $batchIds->merge($this->feeReceiptBatchIds($plan, $obligations, $forUpdate))->unique();
        } catch (ConflictHttpException|RuntimeException|ValueError) {
            $blockers[] = 'Cycle fee receipt custody attribution is unavailable.';
        }
        $custody = [];
        foreach (CollectionBatch::query()->whereIn('id', $batchIds)->orderBy('id')
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get() as $batch) {
            $batchPosition = app(CollectionBatchPosition::class)->read($batch);
            app(CollectionExceptionResolution::class)->assertResolvedCases($batch);
            $review = DB::table('collection_batch_reviews')->where('collection_batch_id', $batch->id)->latest('id')
                ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
            $custody[] = ['batch_id' => $batch->id, 'version' => $batch->version, 'status' => $batch->status,
                'review_id' => $review?->id, 'position' => $batchPosition];
            if ($batch->status !== 'reconciled' || $batchPosition['outstanding_kobo'] !== 0 || $batchPosition['settlement_pending']
                || $review === null || $review->outcome !== 'reconciled' || (int) $review->outstanding_kobo !== 0
                || (int) $review->expected_kobo !== $batchPosition['expected_kobo'] || (int) $review->remitted_kobo !== $batchPosition['received_kobo']
                || DB::table('collection_exceptions')->where('collection_batch_id', $batch->id)->where('status', '!=', 'resolved')->exists()) {
                $blockers[] = 'Original custody and reconciliation remain unresolved.';
            }
        }
        $ownerStates = $this->financialOwnerStates($plan, $forUpdate);
        if ($ownerStates['pending']) {
            $blockers[] = 'Pending financial owner work remains.';
        }
        $payoutReturns = $this->payoutReturnDisposition($plan, $forUpdate);
        if ($payoutReturns['unsettled']) {
            $blockers[] = 'Cycle payout return disposition remains unresolved.';
        }
        if ($this->hasRefundPayable($plan, $obligations, $forUpdate)) {
            $blockers[] = 'Cycle refund payable remains.';
        }
        $resolvedIds = FinancialWorkflowSupplement::query()->where('thrift_plan_id', $plan->id)->where('kind', 'closed_exception_resolved')->get()->pluck('facts.event_id')->all();
        $exceptions = $plan->lifecycleEvents()->where('event_type', 'receipt_compensated')->where('payload->closed_plan_exception', true)->whereNotIn('id', $resolvedIds)->pluck('id')->all();
        if ($action !== 'resolve_exception' && $exceptions !== []) {
            $blockers[] = 'Post-closure correction exception remains.';
        }
        $preparationBlockers = [];
        $terminationFee = null;
        if ($action === 'prepare_termination') {
            if (! in_array($plan->status, [ThriftPlanStatus::Active, ThriftPlanStatus::Paused], true)) {
                $preparationBlockers[] = 'Only an Active or Paused cycle may prepare early termination.';
            }
            if (! $this->hasVerifiedFeeAgreements($plan, $terms, $forUpdate)
                || in_array('Financial obligation attribution is unavailable.', $blockers, true)) {
                $preparationBlockers[] = 'Cycle fee disposition is unavailable.';
            }
            try {
                $effect = app(PlanFeeCorrectionService::class)->preview($plan, terminating: true);
                $obligation = $snapshot->obligation;
                $assessed = $obligation?->assessedAmountKobo() ?? 0;
                $settled = $obligation?->settledAmountKobo() ?? 0;
                $waived = $obligation?->waivedAmountKobo() ?? 0;
                $terminationFee = [...$effect,
                    'policy_version' => $snapshot->early_termination_policy_version,
                    'description' => $snapshot->early_termination_description,
                    'assessment_delta_kobo' => $effect['target_kobo'] - $assessed,
                    'unpaid_after_preparation_kobo' => max(0, $effect['target_kobo'] - $settled - $waived),
                    'insufficient_savings' => max(0, $effect['target_kobo'] - $settled - $waived) > $position['cycle_available_kobo'],
                ];
                if ($snapshot->timing === FeeRuleTiming::FirstContribution && $effect['target_kobo'] > 0 && $obligation === null) {
                    $preparationBlockers[] = 'First-contribution fee disposition is unavailable.';
                }
                if ($effect['refund_kobo'] > 0) {
                    $preparationBlockers[] = 'Paid fee correction must be reviewed before preparing settlement.';
                }
                if ($effect['principal_kobo'] === 0 && ! $snapshot->isZero()
                    && CollectionReceipt::query()->where('thrift_plan_id', $plan->id)->exists()
                    && ($obligation === null || $effect['target_kobo'] !== $assessed || $obligation->outstandingAmountKobo() !== 0)) {
                    $preparationBlockers[] = 'Fully reversed contributions require an explicit reviewed fee disposition.';
                }
                if ($customer->operational_status === CustomerStatus::Restricted
                    && ($effect['target_kobo'] !== $assessed || $effect['refund_kobo'] !== 0)) {
                    $preparationBlockers[] = 'Restricted early termination must have zero financial effect.';
                }
            } catch (RuntimeException|ConflictHttpException|ValueError) {
                $preparationBlockers[] = 'Early termination fee disposition is unavailable.';
            }
            if ($ownerStates['pending']) {
                $preparationBlockers[] = 'Pending financial owner work remains.';
            }
            if ($customer->operational_status === CustomerStatus::Restricted && $blockers !== []) {
                $preparationBlockers[] = 'Restricted early termination requires all zero-financial settlement gates.';
            }
        }
        $facts = ['plan_version' => $plan->version, 'customer_version' => $customer->version,
            'assignment_version' => $customer->currentAssignment?->version, 'position' => $position,
            'fee_history' => $obligations->map(fn ($fee): array => [$fee->id, $fee->entries()->pluck('id')->all()])->all(),
            'financial_owner_fingerprint' => AuditProjection::digest($ownerStates),
            'unapplied_fingerprint' => AuditProjection::digest(['ids' => $unappliedIds]),
            'payout_return_fingerprint' => AuditProjection::digest($payoutReturns),
            'custody_fingerprint' => AuditProjection::digest(['batches' => $custody]), 'exceptions' => $exceptions, 'blockers' => array_values(array_unique($blockers)),
            'termination_fee' => $terminationFee, 'preparation_blockers' => array_values(array_unique($preparationBlockers)),
            'can_prepare' => $action === 'prepare_termination' && $preparationBlockers === [],
            'ledger_watermark' => DB::table('ledger_posting_groups')->max('id'), 'supplement_watermark' => FinancialWorkflowSupplement::query()->max('id')];

        return [...$facts, 'action' => $action, 'can_close' => $blockers === [], 'preview_fingerprint' => hash('sha256', json_encode([$plan->id, $action, $facts], JSON_THROW_ON_ERROR))];
    }

    /** @return array<string, mixed> */
    private function financialOwnerStates(ThriftPlan $plan, bool $forUpdate): array
    {
        $withdrawals = WithdrawalRequest::query()->where('thrift_plan_id', $plan->id)->orderBy('id')
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get(['id', 'state', 'version']);
        $corrections = ReversalRequest::query()->where('customer_profile_id', $plan->customer_profile_id)->orderBy('id')
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get(['id', 'state', 'version']);
        $disbursements = DB::table('cash_disbursements')->where('customer_profile_id', $plan->customer_profile_id)->orderBy('id')
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get(['id', 'status']);

        return ['pending' => $withdrawals->contains(fn ($withdrawal): bool => ! in_array($withdrawal->state, ['posted', 'rejected', 'cancelled', 'expired'], true))
            || $corrections->contains('state', 'pending_review')
            || $disbursements->contains(fn ($disbursement): bool => in_array($disbursement->status, ['processing', 'outcome_unknown'], true)),
            'withdrawals' => $withdrawals->toArray(), 'corrections' => $corrections->toArray(), 'disbursements' => $disbursements->all()];
    }

    /**
     * @param  Collection<int, FeeObligation>  $obligations
     * @return list<int>
     */
    private function feeReceiptBatchIds(ThriftPlan $plan, Collection $obligations, bool $forUpdate): array
    {
        $batchIds = [];
        foreach ($obligations as $fee) {
            foreach ($fee->entries as $entry) {
                if ($entry->entry_type !== FeeObligationEntryType::Settlement || $entry->source_type !== 'collection_receipt') {
                    continue;
                }
                $parts = explode('-', $entry->source_id, 2);
                if (! ctype_digit($parts[0]) || ($parts[1] ?? null) !== (string) $fee->id) {
                    throw new ConflictHttpException('The cycle fee has no authoritative receipt source.');
                }
                $receipt = CollectionReceipt::query()->whereKey($parts[0])
                    ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
                $group = LedgerPostingGroup::query()->where('posting_reference', $entry->ledger_posting_reference)
                    ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
                if ($receipt === null || $group === null || $receipt->customer_profile_id !== $plan->customer_profile_id
                    || $group->customer_profile_id !== $plan->customer_profile_id || $group->currency !== $entry->currency
                    || $group->source_type !== 'collection_receipt' || $group->source_id !== $receipt->id.'-'.$fee->id
                    || $group->thrift_plan_id !== ($receipt->thrift_plan_id ?? $plan->id)
                    || $group->event_type !== ($receipt->replacement_reversal_id === null ? 'external_fee_receipt' : 'unapplied_fee_application')) {
                    throw new ConflictHttpException('The cycle fee receipt owner is unavailable.');
                }
                if ($receipt->thrift_plan_id !== null && ThriftPlan::query()->whereKey($receipt->thrift_plan_id)
                    ->where('customer_profile_id', $plan->customer_profile_id)
                    ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first(['id']) === null) {
                    throw new ConflictHttpException('The cycle fee receipt savings owner is unavailable.');
                }
                $components = DB::table('collection_fee_components')->where('collection_receipt_id', $receipt->id)
                    ->where('fee_obligation_id', $fee->id)->orderBy('id')
                    ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get();
                $component = $components->first();
                if ($components->count() !== 1 || $component === null
                    || filter_var($component->ledger_posting_group_id, FILTER_VALIDATE_INT) !== $group->id
                    || filter_var($component->amount_kobo, FILTER_VALIDATE_INT) !== $entry->amount_kobo) {
                    throw new ConflictHttpException('The cycle fee receipt component is unavailable.');
                }
                $custodyCode = app(CollectionReceiptMethod::class)->assertReceipt($receipt);
                $debitCode = $receipt->replacement_reversal_id === null ? $custodyCode : LedgerAccountCode::UnappliedFunds;
                $lines = $group->entries()->with(['account' => fn ($query) => $query->when($forUpdate, fn ($query) => $query->lockForUpdate())])
                    ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get();
                if ($lines->count() !== 2
                    || ! $lines->contains(fn ($line): bool => $line->side === LedgerEntrySide::Debit && $line->account?->code === $debitCode)
                    || ! $lines->contains(fn ($line): bool => $line->side === LedgerEntrySide::Credit && $line->account?->code === LedgerAccountCode::FeeIncome)) {
                    throw new ConflictHttpException('The cycle fee receipt journal is unavailable.');
                }
                foreach ($lines as $line) {
                    if ($line->amount_kobo !== $entry->amount_kobo || $line->customer_profile_id !== $plan->customer_profile_id
                        || $line->fee_obligation_id !== $fee->id
                        || $line->agent_profile_id !== ($line->side === LedgerEntrySide::Debit && $debitCode === LedgerAccountCode::AgentReceivable ? $receipt->recording_agent_profile_id : null)) {
                        throw new ConflictHttpException('The cycle fee receipt financial dimensions are unavailable.');
                    }
                }
                $batch = CollectionBatch::query()->whereKey($receipt->collection_batch_id)
                    ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
                if ($batch === null || $batch->agent_profile_id !== $receipt->recording_agent_profile_id
                    || $batch->custody_account_code !== $receipt->custody_account_code || $batch->received_date !== $receipt->received_date
                    || $batch->timezone !== $receipt->timezone || $batch->collection_method_version_id !== $receipt->collection_method_version_id) {
                    throw new ConflictHttpException('The cycle fee receipt custody batch is unavailable.');
                }
                $batchIds[] = $batch->id;
            }
        }

        return array_values(array_unique($batchIds));
    }

    /** @return array{unsettled: bool, withdrawals: list<array<string, mixed>>} */
    private function payoutReturnDisposition(ThriftPlan $plan, bool $forUpdate): array
    {
        $withdrawals = WithdrawalRequest::query()->where('thrift_plan_id', $plan->id)->orderBy('id')
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get();
        $facts = [];
        $unsettled = false;
        foreach ($withdrawals as $withdrawal) {
            $executions = CashExecution::query()->where('withdrawal_request_id', $withdrawal->id)->orderBy('id')
                ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get();
            $executionFacts = [];
            foreach ($executions as $execution) {
                $recoveries = CashRecovery::query()->where('cash_execution_id', $execution->id)->orderBy('id')
                    ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get();
                foreach ($recoveries as $recovery) {
                    if ($recovery->status !== 'consumed' || $recovery->consumed_at === null) {
                        $unsettled = true;
                    }
                }
                $compensation = null;
                if ($execution->status === 'posted' && $recoveries->contains('status', 'consumed')) {
                    try {
                        $compensation = app(WithdrawalReversalOwner::class)->assertConsumedReturns($execution, $recoveries, $forUpdate);
                    } catch (ConflictHttpException|RuntimeException|ValueError) {
                        $unsettled = true;
                    }
                }
                $executionFacts[] = ['id' => $execution->id, 'status' => $execution->status,
                    'posting_group_id' => $execution->ledger_posting_group_id,
                    'compensation' => $compensation,
                    'recoveries' => $recoveries->map(fn (CashRecovery $recovery): array => [
                        'id' => $recovery->id, 'event_type' => $recovery->event_type, 'amount_kobo' => $recovery->amount_kobo,
                        'status' => $recovery->status, 'confirmed_at' => $recovery->confirmed_at,
                        'consumed_at' => $recovery->consumed_at, 'return_posting_group_id' => $recovery->return_posting_group_id,
                        'ledger_posting_group_id' => $recovery->ledger_posting_group_id,
                    ])->all()];
            }
            $bankFacts = [];
            foreach (BankPayoutAttempt::query()->where('withdrawal_request_id', $withdrawal->id)->orderBy('id')
                ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get() as $attempt) {
                $returns = BankPayoutReturn::query()->where('bank_payout_attempt_id', $attempt->id)->orderBy('id')
                    ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get();
                $inFlight = in_array($attempt->status, ['prepared', 'submitted', 'unknown'], true)
                    || ($attempt->status === 'succeeded' && $attempt->ledger_posting_group_id === null);
                if ($inFlight || $returns->contains(fn (BankPayoutReturn $return): bool => $return->status !== 'consumed' || $return->consumed_at === null)) {
                    $unsettled = true;
                }
                $bankFacts[] = ['id' => $attempt->id, 'status' => $attempt->status, 'posting_group_id' => $attempt->ledger_posting_group_id,
                    'returns' => $returns->map(fn (BankPayoutReturn $return): array => ['id' => $return->id, 'status' => $return->status,
                        'amount_kobo' => $return->amount_kobo, 'consumed_at' => $return->consumed_at])->all()];
            }
            $facts[] = ['id' => $withdrawal->id, 'version' => $withdrawal->version,
                'state' => $withdrawal->state, 'executions' => $executionFacts, 'bank_payouts' => $bankFacts];
        }

        return ['unsettled' => $unsettled, 'withdrawals' => $facts];
    }

    /** @param Collection<int, PlanTermsRevision> $terms */
    private function hasVerifiedFeeAgreements(ThriftPlan $plan, Collection $terms, bool $forUpdate): bool
    {
        if ($terms->isEmpty()) {
            return false;
        }
        $boundPlan = clone $plan;
        $boundPlan->setRelation('termsRevisions', $terms);
        try {
            foreach ($terms as $revision) {
                $snapshot = FeeSnapshot::query()->whereKey($revision->fee_snapshot_id)
                    ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
                if ($snapshot === null || ! app(PlanFeeSnapshotBinding::class)->isValid($boundPlan, $revision, $snapshot, true)) {
                    return false;
                }
                $snapshot->setRelation('feeRule', $snapshot->feeRule()
                    ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first());
                app(FeeObligationService::class)->assertSnapshotMatchesRuleQuote($snapshot, true);
            }
        } catch (RuntimeException|ConflictHttpException|ValueError) {
            return false;
        }

        return true;
    }

    /** @param Collection<int, PlanTermsRevision> $terms
     * @return Collection<int, FeeObligation>
     */
    private function cycleObligations(ThriftPlan $plan, Collection $terms, bool $forUpdate): Collection
    {
        $snapshotIds = $terms->pluck('fee_snapshot_id');
        $charges = ManualCharge::query()->where('thrift_plan_id', $plan->id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get();
        $records = FeeObligation::query()->where(function ($query) use ($plan, $snapshotIds, $charges): void {
            $query->where(fn ($fees) => $fees->where('customer_profile_id', $plan->customer_profile_id)->where('kind', '!=', 'registration'))
                ->orWhereIn('fee_snapshot_id', $snapshotIds)->orWhereIn('id', $charges->pluck('fee_obligation_id')->filter()->all());
        })->orderBy('id')->when($forUpdate, fn ($query) => $query->lockForUpdate())->get();
        /** @var Collection<int, FeeObligation> $cycle */
        $cycle = new Collection;
        foreach ($records as $fee) {
            $snapshot = FeeSnapshot::query()->whereKey($fee->fee_snapshot_id)->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
            if ($snapshot === null || $fee->customer_profile_id !== $plan->customer_profile_id
                || $snapshot->customer_profile_id !== $fee->customer_profile_id || $fee->kind !== $snapshot->getRawOriginal('kind')
                || ! in_array($fee->kind, ['plan', 'manual'], true) || $fee->source_type !== $snapshot->source_type
                || $fee->source_id !== $snapshot->source_id || $fee->amount_kobo !== $snapshot->amount_kobo) {
                throw new RuntimeException('A cycle fee has no consistent source identity.');
            }
            $snapshot->setRelation('feeRule', $snapshot->feeRule()->when($forUpdate, fn ($query) => $query->lockForUpdate())->first());
            app(FeeObligationService::class)->assertSnapshotMatchesRuleQuote($snapshot, true);
            $fee->setRelation('feeSnapshot', $snapshot);
            $fee->setRelation('entries', $fee->entries()->when($forUpdate, fn ($query) => $query->lockForUpdate())->get());
            $fee->outstandingAmountKobo();
            $ownerPlanId = $this->feeCycleId($fee, $forUpdate);
            if ($ownerPlanId === $plan->id) {
                $cycle->push($fee);
            }
        }
        foreach ($charges as $charge) {
            $category = ChargeCategoryVersion::query()->whereKey($charge->charge_category_version_id)
                ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
            if ($category === null || $charge->customer_profile_id !== $plan->customer_profile_id
                || ! in_array($category->kind, ['manual_fee', 'deduction'], true)
                || ($category->kind === 'manual_fee' && ! $cycle->contains('id', $charge->fee_obligation_id))
                || ($category->kind === 'deduction' && $charge->fee_obligation_id !== null)) {
                throw new RuntimeException('A cycle charge has no verified obligation disposition.');
            }
        }

        return $cycle;
    }

    private function feeCycleId(FeeObligation $fee, bool $forUpdate): int
    {
        $snapshot = $fee->feeSnapshot;
        if (in_array($fee->source_type, ['plan', 'plan_terms_revision'], true) && $fee->kind === 'plan') {
            $terms = PlanTermsRevision::query()->where('fee_snapshot_id', $snapshot->id)->orderBy('revision')
                ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get();
            $origin = $terms->first();
            if ($origin === null || $terms->pluck('thrift_plan_id')->unique()->count() !== 1) {
                throw new RuntimeException('The cycle agreement source is unavailable.');
            }
            $owner = ThriftPlan::query()->whereKey($origin->thrift_plan_id)
                ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
            if ($owner === null || $owner->customer_profile_id !== $fee->customer_profile_id) {
                throw new RuntimeException('The cycle agreement Customer source is unavailable.');
            }
            $owner->setRelation('termsRevisions', $terms);
            if (! app(PlanFeeSnapshotBinding::class)->isValid($owner, $origin, $snapshot, true)) {
                throw new RuntimeException('The cycle agreement binding is unavailable.');
            }

            return $owner->id;
        }
        if ($fee->source_type === 'manual_charge' && $fee->kind === 'manual') {
            $charge = ManualCharge::query()->where('operation_reference', $fee->source_id)
                ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
            $category = $charge === null ? null : ChargeCategoryVersion::query()->whereKey($charge->charge_category_version_id)
                ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
            if ($charge === null || $category === null || $charge->fee_obligation_id !== $fee->id
                || $charge->customer_profile_id !== $fee->customer_profile_id || $category->kind !== 'manual_fee'
                || $charge->amount_kobo !== $fee->amount_kobo || $category->amount_kobo !== $fee->amount_kobo
                || $category->fee_rule_id !== $snapshot->fee_rule_id) {
                throw new RuntimeException('The manual cycle fee owner is unavailable.');
            }
            $ownerId = $charge->thrift_plan_id;
        } elseif ($fee->source_type === 'withdrawal' && $fee->kind === 'plan' && ctype_digit($fee->source_id)) {
            $withdrawal = WithdrawalRequest::query()->whereKey((int) $fee->source_id)
                ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
            $agreement = $withdrawal === null ? null : FeeSnapshot::query()->whereKey($withdrawal->fee_snapshot_id)
                ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
            if ($withdrawal === null || $agreement === null || (string) $withdrawal->id !== $fee->source_id
                || $withdrawal->customer_profile_id !== $fee->customer_profile_id
                || $withdrawal->fee_amount_kobo !== $fee->amount_kobo || $agreement->fee_rule_id !== $snapshot->fee_rule_id
                || ! PlanTermsRevision::query()->where('thrift_plan_id', $withdrawal->thrift_plan_id)->where('fee_snapshot_id', $agreement->id)
                    ->when($forUpdate, fn ($query) => $query->lockForUpdate())->exists()) {
                throw new RuntimeException('The withdrawal cycle fee owner is unavailable.');
            }
            $ownerId = $withdrawal->thrift_plan_id;
        } else {
            throw new RuntimeException('The fee cycle attribution is unsupported.');
        }
        $owner = ThriftPlan::query()->whereKey($ownerId)->where('customer_profile_id', $fee->customer_profile_id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
        if ($owner === null) {
            throw new RuntimeException('The fee cycle Customer source is unavailable.');
        }

        return $owner->id;
    }

    /** @param Collection<int, FeeObligation> $obligations */
    private function hasRefundPayable(ThriftPlan $plan, Collection $obligations, bool $forUpdate): bool
    {
        $rows = DB::table('ledger_entries as e')->join('ledger_accounts as a', 'a.id', '=', 'e.ledger_account_id')
            ->where('a.code', LedgerAccountCode::RefundPayable->value)->whereIn('e.fee_obligation_id', $obligations->modelKeys())
            ->select('e.side', 'e.amount_kobo', 'e.customer_profile_id', 'a.mapping_status')
            ->orderBy('e.id')->when($forUpdate, fn ($query) => $query->lockForUpdate())->get();
        $balance = 0;
        foreach ($rows as $row) {
            $amount = filter_var($row->amount_kobo, FILTER_VALIDATE_INT);
            if ($amount === false || $amount < 1 || (int) $row->customer_profile_id !== $plan->customer_profile_id
                || $row->mapping_status !== 'mapped' || ! in_array($row->side, ['credit', 'debit'], true)
                || ($row->side === 'credit' && $balance > PHP_INT_MAX - $amount)
                || ($row->side === 'debit' && $balance < PHP_INT_MIN + $amount)) {
                return true;
            }
            $balance += $row->side === 'credit' ? $amount : -$amount;
        }

        return $balance !== 0;
    }

    /** @param array<string, mixed> $data */
    public function confirm(User $actor, ThriftPlan $plan, string $action, array $data): ThriftPlan
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $plan, $action, $data): ThriftPlan {
            $context = app(CustomerActionAuthorizationGuard::class)->lockAndAuthorize($actor, $plan->customer_profile_id, 'managePlan');
            $plan = ThriftPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$actor->id, $plan->id, $action, $data], JSON_THROW_ON_ERROR));
            $existing = FinancialWorkflowSupplement::query()->where('operation_reference', $data['attempt_reference'])->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new ConflictHttpException('Changed settlement instructions conflict with this attempt.');
                }

                return $plan;
            }
            $quote = $this->preview($actor, $plan, $action);
            if (! hash_equals($quote['preview_fingerprint'], $data['preview_fingerprint']) || $context->customerProfile->operational_status === CustomerStatus::Archived) {
                throw new ConflictHttpException('Settlement dependencies changed. Review the current preview.');
            }
            $prior = $plan->status;
            if ($action === 'prepare_termination') {
                if (! $quote['can_prepare']) {
                    throw new ConflictHttpException('This cycle cannot prepare early termination.');
                }
                $snapshot = $plan->currentTermsRevision()->feeSnapshot;
                $principal = $quote['termination_fee']['principal_kobo'];
                if ($principal > 0 && $snapshot->timing === FeeRuleTiming::CycleCompletion
                    && $context->customerProfile->operational_status !== CustomerStatus::Restricted) {
                    app(FeeObligationService::class)->assessSnapshot($snapshot, $actor);
                }
                if ($context->customerProfile->operational_status !== CustomerStatus::Restricted) {
                    app(PlanFeeCorrectionService::class)->synchronize($plan, $actor, $data['attempt_reference'], true);
                }
                $plan->status = ThriftPlanStatus::Paused;
                $plan->version++;
                $plan->save();
            } elseif ($action === 'close') {
                if (! $quote['can_close'] || ($prior !== ThriftPlanStatus::Completed
                    && (! in_array($prior, [ThriftPlanStatus::Active, ThriftPlanStatus::Paused], true) || ! $plan->lifecycleEvents()->where('event_type', 'early_termination_prepared')->exists()))) {
                    throw new ConflictHttpException('All settlement gates must pass before a separate closure.');
                }
                $plan->update(['status' => ThriftPlanStatus::Closed, 'open_customer_profile_id' => null, 'version' => $plan->version + 1]);
            } elseif ($action === 'resolve_exception') {
                if ($prior !== ThriftPlanStatus::Closed || ! $quote['can_close'] || $quote['exceptions'] === []) {
                    throw new ConflictHttpException('Only fully settled closed-cycle exceptions can be resolved.');
                }
                $plan->version++;
                $plan->save();
            } else {
                throw new ConflictHttpException('Unsupported settlement action.');
            }
            $eventType = match ($action) {
                'prepare_termination' => 'early_termination_prepared', 'resolve_exception' => 'closed_exception_resolved', default => 'closed'
            };
            $event = PlanLifecycleEvent::create(['thrift_plan_id' => $plan->id, 'event_type' => $eventType, 'from_status' => $prior, 'to_status' => $plan->status,
                'actor_user_id' => $actor->id, 'assignment_version' => $context->currentAssignment?->version, 'plan_version' => $plan->version,
                'reason' => $data['reason'], 'customer_explanation' => $data['customer_explanation'], 'payload' => $quote, 'effective_at' => now()]);
            app(ThriftPlanService::class)->createNotificationIntents($context->customerProfile, $plan, $event, $context->currentAssignment?->agentProfile?->user_id);
            FinancialWorkflowSupplement::create(['operation_reference' => $data['attempt_reference'], 'payload_hash' => $hash, 'kind' => $eventType,
                'customer_profile_id' => $plan->customer_profile_id, 'thrift_plan_id' => $plan->id, 'actor_user_id' => $actor->id,
                'facts' => ['event_id' => $action === 'resolve_exception' ? $quote['exceptions'][0] : $event->id, 'gate_fingerprint' => $quote['preview_fingerprint']], 'evidence' => $data['reason'], 'created_at' => now()]);
            if ($action === 'resolve_exception') {
                foreach (array_slice($quote['exceptions'], 1) as $exceptionId) {
                    FinancialWorkflowSupplement::create(['operation_reference' => (string) Str::uuid(), 'payload_hash' => $hash, 'kind' => $eventType,
                        'customer_profile_id' => $plan->customer_profile_id, 'thrift_plan_id' => $plan->id, 'actor_user_id' => $actor->id,
                        'facts' => ['event_id' => $exceptionId, 'resolution_operation' => $data['attempt_reference'], 'gate_fingerprint' => $quote['preview_fingerprint']],
                        'evidence' => $data['reason'], 'created_at' => now()]);
                }
            }
            AuditEvent::record('thrift_plan.'.$eventType, ThriftPlan::class, $plan->id, $plan->plan_id,
                ['customer_profile_id' => $plan->customer_profile_id,
                    'assignment_version' => $context->currentAssignment?->version,
                    'terms_revision' => $plan->current_terms_revision,
                    'fee_snapshot_id' => $plan->currentTermsRevision()?->fee_snapshot_id,
                    'lifecycle_event_id' => $event->id,
                    'from' => $prior->value, 'to' => $plan->status->value,
                    'from_version' => $quote['plan_version'], 'version' => $plan->version,
                    'gate_fingerprint' => $quote['preview_fingerprint'], 'reason' => $data['reason']],
                $actor, context: ['executor' => self::class, 'correlation_reference' => $data['attempt_reference']]);

            return $plan;
        }, attempts: 3);
    }
}
