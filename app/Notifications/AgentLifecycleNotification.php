<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AgentLifecycleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<string, string> $payload */
    public function __construct(string $notificationId, public array $payload, public string $channel)
    {
        $this->id = $notificationId;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [$this->channel];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->payload['title'])
            ->greeting('Hello,')
            ->line($this->payload['message']);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->payload['title'],
            'message' => $this->payload['message'],
            'status' => $this->payload['status'],
            'effective_at' => $this->payload['effective_at'],
            'action_url' => $this->payload['url'] ?? '',
        ];
    }

    /** @return array<string, string> */
    public function toDatabase(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }
}
