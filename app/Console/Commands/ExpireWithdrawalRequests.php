<?php

namespace App\Console\Commands;

use App\Services\WithdrawalService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('withdrawals:expire')]
#[Description('Safely expire unexecuted withdrawal requests whose review window has elapsed')]
class ExpireWithdrawalRequests extends Command
{
    /**
     * Each request expires in its own transaction, so one conflict cannot roll back or block the others.
     * The service returns zero when the platform is paused.
     */
    public function handle(WithdrawalService $withdrawals): int
    {
        $count = $withdrawals->expireDue();
        $this->info("Expired {$count} withdrawal requests.");

        return self::SUCCESS;
    }
}
