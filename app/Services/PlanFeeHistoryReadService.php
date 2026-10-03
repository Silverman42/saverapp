<?php

namespace App\Services;

use App\Data\PlanFeeReadSnapshot;
use App\Enums\FeeObligationEntryType;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Models\CashExecution;
use App\Models\ChargeCategoryVersion;
use App\Models\CollectionReceipt;
use App\Models\FeeObligation;
use App\Models\FeeObligationEntry;
use App\Models\FeeRefund;
use App\Models\LedgerPostingGroup;
use App\Models\ManualCharge;
use App\Models\PlanLifecycleEvent;
use App\Models\ReversalRequest;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\MoneyFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use ValueError;

class PlanFeeHistoryReadService
{
    public function __construct(
        private ResourceScopeService $scope,
        private PlanFeeSnapshotBinding $binding,
        private FeeObligationService $fees,
        private PlanWithdrawalFeePosition $withdrawals,
        private FeeConcessionPosition $concessions,
    ) {}

    /** @return array<string, mixed> */
    public function read(User $viewer, ThriftPlan $plan, int $page = 1, int $perPage = 25): array
    {
        $this->assertScope($viewer, $plan);
        if ($page < 1 || ! in_array($perPage, [25, 50, 100], true)) {
            throw new \InvalidArgumentException('Unsupported fee history pagination.');
        }
        if (DB::transactionLevel() === 0 && DB::getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        try {
            return DB::transaction(function () use ($viewer, $plan, $page, $perPage): array {
                $this->assertScope($viewer, $plan);
                $current = $plan->fresh(['termsRevisions.feeSnapshot.obligation.entries', 'termsRevisions.feeSnapshot.feeRule']);
                if ($current === null || $current->customer_profile_id !== $plan->customer_profile_id
                    || $current->version !== $plan->version || $current->current_terms_revision !== $plan->current_terms_revision) {
                    return $this->unavailable();
                }
                $summary = $this->summarize($current);
                $obligationIds = $summary['obligation_ids'];
                unset($summary['obligation_ids']);
                $history = FeeObligationEntry::query()->whereIn('fee_obligation_id', $obligationIds)
                    ->with('obligation.feeSnapshot')->orderByDesc('created_at')->orderByDesc('id')
                    ->paginate($perPage, ['*'], 'fee_page', $page);

                return [...$summary, 'history' => ['data' => array_map(fn (FeeObligationEntry $entry): array => $this->entry($entry), $history->items()),
                    'total' => $history->total(), 'current_page' => $history->currentPage(), 'last_page' => $history->lastPage(), 'per_page' => $history->perPage()]];
            });
        } catch (RuntimeException|QueryException|ConflictHttpException|ValueError) {
            return $this->unavailable();
        }
    }

    /** @param list<ThriftPlan> $plans
     * @return array<string, array<string, mixed>>
     */
    public function readMany(User $viewer, array $plans): array
    {
        if (count($plans) > 100) {
            throw new \InvalidArgumentException('Read at most 100 cycle fee summaries at a time.');
        }
        if ($plans === []) {
            return [];
        }
        $result = [];
        $ids = [];
        foreach ($plans as $plan) {
            $result[$plan->plan_id] = $this->unavailable();
            $ids[] = $plan->id;
        }
        if (count(array_unique($ids)) !== count($ids) || count($result) !== count($plans)) {
            throw new \InvalidArgumentException('Cycle fee summaries require distinct identities.');
        }
        if (DB::transactionLevel() === 0 && DB::getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        try {
            return DB::transaction(function () use ($viewer, $plans, $ids, $result): array {
                $records = ThriftPlan::query()->whereIn('id', $ids)
                    ->whereIn('customer_profile_id', $this->scope->forCustomers($viewer)->select('customer_profiles.id'))
                    ->with(['termsRevisions.feeSnapshot.obligation.entries', 'termsRevisions.feeSnapshot.feeRule'])->get();
                $captured = $this->capture($records);
                $asOf = now()->toIso8601String();
                foreach ($plans as $plan) {
                    $current = $records->firstWhere('id', $plan->id);
                    if ($current === null || $current->customer_profile_id !== $plan->customer_profile_id
                        || $current->version !== $plan->version || $current->current_terms_revision !== $plan->current_terms_revision
                        || $current->plan_id !== $plan->plan_id) {
                        continue;
                    }
                    try {
                        $summary = $this->summarize($current, $captured);
                        unset($summary['obligation_ids']);
                        $summary['as_of'] = $asOf;
                        $result[$plan->plan_id] = $summary;
                    } catch (RuntimeException|QueryException|ConflictHttpException|ValueError) {
                        $result[$plan->plan_id] = $this->unavailable();
                    }
                }

                return $result;
            });
        } catch (RuntimeException|QueryException|ConflictHttpException|ValueError) {
            return $result;
        }
    }

    /** @param Collection<int, ThriftPlan> $plans */
    private function capture(Collection $plans): PlanFeeReadSnapshot
    {
        $planIds = $plans->pluck('id')->all();
        $snapshotIds = $plans->flatMap(fn (ThriftPlan $plan) => $plan->termsRevisions->pluck('fee_snapshot_id'))->unique()->all();
        $withdrawals = WithdrawalRequest::query()->whereIn('thrift_plan_id', $planIds)->get();
        $charges = ManualCharge::query()->whereIn('thrift_plan_id', $planIds)->get();
        $categories = ChargeCategoryVersion::query()->whereIn('id', $charges->pluck('charge_category_version_id'))->get();
        $obligations = FeeObligation::query()->where(function (Builder $query) use ($snapshotIds, $withdrawals, $charges): void {
            $query->whereIn('fee_snapshot_id', $snapshotIds)
                ->orWhereIn('id', $charges->pluck('fee_obligation_id')->filter()->all())
                ->orWhere(fn (Builder $query): Builder => $query->where('source_type', 'manual_charge')->whereIn('source_id', $charges->pluck('operation_reference')))
                ->orWhere(fn (Builder $query): Builder => $query->where('source_type', 'withdrawal')->whereIn('source_id', $withdrawals->pluck('id')));
        })->with('feeSnapshot.feeRule', 'entries')->orderBy('id')->get();
        $entries = $obligations->flatMap(fn (FeeObligation $fee) => $fee->entries);
        $references = $entries->pluck('ledger_posting_reference')->filter()->unique()->all();
        $groups = LedgerPostingGroup::query()->where(function (Builder $query) use ($withdrawals, $references): void {
            $query->whereIn('posting_reference', $references)
                ->orWhere(fn (Builder $query): Builder => $query->where('source_type', 'withdrawal')->whereIn('source_id', $withdrawals->pluck('id')));
        })->with('entries.account', 'entries.feeObligation.feeSnapshot', 'entries.feeObligation.entries')->get();
        $reversalIds = $entries->filter(fn (FeeObligationEntry $entry): bool => in_array($entry->getRawOriginal('entry_type'), ['assessment_correction', 'settlement_reversal'], true)
            && $entry->source_type === 'reversal_request')->pluck('source_id')->unique()->all();
        $reversals = ReversalRequest::query()->whereIn('id', $reversalIds)->where('state', 'approved_posted')->get();
        $compensations = LedgerPostingGroup::query()->whereIn('id', $reversals->pluck('compensation_posting_group_id'))
            ->with('entries.account')->get();
        $groups = $groups->concat($compensations)->unique('id')->values();
        $executions = CashExecution::query()->whereIn('withdrawal_request_id', $withdrawals->pluck('id'))->where('status', 'posted')->get();
        $refunds = FeeRefund::query()->whereIn('fee_obligation_id', $obligations->pluck('id'))->get();
        $receiptPlans = CollectionReceipt::query()->whereIn('thrift_plan_id', $planIds)->where('savings_amount_kobo', '>', 0)
            ->distinct()->pluck('thrift_plan_id');
        $completedPlans = PlanLifecycleEvent::query()->whereIn('thrift_plan_id', $planIds)
            ->whereIn('event_type', ['completed', 'early_termination_prepared'])->distinct()->pluck('thrift_plan_id');
        $applicationReferences = $entries->where('source_type', 'fee_savings_application')->pluck('source_id')
            ->unique()->map(function (mixed $reference): string {
                if (! is_string($reference)) {
                    throw new RuntimeException('The fee application identity is invalid.');
                }

                return $reference;
            })->all();
        $applications = app(FeeSavingsApplicationService::class)->verifiedPostedSources(array_values($applicationReferences));

        $feeCorrectionIds = $groups->where('event_type', 'fee_application_compensation')->pluck('source_id')
            ->map(fn ($id): int => (int) $id)->all();
        $feeCompensations = app(FeeSavingsApplicationReversalOwner::class)->verifiedPostedSources(array_values($feeCorrectionIds));

        return new PlanFeeReadSnapshot($plans, $withdrawals, $obligations, $charges, $categories, $groups,
            $executions, $reversals, $refunds, $receiptPlans, $completedPlans, $applications, $feeCompensations);
    }

    /** @return array<string, mixed> */
    private function summarize(ThriftPlan $current, ?PlanFeeReadSnapshot $captured = null): array
    {
        $terms = $current->termsRevisions;
        if ($terms->isEmpty()) {
            throw new RuntimeException('Cycle fee agreements are missing.');
        }
        foreach ($terms as $revision) {
            $snapshot = $revision->feeSnapshot;
            if ($snapshot === null || ! $this->binding->isValid($current, $revision, $snapshot, $captured !== null)) {
                throw new RuntimeException('Cycle fee agreement attribution is unavailable.');
            }
            $this->fees->assertSnapshotMatchesRuleQuote($snapshot, $captured !== null);
            if ($snapshot->model === FeeRuleModel::OneDay && ($snapshot->amount_kobo !== $revision->contribution_amount_kobo
                || $snapshot->basis_amount_kobo !== $revision->contribution_amount_kobo)) {
                throw new RuntimeException('Cycle daily fee basis is unavailable.');
            }
        }
        $currentTerms = $captured === null
            ? $current->currentTermsRevision()
            : $terms->firstWhere('revision', $current->current_terms_revision);
        $agreement = $currentTerms?->feeSnapshot;
        if ($agreement === null) {
            throw new RuntimeException('The current cycle fee agreement is missing.');
        }
        $withdrawalPosition = $agreement->timing === FeeRuleTiming::Withdrawal
            ? $this->withdrawals->read($current, $agreement, $captured) : null;
        $snapshotIds = $terms->pluck('fee_snapshot_id')->unique()->all();
        $withdrawalIds = $captured === null ? WithdrawalRequest::query()->where('thrift_plan_id', $current->id)->pluck('id')->all()
            : $captured->withdrawals->where('thrift_plan_id', $current->id)->pluck('id')->all();
        $manualFees = $this->manualFees($current, $captured);
        $manualReferences = array_map(fn (array $fee): string => (string) $fee['charge']->getRawOriginal('operation_reference'), $manualFees);
        $query = FeeObligation::query()->where(function (Builder $query) use ($snapshotIds, $withdrawalIds, $manualFees, $manualReferences): void {
            $query->whereIn('fee_snapshot_id', $snapshotIds)
                ->orWhere(fn (Builder $query): Builder => $query->where('source_type', 'withdrawal')->whereIn('source_id', $withdrawalIds))
                ->orWhereIn('id', array_keys($manualFees))
                ->orWhere(fn (Builder $query): Builder => $query->where('source_type', 'manual_charge')->whereIn('source_id', $manualReferences));
        });
        $totals = ['original_assessed' => 0, 'assessed' => 0, 'settled' => 0, 'waived' => 0, 'outstanding' => 0];
        $withdrawalTotals = ['assessed_kobo' => 0, 'settled_kobo' => 0, 'waived_kobo' => 0, 'outstanding_kobo' => 0];
        $obligationIds = [];
        $records = $captured === null ? $query->with('feeSnapshot', 'entries')->lazyById(100)
            : $captured->obligations->filter(fn (FeeObligation $fee): bool => in_array($fee->fee_snapshot_id, $snapshotIds, true)
                || ($fee->source_type === 'withdrawal' && in_array((int) $fee->source_id, $withdrawalIds, true))
                || isset($manualFees[$fee->id]) || ($fee->source_type === 'manual_charge' && in_array($fee->source_id, $manualReferences, true)));
        $entryCount = 0;
        $entryWatermark = 0;
        foreach ($records as $obligation) {
            $snapshot = $obligation->feeSnapshot;
            if ($snapshot === null || $obligation->customer_profile_id !== $current->customer_profile_id
                || $snapshot->customer_profile_id !== $current->customer_profile_id || $obligation->kind !== $snapshot->kind->value
                || ! in_array($obligation->kind, ['plan', 'manual'], true) || $obligation->source_type !== $snapshot->source_type
                || $obligation->source_id !== $snapshot->source_id || $obligation->amount_kobo !== $snapshot->amount_kobo) {
                throw new RuntimeException('Cycle fee obligation attribution is unavailable.');
            }
            if ($obligation->kind === 'manual') {
                $manual = $manualFees[$obligation->id] ?? null;
                if ($manual === null || $obligation->source_type !== 'manual_charge'
                    || $obligation->source_id !== $manual['charge']->getRawOriginal('operation_reference')
                    || $obligation->amount_kobo !== $manual['category']->amount_kobo
                    || (string) $snapshot->fee_rule_id !== (string) $manual['category']->getRawOriginal('fee_rule_id')) {
                    throw new RuntimeException('The cycle manual fee source cannot be verified.');
                }
                $this->fees->assertSnapshotMatchesRuleQuote($snapshot, $captured !== null);
            } elseif ($obligation->source_type === 'manual_charge') {
                throw new RuntimeException('A manual fee cannot impersonate the cycle agreement.');
            }
            if ($obligation->source_type === 'withdrawal' && $withdrawalPosition === null) {
                throw new RuntimeException('Unexpected withdrawal fee obligation.');
            }
            $values = ['original_assessed' => $obligation->amount_kobo, 'assessed' => $obligation->assessedAmountKobo(),
                'settled' => $obligation->settledAmountKobo(), 'waived' => $obligation->waivedAmountKobo(),
                'outstanding' => $obligation->outstandingAmountKobo()];
            foreach ($obligation->entries->where('source_type', 'fee_savings_application') as $entry) {
                $application = $captured === null
                    ? app(FeeSavingsApplicationService::class)->assertPosted($entry->source_id)
                    : $captured->savingsApplications->get($entry->source_id);
                if ($application === null || $entry->entry_type !== FeeObligationEntryType::Settlement
                    || $entry->ledger_posting_reference !== $application->posting_reference
                    || $application->customer_profile_id !== $current->customer_profile_id || $application->thrift_plan_id !== $current->id) {
                    throw new RuntimeException('The recorded fee application has no verified cycle source.');
                }
            }
            $this->concessions->retainedSources($obligation, $captured);
            foreach ($values as $key => $amount) {
                $totals[$key] = $this->add($totals[$key], $amount);
                if ($obligation->source_type === 'withdrawal' && $key !== 'original_assessed') {
                    $withdrawalTotals[$key.'_kobo'] = $this->add($withdrawalTotals[$key.'_kobo'], $amount);
                }
            }
            $obligationIds[] = $obligation->id;
            $entryCount += $obligation->entries->count();
            $entryWatermark = max($entryWatermark, $obligation->entries->max('id') ?? 0);
        }
        if (array_diff(array_keys($manualFees), $obligationIds) !== []) {
            throw new RuntimeException('A cycle manual fee assessment is missing.');
        }
        if ($withdrawalPosition !== null) {
            foreach ($withdrawalTotals as $key => $amount) {
                if ($amount !== $withdrawalPosition[$key]) {
                    throw new RuntimeException('Cycle withdrawal fee obligations do not reconcile.');
                }
            }
        } elseif ($agreement->amount_kobo > 0 && $agreement->obligation === null
            && (($agreement->timing === FeeRuleTiming::FirstContribution
                    && ($captured === null ? CollectionReceipt::query()->where('thrift_plan_id', $current->id)->where('savings_amount_kobo', '>', 0)->exists() : $captured->receiptPlanIds->contains($current->id)))
                || ($agreement->timing === FeeRuleTiming::CycleCompletion
                    && ($captured === null ? $current->lifecycleEvents()->whereIn('event_type', ['completed', 'early_termination_prepared'])->exists() : $captured->completedPlanIds->contains($current->id))))) {
            throw new RuntimeException('The triggered cycle fee assessment is missing.');
        }

        return ['status' => 'available', 'message' => 'Recorded cycle agreement and separate manual fees. Settlements retain original payments after concessions; refund entitlements are separate from payment.',
            'as_of' => now()->toIso8601String(), 'source_version' => hash('sha256', json_encode([$current->version, $obligationIds, $entryCount, $totals, $entryWatermark], JSON_THROW_ON_ERROR)),
            'totals' => array_map(fn (int $amount): string => MoneyFormatter::formatNaira($amount), $totals), 'obligation_ids' => $obligationIds];
    }

    private function assertScope(User $viewer, ThriftPlan $plan): void
    {
        if (! $this->scope->forCustomers($viewer)->whereKey($plan->customer_profile_id)->exists()) {
            throw new NotFoundHttpException('Record unavailable.');
        }
    }

    private function add(int $total, int $amount): int
    {
        if ($amount < 0 || $amount > PHP_INT_MAX - $total) {
            throw new RuntimeException('Cycle fee amounts exceed the supported range.');
        }

        return $total + $amount;
    }

    /** @return array<int, array{charge: ManualCharge, category: ChargeCategoryVersion}> */
    private function manualFees(ThriftPlan $plan, ?PlanFeeReadSnapshot $captured): array
    {
        $charges = $captured === null ? ManualCharge::query()->where('thrift_plan_id', $plan->id)->get() : $captured->charges->where('thrift_plan_id', $plan->id);
        $categories = $captured === null ? ChargeCategoryVersion::query()->whereIn('id', $charges->pluck('charge_category_version_id'))->get()->keyBy('id') : $captured->categories->keyBy('id');
        $fees = [];
        foreach ($charges as $charge) {
            $category = $categories->get($charge->getRawOriginal('charge_category_version_id'));
            if ($category === null || (string) $charge->getRawOriginal('customer_profile_id') !== (string) $plan->customer_profile_id) {
                throw new RuntimeException('A cycle charge source is unavailable.');
            }
            if (! in_array($category->getRawOriginal('kind'), ['manual_fee', 'deduction'], true)) {
                throw new RuntimeException('A cycle charge classification is unavailable.');
            }
            if ($category->getRawOriginal('kind') !== 'manual_fee') {
                if ($charge->getRawOriginal('fee_obligation_id') !== null) {
                    throw new RuntimeException('A deduction cannot hide a fee obligation.');
                }

                continue;
            }
            $obligationId = filter_var($charge->getRawOriginal('fee_obligation_id'), FILTER_VALIDATE_INT);
            $amount = filter_var($charge->getRawOriginal('amount_kobo'), FILTER_VALIDATE_INT);
            $categoryAmount = filter_var($category->getRawOriginal('amount_kobo'), FILTER_VALIDATE_INT);
            if ($obligationId === false || $obligationId < 1 || isset($fees[$obligationId])
                || $amount === false || $amount < 1 || $categoryAmount === false || $amount !== $categoryAmount) {
                throw new RuntimeException('A cycle manual fee assessment source is unavailable.');
            }
            $fees[$obligationId] = ['charge' => $charge, 'category' => $category];
        }

        return $fees;
    }

    /** @return array<string, mixed> */
    private function entry(FeeObligationEntry $entry): array
    {
        return ['id' => $entry->id, 'fee_name' => $entry->obligation->feeSnapshot?->name,
            'kind_label' => $entry->obligation->kind === 'manual' ? 'Separate manual fee' : 'Cycle agreement fee',
            'type' => $entry->entry_type->value, 'amount' => MoneyFormatter::formatNaira($entry->amount_kobo),
            'description' => $entry->customer_description, 'reference' => $entry->ledger_posting_reference,
            'recorded_at' => $entry->created_at?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public function unavailable(): array
    {
        return ['status' => 'unavailable', 'message' => 'Verified cycle fee history is unavailable. Reload to try again.',
            'as_of' => null, 'source_version' => null, 'totals' => null, 'history' => null];
    }
}
