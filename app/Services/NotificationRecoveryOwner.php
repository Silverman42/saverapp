<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\User;
use App\Support\RecoveryConflict;
use App\Support\RecoveryOwner;
use App\Support\RecoverySource;
use Illuminate\Support\Facades\DB;

class NotificationRecoveryOwner implements RecoveryOwner
{
    public function __construct(private NotificationPipeline $pipeline, private NotificationCatalogue $catalogue) {}

    public function operation(): string
    {
        return 'external';
    }

    public function snapshot(int $sourceId): RecoverySource
    {
        $intent = DB::table('notification_inbox_intents')->where('id', $sourceId)->first();
        $event = $intent === null ? null : DB::table('notification_events')->where('id', $intent->event_id)->first();
        if ($intent !== null && in_array($event?->family, ['fee_application', 'fee_issue'], true) && DB::transactionLevel() > 0
            && DB::table('platform_recovery_work')->where('owner', 'notification_inbox')->where('source_id', $sourceId)->where('state', 'running')->exists()) {
            $intent = DB::table('notification_inbox_intents')->where('id', $sourceId)->lockForUpdate()->firstOrFail();
            User::query()->whereKey($intent->recipient_user_id)->lockForUpdate()->first();
            if ($intent->customer_profile_id !== null) {
                CustomerProfile::query()->whereKey($intent->customer_profile_id)->lockForUpdate()->first();
                DB::table('customer_assignments')->where('customer_profile_id', $intent->customer_profile_id)
                    ->where('is_current', 1)->lockForUpdate()->first();
            }
        }
        if ($intent === null || $event === null || ! $this->catalogue->validatesStoredContract($event, $intent)) {
            throw new RecoveryConflict('unsupported_contract');
        }
        $state = match ($intent->status) {
            'delivered' => $this->verifiedResult($intent) ? 'succeeded' : 'dead_letter',
            'suppressed' => 'cancelled',
            'pending' => 'queued',
            default => 'dead_letter',
        };
        $hash = AuditProjection::digest(['identity' => [$event->event_id, $event->facts, $event->source_version,
            $intent->recipient_user_id, $intent->notification_id, $intent->snapshot_hash, $intent->template_id,
            $intent->template_version, $intent->locale, $intent->channel, $intent->audiences,
            $intent->customer_profile_id, $intent->agent_profile_id, $intent->assignment_id, $intent->action_correction_id,
            $intent->expires_at]]);

        return new RecoverySource((int) $event->source_version, $hash, 'inbox:'.$intent->notification_id,
            $state, (int) $intent->attempt_count, $intent->next_attempt_at,
            result: in_array($state, ['succeeded', 'cancelled'], true) ? 'inbox:'.$intent->notification_id : null,
            eligible: $this->pipeline->isRecipientEligible($sourceId));
    }

    private function verifiedResult(\stdClass $intent): bool
    {
        $notification = DB::table('notifications')->where('id', $intent->notification_id)
            ->where('notifiable_id', $intent->recipient_user_id)->where('notifiable_type', (new User)->getMorphClass())
            ->where('type', 'shared-inbox-v1')->first();
        if ($notification === null) {
            return false;
        }
        try {
            $data = json_decode($notification->data, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        $expected = ['title' => $intent->title, 'message' => $intent->summary, 'reference' => $intent->reference,
            'template_id' => $intent->template_id, 'template_version' => 1];

        return is_array($data) && hash_equals(AuditProjection::digest($expected), AuditProjection::digest($data));
    }

    public function execute(int $sourceId): void
    {
        $this->pipeline->materializeOwned($sourceId);
    }

    public function failed(int $sourceId, string $state, string $code, ?string $availableAt, bool $countAttempt = true): void
    {
        $this->pipeline->recordRecoveryFailure($sourceId, $state, $code, $availableAt, $countAttempt);
    }
}
