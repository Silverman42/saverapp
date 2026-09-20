<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TwoFactorFailedAttemptsExceededNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
            ->subject('Security Alert: Excessive Two-Factor Authentication Attempts')
            ->line('Multiple failed two-factor authentication attempts were detected on your account.')
            ->line('The current authentication attempt has been ended for your protection.')
            ->line('If this was not you, someone may be attempting to access your account. We recommend reviewing your account security or contacting an administrator.');
    }
}
