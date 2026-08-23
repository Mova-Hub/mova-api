<?php

namespace App\Domain\Documents;

use App\Domain\Settings\Facades\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Mova's marks on a generated document.
 *
 * Used by every PDF — invoice, quotation, receipt — so a brand change is one
 * edit rather than a hunt through Blade files.
 *
 * **The logo is a base64 data URI, not a URL.** dompdf is not a browser: with
 * `isRemoteEnabled` off it will not fetch anything, and with it on the PDF
 * silently loses its logo whenever the network is slow or the storage host is
 * unreachable — which is exactly when an invoice is most likely to be generated
 * from a queue worker. Embedding makes the file self-contained and identical
 * offline, which is also the property that lets it be emailed as an attachment.
 *
 * Cached: base64 of a 60 KB PNG on every invoice is pointless work, and the
 * file changes about once a year.
 */
class DocumentBranding
{
    private const CACHE_KEY = 'mova:branding:logo';
    private const CACHE_TTL = 86400;

    /** Mova green — the wordmark, and the rule under the header. */
    public const GREEN = '#4CAF50';

    /** Mova orange — the accent over the "o". Used sparingly. */
    public const ORANGE = '#F57C00';

    /** Deep green for text on light backgrounds; contrast-safe at 10pt. */
    public const INK = '#0F172A';

    public const MUTED = '#64748B';

    /**
     * The logo as `data:image/png;base64,…`, or null.
     *
     * Null is a supported outcome, not an error: the templates fall back to the
     * text wordmark, so a missing file produces a plain invoice rather than a
     * failed download at the moment a client asked for one.
     */
    public function logoDataUri(): ?string
    {
        if (! Settings::bool('billing.show_logo', true)) {
            return null;
        }

        /*
         * The cache is an OPTIMISATION, not a dependency.
         *
         * Wrapping the whole thing in one try/catch — which is what this did
         * first — means a cache outage silently strips the logo from every
         * invoice generated while it lasts. Reading a 60 KB file is cheap; the
         * cache only saves the base64 pass, so when it is unavailable we do the
         * work rather than degrade the document.
         */
        try {
            $cached = Cache::get(self::CACHE_KEY);

            if ($cached !== null) {
                return $cached ?: null;
            }
        } catch (Throwable) {
            // Fall through and read directly.
        }

        try {
            $bytes = $this->readLogo();
        } catch (Throwable) {
            // A branding problem must never be the reason a client cannot get
            // their invoice. The templates fall back to the text wordmark.
            return null;
        }

        $uri = $bytes ? 'data:image/png;base64,' . base64_encode($bytes) : null;

        try {
            // `?? false` so a genuine "no logo" is cached too — otherwise every
            // invoice re-reads a file that is not there.
            Cache::put(self::CACHE_KEY, $uri ?? false, self::CACHE_TTL);
        } catch (Throwable) {
            // Nothing to do; the value above is still correct.
        }

        return $uri;
    }

    /**
     * Reads the logo, preferring an operator-uploaded one.
     *
     * The bundled file is the floor: uploading a logo in Settings should not be
     * required for an invoice to look right on day one.
     */
    private function readLogo(): ?string
    {
        $uploaded = Settings::string('billing.logo_path', '');

        if ($uploaded !== '' && Storage::disk('public')->exists($uploaded)) {
            return Storage::disk('public')->get($uploaded);
        }

        $bundled = resource_path('branding/mova-logo.png');

        return is_readable($bundled) ? file_get_contents($bundled) : null;
    }

    /** @return array<string, string> Everything a document template needs. */
    public function forDocument(): array
    {
        return [
            'logo' => $this->logoDataUri(),
            'green' => self::GREEN,
            'orange' => self::ORANGE,
            'ink' => self::INK,
            'muted' => self::MUTED,
            'company' => Settings::string('general.company_name', 'Mova Mobility'),
            'legalName' => Settings::string('general.legal_name', 'Mova Mobility SARL'),
            'address' => Settings::string('general.address', 'Brazzaville, République du Congo'),
            'email' => Settings::string('general.support_email', 'contact@mova-mobility.com'),
            'phone' => Settings::string('general.support_phone', ''),
            'legalMentions' => Settings::string('billing.legal_mentions', ''),
            'footerNote' => Settings::string('billing.footer_note', 'Merci de votre confiance.'),
        ];
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
