<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RecoveryCodesRegeneratedNotification extends Notification implements ShouldQueue
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
            ->subject('Security Notice: Recovery Codes Regenerated')
            ->line('Your two-factor emergency recovery codes have been regenerated.')
            ->line('All previous recovery codes for your account have been invalidated.')
            ->line('Please make sure you have safely recorded your new recovery codes in a secure location.')
            ->line('If you did not regenerate these codes, please contact an administrator immediately.');
    }
}
