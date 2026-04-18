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
