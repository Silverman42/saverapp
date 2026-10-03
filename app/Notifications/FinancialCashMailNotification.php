<?php

namespace App\Notifications;

use App\Support\MailTransportEvidence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FinancialCashMailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public MailTransportEvidence $deliveryEvidence;

    public function __construct(string $notificationId, public string $reference, public string $title, public string $message, public string $destination)
    {
        $this->id = $notificationId;
        $this->deliveryEvidence = new MailTransportEvidence;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->title.' '.$this->reference)
            ->greeting('Hello,')->line('Refund reference: '.$this->reference)->line($this->message)
            ->action('View your records', $this->destination)
            ->line('Sign in to view your current savings and fee history.');
    }
}
