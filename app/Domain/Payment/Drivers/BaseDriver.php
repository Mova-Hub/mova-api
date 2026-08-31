<?php

namespace App\Domain\Payment\Drivers;

use App\Domain\Payment\Contracts\PaymentDriver;
use App\Domain\Payment\DTOs\ChargeResult;
use App\Domain\Payment\DTOs\DriverCapabilities;
use App\Domain\Payment\DTOs\HealthResult;
use App\Models\Payment;
use App\Models\PaymentProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * What every driver shares.
 *
 * Only the genuinely common parts: binding a configuration row, resolving the
 * base URL for the current mode, and a pre-configured HTTP client with the
 * timeouts a person holding a phone can tolerate.
 *
 * Deliberately NOT here: anything that varies between providers. A base class
 * that grows an `if ($this->provider->code === …)` has become the `match`
 * statement the registry exists to replace.
 */
abstract class BaseDriver implements PaymentDriver
{
    protected PaymentProvider $provider;

    public function using(PaymentProvider $provider): static
    {
        $clone = clone $this;
        $clone->provider = $provider;

        return $clone;
    }

    /** The driver key, for looking up endpoints in config/payment.php. */
    abstract protected function key(): string;

    /**
     * Base URL for this provider's current mode.
     *
     * From config, not from the database: an operator changing a merchant id
     * must not be able to point the integration at an arbitrary host.
     */
    protected function baseUrl(): string
    {
        $mode = $this->provider->mode === 'live' ? 'live' : 'test';

        return rtrim((string) config("payment.endpoints.{$this->key()}.{$mode}", ''), '/');
    }

    /** One credential from the encrypted bag. */
    protected function credential(string $key, ?string $default = null): ?string
    {
        $value = ($this->provider->credentials ?? [])[$key] ?? $default;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    protected function http(): PendingRequest
    {
        return Http::timeout((int) config('payment.http_timeout', 20))
            ->connectTimeout((int) config('payment.http_connect_timeout', 8))
            ->acceptJson()
            ->asJson();
    }

    /* ── Defaults a minimal driver can inherit ────────────────────── */

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities();
    }

    public function refund(Payment $payment, int $amount): ChargeResult
    {
        return ChargeResult::failed('Ce moyen de paiement ne gère pas les remboursements automatiques.');
    }

    /**
     * Refuses by default, and that direction matters.
     *
     * A driver that returns true unless it knows better turns its webhook route
     * into an endpoint anyone on the internet can use to mark an order paid.
     * Verification is opt-in, per driver, with the provider's own scheme.
     */
    public function verifyCallback(array $payload, array $headers, string $rawBody = ''): bool
    {
        return false;
    }

    public function referenceFromCallback(array $payload): ?string
    {
        return null;
    }

    public function resultFromCallback(array $payload): ChargeResult
    {
        return ChargeResult::failed('Callback non reconnu.');
    }

    public function healthCheck(array $credentials): HealthResult
    {
        return HealthResult::ok('Aucun test disponible pour ce moyen de paiement.');
    }

    /**
     * Normalises a Congolese number to what the operators expect.
     *
     * MTN and Airtel both want a national MSISDN without `+` and without the
     * country code — `066123456`, not `+242066123456`. Sending E.164 is the
     * single most common reason a first integration returns "payee not found".
     */
    protected function msisdn(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        // Strip the CEMAC country code if the app sent E.164.
        if (str_starts_with($digits, '242')) {
            $digits = substr($digits, 3);
        }

        return $digits;
    }
}
