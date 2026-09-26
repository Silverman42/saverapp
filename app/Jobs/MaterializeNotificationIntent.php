<?php

namespace App\Jobs;

use App\Services\NotificationPipeline;
use App\Services\PlatformGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MaterializeNotificationIntent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public int $intentId) {}

    public function handle(NotificationPipeline $pipeline): void
    {
        app(PlatformGuard::class)->work('external', function () use ($pipeline): void {
            $this->handleAllowed($pipeline);
        });
    }

    private function handleAllowed(NotificationPipeline $pipeline): void
    {
        $pipeline->materialize($this->intentId);
    }
}
