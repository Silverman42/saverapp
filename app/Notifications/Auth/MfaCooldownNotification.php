<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MfaCooldownNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public int $durationMinutes = 15,
        public string $reason = 'Excessive invalid authenticator codes',
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
            ->subject('Security Notice: Two-Factor Authentication Cooldown')
            ->line("A {$this->durationMinutes}-minute cooldown has been applied to two-factor authenticator verification on your account due to {$this->reason}.")
            ->line('Your authenticator app configuration has not been modified or removed.')
            ->line('Please wait for the cooldown to expire before attempting two-factor verification again.')
            ->line('If you did not attempt these codes, your credentials may be targeted. We recommend updating your account security.');
    }
}
