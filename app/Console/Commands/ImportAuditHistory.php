<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Services\AuditCapture;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportAuditHistory extends Command
{
    protected $signature = 'audit:import-history {--limit=100 : Maximum records per run}';

    protected $description = 'Import safe legacy audit summaries';

    public function handle(): int
    {
        $limit = min(1000, max(1, (int) $this->option('limit')));
        $count = 0;
        AuditEvent::query()->whereNotIn('id', DB::table('audit_import_results')->select('legacy_audit_event_id'))
            ->whereNotIn('id', DB::table('canonical_audit_events')->select('legacy_audit_event_id')->whereNotNull('legacy_audit_event_id'))
            ->orderBy('id')->limit($limit)->get()->each(function ($event) use (&$count): void {
                try {
                    app(AuditCapture::class)->import($event);
                    $status = 'imported';
                    $code = null;
                    $count++;
                } catch (\InvalidArgumentException) {
                    $status = 'unsupported';
                    $code = 'unsupported_schema';
                }
                DB::table('audit_import_results')->updateOrInsert(['legacy_audit_event_id' => $event->id], ['status' => $status, 'diagnostic_code' => $code, 'updated_at' => now()]);
            });
        $this->info("Imported {$count} safe legacy records.");

        return self::SUCCESS;
    }
}
