<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProfileChangeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{title: string, message: string, fields?: array<int, string>, url?: string}  $payload
     */
    public function __construct(string $notificationId, public array $payload, public string $channel)
    {
        $this->id = $notificationId;
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return [$this->channel];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->payload['title'],
            'message' => $this->payload['message'],
            'fields' => $this->payload['fields'] ?? [],
            'action_url' => $this->payload['url'] ?? null,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->payload['title'])
            ->greeting('Hello,')
            ->line($this->payload['message']);

        if (isset($this->payload['url'])) {
            $mail->action('Review profile', $this->payload['url']);
        }

        return $mail;
    }
}
