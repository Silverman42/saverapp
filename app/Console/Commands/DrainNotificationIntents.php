<?php

namespace App\Console\Commands;

use App\Jobs\MaterializeNotificationIntent;
use App\Services\PlatformGuard;
use App\Support\PlatformBlocked;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DrainNotificationIntents extends Command
{
    protected $signature = 'notifications:drain {--limit=100 : Maximum intents to dispatch}';

    protected $description = 'Recover committed in-app notification intents without repeating their source operations';

    public function handle(): int
    {
        try {
            return app(PlatformGuard::class)->transaction('external', function () {
                return $this->handleAllowed();
            });
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
        DB::table('notification_inbox_intents')->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->limit($limit)->pluck('id')->each(fn ($id) => MaterializeNotificationIntent::dispatch((int) $id)->afterCommit());

        return self::SUCCESS;
    }
}
