<?php

namespace App\Support;

use App\Services\PlatformCatalogue;
use App\Services\PlatformDiagnostics;
use App\Services\PlatformGuard;
use Closure;

class PlatformJobMiddleware
{
    public function __construct(private PlatformGuard $guard, private PlatformCatalogue $catalogue, private PlatformDiagnostics $diagnostics) {}

    public function handle(object $job, Closure $next): mixed
    {
        $started = hrtime(true);
        $operation = $this->catalogue->jobClass($job);
        $outcome = 'failed';
        try {
            $managed = $this->catalogue->isLocalRecoveryJob($job);
            $result = $managed ? $next($job) : $this->guard->work($operation, fn (): mixed => $next($job));
            $outcome = 'succeeded';

            return $result;
        } catch (PlatformBlocked $exception) {
            $outcome = 'paused';
            throw $exception;
        } finally {
            $this->diagnostics->record('job', $operation, $started, $outcome);
        }
    }
}
