<?php

namespace App\Console\Commands;

use App\Services\BusinessSettings;
use App\Services\PlatformGuard;
use App\Support\PlatformBlocked;
use Illuminate\Console\Command;

class ActivateBusinessSettings extends Command
{
    protected $signature = 'business:activate-settings {--limit=100 : Maximum due bundles, from 1 to 1000}';

    protected $description = 'Recover due configuration activation without replaying business actions';

    public function handle(BusinessSettings $settings): int
    {
        try {
            return app(PlatformGuard::class)->transaction('mutation', function () use ($settings) {
                return $this->handleAllowed($settings);
            });
        } catch (PlatformBlocked) {
            return 0;
        }
    }

    private function handleAllowed(BusinessSettings $settings): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('Limit must be from 1 to 1000.');

            return self::FAILURE;
        }
        $this->info($settings->drain($limit).' configuration bundles activated.');

        return self::SUCCESS;
    }
}
