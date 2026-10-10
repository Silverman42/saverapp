<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\CustomerActivity;
use App\Enums\CustomerStatus;
use App\Enums\FeeRuleBasis;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Enums\ThriftPlanStatus;
use App\Enums\UserType;
use App\Models\AgentProfile;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\FeeSnapshot;
use App\Models\FinancialWorkflowSupplement;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalAttempt;
use App\Models\WithdrawalEvent;
use App\Models\WithdrawalRequest;
use App\Support\FeePercentageCalculator;
use App\Support\MoneyAmount;
use App\Support\MoneyFormatter;
use App\Support\PlatformBlocked;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class WithdrawalService
{
    /** States that can carry a hold overlay and expire. */
    private const HOLDABLE_STATES = ['pending_review', 'approved', 'payment_failed'];

    /** Holds that follow the Customer's restriction and pause the review deadline. */
    private const CUSTOMER_HOLDS = ['customer_restricted', 'revalidation_required'];

    /** Holds that block payout but never pause expiry, so the request can still expire and release its reservation. */
    private const NON_PAUSING_HOLDS = ['destination_invalid'];

    /** States that keep a reservation live and therefore block archival. */
    private const LIVE_STATES = ['pending_review', 'approved', 'payment_failed', 'payout_processing', 'outcome_unknown'];

    public function __construct(
        private CustomerActionAuthorizationGuard $authorizationGuard,
        private CustomerActivityGate $activityGate,
        private WithdrawalBalanceService $balances,
        private WithdrawalMethodRegistry $methods,
        private AuthorizationService $authorization,
        private FreshAuthenticationService $freshAuthentication,
        private PublicIdGenerator $references,
        private WithdrawalNoticeService $notices,
        private PlanFeeSnapshotBinding $snapshotBinding,
        private PlanWithdrawalFeePosition $cycleFees,
    ) {}

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function preview(User $actor, CustomerProfile $customer, array $data, bool $forUpdate = false): array
    {
        Gate::forUser($actor)->authorize('initiateWithdrawal', $customer);
        if (! $customer->operational_status->allowsWithdrawalOfExistingFunds()) {
            throw ValidationException::withMessages(['customer' => ['This Customer cannot request settlement now.']]);
        }

        $plan = ThriftPlan::query()->where('plan_id', $data['plan_id'])
            ->where('customer_profile_id', $customer->id);
        if ($forUpdate) {
            $plan->lockForUpdate();
        }
        $plan = $plan->firstOrFail();
        if (! in_array($plan->status, [ThriftPlanStatus::Active, ThriftPlanStatus::Paused, ThriftPlanStatus::Completed], true)) {
            throw ValidationException::withMessages(['plan_id' => ['This cycle cannot fund a withdrawal.']]);
        }
        if ($data['type'] === 'end_of_cycle' && $plan->status !== ThriftPlanStatus::Completed) {
            throw ValidationException::withMessages(['type' => ['End-of-cycle payout requires a Completed cycle.']]);
        }

        $revision = $plan->currentTermsRevision();
        $snapshot = $revision?->feeSnapshot;
        if ($revision === null || $snapshot === null || $snapshot->customer_profile_id !== $customer->id
            || $snapshot->kind !== FeeRuleKind::Plan || $snapshot->currency !== 'NGN'
            || ! $this->snapshotBinding->isValid($plan, $revision, $snapshot)) {
            throw new ConflictHttpException('The cycle fee snapshot is unavailable.');
        }

        $method = $this->methods->resolve($customer->id, $data['method'], $data['destination_reference']);
        try {
            $gross = MoneyAmount::parseNairaToKobo($data['gross_ngn']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['gross_ngn' => [$exception->getMessage()]]);
        }
        $feeQuote = $this->feeQuote($snapshot, $gross, $data['type'], $plan);
        $fee = $feeQuote['amount_kobo'];
        $deductionQuote = app(WithdrawalDeductionContract::class)->quote($forUpdate);
        $deduction = $deductionQuote['amount_kobo'];
        $position = $this->position($customer, $plan, $forUpdate);
        if ($gross < 1 || $gross > min($position['available_kobo'], $position['cycle_available_kobo'])) {
            throw ValidationException::withMessages(['gross_ngn' => ['The gross debit exceeds available savings.']]);
        }
        if ($data['type'] === 'partial' && $gross >= $position['cycle_available_kobo']) {
            throw ValidationException::withMessages(['type' => ['Partial withdrawal must leave source-cycle savings available.']]);
        }
        if (in_array($data['type'], ['full', 'end_of_cycle'], true) && $gross !== $position['cycle_available_kobo']) {
            throw ValidationException::withMessages(['gross_ngn' => ['This type requires the exact current source-cycle amount.']]);
        }

        if ($fee + $deduction >= $gross) {
            throw ValidationException::withMessages(['gross_ngn' => ['The fee and deduction leave no positive Customer payout.']]);
        }
        if (WithdrawalRequest::query()->where('live_thrift_plan_id', $plan->id)->exists()) {
            throw new ConflictHttpException('This cycle already has a live withdrawal request.');
        }

        $business = BusinessProfile::current();
        $expires = isset($data['quote_expires_at'])
            ? CarbonImmutable::parse($data['quote_expires_at'])->utc()
            : CarbonImmutable::now()->utc()->addMinutes((int) config('withdrawals.quote_minutes'));
        if ($expires->isPast() || $expires->greaterThan(CarbonImmutable::now()->utc()->addMinutes((int) config('withdrawals.quote_minutes')))) {
            throw new ConflictHttpException('The withdrawal quote expired. Preview again.');
        }
        $assignment = $customer->currentAssignment;
        if ($assignment === null) {
            throw new ConflictHttpException('Current Agent assignment is unavailable.');
        }
        $quote = [
            'customer_id' => $customer->customer_id, 'customer_version' => $customer->version,
            'assignment_version' => $assignment->version, 'plan_id' => $plan->plan_id,
            'plan_version' => $plan->version, 'business_version' => $business->version,
            'fee_snapshot_id' => $snapshot->id, 'type' => $data['type'],
            'gross_kobo' => $gross, 'fee_kobo' => $fee, 'deduction_kobo' => $deduction,
            'deduction_category_version_id' => $deductionQuote['category_version_id'],
            'deduction_description' => $deductionQuote['description'], 'fee_disclosure' => $feeQuote['disclosure'],
            'net_kobo' => $gross - $fee - $deduction, 'currency' => 'NGN',
            'method' => $data['method'], 'method_version' => $method['version'],
            'destination_reference' => $method['destination_reference'],
            'destination_mask' => $method['destination_mask'],
            'payout_destination_id' => $method['payout_destination_id'] ?? null,
            'reason' => trim($data['reason']),
            'position' => $position, 'quote_expires_at' => $expires->toIso8601String(),
        ];
        $quote['preview_fingerprint'] = $this->fingerprint($quote);

        return $quote;
    }

    /** @param array<string, mixed> $data */
    public function submit(User $actor, CustomerProfile $customer, array $data): WithdrawalRequest
    {
        $hash = $this->attemptHash('submit', $actor->id, $customer->id, $data);

        try {
            return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $customer, $data, $hash): WithdrawalRequest {
                if ($existing = $this->replayedAttempt($actor, $data['attempt_reference'], 'submit', $hash)) {
                    return $existing;
                }
                $context = $this->authorizationGuard->lockAndAuthorize(
                    $actor, $customer->id, 'initiateWithdrawal',
                    (int) $data['customer_version'], (int) $data['assignment_version'],
                );
                $lockedCustomer = $context->customerProfile->load('currentAssignment');
                $this->activityGate->assertAllowed($lockedCustomer, CustomerActivity::InitiateWithdrawal);
                $quote = $this->preview($context->actor, $lockedCustomer, $data, true);
                if (! hash_equals($quote['preview_fingerprint'], $data['preview_fingerprint'])
                    || $quote['plan_version'] !== (int) $data['plan_version']
                    || $quote['business_version'] !== (int) $data['business_version']) {
                    throw new ConflictHttpException('Withdrawal terms changed. Review the current quote before submitting.');
                }
                $plan = ThriftPlan::query()->where('plan_id', $quote['plan_id'])->firstOrFail();
                $reference = $this->references->generate('withdrawal');
                $reservationId = DB::table('withdrawal_reservations')->insertGetId([
                    'customer_profile_id' => $lockedCustomer->id, 'thrift_plan_id' => $plan->id,
                    'owner_reference' => $reference, 'gross_amount_kobo' => $quote['gross_kobo'],
                    'status' => 'live', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $withdrawal = WithdrawalRequest::create([
                    'withdrawal_id' => $reference, 'customer_profile_id' => $lockedCustomer->id,
                    'thrift_plan_id' => $plan->id, 'live_thrift_plan_id' => $plan->id,
                    'initiating_agent_profile_id' => $context->currentAgentProfile->id,
                    'assignment_id' => $context->currentAssignment->id, 'submitted_by_user_id' => $context->actor->id,
                    'fee_snapshot_id' => $quote['fee_snapshot_id'], 'withdrawal_reservation_id' => $reservationId,
                    'type' => $quote['type'], 'state' => 'pending_review', 'held' => false,
                    'gross_amount_kobo' => $quote['gross_kobo'], 'fee_amount_kobo' => $quote['fee_kobo'],
                    'deduction_amount_kobo' => $quote['deduction_kobo'], 'deduction_category_version_id' => $quote['deduction_category_version_id'],
                    'net_amount_kobo' => $quote['net_kobo'], 'currency' => 'NGN',
                    'method' => $quote['method'], 'destination_reference' => $quote['destination_reference'],
                    'destination_mask' => $quote['destination_mask'], 'customer_payout_destination_id' => $quote['payout_destination_id'],
                    'reason' => $quote['reason'],
                    'internal_notes' => trim((string) ($data['internal_notes'] ?? '')) ?: null,
                    'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
                    'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'],
                    'method_version' => $quote['method_version'], 'version' => 1,
                    'submitted_at' => now(), 'deadline_at' => now()->addDays((int) config('withdrawals.review_days')),
                ]);
                WithdrawalAttempt::create(['attempt_reference' => $data['attempt_reference'], 'actor_user_id' => $actor->id,
                    'withdrawal_request_id' => $withdrawal->id, 'operation' => 'submit', 'payload_hash' => $hash]);
                $this->event($withdrawal, 'submitted', null, $context->actor, null, null);

                return $withdrawal;
            }, attempts: 3);
        } catch (AuthorizationException|ConflictHttpException $exception) {
            AuditEvent::record('withdrawal.submission_denied', CustomerProfile::class, $customer->id, $customer->customer_id,
                ['attempt_reference' => $data['attempt_reference'], 'customer_profile_id' => $customer->id,
                    'outcome' => $exception instanceof AuthorizationException ? 'denied' : 'conflict'], $actor,
                ['executor' => self::class]);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function decide(User $actor, WithdrawalRequest $request, string $action, array $data, Request $httpRequest): WithdrawalRequest
    {
        $hash = $this->attemptHash($action, $actor->id, $request->id, $data);

        try {
            return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $request, $action, $data, $hash, $httpRequest): WithdrawalRequest {
                if ($existing = $this->replayedAttempt($actor, $data['attempt_reference'], $action, $hash)) {
                    return $existing;
                }
                $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $customer = CustomerProfile::query()->whereKey($request->customer_profile_id)->lockForUpdate()->firstOrFail();
                $withdrawal = WithdrawalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
                if ($withdrawal->version !== (int) $data['version']) {
                    throw new ConflictHttpException('This withdrawal changed. Reload it before deciding.');
                }
                Gate::forUser($lockedActor)->authorize('view', $customer);
                $isAdminAction = in_array($action, ['approve', 'reject', 'revoke'], true);
                if ($isAdminAction) {
                    if (! $this->authorization->allows($lockedActor, AdminPermission::WithdrawalsReview)
                        || ! $this->freshAuthentication->isFresh($lockedActor, $httpRequest)) {
                        throw new AuthorizationException('Fresh withdrawal-review authority is required.');
                    }
                } elseif ($action === 'cancel') {
                    Gate::forUser($lockedActor)->authorize('managePlan', $customer);
                } else {
                    throw new ConflictHttpException('Unsupported withdrawal decision.');
                }
                if (($action === 'approve' || $action === 'reject' || $action === 'cancel') && $withdrawal->state !== 'pending_review') {
                    throw new ConflictHttpException('This request is no longer pending review.');
                }
                if ($action === 'revoke' && ! in_array($withdrawal->state, ['approved', 'payment_failed'], true)) {
                    throw new ConflictHttpException('Only an unexecuted approval can be revoked.');
                }
                if ($withdrawal->deadline_at->isPast() && $action === 'approve') {
                    throw new ConflictHttpException('This request expired before approval.');
                }

                $before = $withdrawal->state;
                if ($action === 'approve') {
                    if ($withdrawal->held || ! $customer->operational_status->allowsWithdrawalOfExistingFunds()) {
                        throw new ConflictHttpException('This request is held from approval.');
                    }
                    $this->activityGate->assertAllowed($customer, CustomerActivity::ApproveWithdrawal);
                    $this->assertReservationAndBalance($withdrawal, $customer);
                    $method = $this->methods->resolve($customer->id, $withdrawal->method, $withdrawal->destination_reference);
                    if ($method['version'] !== $withdrawal->method_version
                        || $method['destination_reference'] !== $withdrawal->destination_reference
                        || $method['destination_mask'] !== $withdrawal->destination_mask
                        || ($method['payout_destination_id'] ?? null) !== $withdrawal->customer_payout_destination_id) {
                        throw new ConflictHttpException('Payout method or destination changed. Reject and request a new quote.');
                    }
                    $withdrawal->state = 'approved';
                    $withdrawal->reviewed_by_user_id = $lockedActor->id;
                    $withdrawal->approved_at = now();
                    $withdrawal->deadline_at = now()->addDays((int) config('withdrawals.review_days'));
                } else {
                    $withdrawal->state = match ($action) {
                        'reject' => 'rejected', 'cancel', 'revoke' => 'cancelled',
                    };
                    $withdrawal->live_thrift_plan_id = null;
                    $withdrawal->terminal_at = now();
                    if ($action !== 'cancel') {
                        $withdrawal->reviewed_by_user_id = $lockedActor->id;
                    }
                    $this->releaseReservation($withdrawal);
                }
                $withdrawal->version++;
                $withdrawal->save();
                WithdrawalAttempt::create(['attempt_reference' => $data['attempt_reference'], 'actor_user_id' => $actor->id,
                    'withdrawal_request_id' => $withdrawal->id, 'operation' => $action, 'payload_hash' => $hash]);
                $this->event($withdrawal, $action, $before, $lockedActor,
                    trim((string) ($data['internal_reason'] ?? $data['decision_note'] ?? '')),
                    trim((string) ($data['customer_explanation'] ?? '')) ?: null);

                return $withdrawal;
            }, attempts: 3);
        } catch (AuthorizationException|ConflictHttpException $exception) {
            AuditEvent::record('withdrawal.decision_denied', WithdrawalRequest::class, $request->id, $request->withdrawal_id,
                ['attempt_reference' => $data['attempt_reference'], 'source' => $action,
                    'version' => (int) $data['version'], 'customer_profile_id' => $request->customer_profile_id,
                    'outcome' => $exception instanceof AuthorizationException ? 'denied' : 'conflict'], $actor,
                ['executor' => self::class, 'required_permission' => $action === 'cancel' ? null : 'withdrawals.review']);
            throw $exception;
        }
    }

    public function applyCustomerStatus(CustomerProfile $customer, CustomerStatus $status): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Withdrawal holds require the owning Customer-status transaction.');
        }
        app(PlatformGuard::class)->assertAllowed('financial', true);
        $requests = WithdrawalRequest::query()->where('customer_profile_id', $customer->id)
            ->whereIn('state', self::LIVE_STATES)->lockForUpdate()->get();
        if ($status === CustomerStatus::Archived && $requests->isNotEmpty()) {
            throw new ConflictHttpException('A Customer with a live withdrawal request cannot be archived.');
        }
        foreach ($requests->whereIn('state', self::HOLDABLE_STATES) as $withdrawal) {
            if ($status === CustomerStatus::Restricted && ! $withdrawal->held) {
                $withdrawal->held = true;
                $withdrawal->hold_reason = 'customer_restricted';
                $withdrawal->held_at = now();
                $event = 'hold_applied';
            } elseif ($status !== CustomerStatus::Restricted && $withdrawal->held && in_array($withdrawal->hold_reason, self::CUSTOMER_HOLDS, true)) {
                try {
                    $this->assertReservationAndBalance($withdrawal, $customer);
                } catch (ConflictHttpException|\RuntimeException) {
                    if ($withdrawal->hold_reason !== 'revalidation_required') {
                        $withdrawal->hold_reason = 'revalidation_required';
                        $withdrawal->version++;
                        $withdrawal->save();
                        $this->event($withdrawal, 'hold_revalidation_required', $withdrawal->state, null, null, null);
                    }

                    continue;
                }
                $pausedSeconds = $withdrawal->held_at?->diffInSeconds(now()) ?? 0;
                $withdrawal->held = false;
                $withdrawal->hold_reason = null;
                $withdrawal->hold_lifted_at = now();
                $withdrawal->deadline_at = $withdrawal->deadline_at->addSeconds($pausedSeconds)
                    ->max(CarbonImmutable::now()->addHours((int) config('withdrawals.restored_review_hours')));
                $event = 'hold_lifted';
            } else {
                continue;
            }
            $withdrawal->version++;
            $withdrawal->save();
            $this->event($withdrawal, $event, $withdrawal->state, null, null, null);
        }
    }

    public function expireDue(): int
    {
        try {
            app(PlatformGuard::class)->assertAllowed('financial');
        } catch (PlatformBlocked) {
            return 0;
        }

        foreach (WithdrawalRequest::query()->where('held', true)->whereIn('hold_reason', self::CUSTOMER_HOLDS)->distinct()->pluck('customer_profile_id') as $customerId) {
            $this->guardedItem(function () use ($customerId): void {
                $customer = CustomerProfile::query()->whereKey($customerId)->lockForUpdate()->first();
                if ($customer !== null && $customer->operational_status !== CustomerStatus::Restricted) {
                    $this->applyCustomerStatus($customer, $customer->operational_status);
                }
            });
        }
        $count = 0;
        foreach (WithdrawalRequest::query()->whereIn('state', self::HOLDABLE_STATES)
            ->where(fn ($query) => $query->where('held', false)->orWhereIn('hold_reason', self::NON_PAUSING_HOLDS))
            ->where('deadline_at', '<=', now())->orderBy('id')->pluck('id') as $id) {
            $expired = $this->guardedItem(function () use ($id): bool {
                $reference = WithdrawalRequest::query()->whereKey($id)->first();
                if ($reference === null) {
                    return false;
                }
                CustomerProfile::query()->whereKey($reference->customer_profile_id)->lockForUpdate()->firstOrFail();
                $withdrawal = WithdrawalRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                if (($withdrawal->held && ! in_array($withdrawal->hold_reason, self::NON_PAUSING_HOLDS, true))
                    || ! in_array($withdrawal->state, self::HOLDABLE_STATES, true) || $withdrawal->deadline_at->isFuture()) {
                    return false;
                }
                $before = $withdrawal->state;
                $this->releaseReservation($withdrawal);
                $withdrawal->state = 'expired';
                $withdrawal->live_thrift_plan_id = null;
                $withdrawal->terminal_at = now();
                $withdrawal->version++;
                $withdrawal->save();
                $this->event($withdrawal, 'expired', $before, null, null, 'The request expired before payout.');

                return true;
            });
            $count += $expired === true ? 1 : 0;
        }

        return $count;
    }

    /**
     * Runs one independently committed expiry item. A conflict in one item must not roll back or block the others.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T|null
     */
    private function guardedItem(\Closure $callback): mixed
    {
        try {
            return app(PlatformGuard::class)->transaction('financial', $callback, attempts: 3);
        } catch (PlatformBlocked) {
            return null;
        } catch (ConflictHttpException|\RuntimeException $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Applies the terms of a definitive failed attempt: the review window restarts and an active restriction holds the request.
     * The caller saves the request. Returns true when a new hold overlay was applied.
     */
    public function applyFailureTerms(WithdrawalRequest $withdrawal, CustomerProfile $customer): bool
    {
        $withdrawal->deadline_at = now()->addDays((int) config('withdrawals.review_days'));
        if ($customer->operational_status !== CustomerStatus::Restricted || $withdrawal->held) {
            return false;
        }
        $withdrawal->held = true;
        $withdrawal->hold_reason = 'customer_restricted';
        $withdrawal->held_at = now();

        return true;
    }

    public function recordHoldApplied(WithdrawalRequest $withdrawal): void
    {
        $withdrawal->version++;
        $withdrawal->save();
        $this->event($withdrawal, 'hold_applied', $withdrawal->state, null, null, null);
    }

    /** @return array{liability_kobo: int, reservations_kobo: int, available_kobo: int, cycle_liability_kobo: int, cycle_reservations_kobo: int, cycle_available_kobo: int} */
    private function position(CustomerProfile $customer, ThriftPlan $plan, bool $forUpdate): array
    {
        try {
            return $this->balances->position($customer, $plan, $forUpdate);
        } catch (\RuntimeException $exception) {
            throw new ServiceUnavailableHttpException(null, $exception->getMessage(), $exception);
        }
    }

    /** @return array{amount_kobo: int, disclosure: array<string, string>} */
    private function feeQuote(FeeSnapshot $snapshot, int $gross, string $type, ThriftPlan $plan): array
    {
        try {
            app(FeeObligationService::class)->assertSnapshotMatchesRuleQuote($snapshot);
            if ($snapshot->model === FeeRuleModel::OneDay) {
                $unit = filter_var($plan->currentTermsRevision()?->getRawOriginal('contribution_amount_kobo'), FILTER_VALIDATE_INT);
                if ($unit === false || $unit < 1 || $snapshot->amount_kobo !== $unit || $snapshot->basis_amount_kobo !== $unit) {
                    throw new ConflictHttpException('The contractual daily fee basis is unavailable.');
                }
            }
            $position = ['assessed_kobo' => 0, 'settled_kobo' => 0, 'waived_kobo' => 0, 'outstanding_kobo' => 0];
            $newFee = 0;
            $existingFee = 0;
            $message = 'The captured agreement has no fee. Quoting and submitting do not post a payment.';
            if ($snapshot->model !== FeeRuleModel::NoFee && $snapshot->amount_kobo > 0 && $snapshot->timing !== FeeRuleTiming::Withdrawal) {
                $obligation = $snapshot->obligation;
                if ($obligation === null || $obligation->customer_profile_id !== $plan->customer_profile_id
                    || $obligation->fee_snapshot_id !== $snapshot->id || $obligation->source_type !== $snapshot->source_type
                    || $obligation->source_id !== $snapshot->source_id || $obligation->currency !== 'NGN'
                    || $obligation->kind !== $snapshot->kind->value || $obligation->amount_kobo !== $snapshot->amount_kobo) {
                    throw new ConflictHttpException('The existing cycle fee assessment is unavailable.');
                }
                $position = ['assessed_kobo' => $obligation->assessedAmountKobo(), 'settled_kobo' => $obligation->settledAmountKobo(),
                    'waived_kobo' => $obligation->waivedAmountKobo(), 'outstanding_kobo' => $obligation->outstandingAmountKobo()];
                if ($snapshot->timing === FeeRuleTiming::CycleCompletion) {
                    $existingFee = $position['outstanding_kobo'];
                    $approvedPayout = $type === 'end_of_cycle' && $plan->status === ThriftPlanStatus::Completed
                        || ($type === 'full' && $this->hasPreparedEarlyPayoutAgreement($plan, $snapshot));
                    if ($existingFee > 0 && (! $approvedPayout || $snapshot->settlement_source !== FeeSettlementSource::WithdrawalPayout)) {
                        throw new ConflictHttpException('The cycle-completion fee requires its approved settlement path.');
                    }
                }
                $message = $existingFee > 0
                    ? 'This payout collects an existing assessed fee. It does not create another assessment.'
                    : 'The cycle fee was assessed separately. It is not charged again by this payout. Any remaining unpaid fee requires its approved settlement path.';
            } elseif ($snapshot->model !== FeeRuleModel::NoFee && $snapshot->timing === FeeRuleTiming::Withdrawal) {
                if ($snapshot->settlement_source !== FeeSettlementSource::WithdrawalPayout
                    || ($snapshot->model === FeeRuleModel::Percentage && $snapshot->basis !== FeeRuleBasis::GrossWithdrawalDebit)
                    || ($snapshot->model === FeeRuleModel::OneDay && $snapshot->basis !== FeeRuleBasis::ContractualDailyContribution)) {
                    throw new ConflictHttpException('Withdrawal fee terms are not configured for payout settlement.');
                }
                $position = $this->cycleFees->read($plan, $snapshot);
                $newFee = $position['consumed'] ? 0 : match ($snapshot->model) {
                    FeeRuleModel::Fixed => $snapshot->amount_kobo,
                    FeeRuleModel::OneDay => $snapshot->basis_amount_kobo,
                    FeeRuleModel::Percentage => FeePercentageCalculator::calculate($gross, $snapshot->basis_points ?? 0),
                };
                $message = $snapshot->model === FeeRuleModel::Percentage
                    ? 'The new fee applies to this successful withdrawal. Earlier payout fees remain separate.'
                    : ($position['consumed']
                        ? 'The once-per-cycle fee was already charged. This payout does not charge it again.'
                        : ($position['restored']
                            ? 'The earlier erroneous payout fee was fully corrected. The once-per-cycle fee becomes due on the next successful payout.'
                            : 'The once-per-cycle fee becomes due only if this payout is successfully posted.'));
            }

            return ['amount_kobo' => $newFee + $existingFee, 'disclosure' => [
                'new_fee' => MoneyFormatter::formatNaira($newFee), 'existing_fee_included' => MoneyFormatter::formatNaira($existingFee),
                'already_assessed' => MoneyFormatter::formatNaira($position['assessed_kobo']),
                'already_settled' => MoneyFormatter::formatNaira($position['settled_kobo']),
                'already_waived' => MoneyFormatter::formatNaira($position['waived_kobo']),
                'existing_unpaid' => MoneyFormatter::formatNaira($position['outstanding_kobo']), 'message' => $message,
            ]];
        } catch (ConflictHttpException $exception) {
            throw $exception;
        } catch (\RuntimeException|\InvalidArgumentException $exception) {
            throw new ConflictHttpException('The cycle fee quote history is unavailable.', $exception);
        }
    }

    private function hasPreparedEarlyPayoutAgreement(ThriftPlan $plan, FeeSnapshot $snapshot): bool
    {
        if ($plan->status !== ThriftPlanStatus::Paused || ! app(EarlyTerminationPolicy::class)->supports($snapshot)) {
            return false;
        }
        $forUpdate = DB::transactionLevel() > 0;
        $event = $plan->lifecycleEvents()->where('event_type', 'early_termination_prepared')
            ->where('plan_version', $plan->version)->where('to_status', ThriftPlanStatus::Paused->value)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->latest('id')->first();
        if ($event === null) {
            return false;
        }
        $payload = $event->getAttribute('payload');
        if (! is_array($payload)) {
            return false;
        }
        $fee = $payload['termination_fee'] ?? null;
        $fingerprint = $payload['preview_fingerprint'] ?? null;
        if (! is_array($fee) || ($payload['action'] ?? null) !== 'prepare_termination'
            || ($payload['can_prepare'] ?? null) !== true || ($payload['plan_version'] ?? null) !== $plan->version - 1
            || ($fee['snapshot_id'] ?? null) !== $snapshot->id
            || ($fee['policy_version'] ?? null) !== EarlyTerminationPolicy::VERSION
            || ($fee['description'] ?? null) !== $snapshot->early_termination_description
            || ! is_string($fingerprint) || strlen($fingerprint) !== 64) {
            return false;
        }
        $proofs = FinancialWorkflowSupplement::query()->where('thrift_plan_id', $plan->id)
            ->where('customer_profile_id', $plan->customer_profile_id)->where('kind', 'early_termination_prepared')
            ->where('actor_user_id', $event->actor_user_id)->where('facts->event_id', $event->id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get();
        $proof = $proofs->first();

        return $proofs->count() === 1 && $proof !== null
            && ($proof->facts['gate_fingerprint'] ?? null) === $fingerprint;
    }

    /** @param array<string, mixed> $quote */
    private function fingerprint(array $quote): string
    {
        unset($quote['preview_fingerprint']);

        return hash_hmac('sha256', json_encode($quote, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @param array<string, mixed> $data */
    private function attemptHash(string $operation, int $actorId, int $targetId, array $data): string
    {
        ksort($data);

        return hash('sha256', json_encode([$operation, $actorId, $targetId, $data], JSON_THROW_ON_ERROR));
    }

    private function replayedAttempt(User $actor, string $reference, string $operation, string $hash): ?WithdrawalRequest
    {
        $attempt = WithdrawalAttempt::query()->where('attempt_reference', $reference)->lockForUpdate()->first();
        if ($attempt === null) {
            return null;
        }
        if ($attempt->actor_user_id !== $actor->id || $attempt->operation !== $operation
            || ! hash_equals($attempt->payload_hash, $hash)) {
            throw new ConflictHttpException('This attempt reference belongs to a different operation.');
        }
        $withdrawal = WithdrawalRequest::query()->findOrFail($attempt->withdrawal_request_id);
        Gate::forUser($actor)->authorize('view', $withdrawal->customerProfile);

        return $withdrawal;
    }

    public function assertReservationAndBalance(WithdrawalRequest $withdrawal, CustomerProfile $customer): void
    {
        $plan = ThriftPlan::query()->whereKey($withdrawal->thrift_plan_id)->lockForUpdate()->firstOrFail();
        if (! in_array($plan->status, [ThriftPlanStatus::Active, ThriftPlanStatus::Paused, ThriftPlanStatus::Completed], true)
            || ($withdrawal->type === 'end_of_cycle' && $plan->status !== ThriftPlanStatus::Completed)) {
            throw new ConflictHttpException('The source cycle is no longer eligible.');
        }
        $revision = $plan->currentTermsRevision();
        $snapshot = $revision?->feeSnapshot;
        if ($revision === null || $snapshot === null || ! $this->snapshotBinding->isValid($plan, $revision, $snapshot)
            || $snapshot->id !== $withdrawal->fee_snapshot_id
            || $this->feeQuote($snapshot, $withdrawal->gross_amount_kobo, $withdrawal->type, $plan)['amount_kobo'] !== $withdrawal->fee_amount_kobo) {
            throw new ConflictHttpException('The agreed fee quote no longer matches the source cycle.');
        }
        app(WithdrawalDeductionContract::class)->assertUnchanged($withdrawal, true);
        $reservation = DB::table('withdrawal_reservations')->where('id', $withdrawal->withdrawal_reservation_id)->lockForUpdate()->first();
        if ($reservation === null || $reservation->status !== 'live' || $reservation->owner_reference !== $withdrawal->withdrawal_id
            || (int) $reservation->gross_amount_kobo !== $withdrawal->gross_amount_kobo) {
            throw new ConflictHttpException('The withdrawal reservation is unavailable.');
        }
        $position = $this->position($customer, $plan, true);
        if ($position['liability_kobo'] < $position['reservations_kobo']
            || $position['cycle_liability_kobo'] < $position['cycle_reservations_kobo']) {
            throw new ConflictHttpException('Savings no longer cover the withdrawal reservation.');
        }
    }

    private function releaseReservation(WithdrawalRequest $withdrawal): void
    {
        $changed = DB::table('withdrawal_reservations')->where('id', $withdrawal->withdrawal_reservation_id)
            ->where('owner_reference', $withdrawal->withdrawal_id)->where('status', 'live')
            ->update(['status' => 'released', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        if ($changed !== 1) {
            throw new ConflictHttpException('The live reservation could not be released.');
        }
    }

    private function event(WithdrawalRequest $withdrawal, string $eventType, ?string $from, ?User $actor, ?string $reason, ?string $explanation): void
    {
        $event = WithdrawalEvent::create([
            'withdrawal_request_id' => $withdrawal->id, 'actor_user_id' => $actor?->id,
            'event_type' => $eventType, 'from_state' => $from, 'to_state' => $withdrawal->state,
            'request_version' => $withdrawal->version, 'reason' => $reason,
            'customer_explanation' => $explanation, 'effective_at' => now(),
        ]);
        AuditEvent::record('withdrawal.'.$eventType, WithdrawalRequest::class, $withdrawal->id, $withdrawal->withdrawal_id,
            ['state' => $withdrawal->state, 'version' => $withdrawal->version,
                'gross_kobo' => $withdrawal->gross_amount_kobo, 'fee_kobo' => $withdrawal->fee_amount_kobo,
                'net_kobo' => $withdrawal->net_amount_kobo, 'deduction_kobo' => $withdrawal->deduction_amount_kobo,
                'customer_profile_id' => $withdrawal->customer_profile_id], $actor,
            context: ['executor' => self::class, 'approver_id' => $withdrawal->reviewed_by_user_id, 'required_permission' => $actor?->user_type === UserType::Admin ? 'withdrawals.review' : null]
        );
        $this->notices->queue($withdrawal, $event);
    }

    public function archivalStatus(CustomerProfile $customer): string
    {
        foreach (WithdrawalRequest::query()->where('customer_profile_id', $customer->id)->get() as $request) {
            if (! in_array($request->state, ['rejected', 'cancelled', 'revoked', 'expired'], true)) {
                return in_array($request->state, self::LIVE_STATES, true) ? 'blocked' : 'unavailable';
            }
            $reservation = DB::table('withdrawal_reservations')->where('id', $request->withdrawal_reservation_id)->first();
            if ($reservation === null || (int) $reservation->customer_profile_id !== $customer->id
                || $reservation->owner_reference !== $request->withdrawal_id || $reservation->status !== 'released'
                || $request->terminal_at === null) {
                return 'unavailable';
            }
        }
        foreach (DB::table('withdrawal_reservations')->where('customer_profile_id', $customer->id)->get() as $reservation) {
            if ($reservation->status === 'live') {
                return 'blocked';
            }
            if ($reservation->status !== 'released' || ! WithdrawalRequest::query()->where('withdrawal_reservation_id', $reservation->id)->exists()) {
                return 'unavailable';
            }
        }

        return 'passed';
    }

    public function agentOffboardingStatus(AgentProfile $agent, bool $forUpdate = false): string
    {
        $query = WithdrawalRequest::query()->where('initiating_agent_profile_id', $agent->id)->orderBy('id');
        if ($forUpdate) {
            $query->lockForUpdate();
        }
        foreach ($query->get() as $request) {
            if (in_array($request->state, ['pending_review', 'approved'], true) && app(CustomerReassignmentService::class)
                ->hasVerifiedHandover($request->customerProfile, $agent->id, $forUpdate)) {
                continue;
            }
            if (! in_array($request->state, ['rejected', 'cancelled', 'revoked', 'expired'], true)) {
                return 'unavailable';
            }
            $reservation = DB::table('withdrawal_reservations')->where('id', $request->withdrawal_reservation_id)->first();
            if ($reservation === null || $reservation->status !== 'released' || $reservation->owner_reference !== $request->withdrawal_id
                || (int) $reservation->customer_profile_id !== $request->customer_profile_id || $request->terminal_at === null) {
                return 'unavailable';
            }
        }

        return 'passed';
    }
}
