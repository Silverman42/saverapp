<?php

namespace App\Services;

use App\Jobs\ProjectAuditEvent;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\AuditIdentityConflict;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class AuditCapture
{
    public function __construct(private AuditCatalogue $catalogue) {}

    /** @param array<string, mixed> $payload
     * @param  array<string, mixed>  $context
     */
    public function record(string $eventType, string $targetType, ?int $targetId, ?string $targetReference, array $payload, ?User $actor, array $context = []): AuditEvent
    {
        $definition = $this->catalogue->describe($eventType, $payload);
        $this->validateContext($context);
        $operation = $context['operation_id'] ?? $payload['operation_id'] ?? $payload['attempt_reference'] ?? null;
        $operationKey = $operation === null ? null : hash('sha256', json_encode([$eventType, $targetType, $targetId, $operation], JSON_THROW_ON_ERROR));
        $inputHash = hash_hmac('sha256', json_encode([$eventType, $targetType, $targetId, $targetReference, $definition, $actor?->id, $actor?->user_type->value, array_diff_key($context, ['fresh_authentication' => true])], JSON_THROW_ON_ERROR), (string) config('app.key'));
        try {
            return DB::transaction(function () use ($eventType, $targetType, $targetId, $targetReference, $actor, $context, $definition, $operationKey, $inputHash): AuditEvent {
                DB::table('audit_projection_state')->where('id', 1)->lockForUpdate()->firstOrFail();
                if ($operationKey !== null) {
                    $existing = DB::table('canonical_audit_events')->where('operation_key', $operationKey)->first();
                    if ($existing !== null) {
                        if (! hash_equals($existing->input_hash, $inputHash)) {
                            throw new AuditIdentityConflict($targetType, $targetId, $operationKey, $actor?->id);
                        }

                        return AuditEvent::query()->whereKey($existing->legacy_audit_event_id)->firstOrFail();
                    }
                }
                $legacy = AuditEvent::query()->create(['event_type' => $eventType, 'actor_id' => $actor?->id,
                    'actor_type' => $actor?->user_type->value, 'target_type' => $targetType, 'target_id' => $targetId,
                    'target_reference' => $targetReference, 'payload' => $definition['safe'], 'created_at' => now()]);
                $canonicalId = $this->append($legacy, $definition, $actor, $context, false, $operationKey, $inputHash);
                if (in_array($eventType, ['auth.lock_created', 'auth.compromise_sessions_revoked', 'ledger.integrity_incident', 'audit.content_mismatch'], true)) {
                    app(SecurityCaseService::class)->signal($legacy->id, in_array($eventType, ['ledger.integrity_incident', 'audit.content_mismatch'], true) ? null : $targetId, in_array($eventType, ['ledger.integrity_incident', 'audit.content_mismatch'], true) ? 'Critical' : 'High');
                }
                DB::afterCommit(static function () use ($canonicalId): void {
                    try {
                        ProjectAuditEvent::dispatch($canonicalId)->afterCommit();
                    } catch (Throwable) { /* Durable projection work remains pending. */
                    }
                });

                return $legacy;
            }, attempts: 3);
        } catch (AuditIdentityConflict $exception) {
            if (DB::transactionLevel() === 0) {
                $this->reportConflict($exception);
            }
            throw $exception;
        }
    }

    public function reportConflict(AuditIdentityConflict $conflict): void
    {
        $incident = $this->record('audit.identity_conflict', $conflict->targetType, $conflict->targetId, null, [],
            $conflict->actorId === null ? null : User::query()->find($conflict->actorId),
            ['executor' => self::class, 'outcome' => 'Conflict', 'severity' => 'Critical', 'operation_id' => $conflict->operationKey]);
        app(SecurityCaseService::class)->signal($incident->id, null, 'Critical');
    }

    public function import(AuditEvent $legacy): bool
    {
        if (DB::table('canonical_audit_events')->where('legacy_audit_event_id', $legacy->id)->exists()) {
            return false;
        }
        $definition = $this->catalogue->describe($legacy->event_type, $legacy->payload, true);

        return DB::transaction(function () use ($legacy, $definition): bool {
            DB::table('audit_projection_state')->where('id', 1)->lockForUpdate()->firstOrFail();
            if (DB::table('canonical_audit_events')->where('legacy_audit_event_id', $legacy->id)->exists()) {
                return false;
            }
            $this->append($legacy, $definition, null, ['executor' => 'audit.legacy_import'], true, null,
                hash('sha256', json_encode([$legacy->id, $definition['safe']], JSON_THROW_ON_ERROR)));

            return true;
        });
    }

    /** @param array{safe: array<string, mixed>, protected: array<string, mixed>, category: string, retention: string} $definition
     * @param  array<string, mixed>  $context
     */
    private function append(AuditEvent $legacy, array $definition, ?User $actor, array $context, bool $historical, ?string $operationKey, string $inputHash): int
    {
        $eventId = (string) Str::ulid();
        $occurred = CarbonImmutable::parse($legacy->created_at)->utc();
        $permission = $context['required_permission'] ?? null;
        $outcome = $context['outcome'] ?? (str_ends_with($legacy->event_type, 'delivery_failed') ? 'Failed' : 'Succeeded');
        $sourceVersion = (int) ($context['source_version'] ?? $legacy->payload['to_version'] ?? $legacy->payload['version'] ?? $legacy->payload['generation'] ?? 1);
        $content = ['event_id' => $eventId, 'schema_version' => 1, 'event_type' => $legacy->event_type,
            'business_scope' => $context['business_scope'] ?? 'singleton', 'environment' => app()->environment(),
            'actor_id' => $historical ? $legacy->actor_id : $actor?->id,
            'actor_type' => $historical ? ($legacy->actor_type ?? 'unknown') : ($actor?->user_type->value ?? $context['actor_category'] ?? 'service'),
            'approver_id' => $context['approver_id'] ?? null, 'executor' => $context['executor'] ?? 'application.audit.compatibility',
            'authority' => ['required_permission' => $permission, 'permission_version' => $historical ? null : $actor?->permission_version,
                'fresh_authentication' => $context['fresh_authentication'] ?? null, 'evidence' => $context['authority_evidence'] ?? ($historical ? 'unavailable' : 'owner_workflow')],
            'target_type' => $legacy->target_type, 'target_id' => $legacy->target_id, 'target_reference' => $legacy->target_reference,
            'source_module' => $definition['category'], 'source_version' => $sourceVersion,
            'outcome' => $outcome, 'occurred_at' => $occurred->toISOString(), 'recorded_at' => now()->utc()->toISOString(),
            'correlation_reference' => $context['correlation_reference'] ?? $legacy->payload['operation_id'] ?? $legacy->payload['attempt_reference'] ?? null,
            'safe_changes' => $definition['safe'], 'protected_fields' => array_keys($definition['protected']),
            'retention_class' => $definition['retention'], 'legacy_evidence' => $historical, 'integrity' => 'Unverified'];
        $hash = AuditProjection::digest($content);
        $id = DB::table('canonical_audit_events')->insertGetId(['event_id' => $eventId, 'legacy_audit_event_id' => $legacy->id,
            'operation_key' => $operationKey, 'event_type' => $legacy->event_type, 'category' => $definition['category'],
            'severity' => $context['severity'] ?? 'Informational', 'outcome' => $outcome,
            'actor_id' => $content['actor_id'], 'actor_type' => $content['actor_type'], 'target_type' => $legacy->target_type,
            'target_id' => $legacy->target_id, 'target_reference' => $legacy->target_reference,
            'source_module' => $definition['category'], 'source_version' => max(1, $sourceVersion), 'required_permission' => $permission,
            'correlation_reference' => $content['correlation_reference'], 'retention_class' => $definition['retention'],
            'schema_version' => 1, 'legacy_evidence' => $historical, 'content' => json_encode($content, JSON_THROW_ON_ERROR),
            'content_hash' => $hash, 'input_hash' => $inputHash, 'occurred_at' => $occurred, 'recorded_at' => now()->utc()]);
        if ($definition['protected'] !== []) {
            DB::table('audit_protected_payloads')->insert(['canonical_event_id' => $id,
                'ciphertext' => Crypt::encryptString(json_encode($definition['protected'], JSON_THROW_ON_ERROR)),
                'key_id' => config('audit.payload_key_id'), 'created_at' => now()]);
        }
        DB::table('audit_projection_work')->insert(['canonical_event_id' => $id, 'status' => 'pending', 'available_at' => now(), 'updated_at' => now()]);

        return (int) $id;
    }

    /** @param array<string, mixed> $context */
    private function validateContext(array $context): void
    {
        $allowed = ['operation_id', 'actor_category', 'executor', 'required_permission', 'source_version', 'business_scope', 'approver_id', 'fresh_authentication', 'authority_evidence', 'correlation_reference', 'severity', 'outcome'];
        if (array_diff(array_keys($context), $allowed) !== []) {
            throw new InvalidArgumentException('Unknown audit context.');
        }
        foreach ($context as $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Invalid audit context.');
            }
            if (is_string($value) && strlen($value) > 100) {
                throw new InvalidArgumentException('Audit context exceeds schema limits.');
            }
        }
        if (isset($context['outcome']) && ! in_array($context['outcome'], ['Succeeded', 'Denied', 'Failed', 'Conflict', 'Expired'], true)) {
            throw new InvalidArgumentException('Invalid audit outcome.');
        }
        if (isset($context['severity']) && ! in_array($context['severity'], ['Informational', 'Low', 'Medium', 'High', 'Critical'], true)) {
            throw new InvalidArgumentException('Invalid audit severity.');
        }
    }
}
