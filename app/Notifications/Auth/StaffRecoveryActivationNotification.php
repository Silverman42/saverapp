<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffRecoveryActivationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $activationUrl,
        public string $expiresAtFormatted,
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
            ->subject('Complete your account recovery')
            ->line('An authorized recovery of your account was approved.')
            ->line("Verify this email address and choose your own password before {$this->expiresAtFormatted}. You will then set up a new authenticator app and save new recovery codes.")
            ->action('Recover my account', $this->activationUrl)
            ->line('This link works once. Nobody else can see or set your new password.')
            ->line('If you did not expect this, contact your Administrator immediately.');
    }
}
