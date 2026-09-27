<?php

namespace App\Jobs;

use App\Enums\AccountState;
use App\Models\CustomerProfile;
use App\Models\CustomerRecovery;
use App\Models\User;
use App\Notifications\CustomerHandoverNotification;
use App\Services\ManagementMailDelivery;
use App\Services\NotificationPipeline;
use App\Services\PlatformGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class DeliverCustomerHandoverNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $intentId) {}

    public function handle(NotificationPipeline $pipeline): void
    {
        $notice = DB::table('customer_handover_notices')->where('id', $this->intentId)->first();
        if ($notice === null || $notice->status !== 'pending') {
            return;
        }
        if ($notice->channel === 'database') {
            $pipeline->deliverOwner('handover', $this->intentId);

            return;
        }
        app(PlatformGuard::class)->work('external', function () use ($notice): void {
            $payload = json_decode(Crypt::decryptString($notice->payload), true, flags: JSON_THROW_ON_ERROR);
            app(ManagementMailDelivery::class)->deliver('handover', (int) $notice->id,
                fn (): bool => $this->authorized($notice, $payload),
                function () use ($notice, $payload): void {
                    $recipient = User::query()->where('id', $notice->recipient_user_id)->firstOrFail();
                    Notification::route('mail', $payload['email'] ?? $recipient->email)->notifyNow(new CustomerHandoverNotification($payload));
                });
        });
    }

    /** @param array<string, mixed> $payload */
    private function authorized(\stdClass $notice, array $payload): bool
    {
        $recipient = User::query()->where('id', $notice->recipient_user_id)->first();
        if ($recipient === null) {
            return false;
        }
        if ($notice->audience_type === 'subject_customer') {
            return CustomerProfile::query()->whereKey($notice->customer_profile_id)->where('user_id', $recipient->id)->exists();
        }
        if ($notice->audience_type === 'subject_agent') {
            return $recipient->agentProfile?->id === $notice->agent_profile_id;
        }
        if ($notice->audience_type !== 'recovery_address') {
            return false;
        }
        $recovery = CustomerRecovery::query()->where('reference', $payload['recovery_reference'])->first();
        $allowed = $recovery !== null && $recovery->customerProfile->user_id === $recipient->id;
        if ($notice->purpose === 'recovery_activation') {
            $allowed = $allowed && $recipient->account_state === AccountState::Active && $recipient->recovery_pending
                && $recovery->state === 'awaiting_activation' && $recovery->activation_expires_at?->gt(now())
                && $recovery->activation_token_hash !== null && hash_equals($recovery->activation_token_hash, $payload['token_hash']);
        }

        return $allowed;
    }
}
