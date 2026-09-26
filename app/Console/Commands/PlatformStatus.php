<?php

namespace App\Console\Commands;

use App\Services\PlatformState;
use Illuminate\Console\Command;

class PlatformStatus extends Command
{
    protected $signature = 'platform:status {--json}';

    protected $description = 'Show the current platform mode without exposing operational evidence';

    public function handle(PlatformState $state): int
    {
        $this->line(json_encode($state->publicStatus(), JSON_THROW_ON_ERROR | ($this->option('json') ? 0 : JSON_PRETTY_PRINT)));

        return self::SUCCESS;
    }
}
