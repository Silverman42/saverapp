<?php

namespace App\Jobs;

use App\Enums\AccountState;
use App\Enums\UserType;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Models\WithdrawalNotificationIntent;
use App\Notifications\WithdrawalStatusNotification;
use App\Services\AgentEligibilityService;
use App\Services\NotificationPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

class DeliverWithdrawalNotificationIntent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(public int $intentId) {}

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('withdrawal-notice-'.$this->intentId))->expireAfter(300)];
    }

    public function handle(AgentEligibilityService $eligibility): void
    {
        $intent = WithdrawalNotificationIntent::query()->find($this->intentId);
        if ($intent === null || $intent->status !== 'pending') {
            return;
        }
        if ($intent->channel === 'database') {
            app(NotificationPipeline::class)->deliverOwner('withdrawal', $this->intentId);

            return;
        }

        $recipient = User::query()->find($intent->recipient_user_id);
        $customer = CustomerProfile::query()->find($intent->customer_profile_id);
        $allowed = false;
        if ($recipient !== null && $customer !== null && $recipient->account_state === AccountState::Active) {
            if ($intent->audience_type === 'subject_customer') {
                $allowed = $customer->user_id === $recipient->id
                    && ($intent->channel !== 'mail' || $recipient->email_verified_at !== null);
            } elseif ($intent->audience_type === 'current_agent' && $intent->channel === 'database') {
                $allowed = $recipient->user_type === UserType::Agent
                    && $customer->currentAssignment?->agent_profile_id === $recipient->agentProfile?->id
                    && $eligibility->canReadAssignedCustomers($recipient);
            }
        }
        if (! $allowed) {
            $intent->forceFill(['status' => 'suppressed', 'suppressed_at' => now()])->save();

            return;
        }
        if ($intent->channel === 'database' && $recipient->notifications()->whereKey($intent->notification_id)->exists()) {
            $intent->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();

            return;
        }
        Notification::sendNow($recipient,
            new WithdrawalStatusNotification($intent->notification_id, $intent->payload, $intent->channel),
            [$intent->channel]);
        $intent->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();
    }

    public function failed(?Throwable $exception): void
    {
        WithdrawalNotificationIntent::query()->whereKey($this->intentId)->where('status', 'pending')
            ->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
