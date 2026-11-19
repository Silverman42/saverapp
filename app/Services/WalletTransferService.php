<?php

namespace App\Services;

use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Models\WithdrawalEvent;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Posts a plan→wallet transfer (Module 18 §6.1) inside the submitting transaction: plan savings G leave the plan, fee F and deduction D
 * settle from the plan's snapshot, and the wallet is credited with P. No money leaves the business, so no Admin review applies.
 */
class WalletTransferService
{
    public function __construct(private WithdrawalService $withdrawals, private WithdrawalPostingService $posting) {}

    public function post(WithdrawalRequest $withdrawal, User $executor, string $idempotencyKey): LedgerPostingGroup
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A wallet transfer posts inside its submitting transaction.');
        }
        if ($withdrawal->isWalletSource() || $withdrawal->method !== 'wallet' || $withdrawal->state !== 'pending_review') {
            throw new ConflictHttpException('Only a new plan-to-wallet transfer can be posted.');
        }
        $customer = CustomerProfile::query()->whereKey($withdrawal->customer_profile_id)->lockForUpdate()->firstOrFail();
        $this->withdrawals->assertReservationAndBalance($withdrawal, $customer);
        $wallet = $this->posting->account(LedgerAccountCode::CustomerWalletLiability, LedgerAccountClass::CustomerWalletLiability, LedgerEntrySide::Credit);
        $hash = hash('sha256', json_encode([$withdrawal->withdrawal_id, $withdrawal->gross_amount_kobo, $withdrawal->fee_amount_kobo,
            $withdrawal->deduction_amount_kobo, $withdrawal->net_amount_kobo, $wallet->version], JSON_THROW_ON_ERROR));
        $group = $this->posting->post($withdrawal, $wallet, 'plan_to_wallet_transfer', 'WLT-'.$withdrawal->withdrawal_id,
            $idempotencyKey, $hash, now(), $executor->id, $withdrawal->withdrawal_id,
            ['initiated_by' => $withdrawal->initiated_by, 'wallet_mapping_version' => $wallet->version, 'type' => $withdrawal->type], $executor);
        $consumed = DB::table('withdrawal_reservations')->where('id', $withdrawal->withdrawal_reservation_id)
            ->where('status', 'live')->where('owner_reference', $withdrawal->withdrawal_id)
            ->update(['status' => 'consumed', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        if ($consumed !== 1) {
            throw new ConflictHttpException('The transfer reservation could not be consumed.');
        }
        $before = $withdrawal->state;
        $withdrawal->forceFill(['state' => 'posted', 'wallet_posting_group_id' => $group->id, 'approved_at' => now(),
            'terminal_at' => now(), 'version' => $withdrawal->version + 1])->save();
        $event = WithdrawalEvent::create(['withdrawal_request_id' => $withdrawal->id, 'actor_user_id' => $withdrawal->initiated_by === 'system' ? null : $executor->id,
            'event_type' => 'wallet_posted', 'from_state' => $before, 'to_state' => 'posted',
            'request_version' => $withdrawal->version, 'effective_at' => now()]);
        AuditEvent::record('withdrawal.wallet_posted', WithdrawalRequest::class, $withdrawal->id, $withdrawal->withdrawal_id,
            ['state' => 'posted', 'version' => $withdrawal->version, 'customer_profile_id' => $withdrawal->customer_profile_id,
                'gross_kobo' => $withdrawal->gross_amount_kobo, 'fee_kobo' => $withdrawal->fee_amount_kobo,
                'deduction_kobo' => $withdrawal->deduction_amount_kobo, 'net_kobo' => $withdrawal->net_amount_kobo,
                'initiated_by' => $withdrawal->initiated_by, 'posting_reference' => $group->posting_reference],
            $withdrawal->initiated_by === 'system' ? null : $executor,
            context: ['executor' => self::class, 'operation_id' => 'wallet_posted:'.$withdrawal->withdrawal_id,
                'required_permission' => $executor->user_type === UserType::Admin ? 'withdrawals.review' : null]);
        app(WithdrawalNoticeService::class)->queue($withdrawal, $event);
        app(LedgerTransactionProjectionService::class)->projectWithdrawal($withdrawal);
        app(PlanPageClosureService::class)->afterWithdrawalPosted($withdrawal, $withdrawal->initiated_by === 'system' ? null : $executor);

        return $group;
    }
}
