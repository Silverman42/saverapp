<?php

namespace App\Jobs;

use App\Enums\AccountState;
use App\Enums\ThriftPlanStatus;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\PlanNotificationIntent;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Notifications\ThriftPlanNotification;
use App\Services\AgentEligibilityService;
use App\Services\NotificationCatalogue;
use App\Services\NotificationPipeline;
use App\Services\PlatformCatalogue;
use App\Services\PlatformGuard;
use Illuminate\Bus\Queueable;
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

class DeliverPlanNotificationIntent implements ShouldQueue
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
        return [(new WithoutOverlapping('plan-notification-'.$this->intentId))->expireAfter(300)];
    }

    public function handle(AgentEligibilityService $eligibilityService): void
    {
        if (app(NotificationPipeline::class)->recoverLocalOwner('plan', $this->intentId)) {
            return;
        }

        $intent = PlanNotificationIntent::query()->find($this->intentId);
        if ($intent !== null && $intent->channel === 'mail') {
            app(PlatformGuard::class)->assertAllowed('external');
            if ($intent->status === 'sending') {
                if (! PlanNotificationIntent::query()->whereKey($this->intentId)->where('status', 'sending')
                    ->where('attempted_at', '<=', now()->subMinutes(5))->exists()) {
                    return;
                }
                $this->mailOutcome('uncertain', 'delivery_uncertain');

                return;
            }
            $this->deliverMail($eligibilityService);

            return;
        }

        app(PlatformGuard::class)->work('external', function () use ($eligibilityService): void {
            $this->handleAllowed($eligibilityService);
        });
    }

    private function handleAllowed(AgentEligibilityService $eligibilityService): void
    {
        $intent = PlanNotificationIntent::query()->find($this->intentId);
        if ($intent === null || $intent->status !== 'pending') {
            return;
        }

        if ($intent->channel === 'database') {
            app(NotificationPipeline::class)->deliverOwner('plan', $this->intentId);

            return;
        }

        $recipient = User::query()->find($intent->recipient_user_id);
        if ($recipient === null || ! $this->recipientIsStillAuthorized($intent, $recipient, $eligibilityService)) {
            $intent->forceFill(['status' => 'suppressed', 'suppressed_at' => now(), 'failure_reason' => null])->save();

            return;
        }

        if ($intent->channel === 'database' && $recipient->notifications()->whereKey($intent->notification_id)->exists()) {
            $intent->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();

            return;
        }

        Notification::sendNow(
            $recipient,
            new ThriftPlanNotification($intent->notification_id, $intent->payload, $intent->channel),
            [$intent->channel],
        );
        $intent->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'failure_reason' => null])->save();
    }

    private function deliverMail(AgentEligibilityService $eligibilityService): void
    {
        $delivery = app(PlatformGuard::class)->transaction('external', function () use ($eligibilityService): ?array {
            $intent = PlanNotificationIntent::query()->whereKey($this->intentId)->lockForUpdate()->first();
            if ($intent === null || $intent->channel !== 'mail' || $intent->status !== 'pending') {
                return null;
            }
            $recipient = User::query()->whereKey($intent->recipient_user_id)->first();
            if ($recipient === null || $recipient->user_type !== UserType::Customer
                || $recipient->getRoleNames()->all() !== [UserType::Customer->value]
                || filter_var($recipient->email, FILTER_VALIDATE_EMAIL) === false
                || ! $this->recipientIsStillAuthorized($intent, $recipient, $eligibilityService)) {
                $this->mailOutcome('suppressed', 'recipient_source_unavailable');

                return null;
            }
            try {
                $descriptor = app(NotificationCatalogue::class)->describe('plan', (object) $intent->getAttributes());
            } catch (InvalidArgumentException|JsonException|ValueError|ConflictHttpException) {
                $this->mailOutcome('blocked', 'plan_source_unavailable');

                return null;
            }
            $event = $intent->lifecycleEvent;
            $status = $event === null ? null : ThriftPlanStatus::tryFrom($event->getRawOriginal('to_status') ?? '');
            if ($status === null) {
                $this->mailOutcome('blocked', 'plan_source_unavailable');

                return null;
            }
            $notification = new ThriftPlanNotification($intent->notification_id, [
                'title' => $descriptor['title'], 'message' => $descriptor['summary'], 'plan_id' => $descriptor['reference'],
                'status' => $status->displayName(),
                'url' => route($descriptor['destination']['route'], $descriptor['destination']['parameters']),
            ], 'mail');
            $rendered = $notification->toMail($recipient)->render()->toHtml();
            $intent->forceFill(['status' => 'sending', 'attempt_count' => $intent->attempt_count + 1, 'attempted_at' => now(),
                'template_version' => ThriftPlanNotification::TEMPLATE_VERSION,
                'rendered_snapshot' => Crypt::encryptString($rendered), 'rendered_hash' => hash('sha256', $rendered),
                'destination_hash' => hash_hmac('sha256', strtolower($recipient->email), Crypt::getKey())])->save();

            return [$recipient, $notification];
        }, attempts: 3);
        if ($delivery === null) {
            return;
        }
        try {
            Notification::sendNow($delivery[0], $delivery[1], ['mail']);
            $this->mailOutcome($delivery[1]->deliveryEvidence->accepted ? 'delivered' : 'uncertain',
                $delivery[1]->deliveryEvidence->accepted ? 'sent' : 'acceptance_unconfirmed');
        } catch (Throwable $exception) {
            $this->mailOutcome('uncertain', 'delivery_uncertain');
            throw $exception;
        }
    }

    private function mailOutcome(string $status, string $category): void
    {
        DB::transaction(function () use ($status, $category): void {
            $intent = PlanNotificationIntent::query()->whereKey($this->intentId)->lockForUpdate()->first();
            if ($intent === null || $intent->channel !== 'mail' || ! in_array($intent->status, ['pending', 'sending'], true)) {
                return;
            }
            $plan = ThriftPlan::query()->whereKey($intent->thrift_plan_id)
                ->where('customer_profile_id', $intent->customer_profile_id)
                ->whereHas('lifecycleEvents', fn ($events) => $events->whereKey($intent->plan_lifecycle_event_id))->first();
            AuditEvent::record('thrift_plan.delivery_attempt', ThriftPlan::class, $plan?->id, $plan?->plan_id,
                ['notification_reference' => $intent->notification_id, 'channel' => 'mail',
                    'category' => $category, 'customer_profile_id' => $plan?->customer_profile_id,
                    'lifecycle_event_id' => $plan === null ? null : $intent->plan_lifecycle_event_id,
                    'attempt' => $intent->attempt_count, 'template_version' => $intent->template_version,
                    'rendered_hash' => $intent->rendered_hash], null,
                ['executor' => self::class, 'outcome' => match ($status) {
                    'delivered' => 'Succeeded', 'suppressed' => 'Denied', default => 'Failed',
                }, 'operation_id' => 'plan-mail:'.$intent->id.':'.$category]);
            $intent->forceFill(['status' => $status, 'delivered_at' => $status === 'delivered' ? now() : null,
                'suppressed_at' => $status === 'suppressed' ? now() : null,
                'failure_reason' => match ($status) {
                    'delivered', 'suppressed' => null,
                    'failed' => 'Delivery failed after retrying.',
                    default => 'Plan email requires delivery review.',
                }])->save();
        }, attempts: 3);
    }

    public function failed(?Throwable $exception): void
    {
        if (app(PlatformCatalogue::class)->isLocalRecoveryJob($this)) {
            return;
        }
        $intent = PlanNotificationIntent::query()->find($this->intentId);
        $this->mailOutcome($intent?->status === 'sending' ? 'uncertain' : 'failed', 'retry_budget_exhausted');
    }

    private function recipientIsStillAuthorized(
        PlanNotificationIntent $intent,
        User $recipient,
        AgentEligibilityService $eligibilityService,
    ): bool {
        $customer = CustomerProfile::query()->find($intent->customer_profile_id);
        if ($customer === null) {
            return false;
        }

        if ($intent->audience_type === 'subject_customer') {
            if ($customer->user_id !== $recipient->id) {
                return false;
            }

            return match ($intent->channel) {
                'database' => $recipient->account_state === AccountState::Active,
                'mail' => $recipient->account_state === AccountState::Active && $recipient->email_verified_at !== null,
                default => false,
            };
        }

        if ($intent->audience_type !== 'current_agent'
            || $intent->channel !== 'database'
            || $recipient->account_state !== AccountState::Active
            || $recipient->user_type !== UserType::Agent) {
            return false;
        }

        $assignment = CustomerAssignment::query()
            ->where('customer_profile_id', $customer->id)
            ->where('is_current', 1)
            ->first();
        $agent = $recipient->agentProfile;

        return $assignment !== null
            && $agent !== null
            && $assignment->agent_profile_id === $agent->id
            && $eligibilityService->canReadAssignedCustomers($recipient);
    }
}
