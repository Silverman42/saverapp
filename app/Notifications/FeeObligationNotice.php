<?php

namespace App\Notifications;

use App\Enums\FeeObligationEntryType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FeeObligationNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $amountKobo,
        public string $currency,
        public FeeObligationEntryType $entryType,
        public string $customerDescription,
        public int $obligationId,
        public string $customerId,
    ) {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Fee obligation updated')
            ->line($this->customerDescription)
            ->action('View fee details', route('customers.show', $this->customerId));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Fee obligation updated',
            'message' => $this->customerDescription,
            'amount_kobo' => $this->amountKobo,
            'currency' => $this->currency,
            'entry_type' => $this->entryType->value,
            'fee_obligation_id' => $this->obligationId,
            'customer_id' => $this->customerId,
            'action_url' => route('customers.show', $this->customerId),
        ];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }
}
