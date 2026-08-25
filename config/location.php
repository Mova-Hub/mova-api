<?php

use App\Domain\Audit\Drivers\IpInfoSecure;
use App\Domain\Audit\Support\NetworkPosition;
use Stevebauman\Location\Drivers\MaxMind;

return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | The default driver you would like to use for location retrieval.
    |
    */

    /*
     * ipinfo.io, over HTTPS.
     *
     * Works with no setup at all — ipinfo answers unauthenticated requests at a
     * low daily rate, so the map is live as soon as this deploys. Set
     * IPINFO_TOKEN for a real quota.
     *
     * The trade-off, stated because it is a real one: every lookup posts the IP
     * address of a staff member or a customer to a third party. Results are
     * cached for a week (see RequestFingerprint) so it is one disclosure per
     * address rather than one per page view, but it is still a disclosure.
     *
     * **To remove that entirely, switch this line to `MaxMind::class`**, which
     * reads a LOCAL GeoLite2 file and never makes a network call:
     *
     *     # free account at maxmind.com
     *     MAXMIND_LICENSE_KEY=...
     *     php artisan location:update      # re-run monthly
     *
     * The rest of this file is already configured for it; nothing else changes.
     */
    'driver' => IpInfoSecure::class,

    /*
    |--------------------------------------------------------------------------
    | Driver Fallbacks
    |--------------------------------------------------------------------------
    |
    | The drivers you want to use to retrieve the user's location
    | if the above selected driver is unavailable.
    |
    | These will be called upon in order (first to last).
    |
    */

    /*
     * EMPTY, deliberately.
     *
     * The shipped default falls back through four HTTP services. That would
     * silently reintroduce exactly what the local database exists to avoid: a
     * missing .mmdb file would start posting IPs to ipinfo.io with nobody
     * having chosen it. A failed lookup should fail visibly instead.
     */
    'fallbacks' => [],

    /*
    |--------------------------------------------------------------------------
    | Position
    |--------------------------------------------------------------------------
    |
    | Here you may configure the position instance that is created
    | and returned from the above drivers. The instance you
    | create must extend the built-in Position class.
    |
    */

    'position' => NetworkPosition::class,

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Options
    |--------------------------------------------------------------------------
    |
    | Here you may configure the options used by the underlying
    | Laravel HTTP client. This will be used in drivers that
    | request info via HTTP requests through API services.
    |
    */

    'http' => [
        'timeout' => 3,
        'connect_timeout' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Localhost Testing
    |--------------------------------------------------------------------------
    |
    | If your running your website locally and want to test different
    | IP addresses to see location detection, set 'enabled' to true.
    |
    | The testing IP address is a Google host in the United-States.
    |
    */

    'testing' => [
        'ip' => '66.102.0.0',
        /*
         * OFF, and the package's default of TRUE is a hazard here.
         *
         * When enabled, any private or loopback IP is swapped for a Google
         * address in the United States. On an analytics page that is a harmless
         * convenience; on an AUDIT page it fabricates evidence — a local admin's
         * action would render as having come from the US, on a map, beside
         * their name.
         */
        'enabled' => env('LOCATION_TESTING', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | MaxMind Configuration
    |--------------------------------------------------------------------------
    |
    | If web service is enabled, you must fill in your user ID and license key.
    |
    | If web service is disabled, it will try and retrieve the user's location
    | from the MaxMind database file located in the local path below.
    |
    | The MaxMind database file can be either City (default) or Country (smaller).
    |
    */

    'maxmind' => [
        'license_key' => env('MAXMIND_LICENSE_KEY'),

        'web' => [
            'enabled' => false,
            'user_id' => env('MAXMIND_USER_ID'),
            'locales' => ['en'],
            'options' => ['host' => 'geoip.maxmind.com'],
        ],

        'local' => [
            'type' => 'city',
            'path' => database_path('maxmind/GeoLite2-City.mmdb'),
            'url' => sprintf('https://download.maxmind.com/app/geoip_download_by_token?edition_id=GeoLite2-City&license_key=%s&suffix=tar.gz', env('MAXMIND_LICENSE_KEY')),
        ],
    ],

    'ip_api' => [
        'token' => env('IP_API_TOKEN'),
    ],

    'ipinfo' => [
        'token' => env('IPINFO_TOKEN'),
    ],

    'ipdata' => [
        'token' => env('IPDATA_TOKEN'),
    ],

    'ip2locationio' => [
        'token' => env('IP2LOCATIONIO_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Kloudend ~ ipapi.co Configuration
    |--------------------------------------------------------------------------
    |
    | The configuration for the Kloudend driver.
    |
    */

    'kloudend' => [

        'token' => env('KLOUDEND_TOKEN'),

    ],

];
