<?php

namespace App\Console\Commands;

use App\Services\PlanPageClosureService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('plans:close-pending {--limit=100}')]
#[Description('Retry closing pages emptied by a full withdrawal whose settlement gates were blocked')]
class ClosePendingPlanPages extends Command
{
    /**
     * Each page closes in its own transaction, so one blocked page cannot hold back the others.
     */
    public function handle(PlanPageClosureService $closures): int
    {
        $count = $closures->closeDue(max(1, (int) $this->option('limit')));
        $this->info("Closed {$count} pending pages.");

        return self::SUCCESS;
    }
}
