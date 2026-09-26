<?php

namespace App\Jobs;

use App\Services\AuditProjection;
use App\Services\PlatformGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProjectAuditEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $canonicalEventId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 120];
    }

    public function handle(AuditProjection $projection): void
    {
        app(PlatformGuard::class)->work('derived', function () use ($projection): void {
            $this->handleAllowed($projection);
        });
    }

    private function handleAllowed(AuditProjection $projection): void
    {
        $projection->project($this->canonicalEventId);
    }
}
