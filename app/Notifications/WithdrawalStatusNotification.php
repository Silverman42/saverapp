<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WithdrawalStatusNotification extends Notification implements ShouldQueue
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
        return (new MailMessage)->subject($this->payload['title'])
            ->line($this->payload['message'])
            ->action('View withdrawal', $this->payload['url']);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['title' => $this->payload['title'], 'message' => $this->payload['message'],
            'withdrawal_id' => $this->payload['withdrawal_id'], 'state' => $this->payload['state'],
            'action_url' => $this->payload['url']];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }
}
