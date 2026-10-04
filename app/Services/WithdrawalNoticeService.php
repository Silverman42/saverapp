<?php

namespace App\Services;

use App\Jobs\DeliverWithdrawalNotificationIntent;
use App\Models\WithdrawalEvent;
use App\Models\WithdrawalNotificationIntent;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WithdrawalNoticeService
{
    public function queue(WithdrawalRequest $withdrawal, WithdrawalEvent $event): void
    {
        $customer = $withdrawal->customerProfile;
        $customerUser = $customer->user;
        $agentUser = $customer->currentAssignment?->agentProfile?->user;
        $message = match ($event->event_type) {
            'cash_return_recorded' => 'Please confirm the full amount of cash personally returned to the original custodian.',
            'cash_return_confirmed' => 'A full return was confirmed. A posted payout still requires reviewed correction.',
            'cash_started' => 'Cash is reserved for an approved payment.',
            'cash_handoff_recorded' => 'Please confirm the exact cash received. Until then the outcome remains unknown.',
            'cash_posted' => 'Cash receipt was confirmed and the withdrawal was posted.',
            'cash_not_delivered' => 'No cash was handed over; savings remain reserved.',
            'submitted' => 'A withdrawal request was submitted for review. Savings are reserved; no payout has been made.',
            'approve' => 'A withdrawal request was approved. No payout has been made yet.',
            'reject' => 'A withdrawal request was rejected and its savings reservation released.',
            'cancel', 'revoke' => 'A withdrawal request was cancelled and its savings reservation released.',
            'expired' => 'A withdrawal request expired and its savings reservation was released.',
            'hold_applied' => 'A withdrawal request is on hold. No payout can proceed.',
            'hold_lifted' => 'A withdrawal hold was lifted. The request still needs its normal next step.',
            'hold_revalidation_required' => 'A withdrawal hold was lifted but the request must be revalidated before it can proceed.',
            'bank_started' => 'A bank transfer was started for an approved withdrawal. Savings stay reserved until the provider confirms.',
            'bank_submitted' => 'The bank transfer was accepted by the provider. The final result is not known yet.',
            'bank_unknown' => 'The bank transfer result is not yet known. Savings stay reserved and no second payment will be sent.',
            'bank_failed' => 'The bank transfer did not go through. Savings remain reserved.',
            'bank_posted' => 'The bank transfer was confirmed and the withdrawal was posted.',
            'bank_settled' => 'The provider settled the bank transfer. Your savings balance did not change again.',
            'bank_returned' => 'The bank returned the transfer. It is being reviewed; your savings balance has not changed yet.',
            'provider_conflict' => 'The provider reported conflicting results for a withdrawal. It is on hold for review.',
            default => 'A withdrawal request was updated.',
        };
        foreach ([[$customerUser, 'subject_customer', 'database'], [$customerUser, 'subject_customer', 'mail'], [$agentUser, 'current_agent', 'database']] as [$recipient, $audience, $channel]) {
            if ($recipient === null) {
                continue;
            }
            $intent = WithdrawalNotificationIntent::create([
                'notification_id' => (string) Str::uuid(), 'withdrawal_event_id' => $event->id,
                'customer_profile_id' => $customer->id, 'recipient_user_id' => $recipient->id,
                'audience_type' => $audience, 'channel' => $channel,
                'payload' => [
                    'title' => 'Withdrawal '.$withdrawal->withdrawal_id,
                    'message' => $message, 'withdrawal_id' => $withdrawal->withdrawal_id,
                    'state' => $withdrawal->state, 'url' => route('withdrawals.show', $withdrawal),
                ], 'status' => 'pending',
            ]);
            if ($channel === 'database') {
                app(NotificationPipeline::class)->capture('withdrawal', $intent->id, false);
            }

            DB::afterCommit(static function () use ($intent): void {
                if ($intent->channel === 'database') {
                    app(NotificationPipeline::class)->dispatchRecoverably(static fn () => DeliverWithdrawalNotificationIntent::dispatch($intent->id)->afterCommit());
                } else {
                    DeliverWithdrawalNotificationIntent::dispatch($intent->id)->afterCommit();
                }
            });
        }
    }
}
