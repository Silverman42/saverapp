<?php

namespace App\Services;

use App\Enums\ThriftPlanStatus;
use App\Models\AuditEvent;
use App\Models\PlanLifecycleEvent;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\PlatformBlocked;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Closes a page (cycle) that a full withdrawal emptied before it was complete, so the Customer's next page starts fresh
 * with its own service charge. Partial withdrawals and Completed pages keep their normal lifecycle.
 *
 * Closure uses the same settlement gates as a staff closure. When a gate blocks (for example an unreconciled batch),
 * the page stays Paused with closure_pending_at set and closeDue() retries it later.
 */
class PlanPageClosureService
{
    /**
     * Run inside the payout posting transaction, after the withdrawal is Posted and its reservation consumed.
     */
    public function afterWithdrawalPosted(WithdrawalRequest $withdrawal, ?User $actor): void
    {
        if ($withdrawal->type !== 'full' || $withdrawal->state !== 'posted') {
            return;
        }
        $plan = ThriftPlan::query()->whereKey($withdrawal->thrift_plan_id)->lockForUpdate()->firstOrFail();
        if (! in_array($plan->status, [ThriftPlanStatus::Active, ThriftPlanStatus::Paused], true)) {
            return;
        }
        $this->markPrepared($plan, $withdrawal, $actor);
        $this->attemptClose($plan, $actor, 'full_withdrawal:'.$withdrawal->withdrawal_id);
    }

    /**
     * Retry pages whose closure was blocked by a settlement gate. Returns the number closed.
     */
    public function closeDue(int $limit = 100): int
    {
        try {
            app(PlatformGuard::class)->assertAllowed('financial');
        } catch (PlatformBlocked) {
            return 0;
        }
        $closed = 0;
        $ids = ThriftPlan::query()->whereNotNull('closure_pending_at')->where('status', ThriftPlanStatus::Paused->value)
            ->orderBy('closure_pending_at')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            try {
                $closed += app(PlatformGuard::class)->transaction('financial', function () use ($id): int {
                    $plan = ThriftPlan::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                    if ($plan->closure_pending_at === null || $plan->status !== ThriftPlanStatus::Paused) {
                        return 0;
                    }

                    return $this->attemptClose($plan, null, 'closure_retry') ? 1 : 0;
                }, attempts: 3);
            } catch (PlatformBlocked) {
                return $closed;
            } catch (HttpException|RuntimeException $exception) {
                report($exception);
            }
        }

        return $closed;
    }

    private function markPrepared(ThriftPlan $plan, WithdrawalRequest $withdrawal, ?User $actor): void
    {
        if ($plan->lifecycleEvents()->where('event_type', 'early_termination_prepared')->exists()) {
            return;
        }
        $prior = $plan->status;
        $plan->status = ThriftPlanStatus::Paused;
        $plan->version++;
        $plan->save();
        $event = PlanLifecycleEvent::create(['thrift_plan_id' => $plan->id, 'event_type' => 'early_termination_prepared',
            'from_status' => $prior, 'to_status' => $plan->status, 'actor_user_id' => $actor?->id,
            'assignment_version' => $plan->customerProfile->currentAssignment?->version, 'plan_version' => $plan->version,
            'reason' => 'Full withdrawal before the page was complete.',
            'customer_explanation' => 'Your full withdrawal ends this page early. Your next page starts fresh with its own service charge.',
            'payload' => ['source' => 'full_withdrawal', 'withdrawal_id' => $withdrawal->withdrawal_id], 'effective_at' => now()]);
        AuditEvent::record('thrift_plan.early_termination_prepared', ThriftPlan::class, $plan->id, $plan->plan_id,
            ['customer_profile_id' => $plan->customer_profile_id, 'lifecycle_event_id' => $event->id, 'from' => $prior->value,
                'to' => $plan->status->value, 'version' => $plan->version, 'withdrawal_id' => $withdrawal->withdrawal_id],
            $actor, context: ['executor' => self::class, 'correlation_reference' => $withdrawal->withdrawal_id]);
    }

    private function attemptClose(ThriftPlan $plan, ?User $actor, string $source): bool
    {
        try {
            $quote = app(PlanSettlementService::class)->evaluate($plan, 'close');
        } catch (HttpException|RuntimeException) {
            $quote = ['can_close' => false, 'blockers' => ['Settlement gates are unavailable.']];
        }
        if (! $quote['can_close']) {
            if ($plan->closure_pending_at === null) {
                $plan->closure_pending_at = now();
                $plan->save();
                AuditEvent::record('thrift_plan.closure_pending', ThriftPlan::class, $plan->id, $plan->plan_id,
                    ['customer_profile_id' => $plan->customer_profile_id, 'version' => $plan->version],
                    $actor, context: ['executor' => self::class, 'correlation_reference' => $source]);
            }

            return false;
        }
        $prior = $plan->status;
        $plan->update(['status' => ThriftPlanStatus::Closed, 'open_customer_profile_id' => null, 'closure_pending_at' => null, 'version' => $plan->version + 1]);
        $customer = $plan->customerProfile;
        $assignment = $customer->currentAssignment;
        $event = PlanLifecycleEvent::create(['thrift_plan_id' => $plan->id, 'event_type' => 'closed', 'from_status' => $prior,
            'to_status' => $plan->status, 'actor_user_id' => $actor?->id, 'assignment_version' => $assignment?->version,
            'plan_version' => $plan->version, 'reason' => 'Page closed after a full withdrawal.',
            'customer_explanation' => 'This page is closed. Your next page starts fresh with its own service charge.',
            'payload' => [...$quote, 'source' => $source], 'effective_at' => now()]);
        app(ThriftPlanService::class)->createNotificationIntents($customer, $plan, $event, $assignment?->agentProfile?->user_id);
        AuditEvent::record('thrift_plan.closed', ThriftPlan::class, $plan->id, $plan->plan_id,
            ['customer_profile_id' => $plan->customer_profile_id, 'lifecycle_event_id' => $event->id, 'from' => $prior->value,
                'to' => $plan->status->value, 'version' => $plan->version, 'gate_fingerprint' => $quote['preview_fingerprint']],
            $actor, context: ['executor' => self::class, 'correlation_reference' => $source]);

        return true;
    }
}
