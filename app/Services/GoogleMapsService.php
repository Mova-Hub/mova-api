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

    /**
     * Driving route through an ordered list of points.
     *
     * Returns the road-following geometry, the real driven distance, and the
     * typical duration — or null when Google cannot route it (no road link,
     * quota exhausted, key misconfigured). Null rather than an exception:
     * a missing route degrades to a straight line on the map and to the
     * client's own distance estimate for pricing, neither of which is fatal.
     *
     * @param  array<int, array{lat: float, lng: float}>  $points  origin … destination, in travel order
     * @return array{distance_m: int, duration_s: int, polyline: string}|null
     */
    public function directions(array $points): ?array
    {
        $points = array_values(array_filter(
            $points,
            fn ($p) => isset($p['lat'], $p['lng']) && is_numeric($p['lat']) && is_numeric($p['lng'])
        ));

        if (count($points) < 2) {
            return null;
        }

        // Google caps a request at 25 waypoints on top of origin + destination.
        $origin      = array_shift($points);
        $destination = array_pop($points);
        $waypoints   = array_slice($points, 0, 23);

        $format = fn (array $p) => round((float) $p['lat'], 5) . ',' . round((float) $p['lng'], 5);

        $query = [
            'origin'      => $format($origin),
            'destination' => $format($destination),
            'mode'        => 'driving',
            'language'    => 'fr',
            'region'      => 'cg',
            'key'         => $this->apiKey,
        ];

        if ($waypoints !== []) {
            $query['waypoints'] = implode('|', array_map($format, $waypoints));
        }

        // Keyed on the rounded coordinates, so GPS jitter of a few metres still
        // hits the cache instead of billing another Directions call. Road
        // networks do not change hourly; 24h is generous and cheap.
        $cacheKey = 'maps_directions_' . md5(json_encode($query));

        return Cache::remember($cacheKey, 86400, function () use ($query) {
            try {
                $response = Http::timeout(8)->get("{$this->baseUrl}/directions/json", $query);
            } catch (\Throwable $e) {
                Log::warning('Directions request failed', ['error' => $e->getMessage()]);
                return null;
            }

            $json = $response->json();

            if (($json['status'] ?? null) !== 'OK' || empty($json['routes'][0])) {
                Log::info('Directions returned no route', ['status' => $json['status'] ?? 'unknown']);
                return null;
            }

            $route = $json['routes'][0];

            $distance = 0;
            $duration = 0;
            foreach ($route['legs'] ?? [] as $leg) {
                $distance += (int) ($leg['distance']['value'] ?? 0);
                $duration += (int) ($leg['duration']['value'] ?? 0);
            }

            return [
                'distance_m' => $distance,
                'duration_s' => $duration,
                'polyline'   => $route['overview_polyline']['points'] ?? '',
            ];
        });
    }
}
