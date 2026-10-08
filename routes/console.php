<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('authz:expire-restrictions')
    ->everyMinute()
    ->withoutOverlapping()->onOneServer();

Schedule::command('collections:freeze-batches')
    ->everyMinute()
    ->withoutOverlapping()->onOneServer();

Schedule::command('withdrawals:expire')
    ->everyMinute()
    ->withoutOverlapping()->onOneServer();

Schedule::command('withdrawals:reconcile-bank-payouts')
    ->everyMinute()
    ->withoutOverlapping()->onOneServer();

Schedule::command('ledger:rebuild-transactions --if-stale')
    ->everyFiveMinutes()
    ->withoutOverlapping()->onOneServer();

Schedule::command('notifications:drain')->everyMinute()->withoutOverlapping()->onOneServer();

Schedule::command('audit:drain')->everyMinute()->withoutOverlapping()->onOneServer();

Schedule::command('business:activate-settings --limit=100')->everyMinute()->withoutOverlapping()->onOneServer();

Schedule::command('platform:heartbeat')->everyMinute()->evenInMaintenanceMode()->withoutOverlapping()->onOneServer();

Schedule::command('customers:expire-recovery')->everyMinute()->withoutOverlapping()->onOneServer();

Schedule::command('financial-artifacts:drain')->everyMinute()->withoutOverlapping()->onOneServer();

Schedule::command('collections:clean-evidence --limit=500')->daily()->withoutOverlapping()->onOneServer();
