<?php

namespace App\Console\Commands;

use App\Services\BankPayoutService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('withdrawals:reconcile-bank-payouts {--limit=100 : Maximum attempts to reconcile in one run}')]
#[Description('Query the provider for unresolved bank payouts, finish recorded successes and apply provider settlements')]
class ReconcileBankPayouts extends Command
{
    /** Each attempt is reconciled in its own transactions; the provider is never called inside one. */
    public function handle(BankPayoutService $payouts): int
    {
        $count = $payouts->reconcileDue(max(1, (int) $this->option('limit')));
        $this->info("Reconciled {$count} bank payout items.");

        return self::SUCCESS;
    }
}
