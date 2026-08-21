<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\GoogleMapsService;

class LocationController extends Controller
{
    public function __construct(
        protected GoogleMapsService $maps
    ) {}

    public function autocomplete(Request $request)
    {
        $request->validate(['q' => 'required|string|min:2|max:255']);
        return response()->json($this->maps->autocomplete($request->q));
    }

    public function details(string $placeId)
    {
        return response()->json($this->maps->placeDetails($placeId));
    }

    /**
     * Road-following route for the booking itinerary.
     *
     * POST, not GET: the waypoint list is structured data and would be an
     * unreadable query string. Sits behind the same Sanctum + throttle group as
     * the other proxies so the Maps key never leaves the server.
     *
     * A route Google cannot compute returns 200 with `route: null`, not an
     * error — the app falls back to straight segments, which is a degraded
     * picture rather than a broken screen.
     */
    public function directions(Request $request)
    {
        $data = $request->validate([
            'waypoints'         => 'required|array|min:2|max:25',
            'waypoints.*.lat'   => 'required|numeric|between:-90,90',
            'waypoints.*.lng'   => 'required|numeric|between:-180,180',
        ]);

        $route = $this->maps->directions($data['waypoints']);

        return response()->json([
            'status' => true,
            'route'  => $route,
        ]);
    }

    // NEW: For the MapPickerModal
    public function reverseGeocode(Request $request)
    {
        $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
        ]);

        return response()->json(
            $this->maps->reverseGeocode($request->lat, $request->lng)
        );
    }
}
