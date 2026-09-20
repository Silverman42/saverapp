<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AuthenticatorEnrolledNotification extends Notification implements ShouldQueue
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
            ->subject('Security Notice: Authenticator App Configured')
            ->line('Two-factor authentication has been successfully configured on your account.')
            ->line('Your new authenticator app is now active for all future sign-ins and step-up authentication.')
            ->line('Please make sure you have safely stored your 10 emergency recovery codes.')
            ->line('If you did not make this change, please contact support immediately.');
    }
}
