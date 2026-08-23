<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// This tells Laravel to check the 'jobs' table every minute.
// --stop-when-empty ensures the script doesn't hang and get killed by cPanel.
// --max-time=55 ensures it finishes before the next cron starts.
Schedule::command('queue:work --stop-when-empty --max-time=55')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * Mova Pass housekeeping.
 *
 * Moves lapsed subscriptions from `active` to `expired`. Deliberately a nightly
 * job and not more often, because it is NOT what decides a fare: every read
 * path compares the expiry date itself, so a scheduler that is hours behind
 * delays a label in the back-office and never grants a free ride.
 *
 * 03:00 to stay clear of the queue worker's minute-by-minute cycle above.
 */
Schedule::command('pass:expire')
    ->dailyAt('03:00')
    ->withoutOverlapping();

/*
 * Audit retention.
 *
 * 04:00, after pass:expire, so the two housekeeping jobs never contend. The
 * defaults live in the command (400 days for mutations, 90 for sensitive
 * reads) rather than here, so changing them does not mean editing a schedule.
 */
Schedule::command('activity:prune')
    ->dailyAt('04:00')
    ->withoutOverlapping();
