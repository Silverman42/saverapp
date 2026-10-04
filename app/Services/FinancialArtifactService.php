<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Jobs\RenderFinancialArtifact;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\FinancialArtifact;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FinancialArtifactService
{
    public function issueStatement(User $actor, CustomerProfile $customer, string $reference, string $from, string $to, string $fingerprint, ?FinancialArtifact $supersedes = null): FinancialArtifact
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $customer, $reference, $from, $to, $fingerprint, $supersedes): FinancialArtifact {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(ResourceScopeService::class)->forCustomers($actor)->whereKey($customer->id)->exists(), 404);
            CustomerProfile::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $timezone = BusinessProfile::current()->timezone;
            if (! CarbonImmutable::canBeCreatedFromFormat($from, 'Y-m-d') || ! CarbonImmutable::canBeCreatedFromFormat($to, 'Y-m-d')
                || $from > $to || $to > now($timezone)->toDateString()
                || CarbonImmutable::parse($from, $timezone)->diffInDays(CarbonImmutable::parse($to, $timezone)) > 365) {
                abort(422, 'Choose at most 366 valid dates ending no later than today.');
            }
            $hash = $this->digest(['statement', $actor->id, $customer->id, $from, $to, $fingerprint, $supersedes?->id]);
            if ($existing = $this->replay($actor, $reference, $hash)) {
                return $existing;
            }
            app(BusinessSettings::class)->ensureFeature('statement_pdf');
            $snapshot = app(StatementPreviewService::class)->preview($actor, $customer, $from, $to, BusinessProfile::current()->timezone, true);
            if ($snapshot['status'] !== 'ready') {
                throw new ConflictHttpException('The statement source is unavailable.');
            }
            if (! hash_equals($snapshot['preview_fingerprint'], $fingerprint)) {
                throw new ConflictHttpException('Statement values changed. Review the current preview before issuance.');
            }
            if ($supersedes !== null) {
                $supersedes = FinancialArtifact::query()->whereKey($supersedes->id)->lockForUpdate()->firstOrFail();
                $this->authorize($actor, $supersedes);
                if ($supersedes->kind !== 'statement' || $supersedes->status !== 'ready' || $supersedes->customer_profile_id !== $customer->id
                    || $supersedes->snapshot['from'] !== $from || $supersedes->snapshot['to'] !== $to
                    || FinancialArtifact::query()->where('supersedes_artifact_id', $supersedes->id)->exists()) {
                    throw new ConflictHttpException('Only the latest issued statement for this exact Customer period can be superseded.');
                }
            }
            $snapshot['customer_name'] = $customer->user->name;

            return $this->create($actor, $reference, $hash, 'statement', 'pdf', $snapshot, $customer->id, $supersedes);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $filters */
    public function exportReport(User $actor, string $reference, string $report, string $format, array $filters): FinancialArtifact
    {
        if (DB::transactionLevel() === 0 && DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $reference, $report, $format, $filters): FinancialArtifact {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AuthorizationService::class)->allows($actor, AdminPermission::ReportsExport), 403);
            abort_unless(in_array($format, ['csv', 'pdf'], true), 422);
            unset($filters['cursor']);
            $filters['page_size'] = 100;
            ksort($filters);
            $hash = $this->digest(['report', $actor->id, $report, $format, $filters]);
            if ($existing = $this->replay($actor, $reference, $hash)) {
                return $existing;
            }
            app(BusinessSettings::class)->ensureFeature('report_exports');
            if (FinancialArtifact::query()->where('requester_user_id', $actor->id)->where('kind', 'report')
                ->whereIn('status', ['queued', 'running'])->count() >= 2) {
                abort(429, 'At most two active export jobs are permitted.');
            }
            if (FinancialArtifact::query()->where('requester_user_id', $actor->id)->where('kind', 'report')->where('created_at', '>=', now()->subDay())->count() >= 20) {
                abort(429, 'The daily export request limit was reached.');
            }
            $reader = app(ReportReadService::class);
            $snapshot = $reader->read($actor, $report, $filters);
            $count = 0;
            foreach ($snapshot['sections'] as $name => &$section) {
                if (in_array($section['status'], ['Unavailable', 'Too large'], true)) {
                    throw new ConflictHttpException('A requested report section is unavailable. No partial file was created.');
                }
                $rows = $section['rows'];
                $cursor = $section['next_cursor'];
                while ($cursor !== null) {
                    $page = $reader->read($actor, $report, [...$filters, 'cursor' => $cursor]);
                    $next = $page['sections'][$name];
                    if ($next['status'] !== $section['status'] || $next['total'] !== $section['total']) {
                        throw new ConflictHttpException('Report source changed during capture.');
                    }
                    $rows = [...$rows, ...$next['rows']];
                    if (count($rows) > 10000) {
                        abort(422, 'The local renderer supports at most 10,000 rows. Narrow the report.');
                    }
                    $cursor = $next['next_cursor'];
                }
                $count += count($rows);
                if ($count > 10000) {
                    abort(422, 'The local renderer supports at most 10,000 rows. Narrow the report.');
                }
                $section['rows'] = $rows;
                $section['next_cursor'] = null;
            }
            unset($section);
            $snapshot['title'] = app(ReportCatalogue::class)->get($actor, $report)['title'];

            return $this->create($actor, $reference, $hash, 'report', $format, $snapshot, null);
        }, attempts: 3);
    }

    public function authorize(User $actor, FinancialArtifact $artifact): void
    {
        if ($artifact->kind === 'statement') {
            abort_unless(app(ResourceScopeService::class)->forCustomers($actor)->whereKey($artifact->customer_profile_id)->exists(), 404);
        } else {
            abort_unless($actor->id === $artifact->requester_user_id && app(AuthorizationService::class)->allows($actor, AdminPermission::ReportsExport), 404);
        }
    }

    public function render(int $id, ?int $generation = null, ?\Closure $publicationFence = null): void
    {
        $artifact = app(PlatformGuard::class)->transaction('derived', function () use ($id, $generation): ?FinancialArtifact {
            $artifact = FinancialArtifact::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($artifact->status !== 'queued' || ($generation !== null && $artifact->render_generation !== $generation)) {
                return null;
            }
            $this->authorize(User::query()->findOrFail($artifact->requester_user_id), $artifact);
            if ($artifact->expires_at?->isPast()) {
                $artifact->update(['status' => 'expired']);

                return null;
            }
            if (! hash_equals($artifact->snapshot_hash, $this->digest($artifact->snapshot))) {
                throw new RuntimeException('Financial snapshot integrity mismatch.');
            }

            return $artifact;
        });
        if ($artifact === null) {
            return;
        }
        $snapshot = $artifact->snapshot;
        if ($artifact->format === 'csv') {
            $bytes = $this->csv($snapshot, $artifact->manifest);
        } else {
            $pdf = Pdf::loadView('financial-document', ['artifact' => $artifact, 'snapshot' => $snapshot, 'manifest' => $artifact->manifest])
                ->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
                ->setPaper('a4', $artifact->kind === 'statement' ? 'portrait' : 'landscape');
            $bytes = $pdf->output();
            if ($pdf->getDomPDF()->getCanvas()->get_page_count() > 500) {
                throw new RuntimeException('Financial PDF exceeds the local 500-page profile.');
            }
        }
        if (strlen($bytes) > 20 * 1024 * 1024) {
            throw new RuntimeException('Financial artifact exceeds the local 20 MB profile.');
        }
        $path = 'financial-artifacts/'.$artifact->artifact_reference.'/'.$artifact->render_generation.'-'.Str::uuid().'.encrypted';
        if (! Storage::disk('local')->put($path, Crypt::encryptString($bytes))) {
            throw new RuntimeException('Private artifact storage failed.');
        }
        $published = false;
        try {
            $published = app(PlatformGuard::class)->transaction('derived', function () use ($artifact, $bytes, $path, $publicationFence): bool {
                if ($publicationFence !== null) {
                    $publicationFence();
                }
                $current = FinancialArtifact::query()->whereKey($artifact->id)->lockForUpdate()->firstOrFail();
                if ($current->status !== 'queued' || $current->render_generation !== $artifact->render_generation || $current->expires_at?->isPast()) {
                    return false;
                }
                $actor = User::query()->whereKey($current->requester_user_id)->lockForUpdate()->firstOrFail();
                $this->authorize($actor, $current);
                if (! hash_equals($current->snapshot_hash, $this->digest($current->snapshot))) {
                    throw new RuntimeException('Financial snapshot changed before publication.');
                }
                $current->update(['status' => 'ready', 'storage_path' => $path, 'artifact_hash' => hash('sha256', $bytes), 'issued_at' => now(), 'failure_code' => null]);
                $this->audit('ready', $current, $actor);
                $this->notice($current, 'ready');

                return true;
            });
        } finally {
            if (! $published) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    private function notice(FinancialArtifact $artifact, string $event): void
    {
        DB::table('financial_artifact_events')->insertOrIgnore(['financial_artifact_id' => $artifact->id, 'source_version' => $artifact->render_generation, 'event_type' => $event, 'created_at' => now()]);
        $source = DB::table('financial_artifact_events')->where('financial_artifact_id', $artifact->id)->where('source_version', $artifact->render_generation)->where('event_type', $event)->sole();
        DB::table('financial_artifact_notification_intents')->insertOrIgnore(['notification_id' => (string) Str::uuid(),
            'financial_artifact_event_id' => $source->id, 'recipient_user_id' => $artifact->requester_user_id,
            'customer_profile_id' => $artifact->customer_profile_id, 'audience_type' => 'artifact_requester', 'channel' => 'database', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now()]);
        $intent = DB::table('financial_artifact_notification_intents')->where('financial_artifact_event_id', $source->id)->sole();
        app(NotificationPipeline::class)->capture('financial_artifact', (int) $intent->id);
    }

    public function download(User $actor, FinancialArtifact $artifact): string
    {
        return app(PlatformGuard::class)->transaction('read', function () use ($actor, $artifact): string {
            $artifact = FinancialArtifact::query()->whereKey($artifact->id)->lockForUpdate()->firstOrFail();
            $actor = User::query()->whereKey($actor->id)->firstOrFail();
            $this->authorize($actor, $artifact);
            abort_unless($artifact->status === 'ready' && ! $artifact->expires_at?->isPast(), 404);
            $bytes = Crypt::decryptString(Storage::disk('local')->get($artifact->storage_path));
            abort_unless(hash_equals($artifact->artifact_hash, hash('sha256', $bytes)), 503, 'Document integrity verification failed.');
            $this->audit('downloaded', $artifact, $actor);

            return $bytes;
        });
    }

    public function cancel(User $actor, FinancialArtifact $artifact): void
    {
        app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $artifact): void {
            $artifact = FinancialArtifact::query()->whereKey($artifact->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $artifact);
            abort_unless($artifact->requester_user_id === $actor->id, 404);
            if ($artifact->status === 'cancelled') {
                return;
            }
            if ($artifact->status !== 'queued') {
                throw new ConflictHttpException('Only a queued document can be cancelled.');
            }
            $artifact->update(['status' => 'cancelled']);
            $this->audit('cancelled', $artifact, $actor);
        });
    }

    public function recordFailure(int $id, ?int $generation = null): void
    {
        app(PlatformGuard::class)->transaction('derived', function () use ($id, $generation): void {
            $artifact = FinancialArtifact::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if (! in_array($artifact->status, ['queued', 'running'], true)
                || ($generation !== null && $artifact->render_generation !== $generation)) {
                return;
            }
            $artifact->update(['status' => 'failed', 'failure_code' => 'render_or_authorization_failed']);
            $this->audit('failed', $artifact, null);
            $this->notice($artifact, 'failed');
        });
    }

    public function retry(User $actor, FinancialArtifact $reference): void
    {
        app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $reference): void {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $artifact = FinancialArtifact::query()->whereKey($reference->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $artifact);
            abort_unless($artifact->requester_user_id === $actor->id, 404);
            if ($artifact->status === 'queued') {
                return;
            }
            if ($artifact->status !== 'failed' || $artifact->expires_at?->isPast()) {
                throw new ConflictHttpException('Only an unexpired failed artifact can retry its retained snapshot.');
            }
            $artifact->update(['status' => 'queued', 'render_generation' => $artifact->render_generation + 1, 'failure_code' => null]);
            $this->audit('retried', $artifact, $actor);
            app(BackgroundRecovery::class)->restartArtifact($artifact);
            DB::afterCommit(static function () use ($artifact): void {
                try {
                    RenderFinancialArtifact::dispatch($artifact->id, $artifact->render_generation)->afterCommit();
                } catch (\Throwable) {
                    // The durable queued artifact remains available to the drain command.
                }
            });
        });
    }

    public function setHold(User $actor, FinancialArtifact $reference, bool $held, string $reason, Request $request): void
    {
        app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $reference, $held, $reason, $request): void {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AuthorizationService::class)->allows($actor, AdminPermission::SecurityOperationsManage)
                && app(FreshAuthenticationService::class)->isFresh($actor, $request), 403);
            $artifact = FinancialArtifact::query()->whereKey($reference->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $artifact);
            if ($artifact->kind !== 'report' || $artifact->status === 'expired' || trim($reason) === '') {
                throw new ConflictHttpException('A reasoned retention hold requires an existing report artifact.');
            }
            if ($artifact->held === $held) {
                return;
            }
            $artifact->update(['held' => $held]);
            AuditEvent::record('financial_artifact.'.($held ? 'hold_applied' : 'hold_released'), FinancialArtifact::class, $artifact->id, $artifact->artifact_reference,
                ['kind' => $artifact->kind, 'format' => $artifact->format, 'status' => $artifact->status, 'held' => $held, 'reason' => trim($reason)], $actor,
                context: ['executor' => self::class, 'required_permission' => 'security.operations.manage']);
        });
    }

    public function expire(): int
    {
        $count = 0;
        foreach (FinancialArtifact::query()->where('kind', 'report')->where('held', false)->where('expires_at', '<=', now())->where('status', '!=', 'expired')->pluck('id') as $id) {
            $count += (int) app(PlatformGuard::class)->transaction('mutation', function () use ($id): bool {
                $artifact = FinancialArtifact::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                if ($artifact->held || $artifact->status === 'expired') {
                    return false;
                }
                if ($artifact->storage_path !== null && ! Storage::disk('local')->delete($artifact->storage_path)) {
                    throw new RuntimeException('Expired artifact cleanup failed.');
                }
                $artifact->update(['status' => 'expired', 'storage_path' => null]);
                $this->audit('expired', $artifact, null);

                return true;
            });
        }

        return $count;
    }

    public function discardOrphanGenerations(int $limit = 100): int
    {
        $count = 0;
        $disk = Storage::disk('local');
        foreach ($disk->allFiles('financial-artifacts') as $path) {
            if ($count >= min(1000, max(1, $limit))) {
                break;
            }
            $parts = explode('/', $path);
            if (count($parts) !== 3 || ! str_ends_with($path, '.encrypted') || $disk->lastModified($path) > now()->subDay()->timestamp) {
                continue;
            }
            $count += (int) app(PlatformGuard::class)->transaction('mutation', function () use ($parts, $path, $disk): bool {
                $artifact = FinancialArtifact::query()->where('artifact_reference', $parts[1])->lockForUpdate()->first();
                if ($artifact === null || $artifact->held || $artifact->storage_path === $path
                    || DB::table('platform_recovery_work')->where('owner', 'financial_artifact')->where('source_id', $artifact->id)
                        ->where('state', 'running')->where('lease_expires_at', '>', now())->exists()) {
                    return false;
                }
                if (! $disk->delete($path)) {
                    throw new RuntimeException('Unpublished artifact generation cleanup failed.');
                }

                return true;
            });
        }

        return $count;
    }

    /** @param array<string, mixed> $snapshot
     * @param  array<string, mixed>  $manifest
     */
    public function csv(array $snapshot, array $manifest = []): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('The export buffer could not be opened.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Section', 'Record type', 'Field', 'Value'], ',', '"', '', "\r\n");
        foreach (['source' => $snapshot['manifest'] ?? [], 'artifact' => $manifest] as $section => $metadata) {
            foreach ($metadata as $key => $value) {
                $text = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : (string) $value;
                fputcsv($stream, ['Manifest', $section, $this->cell((string) $key), $this->cell($text)], ',', '"', '', "\r\n");
            }
        }
        foreach ($snapshot['sections'] as $name => $section) {
            fputcsv($stream, [$this->cell($name), 'coverage', 'status', $this->cell($section['status'])], ',', '"', '', "\r\n");
            fputcsv($stream, [$this->cell($name), 'coverage', 'reason', $this->cell($section['reason'] ?? '')], ',', '"', '', "\r\n");
            foreach ($section['metrics'] as $metric) {
                foreach ($metric as $key => $value) {
                    if (is_scalar($value) || $value === null) {
                        fputcsv($stream, [$this->cell($name), 'metric', $this->cell((string) $key), $this->cell((string) $value)], ',', '"', '', "\r\n");
                    }
                }
            }
            foreach ($section['groups'] ?? [] as $group) {
                fputcsv($stream, [$this->cell($name), 'group', 'label', $this->cell($group['label'])], ',', '"', '', "\r\n");
                foreach ($group['metrics'] as $metric) {
                    foreach ($metric as $key => $value) {
                        if (is_scalar($value) || $value === null) {
                            fputcsv($stream, [$this->cell($name), 'group:'.$this->cell($group['label']), $this->cell((string) $key), $this->cell((string) $value)], ',', '"', '', "\r\n");
                        }
                    }
                }
            }
            foreach ($section['rows'] as $index => $row) {
                foreach ($section['columns'] as $key => $label) {
                    fputcsv($stream, [$this->cell($name), 'row:'.($index + 1), $this->cell($label), $this->cell((string) ($row[$key] ?? ''))], ',', '"', '', "\r\n");
                }
            }
        }
        rewind($stream);
        $bytes = stream_get_contents($stream);
        fclose($stream);
        if ($bytes === false) {
            throw new RuntimeException('The export buffer could not be read.');
        }

        return $bytes;
    }

    private function cell(string $value): string
    {
        return preg_match('/\A[\s\x00-\x20\x{FEFF}\x{200B}-\x{200D}]*[=+\-@\t\r]/u', $value) === 1 ? "'".$value : $value;
    }

    /** @param array<string, mixed> $snapshot */
    private function create(User $actor, string $operation, string $hash, string $kind, string $format, array $snapshot, ?int $customerId, ?FinancialArtifact $supersedes = null): FinancialArtifact
    {
        $business = BusinessProfile::current();
        $artifact = FinancialArtifact::create(['artifact_reference' => (string) Str::uuid(), 'operation_reference' => $operation,
            'supersedes_artifact_id' => $supersedes?->id, 'requester_user_id' => $actor->id, 'customer_profile_id' => $customerId, 'kind' => $kind, 'format' => $format,
            'render_generation' => 1, 'payload_hash' => $hash, 'snapshot_hash' => $this->digest($snapshot), 'snapshot' => $snapshot,
            'manifest' => ['schema_version' => 1, 'renderer_profile' => 'local-v1-10000-rows-20mb-500pages', 'business_name' => $business->display_name,
                'business_id' => $business->business_id, 'business_version' => $business->version, 'snapshot_hash' => $this->digest($snapshot),
                'supersedes_reference' => $supersedes?->artifact_reference,
                'control_totals' => $kind === 'statement' ? ['opening_kobo' => $snapshot['opening_kobo'], 'activity_kobo' => $snapshot['activity_kobo'], 'closing_kobo' => $snapshot['closing_kobo'], 'line_count' => count($snapshot['lines'])] : array_map(static fn (array $section): array => ['row_count' => count($section['rows']), 'metrics' => $section['metrics']], $snapshot['sections']),
                'requester_user_id' => $actor->id, 'captured_at' => now()->toIso8601String(), 'currency' => 'NGN'],
            'expires_at' => $kind === 'report' ? now()->addDays(7) : null]);
        $this->audit('requested', $artifact, $actor);
        app(BackgroundRecovery::class)->register('financial_artifact', $artifact->id);
        DB::afterCommit(static function () use ($artifact): void {
            try {
                RenderFinancialArtifact::dispatch($artifact->id, $artifact->render_generation)->afterCommit();
            } catch (\Throwable) {
                // The queued record is recovered by financial-artifacts:drain.
            }
        });

        return $artifact;
    }

    private function replay(User $actor, string $reference, string $hash): ?FinancialArtifact
    {
        $artifact = FinancialArtifact::query()->where('operation_reference', $reference)->lockForUpdate()->first();
        if ($artifact !== null) {
            $this->authorize($actor, $artifact);
            if ($artifact->requester_user_id !== $actor->id || ! hash_equals($artifact->payload_hash, $hash)) {
                throw new ConflictHttpException('This operation reference belongs to a different document request.');
            }
        }

        return $artifact;
    }

    /** @param array<array-key, mixed> $value */
    private function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function audit(string $event, FinancialArtifact $artifact, ?User $actor): void
    {
        AuditEvent::record('financial_artifact.'.$event, FinancialArtifact::class, $artifact->id, $artifact->artifact_reference,
            ['kind' => $artifact->kind, 'format' => $artifact->format, 'status' => $artifact->status, 'snapshot_hash' => $artifact->snapshot_hash,
                'artifact_hash' => $artifact->artifact_hash], $actor, context: ['executor' => self::class]);
    }
}
