<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $plainToken,
        public string $businessName,
        public string $recipientName,
        public string $expiresAtFormatted,
        public ?string $fromAddress = null,
        public ?string $fromName = null,
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
        $activationUrl = route('invitations.admin.show', ['token' => $this->plainToken]);

        $mail = (new MailMessage)
            ->subject("You're invited to join {$this->businessName} as an Administrator")
            ->greeting("Hello {$this->recipientName},")
            ->line("You have been invited as an Administrator on {$this->businessName}.")
            ->line("To complete your registration, please activate your account before {$this->expiresAtFormatted}. You will be asked to confirm your details, set your own password, configure mandatory two-factor authentication and save your recovery codes.")
            ->action('Activate Administrator Account', $activationUrl)
            ->line('This invitation link is unique, single-use, and valid for 24 hours.')
            ->line('If you were not expecting this invitation, no action is needed.');

        if ($this->fromAddress) {
            $mail->from($this->fromAddress, $this->fromName ?? $this->businessName);
        }

        return $mail;
    }
}
