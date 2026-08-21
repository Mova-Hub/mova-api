<?php

namespace App\Domain\Booking;

/**
 * Converts a car journey time into a bus journey time.
 *
 * Google Directions has no vehicle profile: `mode=driving` returns the time a
 * CAR would take. `mode=transit` is not the answer either — it routes against
 * published timetables, and Mova's buses are not in Google's transit feed for
 * Brazzaville, nor would a chartered vehicle follow a scheduled line.
 *
 * So the road geometry comes from `driving` (a Coaster follows the same roads a
 * car does) and the duration is adjusted here. Two effects, kept separate
 * because they scale differently:
 *
 *  - **A factor on the driving time.** A 15- or 30-seat minibus accelerates and
 *    brakes more slowly, corners more slowly, and cruises below the car flow.
 *    Urban studies generally put bus running speed at 75–85% of car speed on
 *    the same corridor; 1.25 sits at the conservative end of that.
 *  - **Dwell time per intermediate stop**, which does NOT scale with distance.
 *    Loading a wedding party at a pickup point costs the same five minutes
 *    whether the next leg is two kilometres or twenty.
 *
 * **Both numbers are estimates and should be calibrated.** Once real trips are
 * logged, compare planned against actual and move them in `config/booking.php`
 * — which is why they are config and not constants here.
 */
final class BusTravelTime
{
    /**
     * @param  int  $carSeconds        Google's `driving` duration.
     * @param  int  $intermediateStops Pickup points between origin and destination.
     */
    public static function fromCarSeconds(int $carSeconds, int $intermediateStops = 0): int
    {
        if ($carSeconds <= 0) {
            return 0;
        }

        $factor = (float) config('booking.bus_travel.duration_factor', 1.25);
        $dwell  = (int) config('booking.bus_travel.stop_dwell_minutes', 5);

        return (int) round($carSeconds * $factor) + max(0, $intermediateStops) * $dwell * 60;
    }
}
