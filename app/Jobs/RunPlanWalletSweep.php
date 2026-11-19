<?php

namespace App\Jobs;

use App\Services\PlanWalletSweepService;
use App\Support\PlatformBlocked;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs one automatic plan→wallet sweep after its completion commits. It never retries itself; wallets:sweep-completed owns retries.
 */
class RunPlanWalletSweep implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public int $sweepId) {}

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('plan-wallet-sweep-'.$this->sweepId))->expireAfter(300)];
    }

    public function handle(PlanWalletSweepService $sweeps): void
    {
        try {
            $sweeps->run($this->sweepId);
        } catch (PlatformBlocked) {
            // The platform is frozen; the scheduled retry picks the sweep up later.
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
