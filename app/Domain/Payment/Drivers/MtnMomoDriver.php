<?php

namespace App\Domain\Payment\Drivers;

use App\Domain\Payment\DTOs\ChargeResult;
use App\Domain\Payment\DTOs\DriverCapabilities;
use App\Domain\Payment\DTOs\HealthResult;
use App\Models\Payment;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * MTN MoMo — Collections.
 *
 * Docs: https://momodeveloper.mtn.com/
 *
 * Two things about this API are worth knowing before reading the code.
 *
 * **Authentication is two layers.** An `Ocp-Apim-Subscription-Key` header
 * identifies the *product subscription*, and a bearer token from `/token/`
 * identifies the *API user*. Both are required on every call; supplying only
 * one returns a 401 that says nothing about which.
 *
 * **`requestToPay` returns 202 and nothing else.** No status, no id in the
 * body. The outcome arrives later by callback, or by polling with the
 * `X-Reference-Id` we generated. Which is why `ChargeResult` is not a boolean:
 * the honest answer here is "the customer is being prompted on their phone".
 *
 * @see MOVA-WALLET-AND-PAYMENTS.md §4.1 and §4.3 for onboarding.
 */
class MtnMomoDriver extends BaseDriver
{
    protected function key(): string
    {
        return 'mtn_momo';
    }

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            collect: true,
            // MoMo refunds live in the Disbursements product, a separate
            // subscription. Reported false until that is contracted, so the
            // back-office offers a manual refund rather than a button that 404s.
            refund: false,
            statusPoll: true,
            webhook: true,
        );
    }

    public function charge(Payment $payment): ChargeResult
    {
        $token = $this->token();

        if (! $token) {
            return ChargeResult::failed('Paiement MTN indisponible. Réessayez dans un instant.');
        }

        $response = $this->http()
            ->withToken($token)
            ->withHeaders([
                // OUR uuid, and the reason a retried HTTP request cannot
                // become a second debit. MTN treats it as the transaction id.
                'X-Reference-Id' => $payment->idempotency_key,
                'X-Target-Environment' => $this->targetEnvironment(),
                'Ocp-Apim-Subscription-Key' => $this->credential('subscription_key'),
                'X-Callback-Url' => $this->callbackUrl(),
            ])
            ->post($this->baseUrl() . '/collection/v1_0/requesttopay', [
                'amount' => (string) $payment->amount,
                'currency' => $this->currency(),
                'externalId' => (string) $payment->id,
                'payer' => [
                    'partyIdType' => 'MSISDN',
                    'partyId' => $this->msisdn($payment->payer_phone),
                ],
                'payerMessage' => $this->trim($payment->payable?->paymentDescription() ?? 'Mova'),
                'payeeNote' => 'Mova ' . $payment->uuid,
            ]);

        if ($response->status() === 202) {
            return ChargeResult::processing(
                $payment->idempotency_key,
                'Confirmez la demande sur votre téléphone.',
            );
        }

        return ChargeResult::failed(
            $this->clientMessage($response->status(), $response->json('code')),
            // The provider's own words go to `meta` and to the log, never to
            // the client. "PAYER_NOT_FOUND" is not French and not actionable.
            ['http_status' => $response->status(), 'body' => $response->json() ?? $response->body()],
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
            ->withHeaders([
                'X-Target-Environment' => $this->targetEnvironment(),
                'Ocp-Apim-Subscription-Key' => $this->credential('subscription_key'),
            ])
            ->get($this->baseUrl() . '/collection/v1_0/requesttopay/' . $reference);

        if (! $response->successful()) {
            // Unknown is not failed. A 500 from MTN says nothing about whether
            // the customer paid, and treating it as a failure would release the
            // in-flight lock and invite a second debit.
            return new ChargeResult($payment->status, $reference);
        }

        return $this->fromMtnStatus($response->json(), $reference);
    }

    /**
     * MTN's callback carries the same body as the status endpoint.
     *
     * Signature verification is not offered on the Collections callback, so the
     * defence is different in kind: the callback is only ever *a hint to go and
     * check*. `PaymentWebhookController` re-reads status from the API before
     * applying anything, so a forged callback achieves nothing beyond an extra
     * outbound request.
     */
    public function verifyCallback(array $payload, array $headers, string $rawBody = ''): bool
    {
        return isset($payload['externalId']) || isset($payload['financialTransactionId']);
    }

    public function referenceFromCallback(array $payload): ?string
    {
        return $payload['referenceId']
            ?? $payload['externalId']
            ?? $payload['financialTransactionId']
            ?? null;
    }

    public function resultFromCallback(array $payload): ChargeResult
    {
        return $this->fromMtnStatus($payload, $this->referenceFromCallback($payload));
    }

    public function healthCheck(array $credentials): HealthResult
    {
        $started = microtime(true);

        try {
            $response = $this->http()
                ->withBasicAuth($credentials['api_user'] ?? '', $credentials['api_key'] ?? '')
                ->withHeaders([
                    'Ocp-Apim-Subscription-Key' => $credentials['subscription_key'] ?? '',
                ])
                ->post($this->baseUrl() . '/collection/token/');
        } catch (Throwable $e) {
            return HealthResult::fail('Impossible de joindre MTN : ' . $e->getMessage());
        }

        $latency = (int) round((microtime(true) - $started) * 1000);

        // Named per credential, because an operator pasting four values needs
        // to know which one is wrong — that is the whole value of this button.
        return match (true) {
            $response->successful() => HealthResult::ok('Jeton MTN obtenu.', $latency),
            $response->status() === 401 => HealthResult::fail(
                'Identifiants refusés : vérifiez l’API User et l’API Key.'
            ),
            $response->status() === 403 => HealthResult::fail(
                'Clé d’abonnement refusée : vérifiez la Subscription Key du produit Collections.'
            ),
            default => HealthResult::fail("MTN a répondu {$response->status()}."),
        };
    }

    /* ─────────────────────────── Internals ─────────────────────────── */

    private function fromMtnStatus(?array $body, ?string $reference): ChargeResult
    {
        $status = strtoupper((string) ($body['status'] ?? ''));
        $reason = is_array($body['reason'] ?? null)
            ? ($body['reason']['code'] ?? null)
            : ($body['reason'] ?? null);

        return match ($status) {
            'SUCCESSFUL' => new ChargeResult(
                \App\Domain\Payment\Enums\PaymentStatus::Succeeded,
                $body['financialTransactionId'] ?? $reference,
                null,
                ['mtn' => $body],
            ),
            'FAILED', 'REJECTED', 'TIMEOUT' => ChargeResult::failed(
                $this->reasonMessage((string) $reason),
                ['mtn' => $body],
            ),
            // PENDING, or anything unrecognised. Staying in `processing` is
            // the safe default: reconciliation will ask again, and the
            // expiry window ends it if MTN never answers.
            default => ChargeResult::processing($reference),
        };
    }

    /**
     * MTN reason codes, in French, for a client.
     *
     * Translated rather than passed through because "PAYER_LIMIT_REACHED" tells
     * a client nothing, while "vous avez atteint le plafond de votre compte"
     * tells them exactly what to do next.
     */
    private function reasonMessage(string $code): string
    {
        return match (strtoupper($code)) {
            'PAYER_NOT_FOUND' => 'Ce numéro n’a pas de compte MTN Mobile Money.',
            'NOT_ENOUGH_FUNDS' => 'Solde MTN Mobile Money insuffisant.',
            'PAYER_LIMIT_REACHED' => 'Vous avez atteint le plafond de votre compte MTN.',
            'APPROVAL_REJECTED' => 'Vous avez refusé la demande de paiement.',
            'EXPIRED' => 'La demande a expiré avant confirmation.',
            'PAYEE_NOT_ALLOWED_TO_RECEIVE' => 'Paiement refusé par MTN. Contactez notre équipe.',
            'INTERNAL_PROCESSING_ERROR' => 'MTN a rencontré une erreur. Réessayez dans un instant.',
            default => 'Le paiement MTN n’a pas abouti. Vous pouvez réessayer.',
        };
    }

    /**
     * A refusal, in words the reader can act on.
     *
     * `400` used to say, flatly, "Numéro MTN invalide." — which in the sandbox
     * is simply untrue and cost real debugging time. MoMo's sandbox accepts
     * only ITS OWN test MSISDNs, so a perfectly good Congolese number is
     * rejected there by design; telling an operator their number is malformed
     * sends them off to check a number that was never the problem.
     *
     * So the sandbox says what is actually happening, and production keeps the
     * short client-facing wording. MTN's own `code` is preferred over the HTTP
     * status when it sends one, because it is more specific than any guess
     * keyed on 400.
     */
    private function clientMessage(int $httpStatus, ?string $code = null): string
    {
        // MTN's own reason wins — it is the only thing here that knows WHY.
        if (is_string($code) && $code !== '') {
            return $this->reasonMessage($code);
        }

        $sandbox = $this->provider->mode !== 'live';

        return match ($httpStatus) {
            400 => $sandbox
                ? 'Numéro refusé par le bac à sable MTN. En mode test, seuls les numéros de test MTN '
                    . '(par ex. 46733123450) sont acceptés — un vrai numéro congolais est rejeté. '
                    . 'Passez le fournisseur en production pour encaisser de vrais paiements.'
                : 'Numéro MTN invalide.',
            409 => 'Une demande identique est déjà en cours.',
            401, 403 => 'Paiement MTN indisponible. Notre équipe est prévenue.',
            default => 'Le paiement MTN n’a pas pu démarrer. Réessayez dans un instant.',
        };
    }

    /**
     * The bearer token, cached to just under its lifetime.
     *
     * MoMo tokens last an hour and minting one costs a round trip on the
     * critical path of a customer waiting to pay. Cached 55 minutes: long
     * enough to matter, short enough that clock skew cannot serve an expired one.
     */
    private function token(): ?string
    {
        $cacheKey = 'momo:token:' . $this->provider->id;

        try {
            return Cache::remember($cacheKey, 3300, function () {
                $response = $this->http()
                    ->withBasicAuth(
                        (string) $this->credential('api_user'),
                        (string) $this->credential('api_key'),
                    )
                    ->withHeaders([
                        'Ocp-Apim-Subscription-Key' => $this->credential('subscription_key'),
                    ])
                    ->post($this->baseUrl() . '/collection/token/');

                if (! $response->successful()) {
                    // Throwing rather than caching null: a cached failure would
                    // keep the provider down for 55 minutes after a blip.
                    throw new \RuntimeException('MTN token request failed: ' . $response->status());
                }

                return $response->json('access_token');
            });
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function targetEnvironment(): string
    {
        return $this->credential('target_environment')
            ?? ($this->provider->mode === 'live' ? 'mtncongo' : 'sandbox');
    }

    private function currency(): string
    {
        // The sandbox only ever accepts EUR, whatever you send it. Getting this
        // wrong is the classic first-integration failure.
        return $this->provider->mode === 'live' ? 'XAF' : 'EUR';
    }

    private function callbackUrl(): string
    {
        return url('/api/webhooks/payments/' . $this->provider->code);
    }

    /** MoMo rejects long payer messages outright rather than truncating. */
    private function trim(string $text, int $max = 60): string
    {
        return mb_substr($text, 0, $max);
    }
}
