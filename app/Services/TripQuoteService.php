<?php

namespace App\Services;

use App\Domain\Booking\BusTravelTime;
use App\Domain\Pricing\DTOs\QuoteRequest;
use App\Domain\Pricing\Services\PricingEngine;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Pricing for the consumer app.
 *
 * Wraps PricingEngine with the three things the mobile flow needs and the
 * back-office quote endpoint does not:
 *
 *  1. **The distance is measured, not supplied.** A price the caller can move
 *     by editing one number in a request body is not a price. Distance comes
 *     from the Directions API over the submitted waypoints; the client's own
 *     estimate is only a fallback for when Google cannot route, and it is
 *     clamped before it is believed.
 *  2. **The response is client-safe.** PricingEngine also computes the platform
 *     commission and the operator payout. Those are Mova's margins and have no
 *     business leaving the server to a customer's phone, so this returns only
 *     what the customer is being asked to pay and why.
 *  3. **It is shared with order creation**, so the amount stored against an
 *     order is recomputed here rather than trusted from the app.
 */
class TripQuoteService
{
    /** Quotes are cached briefly: the same screen re-quotes on every edit. */
    private const CACHE_TTL_SECONDS = 600;

    /** Beyond this a "trip" is a data-entry error, not a charter. */
    private const MAX_DISTANCE_KM = 2000.0;

    public function __construct(
        private PricingEngine $engine,
        private GoogleMapsService $maps,
    ) {}

    /**
     * Road distance and duration for an ordered waypoint list.
     *
     * @param  array<int, array{lat: float, lng: float}>  $waypoints
     * @return array{distance_km: float, duration_minutes: int|null, measured: bool}
     */
    public function measure(array $waypoints, ?float $hintKm = null): array
    {
        $route = $this->maps->directions($waypoints);

        if ($route !== null && $route['distance_m'] > 0) {
            return [
                'distance_km' => round($route['distance_m'] / 1000, 1),
                // Google's duration is a car. This is a bus, and it stops to
                // pick people up — see App\Domain\Booking\BusTravelTime.
                'duration_minutes' => (int) round(
                    BusTravelTime::fromCarSeconds(
                        $route['duration_s'],
                        max(0, count($waypoints) - 2),
                    ) / 60
                ),
                'measured' => true,
            ];
        }

        // No route: fall back to the client's estimate, clamped. Better than
        // refusing to price a trip because one pickup point sits off-road.
        return [
            'distance_km'      => max(0.0, min(self::MAX_DISTANCE_KM, (float) ($hintKm ?? 0))),
            'duration_minutes' => null,
            'measured'         => false,
        ];
    }

    /**
     * Full quote for a trip.
     *
     * @param  array<int, array{lat: float, lng: float}>  $waypoints
     * @param  array<string, int>  $fleet  vehicle type => count
     * @return array<string, mixed>  client-safe payload
     *
     * @throws InvalidArgumentException on an unknown event or vehicle type
     */
    public function quote(
        array $waypoints,
        array $fleet,
        string $event = 'none',
        bool $roundTrip = false,
        ?float $hintKm = null,
    ): array {
        $signature = md5(json_encode([
            array_map(
                fn ($p) => [round((float) $p['lat'], 4), round((float) $p['lng'], 4)],
                array_values($waypoints)
            ),
            $this->normaliseFleet($fleet),
            $event,
            $roundTrip,
        ]));

        return Cache::remember(
            "quote.v2.{$signature}",
            self::CACHE_TTL_SECONDS,
            fn () => $this->compute($waypoints, $fleet, $event, $roundTrip, $hintKm)
        );
    }

    /**
     * @param  array<int, array{lat: float, lng: float}>  $waypoints
     * @param  array<string, int>  $fleet
     * @return array<string, mixed>
     */
    private function compute(
        array $waypoints,
        array $fleet,
        string $event,
        bool $roundTrip,
        ?float $hintKm,
    ): array {
        $measurement = $this->measure($waypoints, $hintKm);

        // A return leg is the same road driven twice, so it is billed twice.
        $billedKm = $roundTrip ? $measurement['distance_km'] * 2 : $measurement['distance_km'];

        $fleet = $this->normaliseFleet($fleet);

        // The engine takes a flat list of vehicle types; expand the map.
        $vehicleTypes = [];
        foreach ($fleet as $type => $count) {
            for ($i = 0; $i < $count; $i++) {
                $vehicleTypes[] = $type;
            }
        }

        if ($vehicleTypes === []) {
            throw new InvalidArgumentException('No vehicles provided.');
        }

        $result = $this->engine->quote(new QuoteRequest(
            vehicleTypes: $vehicleTypes,
            distanceKm:   $billedKm,
            eventType:    $event !== '' ? $event : 'none',
        ));

        $config = config('pricing');

        return [
            'currency'          => $config['currency'],
            'total'             => (float) $result->clientRounded,
            'distance_km'       => $measurement['distance_km'],
            'billed_distance_km'=> round($billedKm, 1),
            'duration_minutes'  => $measurement['duration_minutes'],
            'round_trip'        => $roundTrip,
            // False means the distance is the app's own estimate, so the app can
            // say "estimation" instead of implying a measured route.
            'measured'          => $measurement['measured'],
            'vehicles'          => array_map(
                fn ($type, $count) => [
                    'type'  => $type,
                    'count' => $count,
                    'label' => $config['vehicles'][$type]['label'] ?? $type,
                ],
                array_keys($fleet),
                array_values($fleet)
            ),
            // Only the three lines the customer is actually paying. Commission
            // and operator payout stay server-side — see the class docblock.
            'breakdown' => [
                'transport'        => round($result->base + $result->motivation, 2),
                'event_supplement' => round($result->event, 2),
                'service_fees'     => round($result->clientFees, 2),
            ],
        ];
    }

    /**
     * @param  array<string, int|string>  $fleet
     * @return array<string, int>
     */
    private function normaliseFleet(array $fleet): array
    {
        $known = array_keys(config('pricing.vehicles'));
        $clean = [];

        foreach ($fleet as $type => $count) {
            $type  = (string) $type;
            $count = (int) $count;

            if ($count > 0 && in_array($type, $known, true)) {
                $clean[$type] = min(50, $count);
            }
        }

        ksort($clean);

        return $clean;
    }
}
