<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

test('every scheduled task runs on one server and without overlapping', function () {
    $events = collect(app(Schedule::class)->events());

    expect($events)->not->toBeEmpty()
        ->and($events->reject(fn (Event $event): bool => $event->onOneServer && $event->withoutOverlapping)
            ->map(fn (Event $event): string => (string) $event->command)
            ->values()
            ->all())->toBe([]);
});
