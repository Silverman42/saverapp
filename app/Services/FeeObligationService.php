<?php

namespace App\Services;

use App\Data\FeePosition;
use App\Data\FeeQuote;
use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Enums\FeeAssessmentCorrectionDirection;
use App\Enums\FeeObligationEntryType;
use App\Enums\FeeRuleModel;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeObligationEntry;
use App\Models\FeeRule;
use App\Models\FeeSnapshot;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Notifications\FeeObligationNotice;
use App\Support\FeePercentageCalculator;
use App\Support\MoneyFormatter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FeeObligationService
{
    public function __construct(
        protected AuthorizationService $authorizationService,
        protected FreshAuthenticationService $freshAuthenticationService,
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

                context: ['executor' => self::class, 'required_permission' => $actor?->user_type === UserType::Admin ? 'fees.manage' : null]
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

    private function assertSnapshotMatchesRuleQuote(FeeSnapshot $snapshot): void
    {
        $rule = $snapshot->feeRule()->first();
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

        $quote = $this->quote($rule, $snapshot->basis_amount_kobo, $snapshot->source_type, $snapshot->source_id);
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
            $obligation = FeeObligation::query()->whereKey($obligationId)->lockForUpdate()->firstOrFail();
            $this->assertCustomerMayReceiveFeeChanges($obligation);

            return $this->recordAdministrativeEntry(
                obligation: $obligation,
                admin: $admin,
                entryType: FeeObligationEntryType::Waiver,
                amountKobo: $amountKobo,
                reason: $reason,
                customerDescription: $customerDescription,
                attemptReference: $attemptReference,
                eventType: 'fee.obligation.waived',
            );
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
            $obligation = FeeObligation::query()->whereKey($obligationId)->lockForUpdate()->firstOrFail();
            $this->assertCustomerMayReceiveFeeChanges($obligation);

            if ($obligation->settledAmountKobo() > 0 || $obligation->waivedAmountKobo() > 0) {
                throw new ConflictHttpException('Settled or waived fee obligations require their owning correction workflow.');
            }

            $entryType = $direction === FeeAssessmentCorrectionDirection::Increase
                ? FeeObligationEntryType::AssessmentCorrectionIncrease
                : FeeObligationEntryType::AssessmentCorrection;

            if ($direction === FeeAssessmentCorrectionDirection::Increase
                && $amountKobo > 999_999_999_999 - $obligation->assessedAmountKobo()) {
                throw ValidationException::withMessages(['amount_ngn' => ['The corrected assessment exceeds the supported fee limit.']]);
            }

            return $this->recordAdministrativeEntry(
                obligation: $obligation,
                admin: $admin,
                entryType: $entryType,
                amountKobo: $amountKobo,
                reason: $reason,
                customerDescription: $customerDescription,
                attemptReference: $attemptReference,
                eventType: 'fee.assessment.corrected',
            );
        }, attempts: 3);
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

        return $customer->feeObligations->map(function (FeeObligation $obligation): array {
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
        })->values()->all();
    }

    /**
     * Return current authoritative fee and refund results for a future lifecycle caller.
     *
     * A zero is returned only when a fee snapshot proves the source terms and every
     * applicable assessment is represented. Lifecycle remains unavailable while plan
     * and cycle owners are absent, even when the currently recorded balance is zero.
     */
    public function authoritativePosition(CustomerProfile $customer): FeePosition
    {
        $customer->loadMissing(['feeSnapshots.obligation.entries', 'feeObligations.entries']);
        $snapshots = $customer->feeSnapshots;
        if ($snapshots->isEmpty()) {
            return $this->unavailablePosition('No authoritative fee snapshot is available.');
        }

        $outstandingKobo = 0;
        foreach ($snapshots as $snapshot) {
            $obligation = $snapshot->obligation;
            if (! $snapshot->isZero() && $obligation === null) {
                return $this->unavailablePosition('An applicable fee assessment is missing.');
            }

            if ($obligation === null) {
                continue;
            }

            $amount = $obligation->outstandingAmountKobo();
            if ($amount > PHP_INT_MAX - $outstandingKobo) {
                throw new \OverflowException('Customer outstanding fee total exceeds the supported integer range.');
            }
            $outstandingKobo += $amount;
        }

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
                lifecycleGateMessage: 'Refund payable mapping and plan/cycle fee owner contracts are unavailable.',
            );
        }

        $refundTotals = LedgerEntry::query()
            ->where('ledger_account_id', $refundAccount->id)
            ->where('customer_profile_id', $customer->id)
            ->selectRaw('side, SUM(amount_kobo) as amount_kobo')
            ->groupBy('side')
            ->pluck('amount_kobo', 'side');
        $refundPayableKobo = (int) $refundTotals->get(LedgerEntrySide::Credit->value, 0)
            - (int) $refundTotals->get(LedgerEntrySide::Debit->value, 0);

        if ($refundPayableKobo < 0) {
            return $this->unavailablePosition('Refund payable entries are inconsistent.');
        }

        $lifecycleMessage = $outstandingKobo > 0 || $refundPayableKobo > 0
            ? 'Outstanding fees or refund payables block the lifecycle action.'
            : 'Lifecycle eligibility is unavailable until plan and cycle fee contracts are supplied.';

        return new FeePosition(
            outstandingStatus: 'available',
            outstandingFeeKobo: $outstandingKobo,
            refundPayableStatus: 'available',
            refundPayableKobo: $refundPayableKobo,
            lifecycleGateStatus: $outstandingKobo > 0 || $refundPayableKobo > 0 ? 'blocked' : 'unavailable',
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

    private function lockAuthorizedAdmin(int $actorId, Request $request): User
    {
        $admin = User::query()->whereKey($actorId)->lockForUpdate()->firstOrFail();
        if (! $this->authorizationService->allows($admin, AdminPermission::FeesManage)) {
            throw new AuthorizationException('Current authority to manage fees is required.');
        }

        if (! $this->freshAuthenticationService->isFresh($admin, $request)) {
            throw new ConflictHttpException('Fresh password and authenticator confirmation is required.');
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
        $existing = FeeObligationEntry::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
        if ($existing !== null) {
            if ($existing->fee_obligation_id !== $obligation->id
                || $existing->entry_type !== $entryType
                || $existing->amount_kobo !== $amountKobo
                || $existing->actor_user_id !== $admin->id
                || $existing->reason !== trim($reason)
                || $existing->customer_description !== trim($customerDescription)) {
                throw new ConflictHttpException('Changed fee action payload conflicts with its original attempt.');
            }

            return $existing;
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

        AuditEvent::record(
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

            context: ['executor' => self::class, 'required_permission' => $admin?->user_type === UserType::Admin ? 'fees.manage' : null]
        );

        $customer = $obligation->customerProfile()->with('user')->first();
        if ($customer?->user !== null) {
            $customerUser = $customer->user;
            $customerUser->notify(new FeeObligationNotice(
                amountKobo: $amountKobo,
                currency: $obligation->currency,
                entryType: $entryType,
                customerDescription: trim($customerDescription),
                obligationId: $obligation->id,
                customerId: $customer->customer_id,
            ));
        }

        return $entry;
    }
}
