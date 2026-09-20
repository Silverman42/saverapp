<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RecoveryCodeUsedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public int $remainingCodesCount) {}

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
        $mail = (new MailMessage)
            ->subject('Security Notice: Emergency Recovery Code Used')
            ->line('An emergency recovery code was just used to access your account.')
            ->line("You have {$this->remainingCodesCount} unused recovery code(s) remaining.");

        if ($this->remainingCodesCount <= 2) {
            $mail->line('Warning: You have two or fewer recovery codes remaining. We strongly recommend regenerating a new set of codes once you are signed in.');
        }

        $mail->line('If you did not use this recovery code, please contact an administrator immediately.');

        return $mail;
    }
}
