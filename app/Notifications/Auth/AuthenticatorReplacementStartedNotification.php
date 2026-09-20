<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AuthenticatorReplacementStartedNotification extends Notification implements ShouldQueue
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
            ->subject('Security Notice: Authenticator Replacement Initiated')
            ->line('An authenticator replacement has been initiated for your account.')
            ->line('Your current authenticator remains active until the new authenticator is confirmed.')
            ->line('If you did not initiate this request, someone may be attempting to access your account. Please change your password immediately.');
    }
}
