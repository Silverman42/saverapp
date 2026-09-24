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
    public function handle(WithdrawalService $withdrawals): int
    {
        $count = $withdrawals->expireDue();
        $this->info("Expired {$count} withdrawal requests.");

        return self::SUCCESS;
    }
}
