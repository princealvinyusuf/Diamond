<?php

use App\Jobs\RunScheduledH4Analysis;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (): void {
    $boundary = now('UTC')->startOfHour();
    $boundary->subHours($boundary->hour % 4);
    RunScheduledH4Analysis::dispatch($boundary->toISOString());
})
    ->name('analysis:xauusd-h4')
    ->cron('5 */4 * * *')
    ->timezone('UTC')
    ->withoutOverlapping(240)
    ->onOneServer();

Schedule::command('queue:prune-failed --hours=168')
    ->dailyAt('02:30')
    ->timezone('UTC')
    ->onOneServer();
