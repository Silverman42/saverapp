<?php

namespace App\Console\Commands;

use App\Services\AuthorizationRestrictionService;
use App\Services\PlatformGuard;
use App\Support\PlatformBlocked;
use Illuminate\Console\Command;

class ExpireRestrictionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'authz:expire-restrictions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Close elapsed authorization restrictions and advance affected Administrator permission versions.';

    /**
     * Execute the console command.
     */
    public function handle(AuthorizationRestrictionService $restrictionService): int
    {
        try {
            return app(PlatformGuard::class)->transaction('mutation', function () use ($restrictionService) {
                return $this->handleAllowed($restrictionService);
            });
        } catch (PlatformBlocked) {
            return 0;
        }
    }

    private function handleAllowed(AuthorizationRestrictionService $restrictionService): int
    {
        $closedCount = $restrictionService->expireElapsedRestrictions();

        if ($closedCount > 0) {
            $this->components->info("Closed {$closedCount} elapsed authorization restriction(s) and advanced permission version(s).");
        } else {
            $this->components->info('No elapsed authorization restrictions found.');
        }

        return self::SUCCESS;
    }
}
