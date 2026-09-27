<?php

namespace App\Jobs;

use App\Enums\AccountState;
use App\Enums\DeliveryStatus;
use App\Enums\InvitationStatus;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\Invitation;
use App\Notifications\Auth\AgentInvitationNotification;
use App\Services\InvitationDeliveryIssues;
use App\Services\InvitationSenderReadinessService;
use App\Services\PlatformGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class DeliverAgentInvitationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     * Maximum 3 attempts within 15 minutes.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $invitationId,
        #[\SensitiveParameter]
        public string $plainToken,
        public int $generation = 1,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(InvitationSenderReadinessService $senderService): void
    {
        app(PlatformGuard::class)->work('external', function () use ($senderService): void {
            $this->handleAllowed($senderService);
        });
    }

    private function handleAllowed(InvitationSenderReadinessService $senderService): void
    {
        /** @var Invitation|null $invitation */
        $invitation = Invitation::with('user')->find($this->invitationId);

        if (! $invitation) {
            return;
        }

        // 1. Suppress stale, superseded, or unusable work
        if ($invitation->generation !== $this->generation) {
            Log::info("Suppressing stale invitation delivery job for generation {$this->generation}.");

            return;
        }

        if ($invitation->status === InvitationStatus::Cancelled
            || $invitation->status === InvitationStatus::Activated
            || $invitation->isExpired()) {
            Log::info("Suppressing unusable invitation delivery job for invitation {$invitation->id} in status {$invitation->status->value}.");

            return;
        }

        if ($invitation->user->account_state !== AccountState::Invited) {
            Log::info("Suppressing invitation delivery job because account state is {$invitation->user->account_state->value}.");

            return;
        }

        if (in_array($invitation->delivery_status, [DeliveryStatus::Sent, DeliveryStatus::Uncertain], true)) {
            return;
        }

        $business = BusinessProfile::current();
        $senderEmail = $senderService->resolveSenderEmail($business);
        $senderName = $senderService->resolveSenderName($business);

        try {
            $notification = new AgentInvitationNotification(
                plainToken: $this->plainToken,
                businessName: $business->display_name,
                recipientName: $invitation->user->name,
                expiresAtFormatted: $invitation->expires_at->timezone('Africa/Lagos')->format('Y-m-d H:i T'),
                fromAddress: $senderEmail,
                fromName: $senderName,
            );

            Notification::route('mail', $invitation->target_email)->notifyNow($notification);

            // Update invitation delivery state on success
            $invitation->status = InvitationStatus::Sent;
            $invitation->delivery_status = DeliveryStatus::Sent;
            $invitation->sent_at = now();
            $invitation->delivery_error = null;
            $invitation->save();

            AuditEvent::record(
                eventType: 'invitation.sent',
                targetType: Invitation::class,
                targetId: $invitation->id,
                targetReference: null,
                payload: [
                    'recipient_email_normalized' => $invitation->target_email_normalized,
                    'generation' => $invitation->generation,
                ],
                actor: null,

                context: ['executor' => self::class]
            );
        } catch (Throwable $e) {
            $this->handleFailure($invitation, $e);

        }
    }

    /**
     * Handle delivery failure.
     */
    protected function handleFailure(Invitation $invitation, Throwable $exception): void
    {
        app(InvitationDeliveryIssues::class)->record($invitation->id, $this->generation, 'acceptance_unknown', $this->attempts());
    }
}
