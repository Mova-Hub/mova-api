<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Notifications\RateYourTrip;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks passengers to rate trips that finished.
 *
 * A sweep rather than a dispatch from whichever controller marked the trip
 * complete. There are two completion paths, `Field\MissionController::complete`
 * and `ReservationController::setStatus`, and wiring the notification into one
 * of them would silently miss every trip closed by the other. A sweep reads the
 * end state and does not care how the trip got there.
 *
 * Three things it refuses to ask about, and each is deliberate.
 *
 * **Trips closed by `trips:sweep`.** `auto_closed_at` means a cron gave up
 * because nobody pressed Terminer. Asking for a rating there invites one star
 * about a journey that may have gone perfectly well, and that score would land
 * on a coordinator's average.
 *
 * **Trips already rated.** Obvious, but worth stating: the request is not a
 * reminder and does not chase.
 *
 * **Trips already asked about.** `review_requested_at` is stamped, so this fires
 * once per trip. A column and not the cache, because this host runs a file cache
 * that every deploy empties.
 */
class RequestTripReviews extends Command
{
    protected $signature = 'trips:request-reviews
        {--hours=2 : Hours to wait after completion before asking}
        {--days=14 : Do not ask about trips older than this}
        {--dry-run : List who would be asked, send nothing}';

    protected $description = 'Ask passengers to rate trips that recently finished';

    public function handle(): int
    {
        $hours = max(0, (int) $this->option('hours'));
        $days = max(1, (int) $this->option('days'));
        $dry = (bool) $this->option('dry-run');

        /*
         * A floor as well as a ceiling.
         *
         * The floor gives the passenger time to get home before their phone
         * buzzes about the journey. The ceiling stops a backlog: switching this
         * on for the first time, or running it after an outage, would otherwise
         * ask about every trip ever completed, which is a mass send to people
         * who have long since moved on.
         */
        $reservations = Reservation::query()
            ->where('status', 'completed')
            ->whereNull('auto_closed_at')
            ->whereNull('review_requested_at')
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', now()->subHours($hours))
            ->where('completed_at', '>=', now()->subDays($days))
            ->whereDoesntHave('rating')
            ->with(['order.client'])
            ->get();

        if ($reservations->isEmpty()) {
            $this->info('No trips to ask about.');

            return self::SUCCESS;
        }

        $asked = 0;

        foreach ($reservations as $reservation) {
            $client = $reservation->order?->client;

            if (! $client) {
                continue;
            }

            $this->line('  ask  '.($reservation->code ?: $reservation->id));

            if ($dry) {
                $asked++;

                continue;
            }

            /*
             * Stamped BEFORE sending.
             *
             * If the send throws, the trip is not queued up to be asked about
             * again tomorrow. A review request that arrives twice is worse than
             * one that never arrives: the second is silence, the first teaches
             * people the app is broken.
             */
            $reservation->forceFill(['review_requested_at' => now()])->saveQuietly();

            try {
                $client->notify(new RateYourTrip($reservation->order));
                $asked++;
            } catch (Throwable $e) {
                Log::error('Review request could not be sent', [
                    'reservation_id' => $reservation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info($dry
            ? "Would ask about {$asked} trip(s)."
            : "Asked about {$asked} trip(s).");

        return self::SUCCESS;
    }
}
