<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Support\PlatformBlocked;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;
use Throwable;

class AuditProjection
{
    /** @var list<string> */
    private const DOCUMENT_FIELDS = ['event_id', 'event_type', 'category', 'severity', 'outcome', 'actor_id', 'actor_type', 'target_type', 'target_id', 'target_reference', 'source_module', 'required_permission', 'correlation_reference', 'retention_class', 'legacy_evidence', 'content_hash', 'recorded_at'];

    /** @param array<string, mixed> $content */
    public static function digest(array $content): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $sort($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($content), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function project(int $id): void
    {
        try {
            app(PlatformGuard::class)->transaction('derived', function () use ($id): void {
                $state = DB::table('audit_projection_state')->where('id', 1)->lockForUpdate()->firstOrFail();
                $work = DB::table('audit_projection_work')->where('canonical_event_id', $id)->lockForUpdate()->first();
                if ($work === null || $work->status === 'complete') {
                    return;
                }
                $event = DB::table('canonical_audit_events')->where('id', $id)->firstOrFail();
                $this->writeDocument($event, (int) $state->active_version);
                DB::table('audit_projection_work')->where('canonical_event_id', $id)->update(['status' => 'complete', 'failure_code' => null, 'updated_at' => now()]);
                $firstPending = DB::table('audit_projection_work')->where('status', '!=', 'complete')->min('canonical_event_id');
                $watermark = DB::table('canonical_audit_events')->when($firstPending !== null, fn ($q) => $q->where('id', '<', $firstPending))->max('id') ?? 0;
                DB::table('audit_projection_state')->where('id', 1)->update(['watermark' => $watermark, 'status' => $firstPending === null ? 'current' : 'partial', 'updated_at' => now()]);
            }, attempts: 3);
        } catch (PlatformBlocked $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && $exception->getMessage() === 'Audit content verification failed.') {
                $reference = DB::table('canonical_audit_events')->where('id', $id)->value('event_id');
                $this->verificationFailure($reference);
            }
            DB::table('audit_projection_work')->where('canonical_event_id', $id)->update(['status' => 'pending', 'attempts' => DB::raw('attempts + 1'), 'available_at' => now()->addMinute(), 'failure_code' => 'projection_unavailable', 'updated_at' => now()]);
            DB::table('audit_projection_state')->where('id', 1)->update(['status' => 'partial', 'updated_at' => now()]);
            throw $exception;
        }
    }

    public function drain(int $limit = 100): int
    {
        $ids = DB::table('audit_projection_work')->where('status', 'pending')->where('available_at', '<=', now())->orderBy('canonical_event_id')->limit($limit)->pluck('canonical_event_id');
        $count = 0;
        foreach ($ids as $id) {
            try {
                $this->project((int) $id);
                $count++;
            } catch (Throwable) { /* Retry eligibility remains durable. */
            }
        }

        return $count;
    }

    public function rebuild(int $limit = 100): ?int
    {
        $failedReference = null;
        try {
            return app(PlatformGuard::class)->transaction('derived', function () use ($limit, &$failedReference): ?int {
                $state = DB::table('audit_projection_state')->where('id', 1)->lockForUpdate()->firstOrFail();
                $version = $state->rebuild_version === null ? (int) $state->active_version + 1 : (int) $state->rebuild_version;
                $events = DB::table('canonical_audit_events')->where('id', '>', $state->rebuild_cursor)->orderBy('id')->limit(min(1000, max(1, $limit)))->get();
                $cursor = (int) $state->rebuild_cursor;
                foreach ($events as $event) {
                    $failedReference = $event->event_id;
                    $this->writeDocument($event, $version);
                    $cursor = (int) $event->id;
                }
                if (DB::table('canonical_audit_events')->where('id', '>', $cursor)->exists()) {
                    DB::table('audit_projection_state')->where('id', 1)->update(['rebuild_version' => $version, 'rebuild_cursor' => $cursor, 'updated_at' => now()]);

                    return null;
                }
                $count = DB::table('canonical_audit_events')->count();
                $documents = DB::table('audit_search_documents')->where('index_version', $version);
                $mismatches = DB::table('canonical_audit_events as events')->leftJoin('audit_search_documents as documents', function ($join) use ($version): void {
                    $join->on('events.id', '=', 'documents.canonical_event_id')->where('documents.index_version', $version);
                })->where(function ($query): void {
                    $query->whereNull('documents.id');
                    foreach (self::DOCUMENT_FIELDS as $field) {
                        $query->orWhere(function ($difference) use ($field): void {
                            $difference->whereColumn('events.'.$field, '!=', 'documents.'.$field)
                                ->orWhere(fn ($missing) => $missing->whereNull('events.'.$field)->whereNotNull('documents.'.$field))
                                ->orWhere(fn ($missing) => $missing->whereNotNull('events.'.$field)->whereNull('documents.'.$field));
                        });
                    }
                })->exists();
                if ($count !== $documents->count() || $mismatches) {
                    throw new RuntimeException('Audit projection coverage failed.');
                }
                DB::table('audit_projection_work')->where('canonical_event_id', '<=', $cursor)->update(['status' => 'complete', 'failure_code' => null, 'updated_at' => now()]);
                DB::table('audit_projection_state')->where('id', 1)->update(['active_version' => $version, 'watermark' => $cursor, 'status' => 'current', 'rebuild_version' => null, 'rebuild_cursor' => 0, 'updated_at' => now()]);

                return $version;
            }, attempts: 3);
        } catch (PlatformBlocked $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            DB::table('audit_projection_state')->where('id', 1)->update(['rebuild_cursor' => 0]);
            if ($failedReference !== null && $exception->getMessage() === 'Audit content verification failed.') {
                $this->verificationFailure($failedReference);
            }
            throw $exception;
        }
    }

    public function verificationFailure(string $reference): void
    {
        AuditEvent::record('audit.content_mismatch', 'canonical_audit_event', null, $reference,
            ['event_id' => $reference], null, ['executor' => self::class, 'operation_id' => 'integrity:'.$reference, 'severity' => 'Critical', 'outcome' => 'Failed']);
    }

    private function writeDocument(stdClass $event, int $version): void
    {
        $content = json_decode($event->content, true, flags: JSON_THROW_ON_ERROR);
        if ((int) $event->schema_version !== 1 || ! hash_equals($event->content_hash, self::digest($content))) {
            throw new RuntimeException('Audit content verification failed.');
        }
        $document = ['canonical_event_id' => $event->id, 'index_version' => $version, 'indexed_at' => now()];
        foreach (self::DOCUMENT_FIELDS as $field) {
            $document[$field] = $event->{$field};
        }
        DB::table('audit_search_documents')->updateOrInsert(['canonical_event_id' => $event->id, 'index_version' => $version], $document);
    }
}
