<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\DeliveryStatus;
use App\Enums\InvitationStatus;
use App\Enums\UserType;
use App\Jobs\DeliverAgentInvitationJob;
use App\Jobs\DeliverCustomerInvitationJob;
use App\Models\AgentProfile;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class InvitationDeliveryIssues
{
    public function dispatch(int $invitationId, #[\SensitiveParameter] string $token, int $generation): void
    {
        try {
            $invitation = Invitation::query()->findOrFail($invitationId);
            if ($invitation->role === UserType::Customer->value) {
                DeliverCustomerInvitationJob::dispatch($invitationId, $token, $generation)->afterCommit();
            } else {
                DeliverAgentInvitationJob::dispatch($invitationId, $token, $generation)->afterCommit();
            }
        } catch (Throwable) {
            try {
                $this->record($invitationId, $generation, 'queue_unavailable', 0);
            } catch (Throwable) {
                Log::warning('Invitation issue capture unavailable.', ['invitation_id' => $invitationId]);
            }
        }
    }

    public function record(int $invitationId, int $generation, string $category, int $attempt): void
    {
        DB::transaction(function () use ($invitationId, $generation, $category, $attempt): void {
            $invitation = Invitation::query()->whereKey($invitationId)->lockForUpdate()->firstOrFail();
            if ($invitation->generation !== $generation || ($invitation->isExpired() || in_array($invitation->status, [InvitationStatus::Cancelled, InvitationStatus::Activated, InvitationStatus::Expired], true))
                || $invitation->user->account_state !== AccountState::Invited) {
                return;
            }
            $category = $category === 'queue_unavailable' ? 'queue_unavailable' : 'acceptance_unknown';
            $invitation->forceFill(['status' => InvitationStatus::DeliveryFailed, 'delivery_status' => $category === 'acceptance_unknown' ? DeliveryStatus::Uncertain : DeliveryStatus::Failed,
                'delivery_error' => $category === 'queue_unavailable' ? 'Invitation dispatch unavailable.' : 'Email acceptance could not be confirmed.'])->save();
            $issue = DB::table('invitation_delivery_issues')->where('invitation_id', $invitationId)->first();
            if ($issue === null) {
                $audit = AuditEvent::record('invitation.delivery_failed', Invitation::class, $invitation->id, null,
                    ['generation' => $generation, 'attempt' => $attempt], null,
                    ['executor' => self::class, 'outcome' => 'Failed']);
                $id = DB::table('invitation_delivery_issues')->insertGetId(['invitation_id' => $invitationId,
                    'audit_event_id' => $audit->id, 'category' => $category, 'attempt_count' => $attempt,
                    'last_attempt_at' => $attempt > 0 ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $id = (int) $issue->id;
                DB::table('invitation_delivery_issues')->where('id', $id)->update(['category' => $category,
                    'attempt_count' => max($attempt, (int) $issue->attempt_count),
                    'last_attempt_at' => $attempt > 0 ? now() : $issue->last_attempt_at, 'updated_at' => now()]);
            }
            $this->route($id, $invitation);
        }, attempts: 3);
    }

    public function route(int $issueId, Invitation $invitation): void
    {
        $customer = CustomerProfile::query()->where('user_id', $invitation->user_id)->first();
        $agent = $customer === null ? AgentProfile::query()->where('user_id', $invitation->user_id)->first() : null;
        if ($customer === null && $agent === null) {
            return;
        }
        $permission = $customer !== null ? AdminPermission::CustomersManage : AdminPermission::AgentsManage;
        $recipients = User::query()->where('user_type', UserType::Admin)->where('account_state', AccountState::Active)->get()
            ->filter(fn (User $user): bool => app(AuthorizationService::class)->allows($user, $permission))
            ->map(fn (User $user): array => ['user' => $user, 'audience' => $customer !== null ? 'customer_manager' : 'managing_admin'])->values()->all();
        $assigned = $customer?->currentAssignment?->agentProfile?->user;
        if ($assigned !== null && app(AgentEligibilityService::class)->canPerformAssignedCustomerWork($assigned)) {
            $recipients[] = ['user' => $assigned, 'audience' => 'current_agent'];
        }
        foreach ($recipients as $recipient) {
            DB::table('invitation_issue_notification_intents')->insertOrIgnore([
                'notification_id' => (string) Str::uuid(), 'invitation_delivery_issue_id' => $issueId,
                'recipient_user_id' => $recipient['user']->id, 'customer_profile_id' => $customer?->id,
                'agent_profile_id' => $agent?->id, 'audience_type' => $recipient['audience'],
                'channel' => 'database', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $id = DB::table('invitation_issue_notification_intents')->where('invitation_delivery_issue_id', $issueId)
                ->where('recipient_user_id', $recipient['user']->id)->value('id');
            app(NotificationPipeline::class)->capture('invitation_issue', (int) $id);
        }
    }
}
