<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{title: string, message: string, status: string, effective_at: string, customer_id: string, url?: string}  $payload
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

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->payload['title'],
            'message' => $this->payload['message'],
            'status' => $this->payload['status'],
            'effective_at' => $this->payload['effective_at'],
            'customer_id' => $this->payload['customer_id'],
            'action_url' => $this->payload['url'] ?? '',
        ];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->payload['title'])
            ->greeting('Hello,')
            ->line($this->payload['message'])
            ->line('Effective status: '.$this->payload['status'])
            ->line('Effective at: '.$this->payload['effective_at']);
    }
}
