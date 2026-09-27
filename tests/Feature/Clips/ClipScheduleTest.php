<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

// No migrations here: withScarlettConfig() reloads the app, and a loaded migration
// path would try to roll back against the fresh in-memory database.

test('reconcile is scheduled every minute by default', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'scarlett:clips:reconcile'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});

test('the reconcile schedule can be turned off', function (): void {
    $this->withScarlettConfig(['clips.schedule_reconcile' => false]);

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'scarlett:clips:reconcile'));

    expect($events)->toHaveCount(0);
});
