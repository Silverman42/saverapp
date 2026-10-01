<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\LedgerAccountCode;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\CashDisbursement;
use App\Models\CashExecution;
use App\Models\CashRecovery;
use App\Models\CustomerProfile;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\WithdrawalEvent;
use App\Models\WithdrawalRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CashRecoveryService
{
    /** @return array{preview_fingerprint: string, remaining_kobo: int, status: string} */
    public function preview(User $actor, CashExecution|CashDisbursement $execution): array
    {
        $customerId = $execution instanceof CashExecution ? WithdrawalRequest::query()->findOrFail($execution->withdrawal_request_id)->customer_profile_id : $execution->customer_profile_id;
        if ($customerId !== null) {
            Gate::forUser($actor)->authorize('view', CustomerProfile::query()->findOrFail($customerId));
        }
        abort_unless(in_array($actor->id, [$execution->executor_user_id, $execution->recipient_user_id], true), 403);
        $key = $execution instanceof CashExecution ? 'cash_execution_id' : 'cash_disbursement_id';
        $history = CashRecovery::query()->where($key, $execution->id)->orderBy('id')->get(['id', 'amount_kobo', 'event_type', 'status', 'return_posting_group_id']);
        $remaining = $execution->amount_kobo - $history->where('event_type', 'return')->sum('amount_kobo');
        $fingerprint = AuditProjection::digest(['source' => $execution->only(['id', 'execution_reference', 'executor_user_id', 'recipient_user_id', 'amount_kobo', 'method_version', 'cash_mapping_version', 'status', 'ledger_posting_group_id']),
            'customer_version' => $customerId === null ? null : CustomerProfile::query()->findOrFail($customerId)->version,
            'mappings' => LedgerAccount::query()->whereIn('code', [LedgerAccountCode::BusinessCash, LedgerAccountCode::CashRecoveryClearing])->orderBy('id')->get(['id', 'code', 'account_class', 'normal_balance', 'currency', 'mapping_status', 'version'])->toArray(),
            'history' => $history->toArray()]);

        return ['preview_fingerprint' => $fingerprint, 'remaining_kobo' => $remaining, 'status' => $execution->status];
    }

    private function assertPreview(User $actor, CashExecution|CashDisbursement $execution, Request $request): void
    {
        $fingerprint = $request->input('preview_fingerprint');
        if ($fingerprint !== null && ! hash_equals($this->preview($actor, $execution)['preview_fingerprint'], $fingerprint)) {
            throw new ConflictHttpException('Recovery dependencies changed. Review the exact attempt again.');
        }
    }

    public function recordReturn(User $actor, CashExecution $execution, string $reference, string $evidence, Request $request, ?int $amountKobo = null, string $eventType = 'return'): CashRecovery
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $execution, $reference, $evidence, $request, $amountKobo, $eventType): CashRecovery {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AuthorizationService::class)->allows($actor, AdminPermission::CashExecute)
                && app(FreshAuthenticationService::class)->isFresh($actor, $request), 403);
            [$withdrawal, $execution] = $this->lock($execution);
            Gate::forUser($actor)->authorize('view', $withdrawal->customerProfile);
            abort_unless($actor->id === $execution->executor_user_id, 403, 'The original custodian must prove the full cash return.');
            if (! in_array($eventType, ['return', 'dispute', 'custody_uncertain'], true)) {
                throw new ConflictHttpException('Unsupported recovery evidence.');
            }
            $amountKobo ??= $eventType === 'return' ? $execution->amount_kobo : 0;
            $hash = hash('sha256', json_encode([$reference, $actor->id, $execution->execution_reference, $amountKobo, $eventType, trim($evidence), $request->input('preview_fingerprint')], JSON_THROW_ON_ERROR));
            $existing = CashRecovery::query()->where('recovery_reference', $reference)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new ConflictHttpException('This attempt already has different immutable cash return evidence.');
                }

                return $existing;
            }
            $this->assertPreview($actor, $execution, $request);
            if (! in_array($execution->status, ['outcome_unknown', 'posted'], true) || $execution->handoff_at === null || trim($evidence) === '') {
                throw new ConflictHttpException('Only a full return of recorded handed-over cash can be confirmed here.');
            }
            $reserved = (int) CashRecovery::query()->where('cash_execution_id', $execution->id)->where('event_type', 'return')->sum('amount_kobo');
            if (($eventType === 'return' && ($amountKobo < 1 || $amountKobo > $execution->amount_kobo - $reserved)) || ($eventType !== 'return' && $amountKobo !== 0)) {
                throw new ConflictHttpException('Return evidence exceeds the original remaining cash.');
            }
            $recovery = CashRecovery::create(['recovery_reference' => $reference, 'cash_execution_id' => $execution->id,
                'custodian_user_id' => $actor->id, 'recipient_user_id' => $execution->recipient_user_id, 'amount_kobo' => $amountKobo, 'event_type' => $eventType,
                'evidence' => trim($evidence), 'payload_hash' => $hash, 'status' => $eventType === 'return' ? 'awaiting_customer' : 'open_exception']);
            $this->event($withdrawal, $recovery, $actor, 'cash_return_recorded');

            return $recovery;
        }, attempts: 3);
    }

    public function acknowledgeReturn(User $actor, CashRecovery $reference): CashRecovery
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $reference): CashRecovery {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            [$withdrawal, $execution] = $this->lock(CashExecution::query()->findOrFail($reference->cash_execution_id));
            $recovery = CashRecovery::query()->whereKey($reference->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('view', $withdrawal->customerProfile);
            abort_unless($actor->user_type === UserType::Customer && $actor->account_state->value === 'active'
                && $actor->id === $recovery->recipient_user_id && $recovery->event_type === 'return', 403);
            if (in_array($recovery->status, ['confirmed', 'consumed'], true)) {
                return $recovery;
            }
            if ($recovery->status !== 'awaiting_customer' || ! in_array($execution->status, ['outcome_unknown', 'posted'], true)) {
                throw new ConflictHttpException('The original payout disposition changed.');
            }
            $recovery->update(['status' => 'confirmed', 'confirmed_at' => now(), 'customer_acknowledgement' => json_encode([
                'recipient_user_id' => $actor->id, 'recovery_reference' => $recovery->recovery_reference,
                'execution_reference' => $execution->execution_reference, 'amount_kobo' => $recovery->amount_kobo,
                'confirmed_at' => now()->toIso8601String(), 'disposition' => 'returned_amount_confirmed',
            ], JSON_THROW_ON_ERROR)]);
            if ($execution->status === 'posted') {
                app(CashRecoveryLedger::class)->record($recovery, $actor, $withdrawal->customer_profile_id, $withdrawal->thrift_plan_id);
            }
            $total = (int) CashRecovery::query()->where('cash_execution_id', $execution->id)->where('event_type', 'return')->whereIn('status', ['confirmed', 'consumed'])->sum('amount_kobo');
            if ($execution->status === 'outcome_unknown' && $total === $execution->amount_kobo) {
                $execution->update(['status' => 'payment_failed', 'resolved_at' => now(), 'live_withdrawal_request_id' => null]);
                $withdrawal->state = 'payment_failed';
                foreach (CashRecovery::query()->where('cash_execution_id', $execution->id)->where('event_type', 'return')->where('status', 'confirmed')->get() as $returned) {
                    $returned->update(['status' => 'consumed', 'consumed_at' => now()]);
                }
            }
            $this->event($withdrawal, $recovery, $actor, 'cash_return_confirmed');
            if ($execution->status === 'posted') {
                app(LedgerTransactionProjectionService::class)->rebuild();
            }

            return $recovery;
        }, attempts: 3);
    }

    public function recordDisbursementReturn(User $actor, CashDisbursement $source, string $reference, int $amount, string $evidence, Request $request, string $eventType = 'return'): CashRecovery
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $source, $reference, $amount, $evidence, $request, $eventType): CashRecovery {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AuthorizationService::class)->allows($actor, AdminPermission::CashExecute)
                && app(FreshAuthenticationService::class)->isFresh($actor, $request)
                && ($source->kind !== 'earnings_draw' || app(AuthorizationService::class)->allows($actor, AdminPermission::FeesManage)), 403);
            if ($source->customer_profile_id !== null) {
                $customer = CustomerProfile::query()->whereKey($source->customer_profile_id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('view', $customer);
            }
            $source = CashDisbursement::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->id === $source->executor_user_id, 403);
            $hash = hash('sha256', json_encode([$source->id, $reference, $actor->id, $amount, trim($evidence), $eventType, $request->input('preview_fingerprint')], JSON_THROW_ON_ERROR));
            $existing = CashRecovery::query()->where('recovery_reference', $reference)->first();
            if ($existing !== null) {
                if (! hash_equals($hash, $existing->payload_hash)) {
                    throw new ConflictHttpException('Recovery identity conflicts with different evidence.');
                }

                return $existing;
            }
            $this->assertPreview($actor, $source, $request);
            $reserved = (int) CashRecovery::query()->where('cash_disbursement_id', $source->id)->where('event_type', 'return')->sum('amount_kobo');
            if (! in_array($source->status, ['posted', 'outcome_unknown'], true) || $source->handoff_at === null || trim($evidence) === ''
                || ! in_array($eventType, ['return', 'dispute', 'custody_uncertain'], true)
                || ($eventType === 'return' ? $amount < 1 || $amount > $source->amount_kobo - $reserved : $amount !== 0)) {
                throw new ConflictHttpException('The remaining original handoff must bound recovery evidence.');
            }
            $recovery = CashRecovery::create(['recovery_reference' => $reference, 'cash_disbursement_id' => $source->id,
                'custodian_user_id' => $actor->id, 'recipient_user_id' => $source->recipient_user_id, 'amount_kobo' => $amount,
                'event_type' => $eventType, 'payload_hash' => $hash, 'evidence' => trim($evidence),
                'status' => $eventType === 'return' ? 'awaiting_customer' : 'open_exception']);
            $this->disbursementEvent($source, $recovery, $actor, 'recovery_recorded');

            return $recovery;
        }, attempts: 3);
    }

    public function acknowledgeDisbursementReturn(User $actor, CashRecovery $reference, Request $request): CashRecovery
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $reference, $request): CashRecovery {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $initial = CashDisbursement::query()->findOrFail($reference->cash_disbursement_id);
            if ($initial->customer_profile_id !== null) {
                $customer = CustomerProfile::query()->whereKey($initial->customer_profile_id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('view', $customer);
            }
            $source = CashDisbursement::query()->whereKey($initial->id)->lockForUpdate()->firstOrFail();
            $recovery = CashRecovery::query()->whereKey($reference->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->id === $source->recipient_user_id && $actor->account_state->value === 'active', 403);
            if ($source->kind === 'earnings_draw') {
                abort_unless(app(AuthorizationService::class)->allows($actor, AdminPermission::FeesManage)
                    && app(AuthorizationService::class)->allows($actor, AdminPermission::CashExecute)
                    && app(FreshAuthenticationService::class)->isFresh($actor, $request), 403);
            }
            if (in_array($recovery->status, ['confirmed', 'consumed'], true)) {
                return $recovery;
            }
            if ($recovery->status !== 'awaiting_customer' || ! in_array($source->status, ['posted', 'outcome_unknown'], true)) {
                throw new ConflictHttpException('This handoff has no pending return acknowledgement.');
            }
            $recovery->update(['status' => 'confirmed', 'confirmed_at' => now(), 'customer_acknowledgement' => json_encode([
                'recipient_user_id' => $actor->id, 'execution_reference' => $source->execution_reference,
                'amount_kobo' => $recovery->amount_kobo, 'confirmed_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR)]);
            if ($source->status === 'posted') {
                app(CashRecoveryLedger::class)->record($recovery, $actor, $source->customer_profile_id, null);
            }
            $returns = CashRecovery::query()->where('cash_disbursement_id', $source->id)->where('event_type', 'return')->where('status', 'confirmed')->get();
            if ($returns->sum('amount_kobo') === $source->amount_kobo) {
                if ($source->status === 'posted') {
                    app(CashRecoveryLedger::class)->compensateDisbursement($source, $actor);
                } else {
                    $source->update(['status' => 'payment_failed', 'resolved_at' => now(), 'live_fee_refund_id' => null]);
                }
                foreach ($returns as $returned) {
                    $returned->update(['status' => 'consumed', 'consumed_at' => now()]);
                }
            }
            $this->disbursementEvent($source, $recovery, $actor, 'recovery_confirmed');
            app(LedgerTransactionProjectionService::class)->rebuild();

            return $recovery->fresh();
        }, attempts: 3);
    }

    private function disbursementEvent(CashDisbursement $source, CashRecovery $recovery, User $actor, string $event): void
    {
        AuditEvent::record('cash_disbursement.'.$event, CashRecovery::class, $recovery->id, $recovery->recovery_reference,
            ['customer_profile_id' => $source->customer_profile_id, 'execution_reference' => $source->execution_reference,
                'returned_kobo' => $recovery->amount_kobo, 'state' => $recovery->status], $actor,
            context: ['executor' => self::class, 'required_permission' => $actor->user_type === UserType::Admin ? 'cash.execute' : null]);
        app(FinancialCashNotice::class)->queue($actor, $source, $event, $recovery->recovery_reference, $recovery->amount_kobo);
    }

    /** @return array{WithdrawalRequest, CashExecution} */
    private function lock(CashExecution $execution): array
    {
        $source = WithdrawalRequest::query()->findOrFail($execution->withdrawal_request_id);
        CustomerProfile::query()->whereKey($source->customer_profile_id)->lockForUpdate()->firstOrFail();
        $withdrawal = WithdrawalRequest::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();

        return [$withdrawal, CashExecution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail()];
    }

    private function event(WithdrawalRequest $withdrawal, CashRecovery $recovery, User $actor, string $eventType): void
    {
        $withdrawal->version++;
        $withdrawal->save();
        $event = WithdrawalEvent::create(['withdrawal_request_id' => $withdrawal->id, 'actor_user_id' => $actor->id,
            'event_type' => $eventType, 'to_state' => $withdrawal->state, 'request_version' => $withdrawal->version, 'effective_at' => now()]);
        AuditEvent::record('withdrawal.'.$eventType, CashRecovery::class, $recovery->id, $recovery->recovery_reference,
            ['customer_profile_id' => $withdrawal->customer_profile_id, 'execution_reference' => $recovery->recovery_reference,
                'net_kobo' => $recovery->amount_kobo, 'state' => $withdrawal->state, 'version' => $withdrawal->version], $actor,
            context: ['executor' => self::class, 'required_permission' => $actor->user_type === UserType::Admin ? 'cash.execute' : null]);
        app(WithdrawalNoticeService::class)->queue($withdrawal, $event);
    }
}
