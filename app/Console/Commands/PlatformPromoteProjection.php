<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Services\AuditProjection;
use App\Services\LedgerTransactionProjectionService;
use App\Support\PlatformBlocked;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PlatformPromoteProjection extends Command
{
    protected $signature = 'platform:promote-projection {projection : ledger or audit} {--expected-version=} {--operator=} {--reason=} {--incident=} {--json}';

    protected $description = 'Rebuild a derived projection into a new version and promote it only after source verification';

    /** @var array<string, string> */
    private const STATE_TABLES = ['ledger' => 'ledger_projection_state', 'audit' => 'audit_projection_state'];

    public function handle(LedgerTransactionProjectionService $ledger, AuditProjection $audit): int
    {
        $projection = (string) $this->argument('projection');
        $table = self::STATE_TABLES[$projection] ?? null;
        $evidence = array_map(fn (?string $value): string => trim((string) $value), ['operator' => $this->option('operator'), 'reason' => $this->option('reason'), 'incident' => $this->option('incident')]);
        if ($table === null || ! ctype_digit((string) $this->option('expected-version')) || in_array('', $evidence, true)) {
            $this->error('Supply ledger or audit, the expected active version, operator, reason and incident.');

            return self::FAILURE;
        }
        $lock = Cache::store('database')->lock('platform:promote-projection:'.$projection, 600);
        if (! $lock->get()) {
            $this->error('Another promotion of this projection is in progress.');

            return self::FAILURE;
        }
        try {
            return $this->promote($projection, $table, $evidence, $ledger, $audit);
        } finally {
            $lock->release();
        }
    }

    /** @param array{operator: string, reason: string, incident: string} $evidence */
    private function promote(string $projection, string $table, array $evidence, LedgerTransactionProjectionService $ledger, AuditProjection $audit): int
    {
        $previous = (int) DB::table($table)->where('id', 1)->value('active_version');
        if ($previous !== (int) $this->option('expected-version')) {
            $this->error('The active projection version changed; re-read status before promoting.');

            return self::FAILURE;
        }
        try {
            if ($projection === 'ledger') {
                $version = $ledger->rebuild()['version'];
            } else {
                do {
                    $version = $audit->rebuild(1000);
                } while ($version === null);
            }
        } catch (PlatformBlocked|RuntimeException $exception) {
            $this->error('Verification failed; the last verified version '.$previous.' remains active. '.$exception->getMessage());

            return self::FAILURE;
        }
        $result = ['projection' => $projection, 'previous_version' => $previous, 'active_version' => $version];
        AuditEvent::record('platform.projection_promoted', 'platform_projection', null, $projection, $result + ['operator' => $evidence['operator'], 'incident' => $evidence['incident']],
            context: ['executor' => self::class, 'operation_id' => 'projection:'.$projection.':'.$version]);
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | ($this->option('json') ? 0 : JSON_PRETTY_PRINT)));

        return self::SUCCESS;
    }
}
