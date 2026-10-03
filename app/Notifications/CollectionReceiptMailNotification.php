<?php

namespace App\Notifications;

use App\Support\CollectionReceiptMailDeliveryEvidence;
use App\Support\MoneyFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CollectionReceiptMailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TEMPLATE_VERSION = 1;

    public CollectionReceiptMailDeliveryEvidence $deliveryEvidence;

    /** @param list<array{customer_description: string, amount_kobo: int, remaining_kobo: int}> $feeComponents */
    public function __construct(string $notificationId, public string $reference, public int $savingsKobo,
        public int $feeKobo, public string $receivedDate, public string $timezone, public string $method, public bool $isReplacement,
        public array $feeComponents = [])
    {
        $this->id = $notificationId;
        $this->deliveryEvidence = new CollectionReceiptMailDeliveryEvidence;
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Collection receipt '.$this->reference)
            ->greeting('Hello,')
            ->line('Receipt: '.$this->reference)
            ->line(($this->isReplacement ? 'Correction recorded: ' : 'Received: ').$this->receivedDate.' ('.$this->timezone.')')
            ->line('Method: '.$this->method)
            ->line(($this->isReplacement ? 'Funds reallocated: ' : 'Total received: ').MoneyFormatter::formatNaira($this->savingsKobo + $this->feeKobo))
            ->line('Savings: '.MoneyFormatter::formatNaira($this->savingsKobo))
            ->line('Fees: '.MoneyFormatter::formatNaira($this->feeKobo))
            ->line('Contact the business if you need help with this receipt.');
        if ($this->isReplacement) {
            $message->line('This receipt corrects the allocation of existing funds. No additional money was received.');
        }
        foreach ($this->feeComponents as $fee) {
            $message->line($fee['customer_description'].': allocated '.MoneyFormatter::formatNaira($fee['amount_kobo'])
                .'; unpaid at this receipt '.MoneyFormatter::formatNaira($fee['remaining_kobo']).'.');
        }

        return $message;
    }
}
