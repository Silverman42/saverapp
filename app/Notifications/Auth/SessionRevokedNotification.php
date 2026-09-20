<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SessionRevokedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $reason,
        public ?string $deviceInfo = null,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Security Notice: Account Sessions Revoked')
            ->line("An active session was signed out on your account: {$this->reason}.");

        if ($this->deviceInfo) {
            $message->line("Device: {$this->deviceInfo}");
        }

        return $message
            ->line('If you did not perform this action, please review your account security immediately.');
    }
}
