<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('authz:expire-restrictions')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('collections:freeze-batches')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('withdrawals:expire')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('notifications:drain')->everyMinute()->withoutOverlapping();

Schedule::command('audit:drain')->everyMinute()->withoutOverlapping();

Schedule::command('business:activate-settings --limit=100')->everyMinute()->withoutOverlapping();

Schedule::command('platform:heartbeat')->everyMinute()->evenInMaintenanceMode()->withoutOverlapping();

Schedule::command('customers:expire-recovery')->everyMinute()->withoutOverlapping();
