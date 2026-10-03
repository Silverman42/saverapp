<?php

namespace App\Jobs;

use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\CollectionReceipt;
use App\Models\User;
use App\Notifications\CollectionReceiptMailNotification;
use App\Services\CollectionFeeReceiptNotificationSource;
use App\Services\FeeOperationalIssues;
use App\Services\NotificationPipeline;
use App\Services\PlatformCatalogue;
use App\Services\PlatformGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;
use ValueError;

class DeliverCollectionNotificationIntent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(public int $intentId) {}

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('collection-notification-'.$this->intentId))->expireAfter(300)];
    }

    public function handle(): void
    {
        $intent = DB::table('collection_notification_intents')->where('id', $this->intentId)->first();
        if ($intent !== null && ($intent->channel ?? 'database') === 'mail') {
            app(PlatformGuard::class)->assertAllowed('external');
            $this->handleAllowed();

            return;
        }
        if (app(NotificationPipeline::class)->recoverLocalOwner('collection', $this->intentId)) {
            return;
        }

        app(PlatformGuard::class)->work('external', function (): void {
            $this->handleAllowed();
        });
    }

    private function handleAllowed(): void
    {
        $intent = DB::table('collection_notification_intents')->where('id', $this->intentId)->first();
        if ($intent !== null && $intent->channel === 'mail' && $intent->status === 'sending') {
            $this->mailOutcome('uncertain', 'delivery_uncertain');

            return;
        }
        if ($intent === null || $intent->status !== 'pending') {
            return;
        }
        if ($intent->channel === 'mail') {
            $this->deliverMail();

            return;
        }
        app(NotificationPipeline::class)->deliverOwner('collection', $this->intentId);

    }

    private function deliverMail(): void
    {
        $delivery = app(PlatformGuard::class)->transaction('external', function (): ?array {
            $intent = DB::table('collection_notification_intents')->where('id', $this->intentId)->lockForUpdate()->first();
            if ($intent === null || $intent->channel !== 'mail' || $intent->status !== 'pending') {
                return null;
            }
            $receipt = CollectionReceipt::query()->with('customerProfile')->whereKey($intent->collection_receipt_id)->first();
            $recipient = User::query()->whereKey($intent->recipient_user_id)->first();
            if ($receipt === null || $recipient === null || $recipient->user_type !== UserType::Customer
                || $receipt->customerProfile->user_id !== $recipient->id
                || filter_var($recipient->email, FILTER_VALIDATE_EMAIL) === false) {
                $this->mailOutcome('blocked', 'recipient_source_unavailable');

                return null;
            }
            if ($receipt->savings_amount_kobo < 0 || $receipt->fee_amount_kobo < 0
                || $receipt->tender_amount_kobo < 1 || $receipt->tender_amount_kobo > 999_999_999_999
                || $receipt->savings_amount_kobo + $receipt->fee_amount_kobo !== $receipt->tender_amount_kobo) {
                $this->mailOutcome('blocked', 'receipt_source_unavailable');

                return null;
            }
            $method = match ($receipt->method) {
                'cash' => 'Cash', 'transfer' => 'Bank transfer', 'pos' => 'POS', 'other' => 'Other', default => null,
            };
            if ($method === null) {
                $this->mailOutcome('blocked', 'receipt_source_unavailable');

                return null;
            }
            try {
                $feeContext = app(CollectionFeeReceiptNotificationSource::class)->mailContext($intent, $receipt);
            } catch (DecryptException|InvalidArgumentException|JsonException|ConflictHttpException|ValueError) {
                $this->mailOutcome('blocked', 'receipt_source_unavailable');

                return null;
            }
            $notification = new CollectionReceiptMailNotification($intent->notification_id, $receipt->receipt_reference,
                $receipt->savings_amount_kobo, $receipt->fee_amount_kobo, $receipt->received_date, $receipt->timezone, $method,
                $receipt->replacement_reversal_id !== null, $feeContext['fees'] ?? []);
            $rendered = $notification->toMail($recipient)->render()->toHtml();
            DB::table('collection_notification_intents')->where('id', $intent->id)->update([
                'status' => 'sending', 'attempt_count' => $intent->attempt_count + 1, 'attempted_at' => now(),
                'template_version' => CollectionReceiptMailNotification::TEMPLATE_VERSION,
                'rendered_snapshot' => Crypt::encryptString($rendered), 'rendered_hash' => hash('sha256', $rendered),
                'destination_hash' => hash_hmac('sha256', strtolower($recipient->email), Crypt::getKey()),
                'updated_at' => now(),
            ]);

            return [$recipient, $notification];
        }, attempts: 3);
        if ($delivery === null) {
            return;
        }
        try {
            Notification::sendNow($delivery[0], $delivery[1], ['mail']);
            if (! $delivery[1]->deliveryEvidence->accepted) {
                $this->mailOutcome('uncertain', 'acceptance_unconfirmed');

                return;
            }
            $this->mailOutcome('delivered', 'sent');
        } catch (Throwable $exception) {
            $this->mailOutcome('uncertain', 'delivery_uncertain');
            throw $exception;
        }
    }

    private function mailOutcome(string $status, string $category): void
    {
        DB::transaction(function () use ($status, $category): void {
            $intent = DB::table('collection_notification_intents')->where('id', $this->intentId)->lockForUpdate()->first();
            if ($intent === null || ! in_array($intent->status, ['pending', 'sending'], true)) {
                return;
            }
            $receipt = CollectionReceipt::query()->whereKey($intent->collection_receipt_id)->first();
            $deliveryAudit = AuditEvent::record('collection.delivery_attempt', CollectionReceipt::class, $receipt?->id, $receipt?->receipt_reference,
                ['notification_reference' => $intent->notification_id, 'channel' => 'mail', 'attempt' => $intent->attempt_count,
                    'category' => $category, 'template_version' => $intent->template_version, 'rendered_hash' => $intent->rendered_hash],
                null, ['executor' => self::class, 'outcome' => $status === 'delivered' ? 'Succeeded' : 'Failed',
                    'operation_id' => 'collection-mail:'.$intent->id.':'.$category]);
            DB::table('collection_notification_intents')->where('id', $intent->id)->update([
                'status' => $status, 'delivered_at' => $status === 'delivered' ? now() : null,
                'failure_reason' => $status === 'delivered' ? null : 'Receipt email requires delivery review.', 'updated_at' => now(),
            ]);
            if (in_array($status, ['uncertain', 'failed'], true)) {
                app(FeeOperationalIssues::class)->delivery('collection', (int) $intent->id, 'mail',
                    $status === 'uncertain' ? 'acceptance_unknown' : 'dead_letter', $category, $deliveryAudit->id);
            }
        }, attempts: 3);
    }

    public function failed(?Throwable $exception): void
    {
        if (app(PlatformCatalogue::class)->isLocalRecoveryJob($this)) {
            return;
        }

        $intent = DB::table('collection_notification_intents')->where('id', $this->intentId)->first();
        if ($intent !== null && $intent->channel === 'mail') {
            $this->mailOutcome($intent->status === 'sending' ? 'uncertain' : 'failed', 'retry_budget_exhausted');

            return;
        }
        DB::table('collection_notification_intents')->where('id', $this->intentId)
            ->where('status', 'pending')->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
