<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\OfflinePayout;
use App\Models\User;
use App\Models\WithdrawalEvent;
use App\Models\WithdrawalRequest;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Records an approved withdrawal that staff paid outside the platform (cash in hand or a manual bank transfer).
 *
 * There is no gateway and no Customer confirmation: the reviewer's attested record is the payout evidence. Recording posts
 * the withdrawal once, consumes its reservation and moves the request to Posted in one financial transaction.
 */
class OfflinePayoutService
{
    public function __construct(
        private AuthorizationService $authorization,
        private FreshAuthenticationService $freshAuthentication,
        private WithdrawalPostingService $posting,
        private WithdrawalService $withdrawals,
    ) {}

    /**
     * @param  array{attempt_reference: string, version: int, channel: string, external_reference: ?string, paid_on: string, note: string}  $data
     */
    public function record(User $actor, WithdrawalRequest $request, array $data, Request $httpRequest): OfflinePayout
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $request, $data, $httpRequest): OfflinePayout {
            $actor = $this->executor($actor, $httpRequest);
            $customer = CustomerProfile::query()->whereKey($request->customer_profile_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('view', $customer);
            $withdrawal = WithdrawalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $reference = filled($data['external_reference'] ?? null) ? trim((string) $data['external_reference']) : null;
            $note = trim($data['note']);
            $hash = hash('sha256', json_encode([$actor->id, $withdrawal->id, $data['version'], $data['channel'], $reference, $data['paid_on'], $note], JSON_THROW_ON_ERROR));
            $existing = OfflinePayout::query()->where('attempt_reference', $data['attempt_reference'])->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new ConflictHttpException('This attempt reference belongs to another payout record.');
                }

                return $existing;
            }
            if ($withdrawal->method !== 'offline' || $withdrawal->version !== $data['version'] || $withdrawal->state !== 'approved'
                || $withdrawal->held || $withdrawal->deadline_at->isPast() || ! $customer->operational_status->allowsWithdrawalOfExistingFunds()
                || OfflinePayout::query()->where('withdrawal_request_id', $withdrawal->id)->exists()) {
                throw new ConflictHttpException('This withdrawal cannot record an offline payout.');
            }
            $method = app(WithdrawalMethodRegistry::class)->resolve($customer->id, 'offline', $withdrawal->destination_reference);
            if ($method['version'] !== $withdrawal->method_version) {
                throw new ConflictHttpException('The approved offline method version changed. Reject and request a new quote.');
            }
            $timezone = BusinessProfile::current()->timezone;
            $occurredAt = $this->occurredAt($data['paid_on'], $timezone, $withdrawal);
            $this->withdrawals->assertReservationAndBalance($withdrawal, $customer);
            $payoutAccount = $data['channel'] === 'bank_transfer'
                ? $this->posting->account(LedgerAccountCode::BusinessBank, LedgerAccountClass::Asset, LedgerEntrySide::Debit)
                : $this->posting->account(LedgerAccountCode::BusinessCash, LedgerAccountClass::Asset, LedgerEntrySide::Debit);
            $group = $this->posting->post($withdrawal, $payoutAccount, 'offline_withdrawal', 'PAY-'.$data['attempt_reference'],
                'offline-withdrawal-'.$withdrawal->id, $hash, $occurredAt, $actor->id, $data['attempt_reference'],
                ['attempt_reference' => $data['attempt_reference'], 'channel' => $data['channel'], 'external_reference' => $reference,
                    'paid_on' => $data['paid_on'], 'method_version' => $withdrawal->method_version, 'payout_mapping_version' => $payoutAccount->version], $actor);
            $consumed = DB::table('withdrawal_reservations')->where('id', $withdrawal->withdrawal_reservation_id)
                ->where('status', 'live')->where('owner_reference', $withdrawal->withdrawal_id)
                ->update(['status' => 'consumed', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            if ($consumed !== 1) {
                throw new ConflictHttpException('The original reservation could not be consumed.');
            }
            $payout = OfflinePayout::create([
                'withdrawal_request_id' => $withdrawal->id, 'channel' => $data['channel'], 'external_reference' => $reference,
                'paid_on' => $data['paid_on'], 'note' => $note, 'amount_kobo' => $withdrawal->net_amount_kobo,
                'recorded_by_user_id' => $actor->id, 'attempt_reference' => $data['attempt_reference'],
                'payload_hash' => $hash, 'ledger_posting_group_id' => $group->id,
            ]);
            $withdrawal->live_thrift_plan_id = null;
            $withdrawal->terminal_at = now();
            $this->transition($withdrawal, $actor, $payout);
            app(LedgerTransactionProjectionService::class)->projectWithdrawal($withdrawal);
            app(PlanPageClosureService::class)->afterWithdrawalPosted($withdrawal, $actor);

            return $payout;
        }, attempts: 3);
    }

    private function executor(User $actor, Request $request): User
    {
        $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        if (! $this->authorization->allows($actor, AdminPermission::WithdrawalsReview)
            || ! $this->freshAuthentication->isFresh($actor, $request)) {
            throw new AuthorizationException('Fresh withdrawal-review authority is required to record an offline payout.');
        }

        return $actor;
    }

    /**
     * The payout is dated on the business day it was paid: today uses the current time, an earlier day uses its midday.
     */
    private function occurredAt(string $paidOn, string $timezone, WithdrawalRequest $withdrawal): CarbonImmutable
    {
        $today = CarbonImmutable::now($timezone)->toDateString();
        $approvedOn = $withdrawal->approved_at?->setTimezone($timezone)->toDateString() ?? $today;
        if ($paidOn > $today || $paidOn < $approvedOn) {
            throw ValidationException::withMessages(['paid_on' => ['The payment date must be between the approval date and today.']]);
        }

        return $paidOn === $today ? CarbonImmutable::now() : CarbonImmutable::createFromFormat('!Y-m-d', $paidOn, $timezone)->setTime(12, 0)->utc();
    }

    private function transition(WithdrawalRequest $withdrawal, User $actor, OfflinePayout $payout): void
    {
        $before = $withdrawal->state;
        $withdrawal->state = 'posted';
        $withdrawal->version++;
        $withdrawal->save();
        $event = WithdrawalEvent::create(['withdrawal_request_id' => $withdrawal->id, 'actor_user_id' => $actor->id,
            'event_type' => 'offline_posted', 'from_state' => $before, 'to_state' => 'posted',
            'request_version' => $withdrawal->version, 'effective_at' => now()]);
        AuditEvent::record('withdrawal.offline_posted', WithdrawalRequest::class, $withdrawal->id, $withdrawal->withdrawal_id,
            ['state' => 'posted', 'version' => $withdrawal->version, 'customer_profile_id' => $withdrawal->customer_profile_id,
                'attempt_reference' => $payout->attempt_reference, 'channel' => $payout->channel,
                'external_reference' => $payout->external_reference, 'paid_on' => $payout->paid_on->toDateString(),
                'net_kobo' => $payout->amount_kobo], $actor,
            context: ['executor' => self::class, 'approver_id' => $withdrawal->reviewed_by_user_id,
                'operation_id' => 'offline_posted:'.$payout->attempt_reference,
                'required_permission' => $actor->user_type === UserType::Admin ? 'withdrawals.review' : null]);
        app(WithdrawalNoticeService::class)->queue($withdrawal, $event);
    }
}
