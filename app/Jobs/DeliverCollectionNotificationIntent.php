<?php

namespace App\Jobs;

use App\Services\NotificationPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeliverCollectionNotificationIntent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(public int $intentId) {}

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('collection-notification-'.$this->intentId))->expireAfter(300)];
    }

    public function handle(): void
    {
        $intent = DB::table('collection_notification_intents')->where('id', $this->intentId)->first();
        if ($intent === null || $intent->status !== 'pending') {
            return;
        }
        app(NotificationPipeline::class)->deliverOwner('collection', $this->intentId);

    }

    public function failed(?Throwable $exception): void
    {
        DB::table('collection_notification_intents')->where('id', $this->intentId)
            ->where('status', 'pending')->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
