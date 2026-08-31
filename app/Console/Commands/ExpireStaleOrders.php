<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

        if ($total === 0) {
            $this->info('Nothing to expire.');

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

            return self::SUCCESS;
        }

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

        return self::SUCCESS;
    }
}
