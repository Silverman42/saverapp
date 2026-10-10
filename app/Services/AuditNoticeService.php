<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\AuthorizationRestriction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns audited security, authorization and ledger integrity events into minimal in-app notices.
 * The immutable audit event is the notification source; summaries never repeat its payload.
 */
class AuditNoticeService
{
    /** @var array<string, string> */
    public const FAMILIES = [
        'auth.password_changed' => 'account_security',
        'auth.password_reset' => 'account_security',
        'auth.mfa_changed' => 'account_security',
        'auth.session_revoked' => 'account_security',
        'auth.recovery_codes_regenerated' => 'account_security',
        'auth.lock_created' => 'account_security',
        'auth.manual_unlock' => 'account_security',
        'auth.compromise_sessions_revoked' => 'account_security',
        'auth.recovery_codes_used' => 'account_security',
        'auth.staff_recovery_requested' => 'authorization',
        'auth.staff_recovery_approval_recorded' => 'authorization',
        'auth.staff_recovery_approved' => 'authorization',
        'auth.staff_recovery_rejected' => 'authorization',
        'auth.staff_recovery_cancelled' => 'authorization',
        'auth.staff_recovery_completed' => 'authorization',
        'authorization.permissions_changed' => 'authorization',
        'admin.suspended' => 'authorization',
        'admin.reactivated' => 'authorization',
        'admin.deactivated' => 'authorization',
        'authorization.restriction_applied' => 'authorization',
        'authorization.restriction_cleared' => 'authorization',
        'authorization.restriction_expired' => 'authorization',
        'user.email_changed' => 'authorization',
        'ledger.integrity_incident' => 'ledger_incident',
        'ledger.integrity_incident_resolved' => 'ledger_incident',
    ];

    public function __construct(private AuthorizationService $authorization) {}

    public function queue(AuditEvent $audit): void
    {
        $family = self::FAMILIES[$audit->event_type] ?? null;
        if ($family === null) {
            return;
        }
        try {
            foreach ($this->recipients($family, $audit) as [$recipientId, $audience]) {
                $id = DB::table('audit_notification_intents')->insertOrIgnore([
                    'notification_id' => (string) Str::uuid(), 'audit_event_id' => $audit->id, 'recipient_user_id' => $recipientId,
                    'family' => $family, 'audience_type' => $audience, 'channel' => 'database', 'status' => 'pending',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($id === 0) {
                    continue;
                }
                $ownerId = DB::table('audit_notification_intents')->where('audit_event_id', $audit->id)->where('recipient_user_id', $recipientId)->value('id');
                app(NotificationPipeline::class)->capture($family, (int) $ownerId);
            }
        } catch (Throwable $exception) {
            Log::warning('Audit notice could not be captured.', ['event_type' => $audit->event_type, 'audit_event_id' => $audit->id, 'exception' => $exception::class]);
        }
    }

    /** The user an audited account or authorization event is about, if it is still a known account. */
    public function subjectUserId(string $eventType, string $targetType, ?int $targetId): ?int
    {
        if ($targetId === null) {
            return null;
        }
        if ($targetType === User::class) {
            return $targetId;
        }
        if ($targetType === AuthorizationRestriction::class && str_starts_with($eventType, 'authorization.')) {
            $userId = DB::table('authorization_restrictions')->where('id', $targetId)->value('user_id');

            return $userId === null ? null : (int) $userId;
        }

        return null;
    }

    /** @return list<array{int, string}> */
    private function recipients(string $family, AuditEvent $audit): array
    {
        $recipients = [];
        if ($family !== 'ledger_incident') {
            $subject = $this->subjectUserId($audit->event_type, $audit->target_type, $audit->target_id);
            $subjectUser = $subject === null ? null : User::query()->find($subject);
            if ($subjectUser !== null && $audit->event_type !== 'user.email_changed') {
                $recipients[] = [$subjectUser->id, 'subject_user'];
            }
            if ($family === 'account_security' || ($subjectUser?->user_type !== UserType::Admin && ! str_starts_with($audit->event_type, 'auth.staff_recovery_'))) {
                return $recipients;
            }
        }
        $permission = $family === 'ledger_incident' ? AdminPermission::ReconciliationManage : AdminPermission::AdminsManage;
        $audience = $family === 'ledger_incident' ? 'reconciliation_manager' : 'admin_manager';
        User::query()->where('user_type', UserType::Admin->value)->where('account_state', 'active')->orderBy('id')
            ->chunkById(100, function ($admins) use (&$recipients, $audit, $permission, $audience): void {
                foreach ($admins as $admin) {
                    if ($admin->id !== $audit->actor_id && ! in_array($admin->id, array_column($recipients, 0), true)
                        && $this->authorization->allows($admin, $permission)) {
                        $recipients[] = [$admin->id, $audience];
                    }
                }
            });

        return $recipients;
    }
}
