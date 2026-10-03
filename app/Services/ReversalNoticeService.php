<?php

namespace App\Services;

use App\Jobs\DeliverReversalNotificationIntent;
use App\Models\ReversalEvent;
use App\Models\ReversalNotificationIntent;
use App\Models\ReversalRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReversalNoticeService
{
    public function queue(ReversalRequest $reversal, ReversalEvent $event): void
    {
        $customer = $reversal->customerProfile;
        $agentUser = $customer->currentAssignment?->agentProfile?->user;
        $customerUser = $customer->user;
        $message = match ($event->event_type) {
            'submitted' => 'A financial correction request was submitted for review. The original transaction remains effective.',
            'approved_posted' => 'A reviewed correction was posted. Open your account to see the original and linked correction.',
            'approved_no_money' => 'A reviewed correction was completed. Existing fee refunds are preserved; no new money movement was required.',
            'rejected' => 'A financial correction request was rejected. The original transaction remains effective.',
            'cancelled' => 'A financial correction request was cancelled. The original transaction remains effective.',
            default => 'A financial correction request was updated.',
        };
        $recipients = [[$agentUser, 'current_agent', 'database']];
        if (in_array($event->event_type, ['approved_posted', 'approved_no_money'], true)) {
            $recipients[] = [$customerUser, 'subject_customer', 'database'];
            $recipients[] = [$customerUser, 'subject_customer', 'mail'];
        }
        foreach ($recipients as [$recipient, $audience, $channel]) {
            if ($recipient === null) {
                continue;
            }
            $intent = ReversalNotificationIntent::create([
                'notification_id' => (string) Str::uuid(), 'reversal_event_id' => $event->id,
                'customer_profile_id' => $customer->id, 'recipient_user_id' => $recipient->id,
                'audience_type' => $audience, 'channel' => $channel,
                'payload' => [
                    'title' => 'Financial correction update', 'message' => $message,
                    'reversal_id' => $reversal->reversal_id, 'state' => $reversal->state,
                    'url' => route('reversals.show', $reversal),
                ], 'status' => 'pending',
            ]);
            if ($channel === 'database') {
                app(NotificationPipeline::class)->capture('reversal', $intent->id, false);
            }

            DB::afterCommit(static function () use ($intent): void {
                if ($intent->channel === 'database') {
                    app(NotificationPipeline::class)->dispatchRecoverably(static fn () => DeliverReversalNotificationIntent::dispatch($intent->id)->afterCommit());
                } else {
                    DeliverReversalNotificationIntent::dispatch($intent->id)->afterCommit();
                }
            });
        }
    }
}
