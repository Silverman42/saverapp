<?php

namespace App\Console\Commands;

use App\Services\BusinessSettings;
use Illuminate\Console\Command;

class ActivateBusinessSettings extends Command
{
    protected $signature = 'business:activate-settings {--limit=100 : Maximum due bundles, from 1 to 1000}';

    protected $description = 'Recover due configuration activation without replaying business actions';

    public function handle(BusinessSettings $settings): int
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
