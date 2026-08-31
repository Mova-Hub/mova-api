<?php

namespace App\Console\Commands;

use App\Domain\Settings\Facades\Settings;
use App\Models\Order;
use App\Notifications\TripReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Reminds clients about a trip that is about to happen.
 *
 * Push, mail and the in-app inbox, via `TripReminder` and the `NotifiesClient`
 * trait that picks the channels a given client actually has. Separate from
 * `payments:remind`, which is SMS dunning about money: these are people who
 * have already paid and simply need to know when to be at the pickup point.
 *
 * Two sends, the eve and the morning. Neither is negotiable down to one: the
 * eve is when somebody can still rearrange their day, the morning is what stops
 * them oversleeping. Anything more than two teaches people to swipe Mova
 * notifications away without reading them.
 *
 * **Only confirmed reservations with vehicles.** Reminding somebody about a
 * trip that ops have not actually resourced would send them to a car park to
 * wait for a coach that was never assigned.
 */
class SendTripReminders extends Command
{
    protected $signature = 'trips:remind {--dry-run : List who would be reminded, send nothing}';

    protected $description = 'Remind clients about trips departing today or tomorrow';

    private int $sent = 0;

    private int $skipped = 0;

    public function handle(): int
    {
        if (! Settings::bool('notifications.reminders_enabled', true)) {
            $this->info('Reminders are disabled in settings.');

            return self::SUCCESS;
        }

        $today = now()->startOfDay();
        $tomorrow = now()->addDay()->startOfDay();

        Order::query()
            ->with(['client', 'reservation.buses'])
            ->whereNotNull('client_id')
            ->whereIn('status', ['converted'])
            ->whereBetween('pickup_date', [$today, $tomorrow->copy()->endOfDay()])
            // A trip is only worth reminding somebody about once ops have
            // committed to running it. `in_progress` is included because a
            // multi-day charter can already be under way on the morning its
            // pickup date arrives.
            ->whereHas('reservation', fn ($q) => $q->whereIn('status', ['confirmed', 'in_progress']))
            ->whereHas('reservation.buses')
            ->chunkById(200, function ($orders) use ($today) {
                foreach ($orders as $order) {
                    $this->remind($order, $today);
                }
            });

        $this->info(sprintf(
            '%s %d reminder(s). %d already reminded today.',
            $this->option('dry-run') ? 'Would send' : 'Sent',
            $this->sent,
            $this->skipped,
        ));

        return self::SUCCESS;
    }

    private function remind(Order $order, \Illuminate\Support\Carbon $today): void
    {
        /*
         * One reminder per order per day.
         *
         * This is what makes the eve and the morning two sends rather than two
         * chances to send the same one twice. On the eve `trip_reminded_at` is
         * null and the message goes; the next morning the stamp is yesterday's,
         * so it goes again; a second run the same day finds today's stamp and
         * stops. Re-running the schedule after a failed deploy is therefore
         * safe, which is the property that matters on a host where cron
         * overlaps are common.
         */
        if ($order->trip_reminded_at && $order->trip_reminded_at->gte($today)) {
            $this->skipped++;

            return;
        }

        $client = $order->client;

        if (! $client) {
            return;
        }

        $when = $order->pickup_date?->isSameDay($today)
            ? TripReminder::DAY
            : TripReminder::EVE;

        if ($this->option('dry-run')) {
            $this->line(sprintf('  would remind client #%d about order #%d (%s)', $client->id, $order->id, $when));
            $this->sent++;

            return;
        }

        try {
            Notification::send($client, new TripReminder($order, $when));

            // Stamped only after the send returns. Stamping first would lose
            // the reminder entirely if the push provider was down, and this
            // message has one useful moment.
            $order->forceFill(['trip_reminded_at' => now()])->saveQuietly();

            $this->sent++;
        } catch (Throwable $e) {
            // One unreachable client must not stop the sweep.
            report($e);
        }
    }
}
