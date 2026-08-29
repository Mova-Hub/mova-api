<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Models\ReservationPosition;
use Illuminate\Console\Command;

/**
 * Forgets where the buses went.
 *
 * `reservation_positions` is a minute-by-minute record of a named employee's
 * movements. It has a job — showing a client their coach approaching, and
 * settling a dispute about the route a week later — and once that job is done
 * keeping it is a liability, not an asset.
 *
 * Seven days is the window a billing dispute realistically lands in. After
 * that the trail goes, and what survives is what the reservation already
 * records: `started_at`, `completed_at`, and the waypoints the client agreed to.
 *
 * Deliberately keyed on the RESERVATION being finished rather than on the
 * position's own age: a trip that ran for eleven hours would otherwise have its
 * first hour deleted while it was still under way.
 */
class PrunePositions extends Command
{
    protected $signature = 'positions:prune
                            {--days=7 : Keep trails for reservations finished within this many days}
                            {--dry-run : Report what would go without deleting it}';

    protected $description = 'Deletes GPS trails for reservations that finished more than N days ago.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        /*
         * `completed_at` OR `updated_at` for cancelled trips.
         *
         * A cancelled reservation never gets a `completed_at`, so keying on that
         * alone would keep the trail of every abandoned trip for ever — which is
         * precisely the data with the least reason to exist.
         */
        $stale = Reservation::query()
            ->whereIn('status', ['completed', 'cancelled'])
            ->where(fn ($q) => $q
                ->where('completed_at', '<', $cutoff)
                ->orWhere(fn ($c) => $c->whereNull('completed_at')->where('updated_at', '<', $cutoff)))
            ->pluck('id');

        if ($stale->isEmpty()) {
            $this->info('Aucune trace à purger.');
            return self::SUCCESS;
        }

        $count = ReservationPosition::whereIn('reservation_id', $stale)->count();

        if ($count === 0) {
            $this->info("{$stale->count()} réservation(s) éligibles, aucune trace restante.");
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("[dry-run] {$count} position(s) sur {$stale->count()} réservation(s) seraient supprimées.");
            return self::SUCCESS;
        }

        // Chunked: a season of tracking is a lot of rows, and one DELETE over
        // all of them locks the table for as long as it takes.
        $deleted = 0;
        $stale->chunk(200)->each(function ($ids) use (&$deleted) {
            $deleted += ReservationPosition::whereIn('reservation_id', $ids)->delete();
        });

        $this->info("{$deleted} position(s) supprimée(s) sur {$stale->count()} réservation(s).");

        return self::SUCCESS;
    }
}
