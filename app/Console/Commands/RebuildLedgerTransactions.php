<?php

namespace App\Console\Commands;

use App\Services\LedgerTransactionProjectionService;
use App\Support\PlatformBlocked;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('ledger:rebuild-transactions {--if-stale : Rebuild only when the projection is unavailable or behind the ledger}')]
#[Description('Verify ledger posting groups and atomically promote a rebuilt transaction projection')]
class RebuildLedgerTransactions extends Command
{
    /**
     * The rebuild owns its transactions: a failure must keep its integrity incident, so it never runs inside an outer one.
     */
    public function handle(LedgerTransactionProjectionService $projections): int
    {
        if ($this->option('if-stale') && ! $projections->isBehind()) {
            $this->info('The transaction projection is current.');

            return self::SUCCESS;
        }
        try {
            $result = $projections->rebuild();
        } catch (PlatformBlocked) {
            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error('The ledger did not verify: '.$exception->getMessage());

            return self::FAILURE;
        }
        $this->info("Verified version {$result['version']}: {$result['transactions']} transactions from {$result['groups']} posting groups.");
        if ($result['frozen_customers'] > 0) {
            $this->warn("{$result['frozen_customers']} Customer scope(s) remain frozen under open integrity incidents.");
        }

        return self::SUCCESS;
    }
}
