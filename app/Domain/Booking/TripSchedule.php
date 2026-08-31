<?php

namespace App\Domain\Booking;

use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * When a trip actually runs.
 *
 * One answer, in one place, because there are two candidate sources and
 * picking the wrong one is invisible until somebody misses a coach.
 *
 * ## Why the reservation wins
 *
 * `orders.pickup_date` is what the client ASKED for on the request form. It is
 * never rewritten. `reservations.trip_date` is what ops AGREED to when they
 * converted the lead, it is a real dateTime rather than a date plus free text,
 * and it is what ops edit when a trip moves.
 *
 * Reading the order therefore gives you the original request forever. The
 * calendar feed did exactly that, so a rescheduled trip never moved in anyone's
 * calendar, which defeats the entire argument for publishing a subscription
 * rather than a one-off export.
 *
 * ## Midnight means "no time given"
 *
 * `trip_date` is a dateTime, but a row created from a date-only form carries
 * 00:00:00. A charter does not depart at midnight: the app only ever offers
 * 06:00 to 22:00, so midnight is not a departure time, it is a missing one. In
 * that case the order's free text `pickup_time` is consulted for a clock, and
 * if that yields nothing the entry becomes all-day rather than claiming a
 * departure nobody stated.
 */
final class TripSchedule
{
    /**
     * How long to block out when nobody has said when the trip ends.
     *
     * Charters here are typically a half day out and back, and an entry that is
     * too short is worse than one that is too long: a two hour block says you
     * are free at noon when you are on a coach to Pointe-Noire.
     */
    public const DEFAULT_HOURS = 4;

    private function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        /** True when only the day is known, so no hour should be claimed. */
        public readonly bool $allDay,
    ) {}

    public static function for(Order $order): ?self
    {
        $reservation = $order->reservation;

        $departureDay = $reservation?->trip_date ?? $order->pickup_date;

        if (! $departureDay) {
            return null;
        }

        $clock = self::clockFor($reservation?->trip_date, $order->pickup_time);

        $start = CarbonImmutable::parse($departureDay)
            ->setTime($clock['h'] ?? 0, $clock['m'] ?? 0);

        $returnDay = $reservation?->return_date ?? $order->return_date;

        if ($returnDay) {
            $returnClock = self::clockFor($reservation?->return_date, $order->return_time);

            $end = CarbonImmutable::parse($returnDay)
                // A return with no stated hour ends the day rather than
                // starting it, or a two day charter renders as one.
                ->setTime($returnClock['h'] ?? 23, $returnClock['m'] ?? 59);
        } else {
            $end = $start->addHours(self::DEFAULT_HOURS);
        }

        // A return recorded before the departure is bad data, not a negative
        // trip. Fall back rather than emitting an event a calendar will reject.
        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->addHours(self::DEFAULT_HOURS);
        }

        return new self($start, $end, $clock === []);
    }

    /**
     * The clock for one leg: the agreed timestamp first, the free text second.
     *
     * @return array{h?: int, m?: int}
     */
    private static function clockFor(mixed $agreed, ?string $freeText): array
    {
        if ($agreed) {
            $agreed = CarbonImmutable::parse($agreed);

            // Anything but midnight is a real, agreed departure time.
            if ($agreed->format('H:i') !== '00:00') {
                return ['h' => (int) $agreed->format('G'), 'm' => (int) $agreed->format('i')];
            }
        }

        return self::parseClock($freeText);
    }

    /**
     * Reads "06:00", "6h30", "06h" and gives up cleanly on prose.
     *
     * `pickup_time` is free text on `orders` and older rows really do hold
     * things like "tot le matin". Anything unrecognised returns an empty array,
     * which makes the entry all-day instead of inventing an hour somebody would
     * plan their morning around.
     *
     * @return array{h?: int, m?: int}
     */
    public static function parseClock(?string $value): array
    {
        if (! $value || ! preg_match('/^(\d{1,2})\s*[:hH]?\s*(\d{2})?/', trim($value), $m)) {
            return [];
        }

        $h = (int) $m[1];
        $min = isset($m[2]) ? (int) $m[2] : 0;

        return ($h > 23 || $min > 59) ? [] : ['h' => $h, 'm' => $min];
    }
}
