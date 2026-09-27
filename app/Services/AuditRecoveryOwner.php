<?php

namespace App\Services;

use App\Support\RecoveryConflict;
use App\Support\RecoveryOwner;
use App\Support\RecoverySource;
use Illuminate\Support\Facades\DB;
use JsonException;

class AuditRecoveryOwner implements RecoveryOwner
{
    public function __construct(private AuditProjection $projection) {}

    public function operation(): string
    {
        return 'derived';
    }

    public function snapshot(int $sourceId): RecoverySource
    {
        $event = DB::table('canonical_audit_events')->where('id', $sourceId)->first();
        $work = DB::table('audit_projection_work')->where('canonical_event_id', $sourceId)->first();
        if ($event === null || $work === null || (int) $event->schema_version !== 1) {
            throw new RecoveryConflict('unsupported_contract');
        }
        try {
            $content = json_decode($event->content, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($content) || ! hash_equals($event->content_hash, AuditProjection::digest($content))) {
                throw new RecoveryConflict('source_integrity_conflict');
            }
        } catch (JsonException) {
            throw new RecoveryConflict('source_integrity_conflict');
        }
        $version = DB::table('audit_projection_state')->where('id', 1)->value('active_version');
        $state = match ($work->status) {
            'complete' => $this->projection->hasVerifiedDocument($event, $version === null ? null : (int) $version) ? 'succeeded' : 'dead_letter',
            'pending' => 'queued',
            'dead_letter', 'blocked' => 'dead_letter',
            default => 'dead_letter',
        };

        return new RecoverySource((int) $event->source_version,
            AuditProjection::digest(['identity' => [$event->event_id, $event->schema_version, $event->source_version, $event->content_hash, $event->input_hash]]),
            'audit:'.$event->event_id, $state, (int) $work->attempts, $work->available_at,
            result: $state === 'succeeded' ? 'audit:'.$event->event_id.':'.$version : null);
    }

    public function execute(int $sourceId): void
    {
        $this->projection->projectOwned($sourceId);
    }

    public function failed(int $sourceId, string $state, string $code, ?string $availableAt, bool $countAttempt = true): void
    {
        $attempts = (int) DB::table('audit_projection_work')->where('canonical_event_id', $sourceId)->value('attempts') + ($countAttempt ? 1 : 0);
        DB::table('audit_projection_work')->where('canonical_event_id', $sourceId)->where('status', '!=', 'complete')->update([
            'status' => $state === 'dead_letter' ? 'dead_letter' : 'pending', 'attempts' => $attempts,
            'available_at' => $availableAt ?? now(), 'failure_code' => $code, 'updated_at' => now(),
        ]);
        DB::table('audit_projection_state')->where('id', 1)->update(['status' => 'partial', 'updated_at' => now()]);
        if ($code === 'source_integrity_conflict') {
            $reference = DB::table('canonical_audit_events')->where('id', $sourceId)->value('event_id');
            if ($reference !== null) {
                $this->projection->verificationFailure($reference);
            }
        }
    }
}
