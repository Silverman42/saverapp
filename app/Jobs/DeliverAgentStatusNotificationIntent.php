<?php

namespace App\Jobs;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\AgentStatus;
use App\Enums\CustomerStatus;
use App\Enums\UserType;
use App\Models\AgentProfile;
use App\Models\AgentStatusNotificationIntent;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Notifications\AgentStatusNotification;
use App\Services\AuthorizationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

class DeliverAgentStatusNotificationIntent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(public int $intentId) {}

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('agent-status-notification-'.$this->intentId))->expireAfter(300)];
    }

    public function handle(AuthorizationService $authorizationService): void
    {
        $intent = AgentStatusNotificationIntent::query()->find($this->intentId);
        if ($intent === null || $intent->status !== 'pending') {
            return;
        }

        $recipient = User::query()->find($intent->recipient_user_id);
        if ($recipient === null || ! $this->recipientIsStillAuthorized($intent, $recipient, $authorizationService)) {
            $intent->forceFill(['status' => 'suppressed', 'suppressed_at' => now(), 'failure_reason' => null])->save();

            return;
        }

        if ($intent->channel === 'database' && $recipient->notifications()->whereKey($intent->notification_id)->exists()) {
            $intent->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();

            return;
        }

        Notification::sendNow($recipient, new AgentStatusNotification($intent->notification_id, $intent->payload, $intent->channel), [$intent->channel]);
        $intent->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'failure_reason' => null])->save();
    }

    public function failed(?Throwable $exception): void
    {
        AgentStatusNotificationIntent::query()->whereKey($this->intentId)->where('status', 'pending')->update([
            'status' => 'failed',
            'failure_reason' => 'Delivery failed after retrying.',
            'updated_at' => now(),
        ]);
    }

    protected function recipientIsStillAuthorized(
        AgentStatusNotificationIntent $intent,
        User $recipient,
        AuthorizationService $authorizationService,
    ): bool {
        if ($intent->channel === 'database' && $recipient->account_state !== AccountState::Active) {
            return false;
        }

        $agent = AgentProfile::query()->find($intent->agent_profile_id);
        if ($agent === null) {
            return false;
        }

        if ($intent->audience_type === 'subject_agent') {
            return $agent->user_id === $recipient->id && $recipient->user_type === UserType::Agent;
        }

        if ($intent->audience_type === 'managing_admin') {
            return $intent->channel === 'database'
                && $authorizationService->allows($recipient, AdminPermission::AgentsManage);
        }

        if ($intent->audience_type !== 'assigned_customer' || $intent->customer_profile_id === null) {
            return false;
        }

        $customer = CustomerProfile::query()->find($intent->customer_profile_id);

        return $customer !== null
            && $agent->operational_status === AgentStatus::Inactive
            && $customer->user_id === $recipient->id
            && $recipient->user_type === UserType::Customer
            && $customer->operational_status !== CustomerStatus::Archived
            && CustomerAssignment::query()->where('customer_profile_id', $customer->id)
                ->where('agent_profile_id', $agent->id)->where('is_current', 1)->exists();
    }
}
