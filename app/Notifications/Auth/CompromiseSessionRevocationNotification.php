<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompromiseSessionRevocationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $reason = 'Suspicious authentication activity detected',
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
        return (new MailMessage)
            ->subject('Urgent Security Alert: Account Sessions Revoked')
            ->line("All active sessions and trusted devices for your account were signed out due to: {$this->reason}.")
            ->line('This protective action was taken because suspicious authentication patterns were detected.')
            ->line('We strongly recommend resetting your password immediately using the password reset option.')
            ->line('If you need assistance, please contact security support immediately.');
    }
}
