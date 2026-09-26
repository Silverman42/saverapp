<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Models\AuditEvent;
use App\Models\SecurityCase;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SecurityCaseService
{
    public function __construct(private AuthorizationService $authorization) {}

    public function signal(int $legacyEventId, ?int $affectedUserId, string $severity = 'High'): SecurityCase
    {
        return DB::transaction(function () use ($legacyEventId, $affectedUserId, $severity): SecurityCase {
            $event = DB::table('canonical_audit_events')->where('legacy_audit_event_id', $legacyEventId)->firstOrFail();
            abort_unless(in_array($event->event_type, ['auth.lock_created', 'auth.compromise_sessions_revoked', 'audit.identity_conflict', 'ledger.integrity_incident', 'audit.content_mismatch'], true), 422);
            abort_unless(in_array($severity, ['Informational', 'Low', 'Medium', 'High', 'Critical'], true), 422);
            $sourceKey = hash('sha256', $event->event_id);
            DB::table('audit_projection_state')->where('id', 1)->lockForUpdate()->firstOrFail();
            $existing = SecurityCase::query()->where('source_key', $sourceKey)->first();
            if ($existing !== null) {
                return $existing;
            }
            $id = DB::table('security_cases')->insertGetId(['case_reference' => (string) Str::ulid(), 'source_key' => $sourceKey,
                'source_event_id' => $event->id, 'affected_user_id' => $affectedUserId, 'severity' => $severity,
                'state' => 'Open', 'version' => 1, 'episode' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $case = SecurityCase::query()->findOrFail($id);
            $this->transition($case, 'security.case_created', null);

            return $case;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    public function change(User $actor, SecurityCase $case, array $data): SecurityCase
    {
        return DB::transaction(function () use ($actor, $case, $data): SecurityCase {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->authorization->allows($actor, AdminPermission::SecurityOperationsManage), 403);
            $locked = SecurityCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->version !== (int) $data['expected_version']) {
                throw new ConflictHttpException('The case changed. Refresh before continuing.');
            }
            $event = 'security.case_state_changed';
            if ($data['action'] === 'assign') {
                $owner = isset($data['owner_id']) ? User::query()->whereKey($data['owner_id'])->lockForUpdate()->firstOrFail() : null;
                abort_if($owner !== null && ! $this->authorization->allows($owner, AdminPermission::SecurityOperationsManage), 422, 'The owner is unavailable.');
                $locked->owner_id = $owner === null ? null : $owner->getKey();
                $event = 'security.case_assigned';
            } elseif ($data['action'] === 'note') {
                $event = 'security.case_note_added';
            } elseif ($data['action'] === 'reopen') {
                abort_unless(in_array($locked->state, ['Resolved', 'ClosedNoAction'], true), 422);
                $locked->state = 'Investigating';
                $locked->episode++;
                $event = 'security.case_reopened';
            } else {
                $allowed = match ($locked->state) {
                    'Open' => ['Investigating', 'ClosedNoAction'], 'Investigating' => ['Resolved', 'ClosedNoAction'], default => []
                };
                abort_unless(in_array($data['state'], $allowed, true), 422, 'This case transition is unavailable.');
                $locked->state = $data['state'];
            }
            foreach ($data['evidence_references'] ?? [] as $reference) {
                if (str_starts_with($reference, 'audit:')) {
                    abort_unless($this->authorization->allows($actor, AdminPermission::AuditView), 403);
                    abort_unless(DB::table('canonical_audit_events')->where('event_id', substr($reference, 6))->exists(), 422);
                } else {
                    abort_unless(DB::table('authentication_locks')->where('id', substr($reference, 5))->exists(), 422);
                }
            }
            $locked->version++;
            $locked->save();
            $this->transition($locked, $event, $actor, $data['note'] ?? null, $data['evidence_references'] ?? []);

            return $locked;
        }, attempts: 3);
    }

    public function releaseIneligibleOwners(int $limit = 100): void
    {
        $cursor = (int) DB::table('audit_projection_state')->where('id', 1)->value('owner_scan_cursor');
        $cases = SecurityCase::query()->whereNotNull('owner_id')->where('id', '>', $cursor)->orderBy('id')->limit($limit)->get();
        foreach ($cases as $case) {
            $this->releaseOwner($case);
        }
        DB::table('audit_projection_state')->where('id', 1)->update(['owner_scan_cursor' => $cases->isEmpty() ? 0 : $cases->last()->id]);
    }

    public function releaseOwner(SecurityCase $case): void
    {
        DB::transaction(function () use ($case): void {
            $locked = SecurityCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ($locked->owner_id === null) {
                return;
            }
            $owner = User::query()->whereKey($locked->owner_id)->lockForUpdate()->first();
            if ($owner !== null && $this->authorization->allows($owner, AdminPermission::SecurityOperationsManage)) {
                return;
            }
            $locked->owner_id = null;
            $locked->version++;
            $locked->save();
            $this->transition($locked, 'security.case_ownership_released', null);
        }, attempts: 3);
    }

    /** @param list<string> $evidence */
    private function transition(SecurityCase $case, string $eventType, ?User $actor, ?string $note = null, array $evidence = []): void
    {
        $facts = ['case_reference' => $case->case_reference, 'version' => $case->version, 'episode' => $case->episode,
            'state' => $case->state, 'severity' => $case->severity, 'owner_id' => $case->owner_id, 'source_event_id' => $case->source_event_id];
        $audit = AuditEvent::record($eventType, SecurityCase::class, $case->id, $case->case_reference, $facts, $actor,
            ['executor' => self::class, 'required_permission' => $actor === null ? null : AdminPermission::SecurityOperationsManage->value,
                'operation_id' => $case->case_reference.':'.$case->version]);
        $transitionId = DB::table('security_case_transitions')->insertGetId(['security_case_id' => $case->id, 'version' => $case->version,
            'event_type' => $eventType, 'actor_id' => $actor?->id, 'audit_event_id' => $audit->id,
            'facts' => json_encode($facts, JSON_THROW_ON_ERROR), 'note_ciphertext' => $note === null ? null : Crypt::encryptString($note),
            'evidence_references' => json_encode($evidence, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        if (in_array($eventType, ['security.case_created', 'security.case_assigned', 'security.case_reopened'], true)) {
            User::query()->where('user_type', 'admin')->where('account_state', 'active')->orderBy('id')->chunkById(100, function ($admins) use ($transitionId): void {
                foreach ($admins as $admin) {
                    if (! $this->authorization->allows($admin, AdminPermission::SecurityOperationsManage)) {
                        continue;
                    }
                    $id = DB::table('security_notification_intents')->insertGetId(['security_case_transition_id' => $transitionId,
                        'recipient_user_id' => $admin->id, 'notification_id' => (string) Str::uuid(), 'channel' => 'database',
                        'audience_type' => 'security_operations_admin', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                    app(NotificationPipeline::class)->capture('security', (int) $id);
                }
            });
        }
    }
}
