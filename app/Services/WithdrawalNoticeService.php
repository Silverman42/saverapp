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
            'submitted' => 'A withdrawal request was submitted for review. Savings are reserved; no payout has been made.',
            'approve' => 'A withdrawal request was approved. No payout has been made yet.',
            'reject' => 'A withdrawal request was rejected and its savings reservation released.',
            'cancel', 'revoke' => 'A withdrawal request was cancelled and its savings reservation released.',
            'expired' => 'A withdrawal request expired and its savings reservation was released.',
            'hold_applied' => 'A withdrawal request is on hold. No payout can proceed.',
            'hold_lifted' => 'A withdrawal hold was lifted. The request still needs its normal next step.',
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
