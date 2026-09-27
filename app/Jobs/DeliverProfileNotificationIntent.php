<?php

namespace App\Jobs;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\AgentProfile;
use App\Models\CustomerProfile;
use App\Models\ProfileNotificationIntent;
use App\Models\User;
use App\Notifications\ProfileChangeNotification;
use App\Services\AgentEligibilityService;
use App\Services\AuthorizationService;
use App\Services\ManagementMailDelivery;
use App\Services\NotificationPipeline;
use App\Services\PlatformCatalogue;
use App\Services\PlatformGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

class DeliverProfileNotificationIntent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(public int $intentId) {}

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('profile-notification-'.$this->intentId))->expireAfter(300)];
    }

    public function handle(AgentEligibilityService $eligibilityService, AuthorizationService $authorizationService): void
    {
        if (app(NotificationPipeline::class)->recoverLocalOwner('profile', $this->intentId)) {
            return;
        }

        app(PlatformGuard::class)->work('external', function () use ($eligibilityService, $authorizationService): void {
            $this->handleAllowed($eligibilityService, $authorizationService);
        });
    }

    private function handleAllowed(AgentEligibilityService $eligibilityService, AuthorizationService $authorizationService): void
    {
        $intent = ProfileNotificationIntent::query()->find($this->intentId);
        if ($intent === null || $intent->status !== 'pending') {
            return;
        }

        if ($intent->channel === 'database') {
            app(NotificationPipeline::class)->deliverOwner('profile', $this->intentId);

            return;
        }

        $recipient = User::query()->find($intent->recipient_user_id);
        if ($recipient === null || ! $this->recipientIsStillAuthorized($intent, $recipient, $eligibilityService, $authorizationService)) {
            $intent->forceFill(['status' => 'suppressed', 'suppressed_at' => now()])->save();

            return;
        }

        app(ManagementMailDelivery::class)->deliver('profile', $intent->id,
            fn (): bool => $this->recipientIsStillAuthorized($intent, $recipient->fresh(), $eligibilityService, $authorizationService),
            fn () => Notification::sendNow($recipient, new ProfileChangeNotification($intent->notification_id, $intent->payload, $intent->channel), [$intent->channel]));
    }

    public function failed(?Throwable $exception): void
    {
        if (app(PlatformCatalogue::class)->isLocalRecoveryJob($this)) {
            return;
        }

        ProfileNotificationIntent::query()
            ->whereKey($this->intentId)
            ->where('status', 'pending')
            ->update(['status' => 'failed', 'updated_at' => now()]);
    }

    protected function recipientIsStillAuthorized(
        ProfileNotificationIntent $intent,
        User $recipient,
        AgentEligibilityService $eligibilityService,
        AuthorizationService $authorizationService,
    ): bool {
        if ($intent->channel === 'database' && $recipient->account_state !== AccountState::Active) {
            return false;
        }

        if ($intent->subject_type === 'customer') {
            $customer = CustomerProfile::query()->with('currentAssignment.agentProfile')->find($intent->subject_id);
            if ($customer === null) {
                return false;
            }

            if ($intent->audience_type === 'subject_customer') {
                return $customer->user_id === $recipient->id;
            }

            if ($intent->audience_type === 'customer_manager') {
                return $recipient->user_type === UserType::Admin && $authorizationService->allows($recipient, AdminPermission::CustomersManage);
            }
            if ($intent->audience_type === 'current_agent') {
                $agent = $customer->currentAssignment?->agentProfile;

                return $agent !== null
                    && $agent->user_id === $recipient->id
                    && $eligibilityService->canReadAssignedCustomers($recipient);
            }

            return false;
        }

        if ($intent->subject_type === 'agent') {
            if ($intent->audience_type === 'subject_agent') {
                return AgentProfile::query()
                    ->whereKey($intent->subject_id)
                    ->where('user_id', $recipient->id)
                    ->exists();
            }

            if ($intent->audience_type === 'security_operations_admin') {
                return $recipient->user_type === UserType::Admin
                    && $authorizationService->allows($recipient, AdminPermission::SecurityOperationsManage);
            }
        }

        return false;
    }
}
