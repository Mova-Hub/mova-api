<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\V2\QuoteRequest;
use App\Services\TripQuoteService;
use InvalidArgumentException;

/**
 * Quote endpoint for the mobile app.
 *
 * A second implementation rather than a change to App\Http\Controllers\
 * QuoteController on purpose: that one is wired into the back-office
 * reservation flow, which is live, and its response shape (commission, operator
 * payout, full per-vehicle allocation) is exactly what a customer-facing
 * endpoint must NOT return. Sharing it would have meant either leaking margins
 * to phones or reshaping a response the console depends on.
 *
 * What is shared is the thing that should be: App\Domain\Pricing\PricingEngine.
 * Both routes price a trip with the same arithmetic; only the inputs accepted
 * and the fields returned differ. See App\Services\TripQuoteService.
 */
class QuoteController extends Controller
{
    public function __construct(private TripQuoteService $quotes) {}

    public function __invoke(QuoteRequest $request)
    {
        $data = $request->validated();

        try {
            $quote = $this->quotes->quote(
                waypoints: $data['waypoints'],
                fleet:     $data['fleet'],
                event:     $data['event'] ?? 'none',
                roundTrip: (bool) ($data['round_trip'] ?? false),
                hintKm:    isset($data['distance_km_hint']) ? (float) $data['distance_km_hint'] : null,
            );
        } catch (InvalidArgumentException $e) {
            // The engine rejects unknown vehicle/event keys. The form request
            // already screens both, so reaching here means a config drift —
            // report it as a 422 rather than a 500 the app cannot explain.
            return response()->json([
                'status'  => false,
                'message' => 'Tarif indisponible pour cette configuration.',
            ], 422);
        }

        return response()->json([
            'status' => true,
            'data'   => $quote,
        ]);
    }
}
