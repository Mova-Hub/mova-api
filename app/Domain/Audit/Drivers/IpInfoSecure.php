<?php

namespace App\Domain\Audit\Drivers;

use App\Domain\Audit\Support\NetworkPosition;
use Illuminate\Support\Fluent;
use Locale;
use Stevebauman\Location\Drivers\IpInfo;
use Stevebauman\Location\Position;

/**
 * ipinfo.io, over HTTPS, keeping the fields the base driver discards.
 *
 * Three changes, each fixing something in `Stevebauman\Location\Drivers\IpInfo`:
 *
 *  1. **HTTPS.** The shipped driver builds `http://ipinfo.io/{ip}?token=...`.
 *     That puts the API token and the looked-up IP address on the wire in
 *     clear text, readable by anything between this server and ipinfo. For a
 *     lookup whose entire subject matter is somebody's IP address, plain HTTP
 *     is not a defensible default.
 *
 *  2. **`org` is kept.** The carrier — "AS37559 MTN CONGO S.A" — is frequently
 *     the most informative field on the response, and the base driver drops it.
 *
 *  3. **A readable country.** ipinfo returns a two-letter code; `intl` turns
 *     that into a name, so the page shows "Congo-Brazzaville" rather than "CG".
 */
class IpInfoSecure extends IpInfo
{
    public function url(string $ip): string
    {
        $url = "https://ipinfo.io/{$ip}";

        if ($token = config('location.ipinfo.token')) {
            $url .= '?token=' . $token;
        }

        return $url;
    }

    protected function hydrate(Position $position, Fluent $location): Position
    {
        $position = parent::hydrate($position, $location);

        if ($location->country) {
            $position->countryCode = $location->country;
            // `Locale::getDisplayRegion` wants a locale tag, hence the leading
            // underscore. Falls back to the raw code if intl cannot name it.
            $position->countryName =
                Locale::getDisplayRegion('-' . $location->country, 'fr') ?: $location->country;
        }

        if ($position instanceof NetworkPosition) {
            $position->organisation = $location->org ?: null;
        }

        return $position;
    }
}
