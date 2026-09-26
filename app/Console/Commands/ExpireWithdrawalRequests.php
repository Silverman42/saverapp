<?php

namespace App\Console\Commands;

use App\Services\PlatformGuard;
use App\Services\WithdrawalService;
use App\Support\PlatformBlocked;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('withdrawals:expire')]
#[Description('Safely expire unexecuted withdrawal requests whose review window has elapsed')]
class ExpireWithdrawalRequests extends Command
{
    public function handle(WithdrawalService $withdrawals): int
    {
        try {
            return app(PlatformGuard::class)->transaction('financial', function () use ($withdrawals) {
                return $this->handleAllowed($withdrawals);
            });
        } catch (PlatformBlocked) {
            return 0;
        }
    }

    private function handleAllowed(WithdrawalService $withdrawals): int
    {
        $count = $withdrawals->expireDue();
        $this->info("Expired {$count} withdrawal requests.");

        return self::SUCCESS;
    }
}
