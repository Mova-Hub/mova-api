<?php

use App\Domain\Payment\Drivers\AirtelMoneyDriver;
use App\Domain\Payment\Drivers\CardDriver;
use App\Domain\Payment\Drivers\ManualPaymentDriver;
use App\Domain\Payment\Drivers\MovaCreditDriver;
use App\Domain\Payment\Drivers\MtnMomoDriver;
use App\Domain\Payment\Drivers\YabetooDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Driver map
    |--------------------------------------------------------------------------
    |
    | The ONLY place a provider needs code. A `payment_providers` row names one
    | of these keys in its `driver` column; the registry resolves it, injects
    | the row, and hands back a driver.
    |
    | Adding a provider is: a class implementing PaymentDriver, one line here,
    | one row in Settings → Paiement. If it ever needs a fourth step, the
    | abstraction has failed — fix it rather than working around it.
    |
    | Keys are stable identifiers stored in the database. Renaming one orphans
    | every row that points at it, so treat them as you would a column name.
    |
    */

    'drivers' => [
        'mtn_momo' => MtnMomoDriver::class,
        'airtel_money' => AirtelMoneyDriver::class,
        'mova_credit' => MovaCreditDriver::class,
        'manual' => ManualPaymentDriver::class,
        'card' => CardDriver::class,
        'yabetoo' => YabetooDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider endpoints
    |--------------------------------------------------------------------------
    |
    | Base URLs per driver and mode. Here rather than in the database because
    | they are a property of the provider's API, not of Mova's account with it
    | — an operator changing a merchant id should not be able to point the
    | integration at an arbitrary host.
    |
    */

    'endpoints' => [
        'mtn_momo' => [
            'test' => 'https://sandbox.momodeveloper.mtn.com',
            'live' => 'https://proxy.momoapi.mtn.com',
        ],
        'airtel_money' => [
            'test' => 'https://openapiuat.airtel.africa',
            'live' => 'https://openapi.airtel.africa',
        ],
        // Yabetoo aggregates MTN and Airtel behind one API. Note the sandbox
        // and live hosts differ in structure rather than by a subdomain swap.
        'yabetoo' => [
            'test' => 'https://pay.sandbox.yabetoopay.com',
            'live' => 'https://pay.api.yabetoopay.com',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Timing
    |--------------------------------------------------------------------------
    */

    // How long a mobile-money prompt stays valid. Past this, reconciliation
    // fails the attempt so the client can start a clean one rather than
    // watching "en cours" forever.
    'attempt_ttl_minutes' => 15,

    /*
    | How long a request that a PERSON settles stays open.
    |
    | Cash and bank transfer are not prompts. Nothing is waiting on a handset:
    | an agent collects the money and marks it paid, which may be tomorrow.
    | Running those on the 15 minute prompt clock expired every cash request
    | before ops could see it, and the back-office list filters on `processing`,
    | so the client was told a request had been sent and nobody ever received
    | one.
    |
    | Not unlimited, because an in-flight attempt blocks a new one: a request
    | nobody actions has to lapse eventually or the client is stuck with a
    | payment method they cannot change. Two days covers a weekend.
    */
    'manual_attempt_ttl_minutes' => 60 * 48,

    // Grace before the first status poll. Polling instantly only ever returns
    // "pending" and burns a rate-limit slot.
    'poll_after_seconds' => 120,

    // HTTP timeouts against a provider. Short: the client is holding a phone.
    'http_timeout' => 20,
    'http_connect_timeout' => 8,

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    */

    'webhooks' => [
        // Rejects a callback whose signature timestamp is older than this,
        // so a captured payload cannot be replayed days later.
        'tolerance_seconds' => 300,
    ],

];
