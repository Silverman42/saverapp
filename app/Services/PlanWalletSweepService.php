<?php

namespace App\Services;

use App\Enums\ThriftPlanStatus;
use App\Jobs\RunPlanWalletSweep;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\PlanLifecycleEvent;
use App\Models\PlanWalletSweep;
use App\Models\ThriftPlan;
use App\Models\WithdrawalRequest;
use App\Support\PlatformBlocked;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Automatic transfer of a Completed plan's savings into the Customer wallet (Module 18 §6.4).
 * Completion only records a pending sweep; the sweep posts in its own transaction so it can never fail the completing receipt.
 */
class PlanWalletSweepService
{
    public function __construct(
        private WithdrawalService $withdrawals,
        private WithdrawalBalanceService $balances,
        private WalletTransferService $transfers,
        private WalletFeature $wallet,
    ) {}

    /** Called inside the completing transaction. Plans completed while the wallet is inactive keep the manual End-of-cycle path. */
    public function recordForCompletion(ThriftPlan $plan, PlanLifecycleEvent $completion): ?PlanWalletSweep
    {
        if ($completion->event_type !== 'completed' || ! $this->wallet->active()) {
            return null;
        }
        $sweep = PlanWalletSweep::create(['thrift_plan_id' => $plan->id, 'customer_profile_id' => $plan->customer_profile_id,
            'completion_event_id' => $completion->id, 'status' => PlanWalletSweep::STATUS_PENDING, 'attempts' => 0,
            'next_attempt_at' => now(), 'version' => 1]);
        RunPlanWalletSweep::dispatch($sweep->id)->afterCommit();

        return $sweep;
    }

    public function run(int $sweepId): PlanWalletSweep
    {
        try {
            return app(PlatformGuard::class)->transaction('financial', fn (): PlanWalletSweep => $this->attempt($sweepId), attempts: 3);
        } catch (SweepBlocked $blocked) {
            return app(PlatformGuard::class)->transaction('financial', fn (): PlanWalletSweep => $this->block($sweepId, $blocked->getMessage()), attempts: 3);
        }
    }

    /** Retry due pending sweeps and raise the one-time pending alert. Each sweep commits on its own. Returns the number posted. */
    public function runDue(int $limit = 100): int
    {
        try {
            app(PlatformGuard::class)->assertAllowed('financial');
        } catch (PlatformBlocked) {
            return 0;
        }
        $posted = 0;
        foreach (PlanWalletSweep::query()->where('status', PlanWalletSweep::STATUS_PENDING)->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')->limit($limit)->pluck('id') as $id) {
            try {
                $posted += $this->run($id)->status === PlanWalletSweep::STATUS_POSTED ? 1 : 0;
            } catch (PlatformBlocked) {
                return $posted;
            } catch (HttpException|RuntimeException $exception) {
                report($exception);
            }
        }
        foreach (PlanWalletSweep::query()->where('status', PlanWalletSweep::STATUS_PENDING)->whereNull('alerted_at')
            ->where('created_at', '<=', now()->subHours((int) config('wallet.sweep_alert_hours', 24)))->limit($limit)->pluck('id') as $id) {
            app(PlatformGuard::class)->transaction('financial', fn () => $this->alert($id), attempts: 3);
        }

        return $posted;
    }

    private function attempt(int $sweepId): PlanWalletSweep
    {
        $sweep = PlanWalletSweep::query()->whereKey($sweepId)->lockForUpdate()->firstOrFail();
        if ($sweep->status !== PlanWalletSweep::STATUS_PENDING) {
            return $sweep;
        }
        $customer = CustomerProfile::query()->whereKey($sweep->customer_profile_id)->lockForUpdate()->firstOrFail();
        $plan = ThriftPlan::query()->whereKey($sweep->thrift_plan_id)->lockForUpdate()->firstOrFail();
        $latestCompletion = $plan->lifecycleEvents()->where('event_type', 'completed')->max('id');
        if ($plan->status !== ThriftPlanStatus::Completed || (int) $latestCompletion !== $sweep->completion_event_id) {
            return $this->settle($sweep, PlanWalletSweep::STATUS_SUPERSEDED, null);
        }
        if (! $this->wallet->active()) {
            throw new SweepBlocked('wallet_unavailable');
        }
        if (! $customer->operational_status->allowsWithdrawalOfExistingFunds()) {
            throw new SweepBlocked('customer_restricted');
        }
        if (WithdrawalRequest::query()->where('live_thrift_plan_id', $plan->id)->exists()) {
            throw new SweepBlocked('live_request');
        }
        $assignment = $customer->currentAssignment()->with('agentProfile.user')->first();
        if ($assignment === null || $assignment->agentProfile?->user === null) {
            throw new SweepBlocked('no_assignment');
        }
        try {
            $available = $this->balances->position($customer, $plan, true)['cycle_available_kobo'];
        } catch (RuntimeException) {
            throw new SweepBlocked('savings_unavailable');
        }
        if ($available === 0) {
            return $this->settle($sweep, PlanWalletSweep::STATUS_POSTED, null);
        }
        try {
            $quote = $this->withdrawals->quote($customer->load('currentAssignment'), ['plan_id' => $plan->plan_id, 'type' => 'end_of_cycle',
                'gross_ngn' => number_format($available / 100, 2, '.', ''), 'method' => 'wallet',
                'destination_reference' => 'wallet:'.$customer->id, 'reason' => 'Automatic transfer of completed plan savings'], true);
        } catch (ValidationException|HttpException $exception) {
            throw new SweepBlocked($exception instanceof HttpException && $exception->getStatusCode() === 409 ? 'fee_unsettled' : 'quote_unavailable');
        }
        $withdrawal = $this->withdrawals->createRequest($customer, $quote, $assignment->agent_profile_id, $assignment->id, null, null, 'system');
        $this->transfers->post($withdrawal, $assignment->agentProfile->user, 'plan-wallet-sweep-'.$sweep->id);

        return $this->settle($sweep, PlanWalletSweep::STATUS_POSTED, $withdrawal->id);
    }

    private function settle(PlanWalletSweep $sweep, string $status, ?int $withdrawalId): PlanWalletSweep
    {
        $sweep->forceFill(['status' => $status, 'blocker' => null, 'withdrawal_request_id' => $withdrawalId,
            'attempts' => $sweep->attempts + 1, 'next_attempt_at' => null, 'version' => $sweep->version + 1])->save();

        return $sweep;
    }

    private function block(int $sweepId, string $blocker): PlanWalletSweep
    {
        $sweep = PlanWalletSweep::query()->whereKey($sweepId)->lockForUpdate()->firstOrFail();
        if ($sweep->status !== PlanWalletSweep::STATUS_PENDING) {
            return $sweep;
        }
        $sweep->forceFill(['blocker' => $blocker, 'attempts' => $sweep->attempts + 1,
            'next_attempt_at' => now()->addMinutes((int) config('wallet.sweep_retry_minutes', 5)), 'version' => $sweep->version + 1])->save();

        return $sweep;
    }

    private function alert(int $sweepId): void
    {
        $sweep = PlanWalletSweep::query()->whereKey($sweepId)->lockForUpdate()->firstOrFail();
        if ($sweep->status !== PlanWalletSweep::STATUS_PENDING || $sweep->alerted_at !== null) {
            return;
        }
        $plan = ThriftPlan::query()->whereKey($sweep->thrift_plan_id)->firstOrFail();
        $customer = $plan->customerProfile;
        $assignment = $customer->currentAssignment;
        $event = PlanLifecycleEvent::create(['thrift_plan_id' => $plan->id, 'event_type' => 'wallet_sweep_pending',
            'from_status' => $plan->status, 'to_status' => $plan->status, 'actor_user_id' => null,
            'assignment_version' => $assignment?->version, 'plan_version' => $plan->version,
            'reason' => 'Automatic wallet transfer blocked: '.$sweep->blocker,
            'customer_explanation' => null, 'payload' => ['sweep_id' => $sweep->id, 'blocker' => $sweep->blocker], 'effective_at' => now()]);
        if ($assignment?->agentProfile?->user_id !== null) {
            app(ThriftPlanService::class)->createAgentNotificationIntent($customer, $plan, $event, $assignment->agentProfile->user_id);
        }
        AuditEvent::record('thrift_plan.wallet_sweep_pending', ThriftPlan::class, $plan->id, $plan->plan_id,
            ['customer_profile_id' => $plan->customer_profile_id, 'lifecycle_event_id' => $event->id, 'version' => $plan->version],
            null, context: ['executor' => self::class]);
        $sweep->forceFill(['alerted_at' => now(), 'version' => $sweep->version + 1])->save();
    }
}
