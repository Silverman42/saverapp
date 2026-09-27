<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerHandoverNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<string, string> $payload */
    public function __construct(#[\SensitiveParameter] public array $payload) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->payload['title'])->line($this->payload['message']);
        if (isset($this->payload['token'])) {
            $mail->line('Copy this single-use activation code into the recovery form: '.$this->payload['token']);
        }
        if (isset($this->payload['url'])) {
            $mail->action('Activate recovery', $this->payload['url']);
        }

        return $mail;
    }
}
