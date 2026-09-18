<?php

namespace App\Console\Commands;

use App\Domain\Booking\TripSchedule;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\TripNeedsClosing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Trips that started and were never finished.
 *
 * `started_at` and `completed_at` are only ever written by a human: the field
 * app's Démarrer and Terminer, or the back office doing the same. So a
 * coordinator who finishes a journey, puts the phone away and forgets the last
 * tap leaves the reservation `in_progress` for ever. Nothing swept it, the
 * client's app showed a trip still running days later, and the back office
 * counted it as active work.
 *
 * Two stages, deliberately a day apart.
 *
 *  1. **At the trip end**, notify both sides that it looks finished. A
 *     coordinator who simply forgot can close it in one tap, and the client is
 *     told rather than left watching a trip that never ends.
 *  2. **A day later**, close it. By then nobody has acted, and a trip stuck open
 *     is worse than one closed slightly late.
 *
 * The grace period is the whole point. Closing at the end time would fight the
 * normal case: a trip that runs late is not a trip that is over, and this must
 * not cut short a convoy sitting in traffic.
 *
 * **There is no `ends_at` column.** The trip end is
 * `TripSchedule::for($order)->end`, the same value the calendar feed and the
 * order resource use, which resolves a return date when there is one and
 * otherwise allows `TripSchedule::DEFAULT_HOURS` from the start. Inventing a
 * second definition here is how two parts of a system start disagreeing about
 * when a journey finished.
 */
class SweepRunningTrips extends Command
{
    protected $signature = 'trips:sweep
        {--grace=1 : Whole days past the trip end before a running trip is closed automatically}
        {--dry-run : List what would change, write nothing}';

    protected $description = 'Notify about, then automatically close, trips left running past their end';

    public function handle(): int
    {
        $grace = max(0, (int) $this->option('grace'));
        $dry = (bool) $this->option('dry-run');

        /*
         * Hydrated models, not a bulk update.
         *
         * `orders:expire` uses a query-builder UPDATE on purpose, because it
         * touches rows nobody is watching. This one cannot: every row needs its
         * schedule resolved from the order, and both stages send notifications,
         * which means real models and real events.
         */
        $running = Reservation::query()
            ->where('status', 'in_progress')
            ->with(['order.client', 'coordinator'])
            ->get();

        if ($running->isEmpty()) {
            $this->info('No running trips.');

            return self::SUCCESS;
        }

        $notified = 0;
        $closed = 0;

        foreach ($running as $reservation) {
            $end = $this->endOf($reservation);

            // No resolvable schedule means no date to judge against. Leaving it
            // alone is right: guessing an end time would close a real trip.
            if (! $end) {
                continue;
            }

            if (now()->lt($end)) {
                continue;
            }

            $deadline = $end->addDays($grace);

            if (now()->gte($deadline)) {
                $closed += $this->close($reservation, $dry) ? 1 : 0;

                continue;
            }

            // Past the end, inside the grace period: this is the nudge stage.
            if ($reservation->closing_notified_at === null) {
                $notified += $this->nudge($reservation, $dry) ? 1 : 0;
            }
        }

        $verb = $dry ? 'would be' : 'were';
        $this->info("{$notified} trip(s) {$verb} flagged for closing, {$closed} {$verb} closed automatically.");

        return self::SUCCESS;
    }

    /**
     * The trip's end, from the single definition the rest of the app uses.
     */
    private function endOf(Reservation $reservation): ?\Carbon\CarbonImmutable
    {
        $order = $reservation->order;

        if (! $order) {
            return null;
        }

        return TripSchedule::for($order)?->end;
    }

    /** Stage one: tell both sides it looks finished. */
    private function nudge(Reservation $reservation, bool $dry): bool
    {
        $this->line('  flag  '.($reservation->code ?: $reservation->id));

        if ($dry) {
            return true;
        }

        /*
         * Stamped BEFORE the notification.
         *
         * If sending throws, the column is already set and the nudge is not
         * retried tomorrow. That is the deliberate trade: a notice nobody
         * received is better than one that arrives every night for a week, and
         * the auto-close a day later does not depend on this having worked.
         */
        $reservation->forceFill(['closing_notified_at' => now()])->saveQuietly();

        $this->notify($reservation);

        return true;
    }

    /** Stage two: close it. */
    private function close(Reservation $reservation, bool $dry): bool
    {
        $this->line('  close '.($reservation->code ?: $reservation->id));

        if ($dry) {
            return true;
        }

        /*
         * Through the state machine, not around it.
         *
         * `canTransitionTo` is what every other closing path checks, and a cron
         * that writes `completed` directly would be the one caller allowed to
         * produce a state the rules forbid.
         */
        if (! $reservation->canTransitionTo('completed')) {
            return false;
        }

        $reservation->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            // The marker that says a human did not do this. Downstream, the
            // review request must not ask somebody to rate a journey that was
            // closed because it was forgotten.
            'auto_closed_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Both sides, and a failure to reach either must not stop the sweep.
     */
    private function notify(Reservation $reservation): void
    {
        try {
            if ($client = $reservation->order?->client) {
                $client->notify(new TripNeedsClosing($reservation));
            }
        } catch (Throwable $e) {
            Log::error('Trip closing notice could not reach the client', [
                'reservation_id' => $reservation->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $staff = User::whereIn('role', User::STAFF_ROLES)
                ->where('status', 'active')
                ->get();

            if ($staff->isNotEmpty()) {
                Notification::send($staff, new TripNeedsClosing($reservation));
            }
        } catch (Throwable $e) {
            Log::error('Trip closing notice could not reach staff', [
                'reservation_id' => $reservation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
