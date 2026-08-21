<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Entitlement signing (Ed25519)
    |--------------------------------------------------------------------------
    |
    | PRD-MOVA-PASS.md §4.1 is a hard requirement, not a preference: signatures
    | MUST be asymmetric. Mova Control verifies cards offline, so whatever key
    | it holds is extractable from the APK. With HMAC that key also signs, and
    | anyone who decompiles the app can mint a valid entitlement with any expiry
    | they like. With Ed25519 the app holds only the public key, which is
    | useless for forgery.
    |
    | Two rules this config exists to enforce:
    |
    |  1. NEVER reuse APP_KEY. It already protects sessions and cookies; sharing
    |     one key across unrelated security domains means a leak anywhere is a
    |     leak everywhere, and makes rotation practically impossible.
    |  2. The private key lives ONLY here, on the server. It is never returned
    |     by any endpoint, never logged, and never reaches the back-office —
    |     the counter asks the API for a signature instead.
    |
    | Keys are a JSON map so several can coexist during a rotation. Every card
    | carries the id of the key that signed it, so issuing new cards under a new
    | key does not invalidate the ones already in circulation.
    |
    | Generate with: php artisan pass:generate-key
    |
    |   PASS_SIGNING_KEYS='{"1":{"public":"…","secret":"…"}}'
    |   PASS_ACTIVE_KEY_ID=1
    |
    */
    'signing' => [
        'active_key_id' => (string) env('PASS_ACTIVE_KEY_ID', '1'),

        'keys' => (function () {
            $raw = env('PASS_SIGNING_KEYS');
            if (! is_string($raw) || $raw === '') {
                return [];
            }

            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        })(),
    ],

    /*
    |--------------------------------------------------------------------------
    | Card payload
    |--------------------------------------------------------------------------
    |
    | The chip carries one NDEF URI record (§4.2):
    |
    |     https://mova.cg/p#<version>.<keyId>.<subscriberId>.<expiry>.<signature>
    |
    | Everything after `#` is a fragment, so it is never transmitted when an
    | ordinary phone taps the card — that tap just opens a page explaining what
    | the card is and where to return it.
    |
    | `version` gates the layout. A reader that meets a version it does not know
    | must refuse the card rather than guess at the field order.
    |
    */
    'payload' => [
        'version' => (string) env('PASS_PAYLOAD_VERSION', '1'),
        'base_url' => env('PASS_CARD_URL', 'https://mova.cg/p'),
        /** Versions this server will still decode. Extend, don't replace. */
        'supported_versions' => ['1'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cards
    |--------------------------------------------------------------------------
    */
    'cards' => [
        /*
         * Printed serial length, in Crockford-base32 characters.
         *
         * 12 characters is ~60 bits of entropy. That matters because the serial
         * is an ACTIVATION CREDENTIAL (PA-2): anyone who can guess one can try
         * to bind that card to their own account. Sequential or short serials
         * would make the fallback a card-theft oracle, so they are random and
         * the activation endpoint is rate-limited on top.
         */
        'serial_length' => 12,

        /** Activation attempts per client per minute. Guessing defence. */
        'activation_attempts_per_minute' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscriptions
    |--------------------------------------------------------------------------
    */
    'subscriptions' => [
        /*
         * Renewing early extends from the CURRENT expiry, not from today.
         *
         * The alternative silently deletes the unused remainder of a
         * subscription every time someone renews before it lapses, which
         * punishes exactly the customers who renew on time.
         */
        'extend_from_current_expiry' => true,

        /** Days before expiry that a subscription is reported as "expiring". */
        'expiring_soon_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Offline verification (Mova Control)
    |--------------------------------------------------------------------------
    */
    'control' => [
        /*
         * Hard block past this staleness (MC-9).
         *
         * A blacklist that is two days old is worse than no blacklist: it lets
         * a card reported stolen yesterday keep working while telling the
         * inspector it is fine.
         */
        'max_sync_age_hours' => 24,
    ],
];
