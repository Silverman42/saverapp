<?php

namespace App\Jobs;

use App\Services\FinancialArtifactService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RenderFinancialArtifact implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $artifactId, public int $generation = 1) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 120];
    }

    public function handle(FinancialArtifactService $artifacts): void
    {
        $artifacts->render($this->artifactId, $this->generation);
    }

    public function failed(?Throwable $exception): void
    {
        app(FinancialArtifactService::class)->recordFailure($this->artifactId, $this->generation);
    }
}
