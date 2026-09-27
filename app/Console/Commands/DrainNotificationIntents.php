<?php

namespace App\Console\Commands;

use App\Services\BackgroundRecovery;
use App\Services\ManagementMailDelivery;
use App\Services\PlatformGuard;
use App\Support\PlatformBlocked;
use Illuminate\Console\Command;

class DrainNotificationIntents extends Command
{
    protected $signature = 'notifications:drain {--limit=100 : Maximum intents to dispatch}';

    protected $description = 'Recover committed in-app notification intents without repeating their source operations';

    public function handle(): int
    {
        try {
            app(PlatformGuard::class)->assertAllowed('external');

            return $this->handleAllowed();
        } catch (PlatformBlocked) {
            return 0;
        }
    }

    private function handleAllowed(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('Choose a limit from 1 to 1000.');

            return self::FAILURE;
        }
        app(BackgroundRecovery::class)->dispatchNotifications($limit);
        app(ManagementMailDelivery::class)->drain($limit);

        return self::SUCCESS;
    }
}
