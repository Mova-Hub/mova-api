<?php

namespace App\Http\Controllers;

use App\Http\Requests\QuoteRequest as QuoteHttpRequest;
use App\Domain\Pricing\DTOs\QuoteRequest;
use App\Domain\Pricing\Services\PricingEngine;
use App\Models\Bus;
use Carbon\Carbon;
use InvalidArgumentException;

class QuoteController extends Controller
{
    public function __construct(private PricingEngine $engine) {}

    public function __invoke(QuoteHttpRequest $req)
    {
        // Priority: bus_ids[] > vehicles_map{} > vehicles[] > legacy vehicle_type+buses
        $vehicleTypes = [];

        if ($req->filled('bus_ids')) {
            $buses = Bus::query()
                ->whereIn('id', $req->input('bus_ids', []))
                ->get(['id','type']);

            foreach ($buses as $bus) {
                if ($bus->type) {
                    $vehicleTypes[] = $bus->type;
                }
            }
        } elseif ($req->filled('vehicles_map')) {
            /** @var array<string,int> $map */
            $map = (array) $req->input('vehicles_map');
            foreach ($map as $type => $count) {
                $count = max(1, (int) $count);
                for ($i = 0; $i < $count; $i++) {
                    $vehicleTypes[] = (string) $type;
                }
            }
        } elseif ($req->filled('vehicles')) {
            $vehicleTypes = array_values((array) $req->input('vehicles'));
        }

        $dto = new QuoteRequest(
            vehicleType : $req->filled('vehicle_type') ? (string) $req->input('vehicle_type') : null,
            buses       : (int) $req->input('buses', 1),
            vehicleTypes: $vehicleTypes,
            /*
             * Doubled for a return leg, exactly as `TripQuoteService` does it
             * before calling this same engine — "a return leg is the same road
             * driven twice".
             *
             * Without this the back-office quoted a round trip at the one-way
             * price, so a reservation converted from a round-trip request was
             * billed at roughly half what the app had already quoted that same
             * customer.
             */
            distanceKm  : (float) $req->input('distance_km') * ($req->boolean('round_trip') ? 2 : 1),
            eventType   : (string) $req->input('event', 'none'),
            when        : $req->filled('when') ? Carbon::parse($req->input('when')) : null,
        );

        /*
         * An unpriceable vehicle is a 422, not a 500.
         *
         * `config('pricing.vehicles')` holds only `hiace` and `coaster`, while
         * a `Bus` may be any of seven types. Selecting a Sprinter in the
         * back-office therefore threw `InvalidArgumentException` out of the
         * engine and surfaced as a server error with no usable message — the
         * price simply never appeared and nothing said why.
         *
         * The type list is echoed back so the client can name the problem
         * instead of guessing, and without duplicating the config in the
         * browser where it could drift.
         */
        try {
            $quote = $this->engine->quote($dto);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [
                    'vehicles_map' => [$e->getMessage()],
                ],
                'priceable_vehicle_types' => array_keys(config('pricing.vehicles', [])),
            ], 422);
        }

        return response()->json($quote->asArray());
    }

}
