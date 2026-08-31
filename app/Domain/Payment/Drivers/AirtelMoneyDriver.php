<?php

namespace App\Domain\Payment\Drivers;

use App\Domain\Payment\DTOs\ChargeResult;
use App\Domain\Payment\DTOs\DriverCapabilities;
use App\Domain\Payment\DTOs\HealthResult;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Airtel Money — Collections.
 *
 * Docs: https://developers.airtel.africa/
 *
 * Shaped like MTN's — request, prompt on the handset, asynchronous outcome —
 * but different in three ways that matter:
 *
 *  1. **OAuth2 client credentials**, not basic auth plus a subscription key.
 *  2. **`X-Country` and `X-Currency` headers on every call.** Omit them and
 *     Airtel routes the request at the wrong country's wallet base.
 *  3. **Status vocabulary is its own.** `TS` is success, `TF` is failure, and
 *     the transaction status lives at a different depth than the HTTP status.
 *
 * Some Airtel flows require an **RSA-encrypted PIN block** against Airtel's
 * public key. Mova's collection flow does not — the customer approves on their
 * own handset — and `encryptPin()` exists only so a later flow that needs it
 * has one correct implementation instead of three guesses.
 *
 * @see MOVA-WALLET-AND-PAYMENTS.md §4.2 and §4.3 for onboarding.
 */
class AirtelMoneyDriver extends BaseDriver
{
    protected function key(): string
    {
        return 'airtel_money';
    }

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            collect: true,
            refund: true,
            statusPoll: true,
            webhook: true,
        );
    }

    public function charge(Payment $payment): ChargeResult
    {
        $token = $this->token();

        if (! $token) {
            return ChargeResult::failed('Paiement Airtel indisponible. Réessayez dans un instant.');
        }

        $response = $this->http()
            ->withToken($token)
            ->withHeaders($this->countryHeaders())
            ->post($this->baseUrl() . '/merchant/v1/payments/', [
                'reference' => $this->trim($payment->payable?->paymentDescription() ?? 'Mova'),
                'subscriber' => [
                    'country' => $this->country(),
                    'currency' => $this->currency(),
                    'msisdn' => $this->msisdn($payment->payer_phone),
                ],
                'transaction' => [
                    'amount' => $payment->amount,
                    'country' => $this->country(),
                    'currency' => $this->currency(),
                    // Our key, so Airtel's record and ours share an id.
                    'id' => $payment->idempotency_key,
                ],
            ]);

        $body = $response->json() ?? [];
        $success = (bool) data_get($body, 'status.success', false);

        if ($response->successful() && $success) {
            return ChargeResult::processing(
                data_get($body, 'data.transaction.id') ?? $payment->idempotency_key,
                'Confirmez la demande sur votre téléphone.',
            );
        }

        return ChargeResult::failed(
            $this->codeMessage((string) data_get($body, 'status.code', '')),
            ['http_status' => $response->status(), 'body' => $body],
        );
    }

    public function status(Payment $payment): ChargeResult
    {
        $token = $this->token();
        $reference = $payment->provider_reference ?: $payment->idempotency_key;

        if (! $token) {
            return new ChargeResult($payment->status, $reference);
        }

        $response = $this->http()
            ->withToken($token)
            ->withHeaders($this->countryHeaders())
            ->get($this->baseUrl() . '/standard/v1/payments/' . $reference);

        if (! $response->successful()) {
            // Unknown is not failed — see the same note in MtnMomoDriver.
            return new ChargeResult($payment->status, $reference);
        }

        return $this->fromAirtelStatus($response->json() ?? [], $reference);
    }

    public function refund(Payment $payment, int $amount): ChargeResult
    {
        $token = $this->token();

        if (! $token) {
            return ChargeResult::failed('Remboursement Airtel indisponible pour le moment.');
        }

        $response = $this->http()
            ->withToken($token)
            ->withHeaders($this->countryHeaders())
            ->post($this->baseUrl() . '/standard/v1/payments/refund', [
                'transaction' => ['airtel_money_id' => $payment->provider_reference],
            ]);

        $body = $response->json() ?? [];

        if ($response->successful() && data_get($body, 'status.success')) {
            return new ChargeResult(
                PaymentStatus::Succeeded,
                data_get($body, 'data.transaction.airtel_money_id'),
                null,
                ['airtel' => $body],
            );
        }

        return ChargeResult::failed(
            'Le remboursement Airtel a échoué. Traitez-le manuellement.',
            ['body' => $body],
        );
    }

    /**
     * Airtel signs callbacks with an HMAC over the raw body.
     *
     * Compared with `hash_equals` — a plain `===` on a signature is timing-
     * attackable, and while the practical risk here is small the correct
     * comparison costs nothing.
     */
    public function verifyCallback(array $payload, array $headers, string $rawBody = ''): bool
    {
        $secret = $this->credential('webhook_secret');

        if (! $secret) {
            /*
             * No secret configured means we cannot tell a real callback from a
             * forged one, so we refuse. The payment still settles — status
             * polling picks it up within the reconcile window. A slower correct
             * answer beats a fast forgeable one.
             */
            return false;
        }

        $signature = $headers['x-auth-token'][0]
            ?? $headers['x-signature'][0]
            ?? '';

        $expected = hash_hmac('sha256', json_encode($payload), $secret);

        return $signature !== '' && hash_equals($expected, $signature);
    }

    public function referenceFromCallback(array $payload): ?string
    {
        return data_get($payload, 'transaction.id')
            ?? data_get($payload, 'transaction.airtel_money_id');
    }

    public function resultFromCallback(array $payload): ChargeResult
    {
        $reference = $this->referenceFromCallback($payload);
        $status = strtoupper((string) data_get($payload, 'transaction.status_code', ''));

        return $this->mapStatusCode($status, $reference, $payload);
    }

    public function healthCheck(array $credentials): HealthResult
    {
        $started = microtime(true);

        try {
            $response = $this->http()->post($this->baseUrl() . '/auth/oauth2/token', [
                'client_id' => $credentials['client_id'] ?? '',
                'client_secret' => $credentials['client_secret'] ?? '',
                'grant_type' => 'client_credentials',
            ]);
        } catch (Throwable $e) {
            return HealthResult::fail('Impossible de joindre Airtel : ' . $e->getMessage());
        }

        $latency = (int) round((microtime(true) - $started) * 1000);

        if ($response->successful() && $response->json('access_token')) {
            return HealthResult::ok('Jeton Airtel obtenu.', $latency);
        }

        return HealthResult::fail(
            $response->status() === 401
                ? 'Identifiants refusés : vérifiez le Client ID et le Client Secret.'
                : "Airtel a répondu {$response->status()}."
        );
    }

    /* ─────────────────────────── Internals ─────────────────────────── */

    private function fromAirtelStatus(array $body, ?string $reference): ChargeResult
    {
        $code = strtoupper((string) data_get($body, 'data.transaction.status', ''));

        return $this->mapStatusCode($code, $reference, $body);
    }

    /**
     * Airtel's two-letter transaction codes.
     *
     * `TS` transaction successful, `TF` transaction failed, `TA` ambiguous,
     * `TIP` in progress. `TA` is the interesting one: Airtel itself does not
     * know the outcome, so it stays `processing` and reconciliation asks again
     * rather than guessing in either direction.
     */
    private function mapStatusCode(string $code, ?string $reference, array $body): ChargeResult
    {
        return match ($code) {
            'TS' => new ChargeResult(PaymentStatus::Succeeded, $reference, null, ['airtel' => $body]),
            'TF' => ChargeResult::failed(
                'Le paiement Airtel n’a pas abouti. Vérifiez votre solde et réessayez.',
                ['airtel' => $body],
            ),
            default => ChargeResult::processing($reference),
        };
    }

    private function codeMessage(string $code): string
    {
        return match (strtoupper($code)) {
            'ROUTER.001', 'ESB000008' => 'Numéro Airtel Money invalide.',
            'ESB000011' => 'Solde Airtel Money insuffisant.',
            'ESB000034' => 'Vous avez atteint le plafond de votre compte Airtel.',
            'ESB000014' => 'Ce numéro n’a pas de compte Airtel Money actif.',
            default => 'Le paiement Airtel n’a pas pu démarrer. Réessayez dans un instant.',
        };
    }

    private function token(): ?string
    {
        $cacheKey = 'airtel:token:' . $this->provider->id;

        try {
            return Cache::remember($cacheKey, 3300, function () {
                $response = $this->http()->post($this->baseUrl() . '/auth/oauth2/token', [
                    'client_id' => $this->credential('client_id'),
                    'client_secret' => $this->credential('client_secret'),
                    'grant_type' => 'client_credentials',
                ]);

                if (! $response->successful()) {
                    // Never cache a failure — see MtnMomoDriver::token().
                    throw new \RuntimeException('Airtel token request failed: ' . $response->status());
                }

                return $response->json('access_token');
            });
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @return array<string, string> */
    private function countryHeaders(): array
    {
        return [
            'X-Country' => $this->country(),
            'X-Currency' => $this->currency(),
        ];
    }

    private function country(): string
    {
        return $this->credential('country', 'CG') ?? 'CG';
    }

    private function currency(): string
    {
        return $this->credential('currency', 'XAF') ?? 'XAF';
    }

    /**
     * RSA-encrypts a PIN against Airtel's public key.
     *
     * Unused by the collection flow, which is the point: it is here so that a
     * flow that later needs it has one correct implementation rather than a
     * fresh guess. PKCS#1 v1.5 with base64 output is what Airtel documents.
     *
     * A PIN passing through this method is never logged and never persisted —
     * PaymentService::safeFields() strips anything PIN-shaped before `meta`.
     */
    protected function encryptPin(string $pin): ?string
    {
        $publicKey = $this->credential('public_key');

        if (! $publicKey) {
            return null;
        }

        $encrypted = '';
        $key = openssl_pkey_get_public($publicKey);

        if (! $key || ! openssl_public_encrypt($pin, $encrypted, $key, OPENSSL_PKCS1_PADDING)) {
            return null;
        }

        return base64_encode($encrypted);
    }

    private function trim(string $text, int $max = 60): string
    {
        return mb_substr($text, 0, $max);
    }
}
