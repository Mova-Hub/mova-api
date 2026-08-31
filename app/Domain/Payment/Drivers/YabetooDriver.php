<?php

namespace App\Domain\Payment\Drivers;

use App\Domain\Payment\DTOs\ChargeResult;
use App\Domain\Payment\DTOs\DriverCapabilities;
use App\Domain\Payment\DTOs\HealthResult;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Yabetoo Pay, an AGGREGATOR in front of MTN MoMo and Airtel Money.
 *
 * The first driver here that is not a single rail. MTN's driver talks to MTN;
 * this one talks to Yabetoo, which then talks to whichever operator the
 * customer chose. That difference shows up in exactly one place, `operator()`
 * below, and is why `payment_providers.options` exists.
 *
 * **Two stage, unlike every other driver here.** Create a payment intent, then
 * confirm it with the customer's number and operator. Only the confirm step
 * pushes a PIN prompt to the handset, so both calls happen inside `charge()`:
 * a half created intent that was never confirmed is a row nobody will ever
 * settle, and the customer would see nothing at all.
 *
 * **Failures arrive as HTTP 200.** The confirm endpoint returns a body with
 * `status: "failed"` and a `failureMessage` rather than a 4xx. A driver written
 * on the usual assumption would read that as success and mark the payment paid.
 * Every response here is judged on its body, never on `successful()`.
 *
 * Amounts are whole francs. There is no smallest subunit conversion like
 * Stripe's cents, and `Payment::amount` is already whole XAF, so it passes
 * straight through.
 *
 * @see yabetoo.md for the full API reference, including its documented gaps.
 */
class YabetooDriver extends BaseDriver
{
    protected function key(): string
    {
        return 'yabetoo';
    }

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            collect: true,
            // Disbursements exist but need a partner account Yabetoo grants
            // separately, and it is not contracted. Reported false so the back
            // office offers a manual refund rather than a button that 404s.
            refund: false,
            statusPoll: true,
            webhook: true,
        );
    }

    /* ─────────────────────────── Collecting ─────────────────────────── */

    public function charge(Payment $payment): ChargeResult
    {
        $operator = $this->operator($payment);

        if (! $operator) {
            /*
             * No rail chosen. This is a programming error rather than a
             * customer one: the sheet presents the options and the API
             * validates the choice, so reaching here means something bypassed
             * both. Fails closed rather than guessing an operator, because
             * guessing wrong pushes a prompt to the wrong network and the
             * customer sees nothing at all.
             */
            return ChargeResult::failed('Choisissez MTN ou Airtel avant de payer.');
        }

        $intent = $this->createIntent($payment);

        if ($intent instanceof ChargeResult) {
            return $intent;
        }

        return $this->confirmIntent($payment, $intent, $operator);
    }

    /**
     * Stage one: create the intent.
     *
     * Returns the intent on success, or a finished ChargeResult when it failed,
     * so `charge()` reads as two sequential steps rather than nested error
     * handling.
     *
     * @return array<string, mixed>|ChargeResult
     */
    private function createIntent(Payment $payment): array|ChargeResult
    {
        try {
            $response = $this->http()
                ->withToken((string) $this->credential('secret_key'))
                ->post($this->baseUrl() . '/v1/payment-intents', [
                    'amount' => (int) $payment->amount,
                    // Lowercase, as the API documents it.
                    'currency' => strtolower($payment->currency ?: 'XAF'),
                    'description' => $this->trim($payment->payable?->paymentDescription() ?? 'Mova'),
                    /*
                     * OUR uuid travels with the intent and comes back on the
                     * webhook. Intents support metadata where disbursements do
                     * not, so this is what lets a callback find its payment
                     * without a lookup table.
                     */
                    'metadata' => [
                        'payment_uuid' => (string) $payment->uuid,
                        'idempotency_key' => (string) $payment->idempotency_key,
                    ],
                ]);
        } catch (Throwable $e) {
            report($e);

            return ChargeResult::failed('Paiement indisponible. Reessayez dans un instant.');
        }

        $id = $response->json('id');
        $secret = $response->json('clientSecret');

        if (! $response->successful() || ! $id || ! $secret) {
            Log::warning('Yabetoo intent creation failed', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return ChargeResult::failed(
                $this->clientMessage($response->status()),
                ['stage' => 'create', 'http_status' => $response->status()],
            );
        }

        return ['id' => $id, 'client_secret' => $secret];
    }

    /**
     * Stage two: confirm, which is what actually rings the customer's phone.
     *
     * @param  array{id: string, client_secret: string}  $intent
     */
    private function confirmIntent(Payment $payment, array $intent, string $operator): ChargeResult
    {
        try {
            $response = $this->http()
                ->withToken((string) $this->credential('secret_key'))
                ->post($this->baseUrl() . "/v1/payment-intents/{$intent['id']}/confirm", [
                    'client_secret' => $intent['client_secret'],
                    'payment_method_data' => [
                        'type' => 'momo',
                        'momo' => [
                            'country' => strtolower((string) ($this->credential('country') ?: 'cg')),
                            // International format. A bare local number is
                            // rejected outright, which is why this uses the
                            // E.164 helper rather than BaseDriver::msisdn().
                            'msisdn' => $this->e164($payment->payer_phone),
                            'operator_name' => $operator,
                        ],
                    ],
                ]);
        } catch (Throwable $e) {
            report($e);

            /*
             * The intent EXISTS at this point and may still be confirmed, so
             * this is not a clean failure. Reported as processing so
             * reconciliation polls it rather than releasing the in flight lock
             * and inviting a second charge.
             */
            return ChargeResult::processing(
                $intent['id'],
                'Paiement en cours de verification.',
            );
        }

        return $this->fromBody($response->json() ?? [], $intent['id'], $response->status());
    }

    public function status(Payment $payment): ChargeResult
    {
        $reference = $payment->provider_reference;

        if (! $reference) {
            return new ChargeResult($payment->status, null);
        }

        try {
            $response = $this->http()
                ->withToken((string) $this->credential('secret_key'))
                ->get($this->baseUrl() . "/v1/payment-intents/{$reference}");
        } catch (Throwable $e) {
            report($e);

            return new ChargeResult($payment->status, $reference);
        }

        if (! $response->successful()) {
            // Unknown is not failed. A 500 says nothing about whether the
            // customer paid, and treating it as failure would release the in
            // flight lock and invite a second debit.
            return new ChargeResult($payment->status, $reference);
        }

        return $this->fromBody($response->json() ?? [], $reference, $response->status());
    }

    /* ─────────────────────────── Callbacks ─────────────────────────── */

    /**
     * HMAC over `{timestamp}.{raw body}`, compared timing safe.
     *
     * **The RAW body, not a re-encode.** `json_encode($payload)` produces
     * different bytes than Yabetoo signed the moment key order, unicode
     * escaping or float formatting differs, and the signature would then fail
     * for reasons nobody could see. This is the reason `verifyCallback` takes a
     * raw body at all.
     */
    public function verifyCallback(array $payload, array $headers, string $rawBody = ''): bool
    {
        $secret = $this->credential('webhook_secret');

        if (! $secret || $rawBody === '') {
            /*
             * No secret, or no raw body to check it against, means we cannot
             * tell a real callback from a forged one. Refuse. The payment still
             * settles: status polling picks it up inside the reconcile window,
             * and a slower correct answer beats a fast forgeable one.
             */
            return false;
        }

        $signature = $this->header($headers, 'x-yabetoo-webhook-signature');
        $timestamp = $this->header($headers, 'x-yabetoo-webhook-timestamp');

        if ($signature === '' || $timestamp === '') {
            return false;
        }

        // The docs show `v1=<hex>`. A bare hex is accepted too, since that
        // format is documented once and never shown in a real delivery.
        $signature = str_starts_with($signature, 'v1=') ? substr($signature, 3) : $signature;

        /*
         * Replay window. Without it a signature stays valid for ever, so a
         * captured delivery could be replayed months later to re-settle a
         * refunded payment. Five minutes is generous for a webhook that retries
         * within seconds.
         */
        if (abs(time() - (int) $timestamp) > 300) {
            Log::warning('Yabetoo webhook timestamp outside the replay window', [
                'skew_seconds' => time() - (int) $timestamp,
            ]);

            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

        return hash_equals($expected, $signature);
    }

    public function referenceFromCallback(array $payload): ?string
    {
        // The delivery shape is documented only by example, so every field the
        // docs have shown for an intent id is tried before giving up.
        return $payload['data']['id']
            ?? $payload['data']['intentId']
            ?? $payload['intentId']
            ?? $payload['id']
            ?? null;
    }

    public function resultFromCallback(array $payload): ChargeResult
    {
        $body = $payload['data'] ?? $payload;

        return $this->fromBody(
            is_array($body) ? $body : [],
            $this->referenceFromCallback($payload),
            200,
        );
    }

    /* ─────────────────────────── Health ─────────────────────────── */

    public function healthCheck(array $credentials): HealthResult
    {
        $key = $credentials['secret_key'] ?? $this->credential('secret_key');

        if (! $key) {
            return HealthResult::fail('Cle secrete manquante.');
        }

        /*
         * Lists intents rather than reading the account.
         *
         * The account scoped endpoints need an accountId in the URL and have
         * been seen to 404 against a valid account. This one needs only the
         * key, which is exactly what is being tested.
         */
        $started = microtime(true);

        try {
            $response = $this->http()
                ->withToken($key)
                ->get($this->baseUrl() . '/v1/payment-intents');
        } catch (Throwable $e) {
            return HealthResult::fail('Impossible de joindre Yabetoo : ' . $e->getMessage());
        }

        $latency = (int) round((microtime(true) - $started) * 1000);

        return match (true) {
            $response->successful() => HealthResult::ok('Connexion Yabetoo etablie.', $latency),
            $response->status() === 401 => HealthResult::fail('Cle secrete refusee par Yabetoo.'),
            default => HealthResult::fail("Yabetoo a repondu {$response->status()}."),
        };
    }

    /* ─────────────────────────── Plumbing ─────────────────────────── */

    /**
     * Which rail the customer chose.
     *
     * Read from the payment's own recorded fields, never from the provider's
     * configuration: two customers paying through Yabetoo minutes apart may
     * have picked different operators, so this cannot be a property of the
     * provider row.
     *
     * Validated against the options actually configured, so a caller cannot
     * post an arbitrary operator string straight through to Yabetoo.
     */
    private function operator(Payment $payment): ?string
    {
        $chosen = $payment->meta['fields']['operator'] ?? null;

        if (! is_string($chosen) || $chosen === '') {
            return null;
        }

        $chosen = strtolower($chosen);

        return $this->provider->hasOption($chosen) ? $chosen : null;
    }

    /**
     * One response body to one ChargeResult.
     *
     * Shared by confirm, status and the webhook, because all three return the
     * same shape and three copies of this mapping is three chances for them to
     * disagree about what `expired` means.
     *
     * @param  array<string, mixed>  $body
     */
    private function fromBody(array $body, ?string $fallbackReference, int $httpStatus): ChargeResult
    {
        $status = strtolower((string) ($body['status'] ?? ''));

        // Yabetoo's own id for the charge is the best reconciliation handle;
        // the intent id is the fallback the rest of the system already holds.
        $reference = $body['intentId'] ?? $body['id'] ?? $fallbackReference;

        $meta = array_filter([
            'financial_transaction_id' => $body['financialTransactionId'] ?? null,
            'payment_method_id' => $body['paymentMethodId'] ?? null,
            'failure_code' => $body['failureCode'] ?? null,
            'http_status' => $httpStatus,
        ], fn ($v) => $v !== null);

        return match ($status) {
            'succeeded' => new ChargeResult(PaymentStatus::Succeeded, $reference, null, $meta),

            'failed', 'expired', 'canceled', 'cancelled' => new ChargeResult(
                PaymentStatus::Failed,
                $reference,
                $this->failureMessage($body),
                $meta,
            ),

            /*
             * `pending` and `processing` both mean the prompt is live. Anything
             * unrecognised lands here too, deliberately: an unknown status is
             * not a failure, and treating it as one would fail a payment the
             * customer is in the middle of approving.
             */
            default => ChargeResult::processing(
                $reference,
                'Confirmez la demande sur votre telephone.',
            ),
        };
    }

    /**
     * A refusal in words the customer can act on.
     *
     * Yabetoo passes MTN's and Airtel's own codes through `failureCode`, so the
     * mapping is the operator vocabulary rather than anything Yabetoo invents.
     *
     * @param  array<string, mixed>  $body
     */
    private function failureMessage(array $body): string
    {
        $code = strtoupper((string) ($body['failureCode'] ?? ''));

        return match ($code) {
            'PAYER_NOT_FOUND', 'PAYEE_NOT_FOUND' => 'Ce numero n\'a pas de compte Mobile Money.',
            'NOT_ENOUGH_FUNDS' => 'Solde Mobile Money insuffisant.',
            'PAYER_LIMIT_REACHED' => 'Vous avez atteint le plafond de votre compte.',
            'APPROVAL_REJECTED' => 'Vous avez refuse la demande de paiement.',
            'EXPIRED', 'TIMEOUT' => 'La demande a expire avant confirmation.',
            'PAYEE_NOT_ALLOWED_TO_RECEIVE', 'NOT_ALLOWED' => 'Paiement refuse par l\'operateur. Contactez notre equipe.',
            'INTERNAL_PROCESSING_ERROR' => 'L\'operateur a rencontre une erreur. Reessayez dans un instant.',
            default => 'Le paiement n\'a pas abouti. Vous pouvez reessayer.',
        };
    }

    private function clientMessage(int $httpStatus): string
    {
        return match ($httpStatus) {
            401, 403 => 'Paiement indisponible. Notre equipe est prevenue.',
            422 => 'Paiement refuse. Verifiez le numero saisi.',
            default => 'Le paiement n\'a pas pu demarrer. Reessayez dans un instant.',
        };
    }

    /**
     * E.164 with the leading `+`, which is what Yabetoo documents.
     *
     * The opposite of `BaseDriver::msisdn()`, which strips the country code for
     * MTN's and Airtel's direct APIs. An aggregator and a direct integration
     * want different shapes of the same number, so neither can be the default.
     */
    private function e164(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        // A bare Congolese national number, which is what the app's phone field
        // holds before the country code is prepended.
        if (strlen($digits) === 9) {
            $digits = '242' . $digits;
        }

        return '+' . $digits;
    }

    /** Header lookup that survives Laravel's array of values shape. */
    private function header(array $headers, string $name): string
    {
        $value = $headers[$name] ?? $headers[strtolower($name)] ?? '';

        return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
    }

    /** Long descriptions are rejected rather than truncated by some operators. */
    private function trim(string $text, int $max = 60): string
    {
        return mb_substr($text, 0, $max);
    }
}
