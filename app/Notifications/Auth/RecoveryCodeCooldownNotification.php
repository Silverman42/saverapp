<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RecoveryCodeCooldownNotification extends Notification implements ReportsLockDelivery, ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public int $durationMinutes = 60,
        public string $reason = 'Excessive invalid recovery codes',
        public ?int $authenticationLockId = null,
    ) {}

    /**
     * The authentication lock whose notification status this delivery updates.
     */
    public function lockId(): ?int
    {
        return $this->authenticationLockId;
    }

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
        $durationText = $this->durationMinutes >= 60
            ? ($this->durationMinutes / 60).' hour'
            : "{$this->durationMinutes} minutes";

        return (new MailMessage)
            ->subject('Security Notice: Recovery Code Cooldown')
            ->line("A {$durationText} cooldown has been applied to recovery code usage on your account due to {$this->reason}.")
            ->line('Your legitimate unused recovery codes remain safe and have not been consumed or invalidated.')
            ->line('Please wait until the cooldown window expires before attempting recovery codes again, or contact an administrator for assisted recovery.')
            ->line('If you did not make these attempts, please report this incident immediately.');
    }
}
