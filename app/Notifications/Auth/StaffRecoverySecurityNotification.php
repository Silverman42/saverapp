<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffRecoverySecurityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public string $action) {}

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
        $message = match ($this->action) {
            'requested' => 'An Administrator started an assisted recovery of your account. Your current access is unchanged until the recovery is approved.',
            'approved', 'reissued' => 'An assisted recovery of your account was approved. Your previous password, authenticator and sessions no longer work.',
            'completed' => 'Your account recovery is complete. Sign in with your new password and authenticator.',
            'rejected' => 'An assisted recovery request for your account was rejected. Your access is unchanged.',
            default => 'An assisted recovery request for your account was cancelled. Your access is unchanged.',
        };

        return (new MailMessage)
            ->subject('Account recovery security notice')
            ->line($message)
            ->line('If you did not expect this, contact your Administrator immediately.');
    }
}
