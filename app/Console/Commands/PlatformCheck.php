<?php

namespace App\Console\Commands;

use App\Services\PlatformDiagnostics;
use Illuminate\Console\Command;

class PlatformCheck extends Command
{
    protected $signature = 'platform:check {--json}';

    protected $description = 'Inspect safe platform diagnostics; this does not certify financial or production readiness';

    public function handle(PlatformDiagnostics $diagnostics): int
    {
        $report = $diagnostics->report();
        $this->line(json_encode($report, JSON_THROW_ON_ERROR | ($this->option('json') ? 0 : JSON_PRETTY_PRINT)));

        return $report['checks']['database']['state'] === 'Ready' && $report['checks']['schema']['state'] === 'Ready' ? self::SUCCESS : self::FAILURE;
    }
}
