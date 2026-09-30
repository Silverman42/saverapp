<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\CashExecution;
use App\Models\CashRecovery;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Models\WithdrawalEvent;
use App\Models\WithdrawalRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CashRecoveryService
{
    public function recordReturn(User $actor, CashExecution $execution, string $reference, string $evidence, Request $request): CashRecovery
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $execution, $reference, $evidence, $request): CashRecovery {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AuthorizationService::class)->allows($actor, AdminPermission::CashExecute)
                && app(FreshAuthenticationService::class)->isFresh($actor, $request), 403);
            [$withdrawal, $execution] = $this->lock($execution);
            Gate::forUser($actor)->authorize('view', $withdrawal->customerProfile);
            abort_unless($actor->id === $execution->executor_user_id, 403, 'The original custodian must prove the full cash return.');
            $hash = hash('sha256', json_encode([$reference, $actor->id, $execution->execution_reference, $execution->amount_kobo, trim($evidence)], JSON_THROW_ON_ERROR));
            $existing = CashRecovery::query()->where('cash_execution_id', $execution->id)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new ConflictHttpException('This attempt already has different immutable cash return evidence.');
                }

                return $existing;
            }
            if (! in_array($execution->status, ['outcome_unknown', 'posted'], true) || $execution->handoff_at === null || trim($evidence) === '') {
                throw new ConflictHttpException('Only a full return of recorded handed-over cash can be confirmed here.');
            }
            $recovery = CashRecovery::create(['recovery_reference' => $reference, 'cash_execution_id' => $execution->id,
                'custodian_user_id' => $actor->id, 'recipient_user_id' => $execution->recipient_user_id, 'amount_kobo' => $execution->amount_kobo,
                'evidence' => trim($evidence), 'payload_hash' => $hash, 'status' => 'awaiting_customer']);
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
                && $actor->id === $recovery->recipient_user_id && $recovery->amount_kobo === $execution->amount_kobo, 403);
            if (in_array($recovery->status, ['confirmed', 'consumed'], true)) {
                return $recovery;
            }
            if ($recovery->status !== 'awaiting_customer' || ! in_array($execution->status, ['outcome_unknown', 'posted'], true)) {
                throw new ConflictHttpException('The original payout disposition changed.');
            }
            $recovery->update(['status' => 'confirmed', 'confirmed_at' => now(), 'customer_acknowledgement' => json_encode([
                'recipient_user_id' => $actor->id, 'recovery_reference' => $recovery->recovery_reference,
                'execution_reference' => $execution->execution_reference, 'amount_kobo' => $recovery->amount_kobo,
                'confirmed_at' => now()->toIso8601String(), 'disposition' => 'fully_returned',
            ], JSON_THROW_ON_ERROR)]);
            if ($execution->status === 'outcome_unknown') {
                $execution->update(['status' => 'payment_failed', 'resolved_at' => now(), 'live_withdrawal_request_id' => null]);
                $withdrawal->state = 'payment_failed';
                $recovery->update(['status' => 'consumed', 'consumed_at' => now()]);
            }
            $this->event($withdrawal, $recovery, $actor, 'cash_return_confirmed');

            return $recovery;
        }, attempts: 3);
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
