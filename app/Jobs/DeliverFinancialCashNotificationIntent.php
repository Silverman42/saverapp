<?php

namespace App\Jobs;

use App\Enums\AccountState;
use App\Enums\UserType;
use App\Models\User;
use App\Notifications\FinancialCashMailNotification;
use App\Services\ManagementMailDelivery;
use App\Services\NotificationCatalogue;
use App\Services\PlatformGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use ValueError;

class DeliverFinancialCashNotificationIntent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public int $intentId) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('financial-cash-mail-'.$this->intentId))->expireAfter(300)];
    }

    public function handle(): void
    {
        app(PlatformGuard::class)->assertAllowed('external');
        $delivery = app(ManagementMailDelivery::class);
        $delivery->resolveInterrupted('financial_cash', $this->intentId);
        $delivery->deliver('financial_cash', $this->intentId,
            fn (): bool => $this->authorizedContent() !== null,
            function (): void {
                $content = $this->authorizedContent();
                if ($content === null) {
                    throw new ConflictHttpException('Financial cash mail source is unavailable.');
                }
                $notification = new FinancialCashMailNotification(
                    $content['notification_id'], $content['reference'], $content['title'], $content['summary'], $content['destination']);
                Notification::sendNow($content['recipient'], $notification, ['mail']);
                if (! $notification->deliveryEvidence->accepted) {
                    throw new ConflictHttpException('Fee application email acceptance is unverified.');
                }
            });
    }

    /** @return array{recipient: User, notification_id: string, reference: string, title: string, summary: string, destination: string}|null */
    private function authorizedContent(): ?array
    {
        $owner = DB::table('financial_cash_notification_intents')->where('id', $this->intentId)->first();
        if ($owner === null || $owner->channel !== 'mail' || $owner->audience_type !== 'subject_customer') {
            return null;
        }
        $recipient = User::query()->whereKey($owner->recipient_user_id)->first();
        if ($recipient === null || $recipient->account_state !== AccountState::Active || $recipient->user_type !== UserType::Customer
            || $recipient->getRoleNames()->all() !== [UserType::Customer->value] || filter_var($recipient->email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        try {
            $descriptor = app(NotificationCatalogue::class)->describe('financial_cash', $owner);
        } catch (InvalidArgumentException|ConflictHttpException|JsonException|ValueError) {
            return null;
        }

        return ['recipient' => $recipient, 'notification_id' => $owner->notification_id, 'reference' => $descriptor['reference'], 'title' => $descriptor['title'],
            'summary' => $descriptor['summary'], 'destination' => route($descriptor['destination']['route'], $descriptor['destination']['parameters'])];
    }
}
