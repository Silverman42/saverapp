<?php

namespace App\Console\Commands;

use App\Services\RoleSynchronizationService;
use Illuminate\Console\Command;

class CheckRoleSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'authz:check-role-sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify the fixed Spatie role catalogue, user-role synchronization, direct-grant boundaries, empty role-permission pivot, and bootstrap grant provenance.';

    /**
     * Execute the console command.
     */
    public function handle(RoleSynchronizationService $syncService): int
    {
        $this->components->info('Checking role synchronization and authorization invariants...');

        $report = $syncService->check();

        if ($report->isValid()) {
            $this->components->info('Role synchronization and authorization invariants are valid.');
            $this->line('  • Fixed role catalogue: valid');
            $this->line('  • Role-permission pivot: empty');
            $this->line("  • User-role mappings: synchronized ({$report->details()['users_count']} user(s))");
            $this->line('  • Direct-grant boundaries: verified');
            $this->line('  • Bootstrap grant provenance: verified');

            return self::SUCCESS;
        }

        $this->components->error('Role synchronization drift detected:');
        foreach ($report->issues() as $issue) {
            $this->line("  [DRIFT] {$issue}");
        }

        return self::FAILURE;
    }
}
