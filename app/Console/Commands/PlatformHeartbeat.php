<?php

namespace App\Console\Commands;

use App\Services\PlatformDiagnostics;
use Illuminate\Console\Command;

class PlatformHeartbeat extends Command
{
    protected $signature = 'platform:heartbeat';

    protected $description = 'Record scheduler liveness without authorizing business work';

    public function handle(PlatformDiagnostics $diagnostics): int
    {
        $diagnostics->heartbeat('scheduler');

        return self::SUCCESS;
    }
}
