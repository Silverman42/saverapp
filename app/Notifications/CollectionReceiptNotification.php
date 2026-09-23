<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class CollectionReceiptNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(string $notificationId, public string $reference, public int $amountKobo)
    {
        $this->id = $notificationId;
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Cash collection recorded',
            'message' => 'Receipt '.$this->reference.' was recorded for ₦'.number_format($this->amountKobo / 100, 2).'.',
            'receipt_reference' => $this->reference,
            'action_url' => route('collections.show', $this->reference, false),
        ];
    }
}
