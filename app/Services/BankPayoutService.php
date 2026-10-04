<?php

namespace App\Services;

use App\Data\PayoutCallback;
use App\Data\PayoutInstruction;
use App\Data\PayoutProviderResult;
use App\Enums\AdminPermission;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Enums\PayoutProviderOutcome;
use App\Enums\UserType;
use App\Jobs\DispatchBankPayout;
use App\Models\AuditEvent;
use App\Models\BankPayoutAttempt;
use App\Models\BankPayoutCallback;
use App\Models\BankPayoutReturn;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Models\WithdrawalEvent;
use App\Models\WithdrawalRequest;
use App\Support\PayoutProvider;
use App\Support\PlatformBlocked;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Executes an approved bank-transfer withdrawal exactly once.
 *
 * The attempt and its idempotency key are committed (state Payout processing) before any external side effect.
 * A provider result is final only when it comes from a query by the same key. A success is recorded as evidence in one
 * transaction and posted in another, so a posting failure never causes a second transfer and is retried by the reconciler.
 */
class BankPayoutService
{
    public function __construct(
        private AuthorizationService $authorization,
        private FreshAuthenticationService $freshAuthentication,
        private PayoutProvider $provider,
        private BankMethodCatalogue $contract,
        private WithdrawalPostingService $posting,
        private BankPayoutLedger $ledger,
        private WithdrawalService $withdrawals,
    ) {}

    public function start(User $actor, WithdrawalRequest $request, string $attemptReference, int $version, Request $httpRequest): BankPayoutAttempt
    {
        $created = false;
        $attempt = app(PlatformGuard::class)->transaction('financial', function () use ($actor, $request, $attemptReference, $version, $httpRequest, &$created): BankPayoutAttempt {
            $actor = $this->executor($actor, $httpRequest);
            $customer = CustomerProfile::query()->whereKey($request->customer_profile_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('view', $customer);
            $withdrawal = WithdrawalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$actor->id, $withdrawal->id, $version], JSON_THROW_ON_ERROR));
            $existing = BankPayoutAttempt::query()->where('attempt_reference', $attemptReference)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->start_payload_hash, $hash)) {
                    throw new ConflictHttpException('This attempt reference belongs to another instruction.');
                }

                return $existing;
            }
            if ($withdrawal->method !== 'bank_transfer' || $withdrawal->version !== $version
                || ! in_array($withdrawal->state, ['approved', 'payment_failed'], true) || $withdrawal->held
                || $withdrawal->deadline_at->isPast() || ! $customer->operational_status->allowsWithdrawalOfExistingFunds()) {
                throw new ConflictHttpException('This withdrawal cannot start a bank transfer.');
            }
            $contract = $this->contract->contract($withdrawal->method_version);
            $previous = BankPayoutAttempt::query()->where('withdrawal_request_id', $withdrawal->id)->lockForUpdate()->get();
            if ($previous->count() >= $contract['limits']['max_attempts_per_request']) {
                throw new ConflictHttpException('The maximum number of bank transfer attempts was reached. Reject or revoke this request.');
            }
            $method = app(WithdrawalMethodRegistry::class)->resolve($customer->id, 'bank_transfer', $withdrawal->destination_reference);
            if ($method['version'] !== $withdrawal->method_version || $method['payout_destination_id'] !== $withdrawal->customer_payout_destination_id
                || $method['destination_mask'] !== $withdrawal->destination_mask) {
                throw new ConflictHttpException('The approved bank destination or method changed. Reject and request a new quote.');
            }
            $this->withdrawals->assertReservationAndBalance($withdrawal, $customer);
            $timezone = BusinessProfile::current()->timezone;
            app(FinancialPeriodService::class)->assertOpen(now($timezone)->toDateString(), $timezone, true);
            $payout = $this->posting->account(LedgerAccountCode::PayoutClearing, LedgerAccountClass::PayoutClearing, LedgerEntrySide::Credit);
            $funding = $this->posting->account(LedgerAccountCode::BusinessBank, LedgerAccountClass::Asset, LedgerEntrySide::Debit);
            $this->posting->account(LedgerAccountCode::CustomerSavingsLiability, LedgerAccountClass::CustomerSavingsLiability, LedgerEntrySide::Credit);
            if ($withdrawal->fee_amount_kobo > 0) {
                $this->posting->account(LedgerAccountCode::FeeIncome, LedgerAccountClass::FeeIncome, LedgerEntrySide::Credit);
            }
            if ($withdrawal->deduction_amount_kobo > 0) {
                $this->posting->account(LedgerAccountCode::OtherDeductionDestination, LedgerAccountClass::OtherDeductionDestination, LedgerEntrySide::Credit);
            }
            $this->assertLimitsAndFunding($withdrawal, $contract);
            $destination = $withdrawal->destination()->firstOrFail();
            $number = ((int) $previous->max('attempt_number')) + 1;
            $attempt = BankPayoutAttempt::create([
                'attempt_reference' => $attemptReference, 'withdrawal_request_id' => $withdrawal->id, 'attempt_number' => $number,
                'live_withdrawal_request_id' => $withdrawal->id, 'executor_user_id' => $actor->id,
                'customer_payout_destination_id' => $destination->id, 'destination_version' => $destination->version,
                'amount_kobo' => $withdrawal->net_amount_kobo, 'currency' => 'NGN', 'method_version' => $withdrawal->method_version,
                'payout_mapping_version' => $payout->version, 'funding_mapping_version' => $funding->version,
                'provider_key' => $this->provider->key(),
                'idempotency_key' => hash('sha256', $withdrawal->withdrawal_id.':'.$number.':'.$attemptReference),
                'start_payload_hash' => $hash, 'status' => 'prepared', 'dispatch_count' => 0,
                'next_check_at' => now()->addSeconds((int) config('withdrawals.bank.first_check_after_seconds', 60)),
            ]);
            $this->transition($withdrawal, 'payout_processing', 'bank_started', $actor, $attempt);
            $created = true;

            return $attempt;
        }, attempts: 3);
        if ($created) {
            DB::afterCommit(static fn () => DispatchBankPayout::dispatch($attempt->id));
        }

        return $attempt;
    }

    /**
     * Send the transfer for a prepared attempt. Called by the queued job and by the reconciler; the same idempotency key makes a repeat safe.
     */
    public function dispatch(int $attemptId): void
    {
        $instruction = $this->claim($attemptId);
        if ($instruction === null) {
            return;
        }
        try {
            $first = $this->provider->initiate($instruction);
        } catch (Throwable) {
            $first = null;
        }
        $result = $first;
        if ($first !== null && in_array($first->outcome, [PayoutProviderOutcome::Succeeded, PayoutProviderOutcome::Failed], true)) {
            try {
                $result = $this->provider->query($instruction->idempotencyKey);
            } catch (Throwable) {
                $result = null;
            }
        }
        $this->applyProviderResult($attemptId, $result, 'dispatch');
        $this->postIfSucceeded($attemptId);
    }

    /** Executor action: ask the provider about this attempt now. */
    public function check(User $actor, BankPayoutAttempt $attempt): BankPayoutAttempt
    {
        $actor = User::query()->whereKey($actor->id)->firstOrFail();
        if (! $this->authorization->allows($actor, AdminPermission::WithdrawalsReview)) {
            throw new AuthorizationException('Withdrawal-review authority is required.');
        }
        Gate::forUser($actor)->authorize('view', $attempt->withdrawalRequest->customerProfile);
        $this->reconcileAttempt($attempt->id);

        return $attempt->fresh() ?? $attempt;
    }

    /** @param bool $recheckFinalized Query an already failed attempt too, so a late contradicting success becomes a visible exception. */
    public function reconcileAttempt(int $attemptId, bool $recheckFinalized = false): void
    {
        $attempt = BankPayoutAttempt::query()->find($attemptId);
        if ($attempt === null) {
            return;
        }
        if ($attempt->status === 'succeeded') {
            $this->postIfSucceeded($attemptId);

            return;
        }
        if ($attempt->finalized_at !== null && ! $recheckFinalized) {
            return;
        }
        try {
            $result = $this->provider->query($attempt->idempotency_key);
        } catch (Throwable) {
            $result = null;
        }
        if ($attempt->status === 'prepared' && ($result === null || $result->outcome === PayoutProviderOutcome::NotFound)) {
            $this->dispatch($attemptId);

            return;
        }
        $this->applyProviderResult($attemptId, $result, 'query');
        $this->postIfSucceeded($attemptId);
    }

    public function reconcileDue(int $limit = 100): int
    {
        try {
            app(PlatformGuard::class)->assertAllowed('financial');
        } catch (PlatformBlocked) {
            return 0;
        }
        $processed = 0;
        $ids = BankPayoutAttempt::query()->where(function ($query): void {
            $query->where(fn ($query) => $query->where('status', 'prepared')->where('created_at', '<=', now()->subSeconds(30)))
                ->orWhere(fn ($query) => $query->whereIn('status', ['submitted', 'unknown'])->where('next_check_at', '<=', now()))
                ->orWhere(fn ($query) => $query->where('status', 'succeeded')->whereNull('ledger_posting_group_id'));
        })->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            try {
                $this->reconcileAttempt($id);
                $processed++;
            } catch (PlatformBlocked) {
                return $processed;
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $pendingSettlements = BankPayoutAttempt::query()->whereNotNull('ledger_posting_group_id')->whereNull('settlement_posting_group_id')
            ->whereIn('id', BankPayoutCallback::query()->where('event_type', 'transfer.settled')->whereNotNull('bank_payout_attempt_id')->select('bank_payout_attempt_id'))
            ->orderBy('id')->limit($limit)->pluck('id');
        foreach ($pendingSettlements as $id) {
            try {
                $this->recordSettlement($id);
                $processed++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $aged = BankPayoutAttempt::query()->where('status', 'unknown')
            ->where('created_at', '<=', now()->subMinutes((int) config('withdrawals.bank.unknown_alert_minutes', 60)))->count();
        if ($aged > 0) {
            logger()->warning('Bank payout attempts remain in an unknown outcome beyond the alert threshold.', ['count' => $aged]);
        }

        return $processed;
    }

    /**
     * Apply one provider answer. Only a query result may finalize an attempt; $result null means the call failed without an answer.
     */
    public function applyProviderResult(int $attemptId, ?PayoutProviderResult $result, string $source): BankPayoutAttempt
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($attemptId, $result): BankPayoutAttempt {
            [$customer, $withdrawal, $attempt] = $this->lockAttempt($attemptId);
            $outcome = $result?->outcome;
            $attempt->last_checked_at = now();
            if ($attempt->finalized_at !== null) {
                if ($result !== null && (($attempt->status === 'failed' && $result->outcome === PayoutProviderOutcome::Succeeded)
                    || ($attempt->status === 'succeeded' && $result->outcome === PayoutProviderOutcome::Failed))) {
                    $this->recordConflict($withdrawal, $attempt, $result, $attempt->status === 'failed' ? 'late_success_after_failure' : 'failure_after_success');
                }

                return $attempt;
            }
            $recheck = now()->addSeconds((int) config('withdrawals.bank.recheck_after_seconds', 120));
            if ($outcome === PayoutProviderOutcome::NotFound) {
                if ($attempt->initiated_at === null) {
                    return $attempt;
                }
                $window = now()->subMinutes((int) config('withdrawals.bank.not_found_definitive_after_minutes', 30));
                if ($attempt->provider_outcome === 'accepted' || $attempt->initiated_at->greaterThan($window)) {
                    $outcome = PayoutProviderOutcome::Unknown;
                } else {
                    $outcome = PayoutProviderOutcome::Failed;
                    $result = new PayoutProviderResult(PayoutProviderOutcome::Failed, failureCode: 'not_found_after_window',
                        evidence: ['provider' => $this->provider->key(), 'status' => 'not_found_after_window']);
                }
            }
            switch ($outcome) {
                case PayoutProviderOutcome::Accepted:
                    $changed = $attempt->status !== 'submitted';
                    $attempt->forceFill(['status' => 'submitted', 'provider_outcome' => 'accepted',
                        'provider_reference' => $attempt->provider_reference ?? $result?->providerReference, 'next_check_at' => $recheck])->save();
                    if ($changed) {
                        $this->transition($withdrawal, 'payout_processing', 'bank_submitted', null, $attempt);
                    }
                    break;
                case PayoutProviderOutcome::Succeeded:
                    $this->finalizeSuccess($withdrawal, $attempt, $result, $recheck);
                    break;
                case PayoutProviderOutcome::Failed:
                    $this->finalizeFailure($customer, $withdrawal, $attempt, $result ?? new PayoutProviderResult(PayoutProviderOutcome::Failed, failureCode: 'provider_failed'));
                    break;
                default:
                    $changed = $attempt->status !== 'unknown';
                    $attempt->forceFill(['status' => 'unknown', 'provider_outcome' => $outcome?->value, 'next_check_at' => $recheck])->save();
                    if ($changed) {
                        $this->transition($withdrawal, 'outcome_unknown', 'bank_unknown', null, $attempt);
                    }
            }

            return $attempt;
        }, attempts: 3);
    }

    /** Post a recorded provider success. Idempotent: one balanced group per withdrawal, ever. */
    public function postIfSucceeded(int $attemptId): ?LedgerPostingGroup
    {
        $attempt = BankPayoutAttempt::query()->find($attemptId);
        if ($attempt === null || $attempt->status !== 'succeeded' || $attempt->ledger_posting_group_id !== null) {
            return null;
        }

        return app(PlatformGuard::class)->transaction('financial', function () use ($attemptId): ?LedgerPostingGroup {
            [$customer, $withdrawal, $attempt] = $this->lockAttempt($attemptId);
            if ($attempt->status !== 'succeeded' || $attempt->ledger_posting_group_id !== null) {
                return null;
            }
            if (! in_array($withdrawal->state, ['payout_processing', 'outcome_unknown'], true)) {
                throw new ConflictHttpException('This withdrawal is no longer awaiting its payout posting.');
            }
            $this->contract->version($attempt->method_version);
            $this->withdrawals->assertReservationAndBalance($withdrawal, $customer);
            $payout = $this->posting->account(LedgerAccountCode::PayoutClearing, LedgerAccountClass::PayoutClearing, LedgerEntrySide::Credit);
            if ($payout->version !== $attempt->payout_mapping_version) {
                throw new ConflictHttpException('The payout accounting map changed after the transfer started.');
            }
            $executor = User::query()->findOrFail($attempt->executor_user_id);
            $group = $this->posting->post($withdrawal, $payout, 'bank_withdrawal', 'PAY-'.$attempt->attempt_reference,
                'bank-withdrawal-'.$withdrawal->id, $attempt->start_payload_hash, $attempt->provider_occurred_at ?? now(), $attempt->executor_user_id,
                $attempt->attempt_reference, ['attempt_reference' => $attempt->attempt_reference, 'provider_reference' => $attempt->provider_reference,
                    'payout_mapping_version' => $attempt->payout_mapping_version, 'funding_mapping_version' => $attempt->funding_mapping_version,
                    'method_version' => $attempt->method_version], $executor);
            $consumed = DB::table('withdrawal_reservations')->where('id', $withdrawal->withdrawal_reservation_id)
                ->where('status', 'live')->where('owner_reference', $withdrawal->withdrawal_id)
                ->update(['status' => 'consumed', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            if ($consumed !== 1) {
                throw new ConflictHttpException('The original reservation could not be consumed.');
            }
            $attempt->forceFill(['ledger_posting_group_id' => $group->id, 'live_withdrawal_request_id' => null, 'next_check_at' => null])->save();
            $withdrawal->live_thrift_plan_id = null;
            $withdrawal->terminal_at = now();
            $this->transition($withdrawal, 'posted', 'bank_posted', null, $attempt);
            app(LedgerTransactionProjectionService::class)->projectWithdrawal($withdrawal);

            return $group;
        }, attempts: 3);
    }

    /**
     * Record a signed provider callback. A callback only proves that something happened; finality still comes from a same-key query.
     *
     * @return 'applied'|'duplicate'|'conflict'|'unmatched'
     */
    public function ingestCallback(PayoutCallback $callback): string
    {
        try {
            $disposition = $this->recordCallback($callback);
        } catch (UniqueConstraintViolationException) {
            $existing = BankPayoutCallback::query()->where('provider_key', $callback->providerKey)->where('event_id', $callback->eventId)->firstOrFail();
            $disposition = hash_equals($existing->payload_hash, $callback->payloadHash) ? 'duplicate' : 'conflict';
        }
        if ($disposition !== 'applied') {
            return $disposition;
        }
        $attempt = BankPayoutAttempt::query()->where('idempotency_key', $callback->idempotencyKey)->firstOrFail();
        match ($callback->eventType) {
            'transfer.succeeded', 'transfer.failed' => $this->reconcileAttempt($attempt->id, true),
            'transfer.settled' => $this->recordSettlement($attempt->id),
            'transfer.returned' => $this->recordReturn($attempt->id, $callback),
            default => null,
        };

        return 'applied';
    }

    /** @return 'applied'|'duplicate'|'conflict'|'unmatched' */
    private function recordCallback(PayoutCallback $callback): string
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($callback): string {
            $existing = BankPayoutCallback::query()->where('provider_key', $callback->providerKey)->where('event_id', $callback->eventId)->lockForUpdate()->first();
            if ($existing !== null) {
                return hash_equals($existing->payload_hash, $callback->payloadHash) ? 'duplicate' : 'conflict';
            }
            $attempt = BankPayoutAttempt::query()->where('idempotency_key', $callback->idempotencyKey)->where('provider_key', $callback->providerKey)->first();
            $disposition = $attempt === null ? 'unmatched' : 'applied';
            BankPayoutCallback::create(['provider_key' => $callback->providerKey, 'event_id' => $callback->eventId, 'event_type' => $callback->eventType,
                'idempotency_key' => $callback->idempotencyKey, 'payload_hash' => $callback->payloadHash, 'signature_timestamp' => $callback->signatureTimestamp,
                'bank_payout_attempt_id' => $attempt?->id, 'disposition' => $disposition,
                'sanitized_payload' => ['event_type' => $callback->eventType, 'provider_reference' => $callback->providerReference,
                    'amount_kobo' => $callback->amountKobo, 'return_reference' => $callback->returnReference],
                'received_at' => now()]);

            return $disposition;
        }, attempts: 3);
    }

    public function recordSettlement(int $attemptId): ?LedgerPostingGroup
    {
        $result = app(PlatformGuard::class)->transaction('financial', function () use ($attemptId): ?LedgerPostingGroup {
            [, $withdrawal, $attempt] = $this->lockAttempt($attemptId);
            if ($attempt->ledger_posting_group_id === null || $attempt->settlement_posting_group_id !== null
                || BankPayoutReturn::query()->where('bank_payout_attempt_id', $attempt->id)->whereIn('status', ['recorded', 'posted', 'consumed'])
                    ->lockForUpdate()->count() > 0) {
                return null;
            }
            $group = $this->ledger->recordSettlement($attempt, $withdrawal, now());
            $attempt->forceFill(['settlement_posting_group_id' => $group->id, 'settled_at' => now()])->save();
            $this->event($withdrawal, 'bank_settled', null, $attempt);

            return $group;
        }, attempts: 3);
        if ($result !== null) {
            app(LedgerTransactionProjectionService::class)->projectBankPayoutMovement($result);
        }

        return $result;
    }

    public function recordReturn(int $attemptId, PayoutCallback $callback): BankPayoutReturn
    {
        $result = app(PlatformGuard::class)->transaction('financial', function () use ($attemptId, $callback): BankPayoutReturn {
            [, $withdrawal, $attempt] = $this->lockAttempt($attemptId);
            $reference = $callback->returnReference ?? $callback->eventId;
            $existing = BankPayoutReturn::query()->where('provider_key', $callback->providerKey)->where('provider_return_reference', $reference)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }
            $amount = $callback->amountKobo ?? $attempt->amount_kobo;
            $returned = (int) BankPayoutReturn::query()->where('bank_payout_attempt_id', $attempt->id)->where('status', '!=', 'exception')
                ->lockForUpdate()->get(['amount_kobo'])->sum('amount_kobo');
            $posted = $attempt->ledger_posting_group_id !== null;
            $valid = $posted && $amount >= 1 && $returned + $amount <= $attempt->amount_kobo;
            $return = BankPayoutReturn::create(['return_reference' => (string) Str::uuid(), 'bank_payout_attempt_id' => $attempt->id,
                'provider_key' => $callback->providerKey, 'provider_return_reference' => $reference, 'amount_kobo' => max(1, $amount),
                'status' => $valid ? 'recorded' : 'exception', 'evidence' => json_encode(['event_id' => $callback->eventId,
                    'provider_reference' => $callback->providerReference, 'valid' => $valid], JSON_THROW_ON_ERROR), 'payload_hash' => $callback->payloadHash]);
            if (! $valid) {
                $this->event($withdrawal, 'provider_conflict', null, $attempt);

                return $return;
            }
            $group = $this->ledger->recordReturn($return, $attempt, $withdrawal, $callback->occurredAt ?? now());
            $return->forceFill(['status' => 'posted', 'return_posting_group_id' => $group->id])->save();
            $this->event($withdrawal, 'bank_returned', null, $attempt);

            return $return;
        }, attempts: 3);
        if ($result->return_posting_group_id !== null) {
            app(LedgerTransactionProjectionService::class)->projectBankPayoutMovement(LedgerPostingGroup::query()->findOrFail($result->return_posting_group_id));
        }

        return $result;
    }

    private function claim(int $attemptId): ?PayoutInstruction
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($attemptId): ?PayoutInstruction {
            $attempt = BankPayoutAttempt::query()->whereKey($attemptId)->lockForUpdate()->first();
            if ($attempt === null || $attempt->status !== 'prepared') {
                return null;
            }
            $withdrawal = WithdrawalRequest::query()->findOrFail($attempt->withdrawal_request_id);
            $destination = $attempt->destination()->firstOrFail();
            $attempt->forceFill(['dispatch_count' => $attempt->dispatch_count + 1, 'initiated_at' => $attempt->initiated_at ?? now()])->save();

            return new PayoutInstruction($attempt->idempotency_key, $attempt->amount_kobo, $attempt->currency, $destination->account_token,
                $destination->bank_code, 'Withdrawal '.$withdrawal->withdrawal_id);
        }, attempts: 3);
    }

    private function finalizeSuccess(WithdrawalRequest $withdrawal, BankPayoutAttempt $attempt, ?PayoutProviderResult $result, CarbonImmutable $recheck): void
    {
        $destination = $attempt->destination()->firstOrFail();
        $reference = $result?->providerReference;
        $duplicate = $reference !== null && BankPayoutAttempt::query()->where('provider_key', $attempt->provider_key)
            ->where('provider_reference', $reference)->where('id', '!=', $attempt->id)->exists();
        if ($result === null || $reference === null || $duplicate || $result->amountKobo !== $attempt->amount_kobo || $result->currency !== $attempt->currency
            || $result->destinationToken !== $destination->account_token) {
            $attempt->forceFill(['status' => 'unknown', 'provider_outcome' => 'unknown', 'failure_code' => 'result_mismatch', 'next_check_at' => $recheck])->save();
            $this->transition($withdrawal, 'outcome_unknown', 'bank_unknown', null, $attempt);
            if ($result !== null) {
                $this->recordConflict($withdrawal, $attempt, $result, 'result_mismatch');
            }

            return;
        }
        $attempt->forceFill(['status' => 'succeeded', 'provider_outcome' => 'succeeded', 'provider_reference' => $reference,
            'provider_occurred_at' => $result->occurredAt ?? now(), 'provider_evidence' => $result->evidence, 'finalized_at' => now(), 'next_check_at' => null])->save();
        AuditEvent::record('withdrawal.bank_submitted', BankPayoutAttempt::class, $attempt->id, $attempt->attempt_reference,
            ['customer_profile_id' => $withdrawal->customer_profile_id, 'attempt_reference' => $attempt->attempt_reference,
                'provider_outcome' => 'succeeded', 'provider_reference' => $reference, 'amount_kobo' => $attempt->amount_kobo, 'status' => 'succeeded',
                'source' => 'provider_query'],
            null, context: ['executor' => self::class, 'operation_id' => 'provider_result:'.$attempt->attempt_reference]);
    }

    private function finalizeFailure(CustomerProfile $customer, WithdrawalRequest $withdrawal, BankPayoutAttempt $attempt, PayoutProviderResult $result): void
    {
        $attempt->forceFill(['status' => 'failed', 'provider_outcome' => 'failed', 'failure_code' => $result->failureCode ?? 'provider_failed',
            'provider_evidence' => $result->evidence, 'finalized_at' => now(), 'next_check_at' => null, 'live_withdrawal_request_id' => null])->save();
        $held = $this->withdrawals->applyFailureTerms($withdrawal, $customer);
        $this->transition($withdrawal, 'payment_failed', 'bank_failed', null, $attempt);
        if ($held) {
            $this->withdrawals->recordHoldApplied($withdrawal);
        }
    }

    /**
     * Provider evidence that contradicts what was already recorded. Nothing is posted or reversed automatically:
     * the exception stays on file, blocks further transfers and plan closure, and needs an owner's decision.
     */
    private function recordConflict(WithdrawalRequest $withdrawal, BankPayoutAttempt $attempt, PayoutProviderResult $result, string $reason): void
    {
        $reference = 'conflict:'.$attempt->attempt_reference.':'.$reason;
        $exists = BankPayoutReturn::query()->where('provider_key', $attempt->provider_key)->where('provider_return_reference', $reference)->exists();
        if ($exists) {
            return;
        }
        BankPayoutReturn::create(['return_reference' => (string) Str::uuid(), 'bank_payout_attempt_id' => $attempt->id, 'provider_key' => $attempt->provider_key,
            'provider_return_reference' => $reference, 'amount_kobo' => $attempt->amount_kobo, 'status' => 'exception',
            'evidence' => json_encode(['reason' => $reason, 'outcome' => $result->outcome->value, 'provider_reference' => $result->providerReference], JSON_THROW_ON_ERROR),
            'payload_hash' => hash('sha256', $reference)]);
        if (! $withdrawal->held && in_array($withdrawal->state, ['pending_review', 'approved', 'payment_failed'], true)) {
            $withdrawal->held = true;
            $withdrawal->hold_reason = 'provider_conflict';
            $withdrawal->held_at = now();
            $withdrawal->save();
        }
        $this->event($withdrawal, 'provider_conflict', null, $attempt);
    }

    /** @param array<string, mixed> $contract */
    private function assertLimitsAndFunding(WithdrawalRequest $withdrawal, array $contract): void
    {
        $net = $withdrawal->net_amount_kobo;
        if ($net > $contract['limits']['per_payout_max_kobo']) {
            throw new ConflictHttpException('This transfer exceeds the per-transfer limit of the bank method.');
        }
        $today = (int) BankPayoutAttempt::query()->where('status', '!=', 'failed')->where('created_at', '>=', now()->startOfDay())->lockForUpdate()->sum('amount_kobo');
        if ($today + $net > $contract['limits']['daily_business_max_kobo']) {
            throw new ConflictHttpException('The daily bank transfer limit would be exceeded.');
        }
        $position = app(FinancialCashPosition::class);
        $available = $position->balance(LedgerAccountCode::BusinessBank, true) - $position->balance(LedgerAccountCode::PayoutClearing, true) - $position->reservedBankPayoutKobo(true);
        if ($available < $net) {
            throw new ConflictHttpException('Verified business bank funding does not cover this transfer.');
        }
    }

    private function executor(User $actor, Request $request): User
    {
        $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        if (! $this->authorization->allows($actor, AdminPermission::WithdrawalsReview)
            || ! $this->freshAuthentication->isFresh($actor, $request)) {
            throw new AuthorizationException('Fresh withdrawal-review authority is required to execute a bank transfer.');
        }

        return $actor;
    }

    /** @return array{CustomerProfile, WithdrawalRequest, BankPayoutAttempt} */
    private function lockAttempt(int $attemptId): array
    {
        $source = BankPayoutAttempt::query()->findOrFail($attemptId);
        $reference = WithdrawalRequest::query()->findOrFail($source->withdrawal_request_id);
        $customer = CustomerProfile::query()->whereKey($reference->customer_profile_id)->lockForUpdate()->firstOrFail();
        $withdrawal = WithdrawalRequest::query()->whereKey($reference->id)->lockForUpdate()->firstOrFail();

        return [$customer, $withdrawal, BankPayoutAttempt::query()->whereKey($attemptId)->lockForUpdate()->firstOrFail()];
    }

    private function transition(WithdrawalRequest $withdrawal, string $state, string $eventType, ?User $actor, BankPayoutAttempt $attempt): void
    {
        $before = $withdrawal->state;
        $withdrawal->state = $state;
        $this->event($withdrawal, $eventType, $actor, $attempt, $before);
    }

    private function event(WithdrawalRequest $withdrawal, string $eventType, ?User $actor, BankPayoutAttempt $attempt, ?string $from = null): void
    {
        $withdrawal->version++;
        $withdrawal->save();
        $event = WithdrawalEvent::create(['withdrawal_request_id' => $withdrawal->id, 'actor_user_id' => $actor?->id,
            'event_type' => $eventType, 'from_state' => $from ?? $withdrawal->state, 'to_state' => $withdrawal->state,
            'request_version' => $withdrawal->version, 'effective_at' => now()]);
        AuditEvent::record('withdrawal.'.$eventType, WithdrawalRequest::class, $withdrawal->id, $withdrawal->withdrawal_id,
            ['state' => $withdrawal->state, 'version' => $withdrawal->version, 'customer_profile_id' => $withdrawal->customer_profile_id,
                'attempt_reference' => $attempt->attempt_reference, 'net_kobo' => $attempt->amount_kobo, 'provider_outcome' => $attempt->provider_outcome,
                'failure_code' => $attempt->failure_code], $actor,
            context: ['executor' => self::class, 'approver_id' => $withdrawal->reviewed_by_user_id,
                'operation_id' => $eventType.':'.$attempt->attempt_reference.':'.$withdrawal->version,
                'required_permission' => $actor?->user_type === UserType::Admin ? 'withdrawals.review' : null]);
        app(WithdrawalNoticeService::class)->queue($withdrawal, $event);
    }
}
