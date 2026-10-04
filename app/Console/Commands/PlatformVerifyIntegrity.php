<?php

namespace App\Console\Commands;

use App\Services\PlatformIntegrity;
use Illuminate\Console\Command;

class PlatformVerifyIntegrity extends Command
{
    protected $signature = 'platform:verify-integrity {--operator=} {--connection= : An isolated restored connection; omit for the live database} {--json}';

    protected $description = 'Record a read-only canonical integrity run; a failed live run keeps financial writes blocked';

    public function handle(PlatformIntegrity $integrity): int
    {
        $operator = trim((string) $this->option('operator'));
        if ($operator === '') {
            $this->error('An operator reference is required.');

            return self::FAILURE;
        }
        $run = $integrity->verify($operator, $this->option('connection') ?: null);
        $this->line(json_encode($run, JSON_THROW_ON_ERROR | ($this->option('json') ? 0 : JSON_PRETTY_PRINT)));

        return $run['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}
