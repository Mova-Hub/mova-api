<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Support\NetworkPosition;
use DeviceDetector\DeviceDetector;
use DeviceDetector\Parser\Device\AbstractDeviceParser;
use Illuminate\Support\Facades\Cache;
use Stevebauman\Location\Drivers\MaxMind;
use Stevebauman\Location\Facades\Location;
use Throwable;

/**
 * Turns the two forensic fields on an audit entry into something readable.
 *
 * `user_agent` and `ip` are stored raw and never interpreted at write time —
 * an audit record must keep exactly what arrived. This service interprets them
 * at READ time, which means a better parser next year improves every historical
 * entry instead of only new ones.
 *
 * Both halves come from real libraries rather than hand-rolled regex:
 *
 *  - **matomo/device-detector** for the user agent. The same parser Matomo
 *    runs on billions of hits; it knows browser, engine, OS, device type,
 *    brand, model and bots, and it is updated as new devices ship.
 *  - **stevebauman/location** for the IP, through ipinfo.io over HTTPS. Results
 *    are cached for a week, so a given address is disclosed to ipinfo once
 *    rather than once per page view. Switching `config('location.driver')` to
 *    `MaxMind::class` moves the lookup to a local database file and removes the
 *    outbound call altogether — see the note in `config/location.php`.
 *
 * Neither result is treated as fact. A user agent is a client-supplied header
 * and is trivially forged; an IP locates a network, not a person. The response
 * carries that framing so the back-office cannot present either as evidence.
 */
class RequestFingerprint
{
    public function __construct()
    {
        // Full brand/model names ("Samsung SM-G991B") rather than short codes.
        // Costs a slightly larger in-memory list and makes the output legible.
        AbstractDeviceParser::setVersionTruncation(AbstractDeviceParser::VERSION_TRUNCATION_MINOR);
    }

    /**
     * Browser, OS and hardware, from the user agent.
     *
     * @return array<string, mixed>
     */
    public function device(?string $userAgent): array
    {
        if (! $userAgent || trim($userAgent) === '') {
            // No user agent at all: a queued job, an artisan command, or a
            // direct API call. Worth naming — "no browser" is itself a fact
            // about how the action happened.
            return [
                'known' => false,
                'kind' => 'server',
                'client' => 'Sans navigateur',
                'platform' => 'Serveur ou tâche planifiée',
            ];
        }

        /*
         * Memoised per request, in memory — NOT through the cache store.
         *
         * DeviceDetector matches several hundred regexes, so repeat parsing
         * matters; but a fifty-row audit page comes from a handful of distinct
         * browsers, and routing that through Laravel's cache would trade fifty
         * fast in-process parses for fifty cache round-trips. On the database
         * cache driver this project uses, that is fifty queries to avoid a few
         * milliseconds of CPU.
         */
        return self::$parsed[$userAgent] ??= $this->parseUserAgent($userAgent);
    }

    /** @var array<string, array<string, mixed>> */
    private static array $parsed = [];

    /**
     * @return array<string, mixed>
     */
    private function parseUserAgent(string $userAgent): array
    {
        try {
            $detector = new DeviceDetector($userAgent);
            $detector->parse();

            if ($detector->isBot()) {
                $bot = $detector->getBot();

                return [
                    'known' => true,
                    'kind' => 'bot',
                    'client' => $bot['name'] ?? 'Robot',
                    'platform' => $bot['category'] ?? 'Robot d’indexation',
                    'bot' => true,
                ];
            }

            $client = $detector->getClient();
            $os = $detector->getOs();

            $clientName = is_array($client) ? ($client['name'] ?? null) : null;
            $clientVersion = is_array($client) ? ($client['version'] ?? null) : null;
            $osName = is_array($os) ? ($os['name'] ?? null) : null;
            $osVersion = is_array($os) ? ($os['version'] ?? null) : null;

            $brand = $detector->getBrandName();
            $model = $detector->getModel();

            return [
                'known' => $clientName !== null || $osName !== null,
                'kind' => $this->kindFor($detector),
                'client' => trim(($clientName ?? 'Client inconnu') . ' ' . ($clientVersion ?? '')),
                'client_engine' => is_array($client) ? ($client['engine'] ?? null) : null,
                'client_type' => is_array($client) ? ($client['type'] ?? null) : null,
                'platform' => trim(($osName ?? 'Plateforme inconnue') . ' ' . ($osVersion ?? '')),
                'os_name' => $osName,
                'os_version' => $osVersion,
                'device_type' => $detector->getDeviceName() ?: null,
                'brand' => $brand ?: null,
                'model' => $model ?: null,
                'bot' => false,
            ];
        } catch (Throwable) {
            // A parser failure must not take down the audit page. The raw
            // string is still stored and still shown.
            return [
                'known' => false,
                'kind' => 'unknown',
                'client' => 'Non reconnu',
                'platform' => 'Inconnue',
            ];
        }
    }

    private function kindFor(DeviceDetector $detector): string
    {
        return match ($detector->getDeviceName()) {
            'smartphone', 'phablet', 'feature phone' => 'mobile',
            'tablet' => 'tablet',
            'desktop' => 'desktop',
            'television', 'car browser', 'console', 'wearable' => 'other',
            default => $detector->isMobile() ? 'mobile' : 'unknown',
        };
    }

    /**
     * Approximate origin, from the IP.
     *
     * **Network-level, never a position.** An IP resolves to whoever announces
     * the address block; on MTN Congo or Airtel Congo that is a carrier gateway
     * which may be in another city or country from the person holding the
     * phone, and a VPN moves it anywhere at all.
     *
     * @return array<string, mixed>
     */
    public function location(?string $ip): array
    {
        if (! $ip) {
            return ['resolvable' => false, 'reason' => 'no_ip'];
        }

        // Loopback and RFC1918. Geolocating a private address is meaningless
        // rather than merely imprecise — it designates no public place.
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['resolvable' => false, 'reason' => 'private_ip', 'ip' => $ip];
        }

        return Cache::remember(
            'ipgeo:' . md5($ip),
            now()->addWeek(),
            fn () => $this->lookup($ip),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function lookup(string $ip): array
    {
        try {
            $position = Location::get($ip);

            if (! $position || ! $position->latitude || ! $position->longitude) {
                return [
                    'resolvable' => false,
                    // ipinfo answers for most public addresses but not all —
                    // some ranges genuinely carry no coordinates, which is a
                    // lookup that succeeded and had nothing to say.
                    'reason' => $this->notConfigured() ? 'not_configured' : 'lookup_failed',
                    'ip' => $ip,
                ];
            }

            return [
                'resolvable' => true,
                'ip' => $ip,
                'latitude' => (float) $position->latitude,
                'longitude' => (float) $position->longitude,
                'city' => $position->cityName ?: null,
                'region' => $position->regionName ?: null,
                'country' => $position->countryName ?: null,
                'country_code' => $position->countryCode ?: null,
                'postal_code' => $position->postalCode ?: null,
                'timezone' => $position->timezone ?: null,
                // The carrier, when the driver supplies one. Often the most
                // informative field on the response: "AS37559 MTN CONGO S.A"
                // explains why the city is unreliable better than the city does.
                'organisation' => $position instanceof NetworkPosition
                    ? $position->organisation
                    : null,
                /*
                 * City-level resolution, expressed as a radius.
                 *
                 * Accuracy is tens of kilometres at best and much worse on
                 * mobile carrier ranges, which is most of Mova's traffic. The
                 * client draws this as a circle so nobody reads the centre
                 * point as a location.
                 */
                'accuracy_km' => 50,
                'is_approximate' => true,
                'provider' => class_basename(config('location.driver')),
            ];
        } catch (Throwable) {
            return [
                'resolvable' => false,
                'reason' => $this->notConfigured() ? 'not_configured' : 'lookup_failed',
                'ip' => $ip,
            ];
        }
    }

    /**
     * Distinguishes "never set up" from "set up and could not answer".
     *
     * Only the MaxMind driver has a setup step that can be missing — its local
     * `.mmdb` file. ipinfo.io needs nothing to start working, so a failure
     * there is a genuine lookup failure (rate limit, network, unknown address)
     * and must not be reported to the operator as "configure a provider", which
     * would send them looking for a setting that does not exist.
     */
    private function notConfigured(): bool
    {
        if (config('location.driver') !== MaxMind::class) {
            return false;
        }

        $path = config('location.maxmind.local.path');

        return ! is_string($path) || ! file_exists($path);
    }
}
