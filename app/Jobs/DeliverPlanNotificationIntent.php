<?php

namespace App\Jobs;

use App\Enums\AccountState;
use App\Enums\UserType;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\PlanNotificationIntent;
use App\Models\User;
use App\Notifications\ThriftPlanNotification;
use App\Services\AgentEligibilityService;
use App\Services\NotificationPipeline;
use App\Services\PlatformGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

class DeliverPlanNotificationIntent implements ShouldQueue
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
        return [(new WithoutOverlapping('plan-notification-'.$this->intentId))->expireAfter(300)];
    }

    public function handle(AgentEligibilityService $eligibilityService): void
    {
        app(PlatformGuard::class)->work('external', function () use ($eligibilityService): void {
            $this->handleAllowed($eligibilityService);
        });
    }

    private function handleAllowed(AgentEligibilityService $eligibilityService): void
    {
        $intent = PlanNotificationIntent::query()->find($this->intentId);
        if ($intent === null || $intent->status !== 'pending') {
            return;
        }

        if ($intent->channel === 'database') {
            app(NotificationPipeline::class)->deliverOwner('plan', $this->intentId);

            return;
        }

        $recipient = User::query()->find($intent->recipient_user_id);
        if ($recipient === null || ! $this->recipientIsStillAuthorized($intent, $recipient, $eligibilityService)) {
            $intent->forceFill(['status' => 'suppressed', 'suppressed_at' => now(), 'failure_reason' => null])->save();

            return;
        }

        if ($intent->channel === 'database' && $recipient->notifications()->whereKey($intent->notification_id)->exists()) {
            $intent->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();

            return;
        }

        Notification::sendNow(
            $recipient,
            new ThriftPlanNotification($intent->notification_id, $intent->payload, $intent->channel),
            [$intent->channel],
        );
        $intent->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'failure_reason' => null])->save();
    }

    public function failed(?Throwable $exception): void
    {
        PlanNotificationIntent::query()->whereKey($this->intentId)->where('status', 'pending')->update([
            'status' => 'failed',
            'failure_reason' => 'Delivery failed after retrying.',
            'updated_at' => now(),
        ]);
    }

    private function recipientIsStillAuthorized(
        PlanNotificationIntent $intent,
        User $recipient,
        AgentEligibilityService $eligibilityService,
    ): bool {
        $customer = CustomerProfile::query()->find($intent->customer_profile_id);
        if ($customer === null) {
            return false;
        }

        if ($intent->audience_type === 'subject_customer') {
            if ($customer->user_id !== $recipient->id) {
                return false;
            }

            return match ($intent->channel) {
                'database' => $recipient->account_state === AccountState::Active,
                'mail' => $recipient->account_state === AccountState::Active && $recipient->email_verified_at !== null,
                default => false,
            };
        }

        if ($intent->audience_type !== 'current_agent'
            || $intent->channel !== 'database'
            || $recipient->account_state !== AccountState::Active
            || $recipient->user_type !== UserType::Agent) {
            return false;
        }

        $assignment = CustomerAssignment::query()
            ->where('customer_profile_id', $customer->id)
            ->where('is_current', 1)
            ->first();
        $agent = $recipient->agentProfile;

        return $assignment !== null
            && $agent !== null
            && $assignment->agent_profile_id === $agent->id
            && $eligibilityService->canReadAssignedCustomers($recipient);
    }
}
