<?php

namespace App\Http\Controllers\Api\V2\Payment;

use App\Domain\Payment\PaymentDriverRegistry;
use App\Domain\Payment\PaymentService;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Provider callbacks.
 *
 * The only unauthenticated route that can move money, so it is written
 * defensively throughout.
 *
 * **It always answers 200.** Providers retry non-2xx responses, often
 * aggressively, and a 500 caused by our own bug turns into a retry storm that
 * makes the bug harder to fix. Anything we cannot act on is recorded and
 * acknowledged; the reconciliation job is the safety net that catches whatever
 * was dropped.
 *
 * Three layers of defence, because signature verification alone is not enough
 * when one of the providers does not offer signatures:
 *
 *  1. **Signature**, per driver, refusing by default.
 *  2. **Re-read from the provider** where the driver can poll — the callback is
 *     treated as a hint to go and check, not as a statement of fact. This is
 *     what makes MTN safe despite having no signature on Collections.
 *  3. **Terminal-state guard** in PaymentService::apply(), so even an accepted
 *     forgery cannot flip a refunded payment back to paid.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(
        private PaymentService $payments,
        private PaymentDriverRegistry $registry,
    ) {}

    public function handle(Request $request, string $provider)
    {
        $payload = $request->all();

        try {
            $driver = $this->registry->driver($provider);
        } catch (Throwable $e) {
            Log::warning('Webhook for unknown provider', ['provider' => $provider]);

            return response()->json(['received' => true]);
        }

        if (! $driver->capabilities()->webhook) {
            // A route pointed at a driver with no callbacks. Refuse to act,
            // acknowledge anyway so nobody retries into it forever.
            Log::warning('Webhook for a driver that has none', ['provider' => $provider]);

            return response()->json(['received' => true]);
        }

        if (! $driver->verifyCallback($payload, $request->headers->all())) {
            /*
             * Logged at warning, not error: an unverified callback is a normal
             * event on a public endpoint, and paging on it would train people
             * to ignore the alert. The payload is recorded so a genuine
             * misconfiguration is diagnosable — and so is an attack.
             */
            Log::warning('Rejected payment webhook signature', [
                'provider' => $provider,
                'ip' => $request->ip(),
            ]);

            return response()->json(['received' => true]);
        }

        $reference = $driver->referenceFromCallback($payload);

        if (! $reference) {
            Log::warning('Payment webhook carried no reference', ['provider' => $provider]);

            return response()->json(['received' => true]);
        }

        /*
         * Matched on EITHER key. `provider_reference` is what the provider
         * knows once it has assigned one; `idempotency_key` is what we sent it
         * and is all it knows before that. A callback arriving between the two
         * would find nothing if only one were checked.
         */
        $payment = Payment::where('provider_code', $provider)
            ->where(fn ($q) => $q
                ->where('provider_reference', $reference)
                ->orWhere('idempotency_key', $reference))
            ->first();

        if (! $payment) {
            Log::warning('Payment webhook for an unknown payment', [
                'provider' => $provider,
                'reference' => $reference,
            ]);

            return response()->json(['received' => true]);
        }

        // The raw payload lands BEFORE anything is interpreted, so a callback
        // that then fails to process is still on record for a dispute.
        $payment->forceFill([
            'meta' => array_merge($payment->meta ?? [], [
                'callbacks' => array_merge($payment->meta['callbacks'] ?? [], [[
                    'at' => now()->toIso8601String(),
                    'payload' => $payload,
                ]]),
            ]),
        ])->saveQuietly();

        try {
            /*
             * Layer 2. Where the driver can poll, we ask the provider directly
             * rather than believing the body — which is what makes an
             * unsigned MTN callback harmless: at worst a forgery costs us one
             * outbound request and learns nothing.
             */
            $result = $driver->capabilities()->statusPoll
                ? $driver->status($payment)
                : $driver->resultFromCallback($payload);

            $this->payments->apply($payment, $result);
        } catch (Throwable $e) {
            report($e);
            // Reconciliation will pick it up. Still 200 — see the class note.
        }

        return response()->json(['received' => true]);
    }
}
