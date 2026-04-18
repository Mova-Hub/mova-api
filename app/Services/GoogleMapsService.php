<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GoogleMapsService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://maps.googleapis.com/maps/api';

    public function __construct()
    {
        $this->apiKey = env('GOOGLE_MAPS_API_KEY', '');
    }

    public function autocomplete(string $query)
    {
        // Cache autocomplete queries for 1 hour to save API costs
        $cacheKey = 'maps_autocomplete_' . md5($query);

        return Cache::remember($cacheKey, 3600, function () use ($query) {
            $response = Http::get("{$this->baseUrl}/place/autocomplete/json", [
                'input' => $query,
                'components' => 'country:cg', // Locked to Congo
                'language' => 'fr',
                'key' => $this->apiKey,
            ]);

            return $response->json();
        });
    }

    public function placeDetails(string $placeId)
    {
        // Cache place details (Lat/Lng rarely change) for 24 hours
        return Cache::remember('maps_details_' . $placeId, 86400, function () use ($placeId) {
            $response = Http::get("{$this->baseUrl}/place/details/json", [
                'place_id' => $placeId,
                'fields' => 'geometry',
                'key' => $this->apiKey,
            ]);

            return $response->json();
        });
    }

    public function reverseGeocode(float $lat, float $lng)
    {
        // Cache reverse geocoding for 24 hours
        $cacheKey = "maps_rev_geo_{$lat}_{$lng}";

        return Cache::remember($cacheKey, 86400, function () use ($lat, $lng) {
            $response = Http::get("{$this->baseUrl}/geocode/json", [
                'latlng' => "{$lat},{$lng}",
                'language' => 'fr',
                'key' => $this->apiKey,
            ]);

            return $response->json();
        });
    }
}
