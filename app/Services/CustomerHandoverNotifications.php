<?php

namespace App\Services;

use App\Jobs\DeliverCustomerHandoverNotice;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerHandoverNotifications
{
    /** @param array<string, mixed> $details */
    public function event(CustomerProfile $customer, ?User $actor, string $type, int $version, array $details, ?int $recoveryId = null): int
    {
        $reference = (string) Str::uuid();
        $audit = AuditEvent::record($type, 'customer', $customer->id, $customer->customer_id,
            ['operation_id' => $reference, 'to_version' => $version, 'after_values' => $details, 'outcome' => 'succeeded'], $actor,
            ['executor' => self::class]);

        return DB::table('customer_handover_events')->insertGetId(['reference' => $reference,
            'customer_profile_id' => $customer->id, 'customer_recovery_id' => $recoveryId,
            'actor_user_id' => $actor?->id, 'audit_event_id' => $audit->id, 'event_type' => $type,
            'to_version' => $version, 'details' => Crypt::encryptString(json_encode($details, JSON_THROW_ON_ERROR)), 'effective_at' => now()]);
    }

    /** @param array<string, string> $payload */
    public function notice(int $eventId, User $recipient, string $audience, string $channel, string $purpose, ?CustomerProfile $customer, array $payload, ?int $agentId = null): void
    {
        $id = DB::table('customer_handover_notices')->insertGetId(['notification_id' => (string) Str::uuid(),
            'customer_handover_event_id' => $eventId, 'recipient_user_id' => $recipient->id,
            'customer_profile_id' => $customer?->id, 'agent_profile_id' => $agentId,
            'audience_type' => $audience, 'channel' => $channel, 'purpose' => $purpose,
            'payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        if ($channel === 'database') {
            app(NotificationPipeline::class)->capture('handover', $id, false);
        } else {
            app(ManagementMailDelivery::class)->register('handover', $id);
        }
        DB::afterCommit(static fn () => app(NotificationPipeline::class)->dispatchRecoverably(
            static fn () => DeliverCustomerHandoverNotice::dispatch($id)->afterCommit()));
    }
}
