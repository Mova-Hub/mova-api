<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Domain\Pricing\DTOs\QuoteRequest;
use App\Domain\Pricing\Services\PricingEngine;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The Algorithme tab's simulator.
 *
 * Runs the REAL PricingEngine over supplied inputs and returns the breakdown.
 * Read-only: it computes and returns, it never writes.
 *
 * Worth having even though the tab's parameters are not yet driving the engine
 * (see the banner on that page, and MOVA-WALLET-AND-PAYMENTS.md §6): the most
 * common pricing question is "what would a 40 km wedding with two Hiace cost?",
 * and today the only way to answer it is to submit a booking. This answers it
 * with the same code path that prices a real quote, so the number is the number.
 *
 * It also exposes the live parameters, so an operator can see what the engine
 * is actually using rather than what the settings table claims.
 */
class PricingSimulatorController extends Controller
{
    public function __construct(private PricingEngine $engine) {}

    public function simulate(Request $request)
    {
        $vehicleTypes = array_keys(config('pricing.vehicles', []));
        $eventTypes = array_keys(config('pricing.events', []));

        $data = $request->validate([
            'distance_km' => ['required', 'numeric', 'min:0', 'max:2000'],
            'event_type' => ['required', Rule::in($eventTypes)],
            'fleet' => ['required', 'array', 'min:1'],
            'fleet.*' => ['integer', 'min:1', 'max:50'],
            'round_trip' => ['nullable', 'boolean'],
        ]);

        foreach (array_keys($data['fleet']) as $type) {
            if (! in_array($type, $vehicleTypes, true)) {
                return response()->json([
                    'status' => false,
                    'message' => "Type de véhicule inconnu : {$type}.",
                ], 422);
            }
        }

        // Expanded to one entry per vehicle, which is the shape the engine
        // takes — `['hiace' => 2]` becomes `['hiace', 'hiace']`.
        $expanded = [];
        foreach ($data['fleet'] as $type => $count) {
            $expanded = array_merge($expanded, array_fill(0, (int) $count, $type));
        }

        $distance = (float) $data['distance_km'] * (($data['round_trip'] ?? false) ? 2 : 1);

        try {
            $result = $this->engine->quote(new QuoteRequest(
                vehicleTypes: $expanded,
                distanceKm: $distance,
                eventType: $data['event_type'],
            ));
        } catch (Throwable $e) {
            // Surfaced rather than 500'd: the simulator's whole purpose is to
            // show what the engine does, including when it refuses.
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => true,
            'data' => [
                'result' => $result->asArray(),
                'inputs' => [
                    'distance_km' => $distance,
                    'vehicles' => $expanded,
                    'event_type' => $data['event_type'],
                ],
            ],
        ]);
    }

    /**
     * The parameters the engine is ACTUALLY using.
     *
     * Read from config, which is what PricingEngine reads. The Algorithme tab
     * renders these as the live values next to whatever has been typed into
     * the settings form — so the gap between the two is visible rather than
     * something an operator discovers from a customer complaint.
     */
    public function parameters()
    {
        return response()->json([
            'status' => true,
            'data' => [
                'currency' => config('pricing.currency'),
                'vehicles' => config('pricing.vehicles'),
                'events' => config('pricing.events'),
                'min_distance_km' => config('pricing.min_distance_km'),
                'commission_percent' => config('pricing.commission_percent'),
                'mobile_money_client_percent' => config('pricing.mobile_money_client_percent'),
                'mobile_money_bus_percent' => config('pricing.mobile_money_bus_percent'),
                'rounding' => config('pricing.rounding'),
            ],
            'meta' => [
                /*
                 * Stated in the payload, not just in the UI.
                 *
                 * The Settings page can write pricing values, but PricingEngine
                 * still reads config/pricing.php. A page that silently
                 * half-drives the engine is worse than one that says which
                 * half — so the API says it too, and any future consumer
                 * inherits the warning rather than the misconception.
                 */
                'source' => 'config',
                'settings_driven' => false,
                'note' => 'Le moteur lit config/pricing.php. Les valeurs enregistrées dans les réglages ne sont pas encore appliquées.',
            ],
        ]);
    }
}
