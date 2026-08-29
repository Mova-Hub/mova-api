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

/*
 * Payment reconciliation.
 *
 * The safety net under every mobile-money payment. Webhooks get lost, and a
 * payment stuck at `processing` means a client who has been debited, an order
 * that still reads unpaid, and an in-flight guard that blocks any retry. This
 * is the only thing that resolves that state.
 *
 * Every five minutes rather than every minute: the poll only starts two
 * minutes after an attempt, providers rate-limit, and the app is already
 * polling on the client's behalf while they watch the sheet.
 */
Schedule::command('payments:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping();

/*
 * Mova Credit expiry.
 *
 * Sweeps lapsed promotional credit by writing an explicit `expired` debit —
 * the ledger is append-only, so nothing is deleted. 03:30, between the Pass
 * sweep and the audit prune.
 */
Schedule::command('wallet:expire')
    ->dailyAt('03:30')
    ->withoutOverlapping();

/*
 * GPS trail retention.
 *
 * `reservation_positions` is a minute-by-minute record of where a named
 * employee was. It has a job — the client's live map, and settling a route
 * dispute a few days later — and once that job is done, keeping it is a
 * liability. Seven days is the window in which a dispute realistically lands;
 * the threshold lives in the command so changing it is not a schedule edit.
 *
 * 04:30, after the audit prune, so the housekeeping jobs never contend.
 */
Schedule::command('positions:prune')
    ->dailyAt('04:30')
    ->withoutOverlapping();

/*
 * Payment reminders.
 *
 * Once a day, mid-morning — a dunning SMS at 04:00 is how a brand teaches
 * people to mute it. Frequency-capped per client inside the command.
 */
Schedule::command('payments:remind')
    ->dailyAt('09:30')
    ->withoutOverlapping();
