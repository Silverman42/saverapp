<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountUnlockedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $unlockedByRole = 'Administrator',
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
            ->subject('Security Notice: Account Restriction Cleared')
            ->line("A temporary authentication restriction on your account has been cleared by an authorized {$this->unlockedByRole} following identity verification.")
            ->line('You may now sign in using your regular authentication credentials.')
            ->line('Your password, multi-factor authentication, and account permissions were not modified by this action.')
            ->line('If you did not request this unlock, please contact platform security immediately.');
    }
}
