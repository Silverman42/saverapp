<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CollectionExceptionNoticeService
{
    public function __construct(private AuthorizationService $authorization, private NotificationPipeline $pipeline) {}

    /**
     * Notify reconciliation managers and the batch's original Agent of an exception transition, excluding its actor.
     */
    public function queue(int $eventId): void
    {
        $event = DB::table('collection_exception_events as events')
            ->join('collection_exceptions as exceptions', 'exceptions.id', '=', 'events.collection_exception_id')
            ->join('collection_batches as batches', 'batches.id', '=', 'exceptions.collection_batch_id')
            ->join('agent_profiles as agents', 'agents.id', '=', 'batches.agent_profile_id')
            ->where('events.id', $eventId)->first(['events.actor_user_id', 'batches.agent_profile_id', 'agents.user_id as agent_user_id']);
        if ($event === null) {
            return;
        }
        $recipients = [];
        User::query()->where('user_type', 'admin')->where('account_state', 'active')->orderBy('id')
            ->chunkById(100, function ($admins) use (&$recipients, $event): void {
                foreach ($admins as $admin) {
                    if ($admin->id !== (int) $event->actor_user_id && $this->authorization->allows($admin, AdminPermission::ReconciliationManage)) {
                        $recipients[] = [$admin->id, 'reconciliation_manager', null];
                    }
                }
            });
        if ((int) $event->agent_user_id !== (int) $event->actor_user_id) {
            $recipients[] = [(int) $event->agent_user_id, 'subject_agent', (int) $event->agent_profile_id];
        }
        foreach ($recipients as [$recipientId, $audience, $agentProfileId]) {
            $id = DB::table('collection_exception_notification_intents')->insertGetId([
                'notification_id' => (string) Str::uuid(), 'collection_exception_event_id' => $eventId,
                'recipient_user_id' => $recipientId, 'agent_profile_id' => $agentProfileId, 'audience_type' => $audience,
                'channel' => 'database', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->pipeline->capture('collection_exception', (int) $id);
        }
    }
}
