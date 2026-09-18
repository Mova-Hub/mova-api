<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\TripExpired;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Closes requests whose travel date came and went while they were still a lead.
 *
 * A client submits a charter request for the 12th. Ops never quote it, or quote
 * it and never hear back. On the 13th that row is still `pending`, still sitting
 * at the top of the client's "A venir" list, still carrying a price and, until
 * `Order::isPayable()` was tightened alongside this command, still offering a
 * "Payer" button for a coach that was never going to arrive.
 *
 * **Expired, not cancelled.** Cancelling is an act: somebody decided. The
 * back-office reads cancellations as lost business and ops are measured on
 * them, so filing lapsed requests there would blame the sales team for trips
 * nobody ever declined. See `Order::STATUS_EXPIRED`.
 *
 * **Only leads.** The sweep touches orders that never reached a reservation,
 * which is precisely "not started nor confirmed". A confirmed booking that
 * failed to run is an operational incident with money attached, and quietly
 * relabelling it overnight would hide that from the people who have to refund
 * it. Those stay put and stay visible.
 */
class ExpireStaleOrders extends Command
{
    protected $signature = 'orders:expire
        {--days=1 : Whole days a travel date must be past before the order lapses}
        {--dry-run : List what would change, write nothing}';

    protected $description = 'Mark past-dated charter requests that were never confirmed as expired';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $cutoff = now()->subDays($days)->startOfDay();

        /*
         * `return_date ?? pickup_date` decides staleness, because a two-day
         * charter leaving on the 10th and returning on the 12th is not stale on
         * the 11th. COALESCE rather than two queries so the cutoff is applied
         * once, by the database, on an indexed column.
         */
        $query = Order::query()
            ->whereIn('status', ['pending', 'contacted'])
            ->whereRaw('COALESCE(return_date, pickup_date) < ?', [$cutoff->toDateString()])
            // Belt and braces against a reservation that exists while the order
            // status was left behind: converting sets `converted`, but a row
            // repaired by hand may not have.
            ->whereDoesntHave('reservation');

        $total = (clone $query)->count();

        /*
         * No leads is not "nothing to do".
         *
         * This used to return here, which was correct when the command only
         * ever swept leads. Now that confirmed trips are a second stage, an
         * early return meant the far more important half never ran on any night
         * where no enquiry happened to lapse. Caught by a test that expired a
         * confirmed trip and found it untouched.
         */
        if ($total === 0) {
            $this->info('No stale leads.');

            $this->expireConfirmed($cutoff);

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            (clone $query)->select(['id', 'status', 'pickup_date', 'destination'])
                ->orderBy('pickup_date')
                ->chunkById(200, function ($orders) {
                    foreach ($orders as $order) {
                        $this->line(sprintf(
                            '  #%d  %s  %s  -> %s',
                            $order->id,
                            $order->status,
                            $order->pickup_date?->toDateString() ?? '(no date)',
                            $order->destination,
                        ));
                    }
                });

            $this->info(sprintf('Would expire %d order(s).', $total));

            // The confirmed stage has its own dry-run branch. Returning here
            // would make `--dry-run` report on half the command, which is worse
            // than useless for something whose purpose is to be trusted before
            // it writes.
            $this->expireConfirmed($cutoff);

            return self::SUCCESS;
        }

        /*
         * The ids are captured BEFORE the update.
         *
         * Afterwards the rows no longer match the query, so there would be
         * nothing left to notify. This is the one thing the bulk path costs.
         */
        $ids = (clone $query)->pluck('id')->all();

        /*
         * A bulk UPDATE, not a loop of saves.
         *
         * This is a nightly tidy-up over rows nobody is watching, and firing the
         * model observers would write one audit entry per order for a change no
         * human made. `updated_at` is set explicitly because the query builder
         * does not maintain timestamps.
         */
        $updated = DB::transaction(fn () => $query->update([
            'status' => Order::STATUS_EXPIRED,
            'updated_at' => now(),
        ]));

        $this->info(sprintf('Expired %d order(s) with a travel date before %s.',
            $updated,
            $cutoff->toDateString(),
        ));

        /*
         * Told, rather than left to notice.
         *
         * Until now this sweep informed nobody: a request stopped appearing
         * under "A venir" one morning and the only way to find out was to open
         * the app. Silence there reads as the app losing a booking.
         */
        $this->announce($ids, wasConfirmed: false);

        $this->expireConfirmed($cutoff);

        return self::SUCCESS;
    }

    /**
     * Trips that were CONFIRMED and never ran.
     *
     * This sweep used to stop at leads, and the reasoning was sound as far as it
     * went: a confirmed booking that failed to run is an operational incident
     * with money attached, and quietly relabelling it overnight would hide that
     * from whoever has to issue the refund.
     *
     * The answer to that is not to leave it running for ever, which is what
     * happened: the trip sat under "A venir" months after its date, in the
     * client's app and in the back office alike. The answer is to stop doing it
     * QUIETLY. So the trip is expired AND both the client and staff are told, so
     * a refund obligation surfaces rather than being buried by a cron.
     *
     * Hydrated models here, unlike the lead sweep above. Every row needs a
     * notification and a state-machine check, and there are few enough of them
     * that the cost does not matter.
     */
    private function expireConfirmed(\Carbon\CarbonInterface $cutoff): void
    {
        $reservations = Reservation::query()
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereNull('started_at')
            ->whereRaw('COALESCE(return_date, trip_date) < ?', [$cutoff->toDateString()])
            ->with('order.client')
            ->get();

        if ($reservations->isEmpty()) {
            return;
        }

        if ($this->option('dry-run')) {
            foreach ($reservations as $reservation) {
                $this->line('  confirmed  '.($reservation->code ?: $reservation->id));
            }

            $this->info(sprintf('Would expire %d confirmed trip(s).', $reservations->count()));

            return;
        }

        $expired = 0;

        foreach ($reservations as $reservation) {
            if (! $reservation->canTransitionTo('expired')) {
                continue;
            }

            $reservation->forceFill(['status' => 'expired'])->save();

            if ($order = $reservation->order) {
                // The order follows the trip. Leaving it `converted` would show
                // a live booking in the app for a trip that has just lapsed.
                $order->forceFill(['status' => Order::STATUS_EXPIRED])->saveQuietly();
            }

            $expired++;
        }

        $this->info(sprintf('Expired %d confirmed trip(s) that never started.', $expired));

        $this->announce(
            $reservations->pluck('order_id')->filter()->all(),
            wasConfirmed: true,
        );
    }

    /**
     * Tells the client, and for a confirmed trip tells staff too.
     *
     * Failures are logged and swallowed. A notification that cannot be sent must
     * not abort a sweep that has already written its rows, or the next run finds
     * nothing to do and nobody is ever told.
     *
     * @param  array<int, int>  $orderIds
     */
    private function announce(array $orderIds, bool $wasConfirmed): void
    {
        if ($orderIds === []) {
            return;
        }

        Order::with('client')->whereIn('id', $orderIds)->chunkById(100, function ($orders) use ($wasConfirmed) {
            foreach ($orders as $order) {
                try {
                    $order->client?->notify(new TripExpired($order, $wasConfirmed));
                } catch (Throwable $e) {
                    Log::error('Expiry notice could not reach the client', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        if (! $wasConfirmed) {
            return;
        }

        /*
         * Staff only for a confirmed trip.
         *
         * A lapsed enquiry is routine and does not need an alert. A confirmed
         * trip that never ran is the case with money attached, and it is
         * precisely the one the old docblock was protecting by refusing to touch
         * it at all.
         */
        try {
            $staff = User::whereIn('role', User::STAFF_ROLES)
                ->where('status', 'active')
                ->get();

            if ($staff->isEmpty()) {
                return;
            }

            Order::whereIn('id', $orderIds)->chunkById(100, function ($orders) use ($staff) {
                foreach ($orders as $order) {
                    Notification::send($staff, new TripExpired($order, true));
                }
            });
        } catch (Throwable $e) {
            Log::error('Expiry notice could not reach staff', [
                'order_ids' => $orderIds,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
