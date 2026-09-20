<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminConcurrentDeviceRevokedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $revokedDeviceName,
        public string $newDeviceName,
        public ?string $maskedIpAddress = null,
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
        $message = (new MailMessage)
            ->subject('Security Alert: Previous Administrator Session Revoked')
            ->line('Your previous administrator session was signed out because a new administrator sign-in was confirmed on another device.')
            ->line("Revoked device: {$this->revokedDeviceName}")
            ->line("New sign-in device: {$this->newDeviceName}");

        if ($this->maskedIpAddress) {
            $message->line("Approximate network: {$this->maskedIpAddress}");
        }

        return $message
            ->line('Administrators are strictly limited to one active device session at a time.')
            ->line('If you did not authorize this sign-in, please immediately change your password and notify the security team.');
    }
}
