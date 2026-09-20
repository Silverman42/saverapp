<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordLockoutNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public int $durationMinutes,
        public string $reason,
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
        $durationText = $this->durationMinutes >= 60
            ? ($this->durationMinutes / 60).' hour'
            : "{$this->durationMinutes} minutes";

        return (new MailMessage)
            ->subject('Security Alert: Temporary Login Lock Applied')
            ->line("A temporary {$durationText} login lock has been applied to your account due to {$this->reason}.")
            ->line('New password sign-in attempts will be rejected during this period. Any existing active sessions remain unaffected.')
            ->line('You may wait for the lock duration to expire, or you may reset your password to immediately clear this lock.')
            ->line('If you did not initiate these sign-in attempts, please contact security support immediately.');
    }
}
