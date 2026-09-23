<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerInvitationNotification extends Notification implements ShouldQueue
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
        public string $feeFormatted,
        public bool $isZeroFee,
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
        $activationUrl = route('invitations.customer.show', ['token' => $this->plainToken]);

        $mail = (new MailMessage)
            ->subject("You're invited to join {$this->businessName} as a Customer")
            ->greeting("Hello {$this->recipientName},")
            ->line("You have been registered as a Customer on {$this->businessName}.")
            ->line($this->isZeroFee
                ? 'There is no registration fee required for your account.'
                : "Your applicable registration fee is {$this->feeFormatted}. Payment is not required for activation, but you will be asked to review and acknowledge these terms."
            )
            ->line("To complete your registration, please activate your account before {$this->expiresAtFormatted}. You will be asked to acknowledge your fee terms and set your secure password.")
            ->action('Activate Customer Account', $activationUrl)
            ->line('This invitation link is unique, single-use, and valid for 7 days.')
            ->line('If you were not expecting this invitation, no action is needed.');

        if ($this->fromAddress) {
            $mail->from($this->fromAddress, $this->fromName ?? $this->businessName);
        }

        return $mail;
    }
}
