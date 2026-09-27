<?php

namespace App\Jobs;

use App\Services\BackgroundRecovery;
use App\Services\NotificationPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MaterializeNotificationIntent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public int $intentId) {}

    public function handle(NotificationPipeline $pipeline): void
    {
        app(BackgroundRecovery::class)->runSource('notification_inbox', $this->intentId);
    }
}
