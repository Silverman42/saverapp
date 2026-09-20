<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AuthenticatorReplacedNotification extends Notification implements ShouldQueue
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
            ->subject('Security Notice: Authenticator App Replaced')
            ->line('Your authenticator app has been successfully replaced with a new device.')
            ->line('All previous recovery codes and other active sessions on your account have been revoked.')
            ->line('If you did not authorize this replacement, your account may be compromised. Please contact an administrator immediately.');
    }
}
