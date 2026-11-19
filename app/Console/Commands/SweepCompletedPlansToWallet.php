<?php

namespace App\Console\Commands;

use App\Services\PlanWalletSweepService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('wallets:sweep-completed {--limit=100}')]
#[Description('Retry pending automatic transfers of Completed plan savings into Customer wallets')]
class SweepCompletedPlansToWallet extends Command
{
    /**
     * Each sweep commits on its own, so one blocked plan cannot hold back the others.
     */
    public function handle(PlanWalletSweepService $sweeps): int
    {
        $count = $sweeps->runDue(max(1, (int) $this->option('limit')));
        $this->info("Moved {$count} completed plans to wallets.");

        return self::SUCCESS;
    }
}
