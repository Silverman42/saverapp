<?php

namespace App\Console\Commands;

use App\Services\BusinessSettings;
use Illuminate\Console\Command;

class ImportBusinessSettings extends Command
{
    protected $signature = 'business:import-settings';

    protected $description = 'Import the trusted singleton profile into immutable configuration without reconstructing history';

    public function handle(BusinessSettings $settings): int
    {
        $version = $settings->import();
        $this->info('Trusted configuration version '.$version->version.' retained. Financial mutation features remain gated.');

        return self::SUCCESS;
    }
}
