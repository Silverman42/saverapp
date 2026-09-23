<?php

namespace App\Jobs;

use App\Enums\AccountState;
use App\Models\CollectionReceipt;
use App\Models\User;
use App\Notifications\CollectionReceiptNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
        $receipt = CollectionReceipt::query()->whereKey($intent->collection_receipt_id)->first();
        $recipient = User::query()->whereKey($intent->recipient_user_id)->first();
        if ($receipt === null || $recipient === null || $recipient->account_state !== AccountState::Active
            || $receipt->customerProfile->user_id !== $recipient->id) {
            DB::table('collection_notification_intents')->where('id', $this->intentId)->update([
                'status' => 'suppressed', 'suppressed_at' => now(), 'updated_at' => now(),
            ]);

            return;
        }
        if (! $recipient->notifications()->whereKey($intent->notification_id)->exists()) {
            Notification::sendNow($recipient, new CollectionReceiptNotification(
                $intent->notification_id, $receipt->receipt_reference, $receipt->tender_amount_kobo,
            ), ['database']);
        }
        DB::table('collection_notification_intents')->where('id', $this->intentId)->update([
            'status' => 'delivered', 'delivered_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('collection_notification_intents')->where('id', $this->intentId)
            ->where('status', 'pending')->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
