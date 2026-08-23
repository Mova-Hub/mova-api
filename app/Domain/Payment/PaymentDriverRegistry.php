<?php

namespace App\Domain\Payment;

use App\Domain\Payment\Contracts\PaymentDriver;
use App\Domain\Payment\Exceptions\PaymentException;
use App\Models\PaymentProvider;
use Illuminate\Support\Collection;

/**
 * Provider code → a configured driver.
 *
 * This class is what replaced `PaymentService::driverFor()`'s hardcoded
 * `match`, and the difference is the whole point of the rebuild: that match
 * meant every new provider was a code change in the service that owns the money
 * state machine — the last file that should be edited casually.
 *
 * Rows are cached for the request only. A provider disabled in Settings must
 * stop being offered on the next request, not in five minutes; and the cost
 * here is one indexed lookup, so a longer cache buys nothing worth the
 * staleness.
 */
class PaymentDriverRegistry
{
    /** @var array<string, PaymentProvider> */
    private array $providers = [];

    /**
     * The provider row for a code.
     *
     * @throws PaymentException when nothing is configured under that code
     */
    public function provider(string $code): PaymentProvider
    {
        if (isset($this->providers[$code])) {
            return $this->providers[$code];
        }

        $provider = PaymentProvider::where('code', $code)->first();

        if (! $provider) {
            throw new PaymentException("Moyen de paiement inconnu : {$code}.");
        }

        return $this->providers[$code] = $provider;
    }

    /**
     * A driver, bound to its configuration.
     *
     * @throws PaymentException when the driver key names no class
     */
    public function driver(string $code): PaymentDriver
    {
        $provider = $this->provider($code);

        $class = config("payment.drivers.{$provider->driver}");

        if (! $class || ! class_exists($class)) {
            /*
             * A row pointing at a driver that no longer exists — someone
             * renamed a key in config/payment.php, or deployed a config that
             * dropped a class. Loud, because the alternative is a payment
             * method that silently stops working.
             */
            throw new PaymentException(
                "Le pilote « {$provider->driver} » du moyen de paiement « {$code} » est introuvable."
            );
        }

        return app($class)->using($provider);
    }

    public function driverFor(PaymentProvider $provider): PaymentDriver
    {
        $this->providers[$provider->code] = $provider;

        return $this->driver($provider->code);
    }

    /**
     * Every provider that could take this payment.
     *
     * Filtered here rather than in the controller, so the mobile endpoint and
     * the back-office cannot drift on what "available" means.
     *
     * @return Collection<int, PaymentProvider>
     */
    public function available(int $amount, string $currency = 'XAF', ?string $country = null): Collection
    {
        return PaymentProvider::enabled()
            ->ordered()
            ->get()
            ->filter(fn (PaymentProvider $p) => $p->accepts($amount, $currency))
            ->filter(function (PaymentProvider $p) use ($country) {
                if (! $country) {
                    return true;
                }
                // An empty country list means "anywhere" rather than "nowhere":
                // the common case is one country, and requiring the column to
                // be filled would hide every provider the day it is added.
                $countries = $p->countries ?: [];

                return $countries === [] || in_array($country, $countries, true);
            })
            ->values();
    }
}
