<?php

namespace App\Console\Commands;

use App\Services\AuditProjection;
use App\Services\SecurityCaseService;
use Illuminate\Console\Command;

class DrainAuditProjection extends Command
{
    protected $signature = 'audit:drain {--limit=100 : Maximum pending records}';

    protected $description = 'Process durable audit projection work';

    public function handle(): int
    {
        $count = app(AuditProjection::class)->drain(min(1000, max(1, (int) $this->option('limit'))));
        app(SecurityCaseService::class)->releaseIneligibleOwners();
        $this->info("Projected {$count} audit events.");

        return self::SUCCESS;
    }
}
