<?php

namespace App\Console\Commands;

use App\Services\AuditProjection;
use App\Services\PlatformGuard;
use App\Support\PlatformBlocked;
use Illuminate\Console\Command;

class RebuildAuditProjection extends Command
{
    protected $signature = 'audit:rebuild {--limit=100 : Maximum records per resumable batch}';

    protected $description = 'Rebuild and verify the audit search projection';

    public function handle(): int
    {
        try {
            return app(PlatformGuard::class)->transaction('derived', function () {
                return $this->handleAllowed();
            });
        } catch (PlatformBlocked) {
            return 0;
        }
    }

    private function handleAllowed(): int
    {
        $version = app(AuditProjection::class)->rebuild((int) $this->option('limit'));
        $this->info($version === null ? 'Rebuild checkpoint saved; run again to continue.' : "Promoted audit projection version {$version}.");

        return self::SUCCESS;
    }
}
