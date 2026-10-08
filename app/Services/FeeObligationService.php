<?php

namespace App\Services;

use App\Data\FeePosition;
use App\Data\FeeQuote;
use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Enums\FeeAssessmentCorrectionDirection;
use App\Enums\FeeObligationEntryType;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Enums\ThriftPlanStatus;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeObligationEntry;
use App\Models\FeeRule;
use App\Models\FeeSnapshot;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Support\FeePercentageCalculator;
use App\Support\MoneyFormatter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FeeObligationService
{
    public function __construct(
        protected AuthorizationService $authorizationService,
    ) {}

    /**
     * Create the initial positive registration-fee obligation and assessment entry.
     */
    public function assessRegistrationSnapshot(FeeSnapshot $snapshot, User $actor): ?FeeObligation
    {
        if ($snapshot->kind->value !== 'registration') {
            throw new \InvalidArgumentException('Only registration snapshots can be assessed during Customer creation.');
        }

        return $this->assessSnapshot($snapshot, $actor);
    }

    /**
     * Assess a due immutable snapshot once for its stable source identity.
     *
     * Plan and cycle owners can call this only after their own trigger contract confirms
     * the due condition and commits the snapshot in the same transaction.
     */
    public function assessSnapshot(FeeSnapshot $snapshot, User $actor): ?FeeObligation
    {
        if ($snapshot->source_type === '' || $snapshot->source_id === '') {
            throw new \InvalidArgumentException('Fee assessment requires an immutable source identity.');
        }

        return app(PlatformGuard::class)->transaction('financial', function () use ($snapshot, $actor): ?FeeObligation {
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $customer = CustomerProfile::query()->whereKey($snapshot->customer_profile_id)->lockForUpdate()->firstOrFail();
            $lockedSnapshot = FeeSnapshot::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            $this->assertSnapshotMatchesRuleQuote($lockedSnapshot);

            if ($lockedSnapshot->isZero()) {
                return null;
            }

            $existing = FeeObligation::query()
                ->where('source_type', $lockedSnapshot->source_type)
                ->where('source_id', $lockedSnapshot->source_id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->fee_snapshot_id !== $lockedSnapshot->id || $existing->amount_kobo !== $lockedSnapshot->amount_kobo) {
                    throw new ConflictHttpException('A fee obligation already exists with different immutable terms.');
                }

                return $existing;
            }

            if ($customer->operational_status === CustomerStatus::Archived) {
                throw new ConflictHttpException('Restore the Archived Customer before assessing a fee.');
            }

            $obligation = FeeObligation::create([
                'customer_profile_id' => $lockedSnapshot->customer_profile_id,
                'fee_snapshot_id' => $lockedSnapshot->id,
                'source_type' => $lockedSnapshot->source_type,
                'source_id' => $lockedSnapshot->source_id,
                'kind' => $lockedSnapshot->kind->value,
                'amount_kobo' => $lockedSnapshot->amount_kobo,
                'currency' => $lockedSnapshot->currency,
                'due_condition' => match ($lockedSnapshot->timing->value) {
                    'registration' => 'upon_registration',
                    'manual' => 'confirmed_manual_assessment',
                    'first_contribution' => 'first_contribution',
                    'cycle_completion' => 'cycle_completion',
                    'withdrawal' => 'withdrawal',
                },
                'customer_description' => $lockedSnapshot->customer_description,
                'created_by_user_id' => $actor->id,
            ]);

            FeeObligationEntry::create([
                'fee_obligation_id' => $obligation->id,
                'entry_type' => FeeObligationEntryType::Assessment,
                'amount_kobo' => $lockedSnapshot->amount_kobo,
                'currency' => $lockedSnapshot->currency,
                'source_type' => $lockedSnapshot->source_type,
                'source_id' => $lockedSnapshot->source_id,
                'idempotency_key' => 'fee-assessment-'.$lockedSnapshot->id,
                'actor_user_id' => $actor->id,
                'customer_description' => $lockedSnapshot->customer_description,
            ]);

            AuditEvent::record(
                eventType: 'fee.obligation.assessed',
                targetType: FeeObligation::class,
                targetId: $obligation->id,
                targetReference: $lockedSnapshot->source_type.':'.$lockedSnapshot->source_id,
                payload: [
                    'customer_profile_id' => $lockedSnapshot->customer_profile_id,
                    'fee_snapshot_id' => $lockedSnapshot->id,
                    'fee_rule_id' => $lockedSnapshot->fee_rule_id,
                    'fee_rule_version' => $lockedSnapshot->fee_rule_version,
                    'amount_kobo' => $lockedSnapshot->amount_kobo,
                    'currency' => $lockedSnapshot->currency,
                    'source_type' => $lockedSnapshot->source_type,
                    'source_id' => $lockedSnapshot->source_id,
                ],
                actor: $actor,

                context: ['executor' => self::class, 'required_permission' => $actor->user_type === UserType::Admin ? 'fees.manage' : null]
            );

            return $obligation;
        }, attempts: 3);
    }

    /**
     * Calculate an immutable fee quote for an owning workflow to review and confirm.
     */
    public function quote(FeeRule $rule, int $basisKobo, string $sourceType, string $sourceId): FeeQuote
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($rule, $basisKobo, $sourceType, $sourceId) {
            return $this->quoteAllowed($rule, $basisKobo, $sourceType, $sourceId);
        });
    }

    private function quoteAllowed(FeeRule $rule, int $basisKobo, string $sourceType, string $sourceId): FeeQuote
    {
        if ($basisKobo < 0 || $sourceType === '' || $sourceId === '') {
            throw new \InvalidArgumentException('Fee quotes require a non-negative basis and source identity.');
        }

        $amountKobo = match ($rule->model) {
            FeeRuleModel::NoFee => 0,
            FeeRuleModel::Fixed => $rule->amount_kobo,
            FeeRuleModel::OneDay => $basisKobo,
            FeeRuleModel::Percentage => FeePercentageCalculator::calculate($basisKobo, $rule->basis_points ?? 0),
        };

        if ($rule->model === FeeRuleModel::OneDay && $amountKobo < 1) {
            throw new \InvalidArgumentException('A one-day fee quote requires a positive contractual daily contribution.');
        }

        return new FeeQuote(
            ruleId: $rule->id,
            ruleVersion: $rule->version,
            model: $rule->model,
            timing: $rule->timing,
            basis: $rule->basis,
            amountKobo: $amountKobo,
            currency: $rule->currency,
            basisKobo: $basisKobo,
            sourceType: $sourceType,
            sourceId: $sourceId,
        );
    }

    /**
     * Recompute a previewed quote under lock so a caller can fail closed when terms changed.
     */
    public function confirmQuote(FeeQuote $quote, int $currentBasisKobo): FeeQuote
    {
        if ($currentBasisKobo < 0 || $currentBasisKobo !== $quote->basisKobo) {
            throw new ConflictHttpException('Fee quote basis changed. Reconfirm the current amount before proceeding.');
        }

        return app(PlatformGuard::class)->transaction('financial', function () use ($quote, $currentBasisKobo): FeeQuote {
            $rule = FeeRule::query()->whereKey($quote->ruleId)->lockForUpdate()->firstOrFail();
            if ($rule->version !== $quote->ruleVersion
                || $rule->model !== $quote->model
                || $rule->timing !== $quote->timing
                || $rule->basis !== $quote->basis
                || $rule->effective_at->isFuture()
                || ($rule->retired_at !== null && $rule->retired_at->isPast())) {
                throw new ConflictHttpException('Fee quote is stale. Reconfirm the current fee terms before proceeding.');
            }

            $confirmed = $this->quote($rule, $currentBasisKobo, $quote->sourceType, $quote->sourceId);
            if ($confirmed->amountKobo !== $quote->amountKobo || $confirmed->currency !== $quote->currency) {
                throw new ConflictHttpException('Fee quote no longer matches its immutable source terms.');
            }

            return $confirmed;
        }, attempts: 3);
    }

    public function assertSnapshotMatchesRuleQuote(FeeSnapshot $snapshot, bool $useLoadedRule = false): void
    {
        foreach (['amount_kobo', 'basis_amount_kobo'] as $attribute) {
            $amount = filter_var($snapshot->getRawOriginal($attribute), FILTER_VALIDATE_INT);
            if ($amount === false || $amount < 0) {
                throw new ConflictHttpException('Fee snapshot pricing requires non-negative integer kobo.');
            }
        }
        if ($snapshot->model === FeeRuleModel::Percentage) {
            $rate = filter_var($snapshot->getRawOriginal('basis_points'), FILTER_VALIDATE_INT);
            if ($rate === false || $rate < 0 || $rate > 10000) {
                throw new ConflictHttpException('Fee snapshot percentage rate is unavailable.');
            }
        }
        $rule = $useLoadedRule && $snapshot->relationLoaded('feeRule') ? $snapshot->feeRule : $snapshot->feeRule()->first();
        if ($rule === null
            || $rule->version !== $snapshot->fee_rule_version
            || $rule->kind !== $snapshot->kind
            || $rule->model !== $snapshot->model
            || $rule->timing !== $snapshot->timing
            || $rule->basis !== $snapshot->basis
            || $rule->basis_points !== $snapshot->basis_points
            || $rule->settlement_source !== $snapshot->settlement_source
            || $rule->currency !== $snapshot->currency) {
            throw new ConflictHttpException('Fee snapshot does not match an immutable published rule version.');
        }

        $quote = $this->quoteAllowed($rule, $snapshot->basis_amount_kobo, $snapshot->source_type, $snapshot->source_id);
        if ($quote->amountKobo !== $snapshot->amount_kobo) {
            throw new ConflictHttpException('Fee snapshot amount does not reproduce from its immutable rule and basis.');
        }
    }

    /**
     * Waive an unpaid portion of a fee obligation with commit-time authorization and idempotency checks.
     */
    public function waive(
        User $actor,
        int $obligationId,
        int $amountKobo,
        string $reason,
        string $customerDescription,
        string $attemptReference,
        Request $request,
    ): FeeObligationEntry {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $obligationId, $amountKobo, $reason, $customerDescription, $attemptReference, $request): FeeObligationEntry {
            $admin = $this->lockAuthorizedAdmin($actor->id, $request);
            $attemptOwner = app(FeeActionAttemptService::class);
            $attempt = $attemptOwner->reserveForCommit($admin, $obligationId, 'waive', $attemptReference,
                ['amount_kobo' => $amountKobo, 'reason' => $reason, 'customer_description' => $customerDescription]);
            $source = FeeObligation::query()->findOrFail($obligationId);
            $this->assertCustomerMayReceiveFeeChanges($source);
            $obligation = FeeObligation::query()->whereKey($obligationId)->lockForUpdate()->firstOrFail();
            $obligation->setRelation('entries', $obligation->entries()->lockForUpdate()->get());

            $entry = $this->recordAdministrativeEntry(
                obligation: $obligation,
                admin: $admin,
                entryType: FeeObligationEntryType::Waiver,
                amountKobo: $amountKobo,
                reason: $reason,
                customerDescription: $customerDescription,
                attemptReference: $attemptReference,
                eventType: 'fee.obligation.waived',
            );
            $attemptOwner->markRecorded($attempt, 'fee_obligation_entry', (string) $entry->id);

            return $entry;
        }, attempts: 3);
    }

    /**
     * Correct only an unsettled, unwaived assessment. Posted money belongs to Reversals.
     */
    public function correctUnsettledAssessment(
        User $actor,
        int $obligationId,
        int $amountKobo,
        FeeAssessmentCorrectionDirection $direction,
        string $reason,
        string $customerDescription,
        string $attemptReference,
        Request $request,
    ): FeeObligationEntry {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $obligationId, $amountKobo, $direction, $reason, $customerDescription, $attemptReference, $request): FeeObligationEntry {
            $admin = $this->lockAuthorizedAdmin($actor->id, $request);
            $attemptOwner = app(FeeActionAttemptService::class);
            $attempt = $attemptOwner->reserveForCommit($admin, $obligationId, 'correct', $attemptReference,
                ['amount_kobo' => $amountKobo, 'reason' => $reason, 'customer_description' => $customerDescription, 'direction' => $direction->value]);
            $source = FeeObligation::query()->findOrFail($obligationId);
            $this->assertCustomerMayReceiveFeeChanges($source);
            $obligation = FeeObligation::query()->whereKey($obligationId)->lockForUpdate()->firstOrFail();
            $obligation->setRelation('entries', $obligation->entries()->lockForUpdate()->get());

            $entryType = $direction === FeeAssessmentCorrectionDirection::Increase
                ? FeeObligationEntryType::AssessmentCorrectionIncrease
                : FeeObligationEntryType::AssessmentCorrection;

            $entry = $this->recordAdministrativeEntry(
                obligation: $obligation,
                admin: $admin,
                entryType: $entryType,
                amountKobo: $amountKobo,
                reason: $reason,
                customerDescription: $customerDescription,
                attemptReference: $attemptReference,
                eventType: 'fee.assessment.corrected',
            );
            $attemptOwner->markRecorded($attempt, 'fee_obligation_entry', (string) $entry->id);

            return $entry;
        }, attempts: 3);
    }

    /** @return array{status: string, action: string, direction: ?string, attempt_reference: string, entry_id: int, amount_kobo: int, currency: string, recorded_at: string} */
    public function administrativeActionStatus(User $actor, int $obligationId, string $attemptReference): array
    {
        return DB::transaction(function () use ($actor, $obligationId, $attemptReference): array {
            $admin = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (! $this->authorizationService->allows($admin, AdminPermission::FeesManage)) {
                throw new AuthorizationException('Current authority to manage fees is required.');
            }
            $obligation = FeeObligation::query()->whereKey($obligationId)->lockForUpdate()->first();
            $entries = FeeObligationEntry::query()->where('fee_obligation_id', $obligationId)
                ->where('actor_user_id', $admin->id)
                ->where(fn ($query) => $query->where('idempotency_key', 'fee-admin-'.$attemptReference)
                    ->orWhere('source_id', $attemptReference))
                ->lockForUpdate()->get();
            if ($obligation === null || $entries->isEmpty()) {
                throw new NotFoundHttpException('Record unavailable.');
            }
            $entry = $entries->first();
            if ($entries->count() !== 1) {
                throw new ConflictHttpException('The retained administrative fee action is unavailable.');
            }

            return $this->verifiedAdministrativeAction($entry, $obligation, $admin, $attemptReference);
        });
    }

    /** @return array{status: string, action: string, direction: ?string, attempt_reference: string, entry_id: int, amount_kobo: int, currency: string, recorded_at: string} */
    private function verifiedAdministrativeAction(FeeObligationEntry $entry, FeeObligation $obligation, User $admin, string $attemptReference): array
    {
        $type = $entry->getRawOriginal('entry_type');
        $isWaiver = $type === FeeObligationEntryType::Waiver->value;
        $isIncrease = $type === FeeObligationEntryType::AssessmentCorrectionIncrease->value;
        if (! in_array($type, [FeeObligationEntryType::Waiver->value, FeeObligationEntryType::AssessmentCorrection->value, FeeObligationEntryType::AssessmentCorrectionIncrease->value], true)
            || $entry->source_type !== ($isWaiver ? 'admin_waiver' : 'admin_assessment_correction')
            || $entry->source_id !== $attemptReference || $entry->idempotency_key !== 'fee-admin-'.$attemptReference
            || $entry->ledger_posting_reference !== null || $entry->currency !== 'NGN'
            || $entry->currency !== $obligation->currency || $entry->amount_kobo < 1 || $entry->created_at === null
            || ! Schema::hasTable('fee_obligation_events')) {
            throw new ConflictHttpException('The retained administrative fee action is unavailable.');
        }
        $event = DB::table('fee_obligation_events')->where('fee_obligation_entry_id', $entry->id)->lockForUpdate()->first();
        $audit = $event === null ? null : AuditEvent::query()->whereKey($event->audit_event_id)->lockForUpdate()->first();
        if ($event === null || $audit === null
            || (int) $event->version !== 1 || $event->operation_reference !== $attemptReference
            || (int) $event->fee_obligation_id !== $obligation->id
            || (int) $event->customer_profile_id !== $obligation->customer_profile_id
            || (int) $event->actor_user_id !== $admin->id || $event->entry_type !== $type
            || $event->event_type !== ($isWaiver ? 'waived' : 'assessment_corrected')
            || (int) $event->amount_kobo !== $entry->amount_kobo || $event->currency !== $entry->currency
            || $event->customer_description !== $entry->customer_description
            || (int) $event->outstanding_before_kobo < 0 || (int) $event->outstanding_after_kobo < 0
            || (int) $event->outstanding_after_kobo !== (int) $event->outstanding_before_kobo + ($isIncrease ? $entry->amount_kobo : -$entry->amount_kobo)
            || $audit->event_type !== ($isWaiver ? 'fee.obligation.waived' : 'fee.assessment.corrected')
            || $audit->actor_id !== $admin->id || $audit->actor_type !== UserType::Admin->value
            || $audit->target_type !== FeeObligation::class || $audit->target_id !== $obligation->id
            || $audit->target_reference !== (string) $obligation->id
            || ($audit->payload['attempt_reference'] ?? null) !== $attemptReference
            || ($audit->payload['entry_type'] ?? null) !== $type
            || ($audit->payload['amount_kobo'] ?? null) !== $entry->amount_kobo
            || ($audit->payload['currency'] ?? null) !== $entry->currency
            || ($audit->payload['customer_profile_id'] ?? null) !== $obligation->customer_profile_id
            || ($audit->payload['outstanding_before_kobo'] ?? null) !== (int) $event->outstanding_before_kobo
            || ($audit->payload['outstanding_after_kobo'] ?? null) !== (int) $event->outstanding_after_kobo) {
            throw new ConflictHttpException('The retained administrative fee action is unavailable.');
        }

        return ['status' => 'recorded', 'action' => $isWaiver ? 'waive' : 'correct',
            'direction' => $isWaiver ? null : ($isIncrease ? 'increase' : 'reduce'),
            'attempt_reference' => $attemptReference, 'entry_id' => $entry->id,
            'amount_kobo' => $entry->amount_kobo, 'currency' => $entry->currency,
            'recorded_at' => $entry->created_at->toIso8601String()];
    }

    /**
     * Build a privacy-safe fee summary for a profile already resolved through ResourceScopeService.
     *
     * @return array<string, mixed>
     */
    public function customerSummary(CustomerProfile $customer): array
    {
        $snapshot = $customer->feeSnapshot;
        if ($snapshot === null) {
            return ['status' => 'unavailable', 'message' => 'Fee terms are unavailable.'];
        }

        $obligation = $customer->feeObligations->firstWhere('fee_snapshot_id', $snapshot->id);
        if ($obligation === null && ! $snapshot->isZero()) {
            return ['status' => 'unavailable', 'message' => 'Fee obligation history is unavailable.'];
        }

        return [
            'status' => $snapshot->isZero() ? 'no_fee' : 'available',
            'assessed_amount_kobo' => $obligation?->assessedAmountKobo() ?? 0,
            'formatted_assessed_amount' => $obligation?->formattedAmount() ?? '₦0.00',
            'settled_amount_kobo' => $obligation?->settledAmountKobo() ?? 0,
            'formatted_settled_amount' => MoneyFormatter::formatNaira($obligation?->settledAmountKobo() ?? 0),
            'waived_amount_kobo' => $obligation?->waivedAmountKobo() ?? 0,
            'formatted_waived_amount' => MoneyFormatter::formatNaira($obligation?->waivedAmountKobo() ?? 0),
            'outstanding_amount_kobo' => $obligation?->outstandingAmountKobo() ?? 0,
            'formatted_outstanding_amount' => MoneyFormatter::formatNaira($obligation?->outstandingAmountKobo() ?? 0),
            'obligation_status' => $obligation?->status->value ?? 'no_fee',
            'obligation_status_label' => $obligation?->status->displayName() ?? 'No fee due',
            'position' => $this->authoritativePosition($customer)->toArray(),
            'history' => $obligation?->entries->map(fn (FeeObligationEntry $entry): array => [
                'type' => $entry->entry_type->value,
                'amount_kobo' => $entry->amount_kobo,
                'formatted_amount' => MoneyFormatter::formatNaira($entry->amount_kobo),
                'description' => $entry->customer_description,
                'recorded_at' => $entry->created_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
            ])->values()->all() ?? [],
        ];
    }

    /**
     * Build Customer-safe history for every obligation on a profile already scoped by the caller.
     *
     * @return list<array<string, mixed>>
     */
    public function customerObligations(CustomerProfile $customer): array
    {
        $customer->loadMissing(['feeObligations.feeSnapshot', 'feeObligations.entries']);

        return array_values($customer->feeObligations->map(function (FeeObligation $obligation): array {
            $snapshot = $obligation->feeSnapshot;
            if ($snapshot === null) {
                return [
                    'id' => $obligation->id,
                    'status' => 'unavailable',
                    'message' => 'Fee snapshot history is unavailable.',
                ];
            }

            try {
                $status = $obligation->status;
                $assessedKobo = $obligation->assessedAmountKobo();
                $settledKobo = $obligation->settledAmountKobo();
                $waivedKobo = $obligation->waivedAmountKobo();
                $outstandingKobo = $obligation->outstandingAmountKobo();
            } catch (\RuntimeException) {
                return [
                    'id' => $obligation->id,
                    'kind' => $obligation->kind,
                    'name' => $snapshot->name,
                    'status' => 'unavailable',
                    'message' => 'Fee balance history is unavailable.',
                ];
            }

            return [
                'id' => $obligation->id,
                'kind' => $obligation->kind,
                'name' => $snapshot->name,
                'rule_version' => $snapshot->fee_rule_version,
                'currency' => $obligation->currency,
                'status' => $status->value,
                'status_label' => $status->displayName(),
                'assessed_amount_kobo' => $assessedKobo,
                'formatted_assessed_amount' => MoneyFormatter::formatNaira($assessedKobo),
                'settled_amount_kobo' => $settledKobo,
                'formatted_settled_amount' => MoneyFormatter::formatNaira($settledKobo),
                'waived_amount_kobo' => $waivedKobo,
                'formatted_waived_amount' => MoneyFormatter::formatNaira($waivedKobo),
                'outstanding_amount_kobo' => $outstandingKobo,
                'formatted_outstanding_amount' => MoneyFormatter::formatNaira($outstandingKobo),
                'history' => $obligation->entries->map(fn (FeeObligationEntry $entry): array => [
                    'type' => $entry->entry_type->value,
                    'amount_kobo' => $entry->amount_kobo,
                    'formatted_amount' => MoneyFormatter::formatNaira($entry->amount_kobo),
                    'description' => $entry->customer_description,
                    'recorded_at' => $entry->created_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                ])->values()->all(),
            ];
        })->values()->all());
    }

    /**
     * Return current authoritative fee and refund results for lifecycle callers.
     *
     * A zero is returned only when a fee snapshot proves the source terms and every
     * applicable assessment is represented. Unsupported cycle evidence remains unavailable.
     */
    public function authoritativePosition(CustomerProfile $customer, bool $forUpdate = false): FeePosition
    {
        $customer->load(['feeSnapshots.obligation.entries', 'feeObligations.entries']);
        $snapshots = $customer->feeSnapshots;
        if (! $snapshots->contains(fn (FeeSnapshot $snapshot): bool => $snapshot->kind === FeeRuleKind::Registration)) {
            return $this->unavailablePosition('No authoritative fee snapshot is available.');
        }

        $outstandingKobo = 0;
        foreach ($snapshots as $snapshot) {
            $obligation = $snapshot->obligation;
            if ($snapshot->currency !== 'NGN') {
                return $this->unavailablePosition('Fee currency is unsupported.');
            }
            if (! $snapshot->isZero() && $obligation === null) {
                $plan = $snapshot->source_type === 'plan'
                    ? ThriftPlan::query()->where('plan_id', $snapshot->source_id)->where('customer_profile_id', $customer->id)->first()
                    : null;
                if ($plan !== null && $plan->status === ThriftPlanStatus::Cancelled
                    && ! CollectionReceipt::query()->where('thrift_plan_id', $plan->id)->exists()) {
                    continue;
                }

                return $this->unavailablePosition('An applicable fee assessment is missing.');
            }

            if ($obligation === null) {
                continue;
            }

            if ($obligation->customer_profile_id !== $customer->id || $obligation->currency !== 'NGN'
                || $obligation->fee_snapshot_id !== $snapshot->id || ! $obligation->entries()->where('entry_type', 'assessment')->exists()) {
                return $this->unavailablePosition('Fee assessment history is inconsistent.');
            }
            $amount = $obligation->outstandingAmountKobo();
            if ($amount > PHP_INT_MAX - $outstandingKobo) {
                throw new \OverflowException('Customer outstanding fee total exceeds the supported integer range.');
            }
            $outstandingKobo += $amount;
        }

        $refundQuery = LedgerAccount::query()->where('code', LedgerAccountCode::RefundPayable->value);
        if ($forUpdate) {
            $refundQuery->lockForUpdate();
        }
        $refundAccount = $refundQuery->first();
        if ($refundAccount === null
            || $refundAccount->mapping_status !== 'mapped'
            || $refundAccount->account_class !== LedgerAccountClass::RefundPayable
            || $refundAccount->normal_balance !== LedgerEntrySide::Credit
            || $refundAccount->currency !== 'NGN') {
            return new FeePosition(
                outstandingStatus: 'available',
                outstandingFeeKobo: $outstandingKobo,
                refundPayableStatus: 'unavailable',
                refundPayableKobo: null,
                lifecycleGateStatus: 'unavailable',
                lifecycleGateMessage: 'Refund payable mapping and plan/cycle fee owner contracts are unavailable.',
            );
        }

        $refundPayableKobo = 0;
        foreach (LedgerEntry::query()->where('ledger_account_id', $refundAccount->id)
            ->where('customer_profile_id', $customer->id)->get() as $entry) {
            $side = $entry->getRawOriginal('side');
            if ($entry->amount_kobo < 1 || ! in_array($side, ['credit', 'debit'], true)) {
                return $this->unavailablePosition('Refund payable entries are inconsistent.');
            }
            if ($side === 'credit') {
                if ($refundPayableKobo > PHP_INT_MAX - $entry->amount_kobo) {
                    return $this->unavailablePosition('Refund payable amount exceeds the supported range.');
                }
                $refundPayableKobo += $entry->amount_kobo;
            } else {
                $refundPayableKobo -= $entry->amount_kobo;
            }
        }

        if ($refundPayableKobo < 0) {
            return $this->unavailablePosition('Refund payable entries are inconsistent.');
        }

        $plansClear = app(ThriftPlanService::class)->archivalStatus($customer) === 'passed';
        $knownObligations = $snapshots->pluck('obligation.id')->filter()->all();
        if ($customer->feeObligations->contains(fn (FeeObligation $obligation): bool => ! in_array($obligation->id, $knownObligations, true))) {
            return $this->unavailablePosition('An obligation has no matching authoritative fee snapshot.');
        }
        $lifecycleMessage = $outstandingKobo > 0 || $refundPayableKobo > 0
            ? 'Outstanding fees or refund payables block the lifecycle action.'
            : ($plansClear ? 'Fee obligations and refund payables are settled.' : 'Plan and cycle fee eligibility is unavailable.');

        return new FeePosition(
            outstandingStatus: 'available',
            outstandingFeeKobo: $outstandingKobo,
            refundPayableStatus: 'available',
            refundPayableKobo: $refundPayableKobo,
            lifecycleGateStatus: $outstandingKobo > 0 || $refundPayableKobo > 0 ? 'blocked' : ($plansClear ? 'available' : 'unavailable'),
            lifecycleGateMessage: $lifecycleMessage,
        );
    }

    /**
     * Resolve fee-only closure eligibility for a supplied immutable cycle snapshot.
     *
     * The cycle owner remains responsible for cycle state, reservations, and other
     * closure checks. A missing fee snapshot or refund mapping is never treated as zero.
     */
    public function cycleClosurePosition(FeeSnapshot $cycleSnapshot): FeePosition
    {
        if ($cycleSnapshot->kind->value !== 'plan'
            || $cycleSnapshot->timing->value !== 'cycle_completion'
            || $cycleSnapshot->source_type === ''
            || $cycleSnapshot->source_id === '') {
            return $this->unavailablePosition('An authoritative cycle-completion fee snapshot is required.');
        }

        $obligation = $cycleSnapshot->obligation()->with('entries')->first();
        if (! $cycleSnapshot->isZero() && $obligation === null) {
            return $this->unavailablePosition('The cycle fee assessment is missing.');
        }

        $outstandingKobo = $obligation?->outstandingAmountKobo() ?? 0;
        $refundAccount = LedgerAccount::query()->where('code', LedgerAccountCode::RefundPayable->value)->first();
        if ($refundAccount === null
            || $refundAccount->mapping_status !== 'mapped'
            || $refundAccount->account_class !== LedgerAccountClass::RefundPayable
            || $refundAccount->normal_balance !== LedgerEntrySide::Credit
            || $refundAccount->currency !== 'NGN') {
            return new FeePosition(
                outstandingStatus: 'available',
                outstandingFeeKobo: $outstandingKobo,
                refundPayableStatus: 'unavailable',
                refundPayableKobo: null,
                lifecycleGateStatus: 'unavailable',
                lifecycleGateMessage: 'Refund payable mapping is unavailable; cycle closure must remain blocked.',
            );
        }

        $refundTotals = $obligation === null
            ? collect()
            : LedgerEntry::query()
                ->where('ledger_account_id', $refundAccount->id)
                ->where('fee_obligation_id', $obligation->id)
                ->selectRaw('side, SUM(amount_kobo) as amount_kobo')
                ->groupBy('side')
                ->pluck('amount_kobo', 'side');
        $refundPayableKobo = (int) $refundTotals->get(LedgerEntrySide::Credit->value, 0)
            - (int) $refundTotals->get(LedgerEntrySide::Debit->value, 0);
        if ($refundPayableKobo < 0) {
            return $this->unavailablePosition('Cycle refund payable entries are inconsistent.');
        }

        $isBlocked = $outstandingKobo > 0 || $refundPayableKobo > 0;

        return new FeePosition(
            outstandingStatus: 'available',
            outstandingFeeKobo: $outstandingKobo,
            refundPayableStatus: 'available',
            refundPayableKobo: $refundPayableKobo,
            lifecycleGateStatus: $isBlocked ? 'blocked' : 'available',
            lifecycleGateMessage: $isBlocked
                ? 'Cycle fee obligations or refund payables remain outstanding.'
                : 'Module 05 cycle fee conditions are clear; Module 06 must still verify cycle state and reservations.',
        );
    }

    private function unavailablePosition(string $message): FeePosition
    {
        return new FeePosition(
            outstandingStatus: 'unavailable',
            outstandingFeeKobo: null,
            refundPayableStatus: 'unavailable',
            refundPayableKobo: null,
            lifecycleGateStatus: 'unavailable',
            lifecycleGateMessage: $message,
        );
    }

    /** @param array<string, mixed> $payload */
    public function validatePreparation(int $obligationId, string $operation, array $payload): void
    {
        $source = FeeObligation::query()->findOrFail($obligationId);
        $this->assertCustomerMayReceiveFeeChanges($source);
        $fee = FeeObligation::query()->whereKey($obligationId)->lockForUpdate()->firstOrFail();
        $fee->setRelation('entries', $fee->entries()->lockForUpdate()->get());
        if ($payload['amount_kobo'] < 1) {
            throw ValidationException::withMessages(['payload.amount_ngn' => ['Enter an amount greater than zero.']]);
        }
        if ($operation === 'correct' && ($fee->settledAmountKobo() > 0 || $fee->waivedAmountKobo() > 0)) {
            throw new ConflictHttpException('Settled or waived fee obligations require their owning correction workflow.');
        }
        if (($payload['direction'] ?? null) === 'increase') {
            if ($payload['amount_kobo'] > 999_999_999_999 - $fee->assessedAmountKobo()) {
                throw ValidationException::withMessages(['payload.amount_ngn' => ['The corrected assessment exceeds the supported fee limit.']]);
            }
        } elseif ($payload['amount_kobo'] > $fee->outstandingAmountKobo()) {
            throw ValidationException::withMessages(['payload.amount_ngn' => ['The amount exceeds the current unpaid fee balance.']]);
        }
    }

    private function lockAuthorizedAdmin(int $actorId, Request $request): User
    {
        $admin = User::query()->whereKey($actorId)->lockForUpdate()->firstOrFail();
        if (! $this->authorizationService->allows($admin, AdminPermission::FeesManage)) {
            throw new AuthorizationException('Current authority to manage fees is required.');
        }

        return $admin;
    }

    private function assertCustomerMayReceiveFeeChanges(FeeObligation $obligation): void
    {
        $customer = CustomerProfile::query()->whereKey($obligation->customer_profile_id)->lockForUpdate()->firstOrFail();
        if ($customer->operational_status === CustomerStatus::Archived) {
            throw new ConflictHttpException('Archived Customer obligations cannot be changed until restoration.');
        }
    }

    private function recordAdministrativeEntry(
        FeeObligation $obligation,
        User $admin,
        FeeObligationEntryType $entryType,
        int $amountKobo,
        string $reason,
        string $customerDescription,
        string $attemptReference,
        string $eventType,
    ): FeeObligationEntry {
        if ($amountKobo < 1) {
            throw ValidationException::withMessages(['amount_ngn' => ['Enter an amount greater than zero.']]);
        }

        $idempotencyKey = 'fee-admin-'.$attemptReference;
        $retained = FeeObligationEntry::query()
            ->where(fn ($query) => $query->where('idempotency_key', $idempotencyKey)->orWhere('source_id', $attemptReference))
            ->lockForUpdate()->get();
        if ($retained->count() > 1) {
            throw new ConflictHttpException('The retained administrative fee action is unavailable.');
        }
        $existing = $retained->first();
        if ($existing !== null) {
            if ($existing->fee_obligation_id !== $obligation->id
                || $existing->currency !== $obligation->currency
                || $existing->source_type !== ($entryType === FeeObligationEntryType::Waiver ? 'admin_waiver' : 'admin_assessment_correction')
                || $existing->source_id !== $attemptReference || $existing->ledger_posting_reference !== null
                || $existing->getRawOriginal('entry_type') !== $entryType->value
                || $existing->amount_kobo !== $amountKobo
                || $existing->actor_user_id !== $admin->id
                || $existing->reason !== trim($reason)
                || $existing->customer_description !== trim($customerDescription)) {
                throw new ConflictHttpException('Changed fee action payload conflicts with its original attempt.');
            }

            $this->verifiedAdministrativeAction($existing, $obligation, $admin, $attemptReference);

            return $existing;
        }

        if (in_array($entryType, [FeeObligationEntryType::AssessmentCorrection, FeeObligationEntryType::AssessmentCorrectionIncrease], true)
            && ($obligation->settledAmountKobo() > 0 || $obligation->waivedAmountKobo() > 0)) {
            throw new ConflictHttpException('Settled or waived fee obligations require their owning correction workflow.');
        }
        if ($entryType === FeeObligationEntryType::AssessmentCorrectionIncrease
            && $amountKobo > 999_999_999_999 - $obligation->assessedAmountKobo()) {
            throw ValidationException::withMessages(['amount_ngn' => ['The corrected assessment exceeds the supported fee limit.']]);
        }

        $outstanding = $obligation->outstandingAmountKobo();
        if ($entryType !== FeeObligationEntryType::AssessmentCorrectionIncrease && $amountKobo > $outstanding) {
            throw ValidationException::withMessages(['amount_ngn' => ['The amount exceeds the current unpaid fee balance.']]);
        }

        $entry = FeeObligationEntry::create([
            'fee_obligation_id' => $obligation->id,
            'entry_type' => $entryType,
            'amount_kobo' => $amountKobo,
            'currency' => $obligation->currency,
            'source_type' => $entryType === FeeObligationEntryType::Waiver ? 'admin_waiver' : 'admin_assessment_correction',
            'source_id' => $attemptReference,
            'idempotency_key' => $idempotencyKey,
            'actor_user_id' => $admin->id,
            'reason' => trim($reason),
            'customer_description' => trim($customerDescription),
        ]);

        $outstandingAfter = $entryType === FeeObligationEntryType::AssessmentCorrectionIncrease
            ? $outstanding + $amountKobo
            : $outstanding - $amountKobo;

        $audit = AuditEvent::record(
            eventType: $eventType,
            targetType: FeeObligation::class,
            targetId: $obligation->id,
            targetReference: (string) $obligation->id,
            payload: [
                'attempt_reference' => $attemptReference,
                'entry_type' => $entryType->value,
                'amount_kobo' => $amountKobo,
                'currency' => $obligation->currency,
                'customer_profile_id' => $obligation->customer_profile_id,
                'reason' => trim($reason),
                'customer_description' => trim($customerDescription),
                'outstanding_before_kobo' => $outstanding,
                'outstanding_after_kobo' => $outstandingAfter,
            ],
            actor: $admin,

            context: ['executor' => self::class, 'required_permission' => $admin->user_type === UserType::Admin ? 'fees.manage' : null]
        );

        app(FeeObligationChangeNotice::class)->capture($entry, $audit, $outstanding, $outstandingAfter);

        return $entry;
    }
}
