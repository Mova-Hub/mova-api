<?php

namespace App\Domain\Analytics;

use App\Models\Client;
use Illuminate\Support\Facades\Log;
use PostHog\PostHog;
use Throwable;

/**
 * Server-side product events.
 *
 * A deliberately small surface, for the events the CLIENT CANNOT SEE. The app
 * knows when someone starts a booking and where they abandon it; only the
 * server knows that ops converted that order three days later and that the
 * money eventually arrived. Sending those from here is what lets one funnel
 * span both halves — "of the people who started a booking, how many were
 * ultimately paid for" is otherwise two disconnected numbers.
 *
 * **This is not the audit log, and must not become it.** The audit trail is the
 * authoritative record, retained, queryable and legally meaningful. This is
 * aggregate product behaviour in a third party's store, sampled and eventually
 * expired. When in doubt about which one an event belongs in, it is the audit
 * log — see App\Domain\Audit.
 *
 * Every call is wrapped: analytics must never break the transaction it is
 * describing.
 */
class ProductAnalytics
{
    private static bool $booted = false;

    /** Inert without a key, which is the default in development. */
    public static function enabled(): bool
    {
        return ! empty(config('services.posthog.key'));
    }

    private static function boot(): void
    {
        if (self::$booted || ! self::enabled()) {
            return;
        }

        PostHog::init(config('services.posthog.key'), [
            'host' => config('services.posthog.host'),
        ]);

        self::$booted = true;
    }

    /**
     * Records an event against a client.
     *
     * The distinct id is the client's integer id, matching what the mobile app
     * sends to `identify()`. If those two ever disagree, the client-side and
     * server-side halves of a funnel become two different people and the whole
     * exercise is worthless.
     *
     * @param  array<string, string|int|float|bool|null>  $properties
     */
    public static function capture(Client|int|null $client, string $event, array $properties = []): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            self::boot();

            $id = $client instanceof Client ? $client->getKey() : $client;

            PostHog::capture([
                // A null actor still gets recorded, against a system id, so
                // scheduled conversions do not silently vanish from the funnel.
                'distinctId' => (string) ($id ?? 'system'),
                'event' => $event,
                'properties' => $properties + ['source' => 'api'],
            ]);
        } catch (Throwable $e) {
            // Swallowed on purpose — see the class docblock.
            Log::warning('Analytics capture failed', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /* The events. Named here so they cannot drift across call sites. */

    public static function orderSubmitted(Client $client, int $vehicles, int $passengers, ?int $total): void
    {
        self::capture($client, 'order_submitted', [
            'vehicles' => $vehicles,
            'passengers' => $passengers,
            // The amount, never the itinerary. A price is a business metric; an
            // address is somebody's home.
            'quoted_total' => $total,
        ]);
    }

    public static function orderConverted(Client $client, int $orderId): void
    {
        self::capture($client, 'order_converted', ['order_id' => $orderId]);
    }

    public static function paymentSucceeded(Client $client, string $provider, int $amount): void
    {
        self::capture($client, 'payment_succeeded', ['provider' => $provider, 'amount' => $amount]);
    }

    public static function passActivated(Client $client, string $planCode): void
    {
        self::capture($client, 'pass_activated', ['plan' => $planCode]);
    }
}
