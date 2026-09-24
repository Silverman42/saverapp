<?php

namespace App\Console\Commands;

use App\Services\LedgerTransactionProjectionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ledger:rebuild-transactions')]
#[Description('Verify ledger posting groups and atomically promote a rebuilt transaction projection')]
class RebuildLedgerTransactions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(LedgerTransactionProjectionService $projections): int
    {
        $result = $projections->rebuild();
        $this->info("Verified version {$result['version']}: {$result['transactions']} transactions from {$result['groups']} posting groups.");

        return self::SUCCESS;
    }
}
