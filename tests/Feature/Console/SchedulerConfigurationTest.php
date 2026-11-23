<?php

use Illuminate\Console\Scheduling\Schedule;

it('expires the stale-generation scheduler lock after five minutes', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(
            fn ($event) => str_contains(
                $event->command,
                'app:reconcile-stale-generations'
            )
        );

    expect($event)->not->toBeNull()
        ->and($event->expiresAt)->toBe(5)
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('runs stale-generation reconciliation on only one server', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(
            fn ($event) => str_contains(
                $event->command,
                'app:reconcile-stale-generations'
            )
        );

    expect($event)->not->toBeNull()
        ->and($event->onOneServer)->toBeTrue();
});
