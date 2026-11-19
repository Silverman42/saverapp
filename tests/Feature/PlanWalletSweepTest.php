<?php

use App\Enums\CustomerStatus;
use App\Enums\ThriftPlanStatus;
use App\Jobs\RunPlanWalletSweep;
use App\Models\CustomerProfile;
use App\Models\LedgerPostingGroup;
use App\Models\PlanNotificationIntent;
use App\Models\PlanWalletSweep;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\CollectionReadService;
use App\Services\CollectionService;
use App\Services\PlanWalletSweepService;
use App\Services\WalletBalanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../WalletFixtures.php';

beforeEach(function (): void {
    config()->set('collections.enabled', true);
    Queue::fake();
});

/** @return array{0: User, 1: CustomerProfile, 2: ThriftPlan} */
function completeWalletPlan(bool $walletActive = true): array
{
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(1);
    if ($walletActive) {
        enableWallet();
    }
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $payload);

    return [$agent, $customer->fresh(), $plan->fresh()];
}

test('completing a plan records one sweep that moves all its savings into the wallet', function (): void {
    [, $customer, $plan] = completeWalletPlan();
    $sweep = PlanWalletSweep::query()->sole();
    Queue::assertPushed(RunPlanWalletSweep::class, fn (RunPlanWalletSweep $job): bool => $job->sweepId === $sweep->id);
    expect($sweep->status)->toBe('pending');

    $sweep = app(PlanWalletSweepService::class)->run($sweep->id);

    expect($sweep->status)->toBe('posted')
        ->and($sweep->withdrawal->initiated_by)->toBe('system')
        ->and($sweep->withdrawal->type)->toBe('end_of_cycle')
        ->and($sweep->withdrawal->submitted_by_user_id)->toBeNull()
        ->and(app(WalletBalanceService::class)->position($customer)['liability_kobo'])->toBe(200000)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0)
        ->and($plan->fresh()->status)->toBe(ThriftPlanStatus::Completed);
});

test('running a posted sweep again posts nothing more', function (): void {
    completeWalletPlan();
    $sweep = PlanWalletSweep::query()->sole();
    app(PlanWalletSweepService::class)->run($sweep->id);

    app(PlanWalletSweepService::class)->run($sweep->id);

    expect(LedgerPostingGroup::query()->where('event_type', 'plan_to_wallet_transfer')->count())->toBe(1);
});

test('a sweep after a manual end-of-cycle transfer finishes without posting', function (): void {
    [$agent, $customer, $plan] = completeWalletPlan();
    transferToWallet($agent, $customer, $plan, 'end_of_cycle', '2000.00');

    $sweep = app(PlanWalletSweepService::class)->run(PlanWalletSweep::query()->sole()->id);

    expect($sweep->status)->toBe('posted')
        ->and($sweep->withdrawal_request_id)->toBeNull()
        ->and(LedgerPostingGroup::query()->where('event_type', 'plan_to_wallet_transfer')->count())->toBe(1);
});

test('a restricted Customer keeps the sweep pending, alerts the Agent after a day, and posts once the restriction lifts', function (): void {
    [$agent, $customer] = completeWalletPlan();
    $customer->update(['operational_status' => CustomerStatus::Restricted]);
    $sweep = app(PlanWalletSweepService::class)->run(PlanWalletSweep::query()->sole()->id);
    expect($sweep->status)->toBe('pending')->and($sweep->blocker)->toBe('customer_restricted');

    $this->travel(25)->hours();
    app(PlanWalletSweepService::class)->runDue();

    expect($sweep->fresh()->alerted_at)->not->toBeNull()
        ->and(PlanNotificationIntent::query()->where('recipient_user_id', $agent->id)->where('audience_type', 'current_agent')->count())->toBe(1);

    $customer->update(['operational_status' => CustomerStatus::Active]);
    $this->travel(6)->minutes();
    app(PlanWalletSweepService::class)->runDue();

    expect($sweep->fresh()->status)->toBe('posted')
        ->and(app(WalletBalanceService::class)->position($customer)['liability_kobo'])->toBe(200000);
});

test('a sweep is superseded when the plan is no longer the completion it was recorded for', function (): void {
    [, , $plan] = completeWalletPlan();
    DB::table('thrift_plans')->where('id', $plan->id)->update(['status' => ThriftPlanStatus::Paused->value]);

    $sweep = app(PlanWalletSweepService::class)->run(PlanWalletSweep::query()->sole()->id);

    expect($sweep->status)->toBe('superseded')
        ->and(LedgerPostingGroup::query()->where('event_type', 'plan_to_wallet_transfer')->count())->toBe(0);
});

test('plans completed while the wallet is inactive get no sweep', function (): void {
    completeWalletPlan(walletActive: false);

    expect(PlanWalletSweep::query()->count())->toBe(0);
    Queue::assertNotPushed(RunPlanWalletSweep::class);
});
