<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GoogleMapsService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://maps.googleapis.com/maps/api';

    /** Statuses that represent a real answer, not a failure. */
    private const OK_STATUSES = ['OK', 'ZERO_RESULTS'];

    public function __construct()
    {
        /*
         * `config()`, NOT `env()`.
         *
         * This is a real bug, not a style preference. `php artisan config:cache`
         * is standard on any production deploy, and once the config is cached
         * Laravel stops loading `.env` at all — every `env()` call outside a
         * config file then returns its default. This constructor would have
         * silently taken `''` as the key, and every Places, geocode and
         * Directions call would come back REQUEST_DENIED with no exception to
         * notice: autocomplete returns no suggestions, routes fall back to
         * straight lines, and nothing in the logs says why.
         *
         * `config/services.php` already read the same variable into
         * `google.places_key`, so this was reading it the one way that breaks.
         */
        $this->apiKey = (string) config('services.google.places_key', '');
    }

    public function autocomplete(string $query)
    {
        return $this->cached('maps_autocomplete_' . md5($query), 3600, fn () => $this->get('/place/autocomplete/json', [
            'input' => $query,
            'components' => 'country:cg', // Locked to Congo
            'language' => 'fr',
        ]));
    }

    public function placeDetails(string $placeId)
    {
        // Lat/lng for a place does not change, so a day is conservative.
        return $this->cached('maps_details_' . $placeId, 86400, fn () => $this->get('/place/details/json', [
            'place_id' => $placeId,
            'fields' => 'geometry',
        ]));
    }

    public function reverseGeocode(float $lat, float $lng)
    {
        return $this->cached("maps_rev_geo_{$lat}_{$lng}", 86400, fn () => $this->get('/geocode/json', [
            'latlng' => "{$lat},{$lng}",
            'language' => 'fr',
        ]));
    }

    /**
     * Driving route through an ordered list of points.
     *
     * Returns the road-following geometry, the real driven distance, and the
     * typical duration — or null when Google cannot route it (no road link,
     * quota exhausted, key misconfigured). Null rather than an exception: a
     * missing route degrades to a straight line on the map and to the client's
     * own distance estimate for pricing, neither of which is fatal.
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
        ];

        if ($waypoints !== []) {
            $query['waypoints'] = implode('|', array_map($format, $waypoints));
        }

        // Keyed on the rounded coordinates, so GPS jitter of a few metres still
        // hits the cache instead of billing another Directions call. Road
        // networks do not change hourly; 24h is generous and cheap.
        $cacheKey = 'maps_directions_' . md5(json_encode($query));

        return $this->cached($cacheKey, 86400, function () use ($query) {
            $json = $this->get('/directions/json', $query);

            if (($json['status'] ?? null) !== 'OK' || empty($json['routes'][0])) {
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

    /**
     * One request, with the key attached and failures reported.
     *
     * Google answers a bad key with HTTP 200 and `status: REQUEST_DENIED`, so
     * nothing throws and nothing appears in the log unless it is looked for.
     * That is how a missing key turns into "autocomplete returns nothing" with
     * no trail — this logs it once, with the reason Google gave.
     *
     * @return array<string, mixed>|null
     */
    private function get(string $path, array $query): ?array
    {
        if ($this->apiKey === '') {
            Log::warning('Google Maps key is not configured', ['path' => $path]);
            return null;
        }

        try {
            $response = Http::timeout(8)->get($this->baseUrl . $path, $query + ['key' => $this->apiKey]);
        } catch (\Throwable $e) {
            Log::warning('Google Maps request failed', ['path' => $path, 'error' => $e->getMessage()]);
            return null;
        }

        $json = $response->json();

        if (! is_array($json)) {
            return null;
        }

        if (! in_array($json['status'] ?? '', self::OK_STATUSES, true)) {
            Log::warning('Google Maps returned an error', [
                'path' => $path,
                'status' => $json['status'] ?? 'unknown',
                // Google puts the actual reason here — "This API project is not
                // authorized", "The provided API key is expired", and so on.
                'error' => $json['error_message'] ?? null,
            ]);
        }

        return $json;
    }

    /**
     * Cache only what succeeded.
     *
     * `Cache::remember()` stores whatever the closure returns, INCLUDING null
     * and including Google's REQUEST_DENIED payload. That turns a momentary
     * failure — an unset key, a quota blip, a network hiccup — into 24 hours of
     * the same failure served from cache, so fixing the underlying problem
     * appears to change nothing and the next person goes looking in the wrong
     * place entirely.
     *
     * A failed lookup is therefore not cached at all: the next request retries.
     *
     * @template T
     * @param  callable(): T  $resolve
     * @return T|null
     */
    private function cached(string $key, int $seconds, callable $resolve)
    {
        $hit = Cache::get($key);
        if ($hit !== null) {
            return $hit;
        }

        $value = $resolve();

        if ($value === null) {
            return null;
        }

        // An array response still has to be a SUCCESSFUL one before it is kept.
        if (is_array($value) && isset($value['status'])
            && ! in_array($value['status'], self::OK_STATUSES, true)) {
            return $value;
        }

        Cache::put($key, $value, $seconds);

        return $value;
    }
}
