<?php

namespace App\Domain\Payment;

use App\Domain\Payment\Contracts\PaymentDriver;
use App\Domain\Payment\DTOs\ChargeResult;
use App\Domain\Payment\Drivers\ManualPaymentDriver;
use App\Domain\Payment\Enums\PaymentProvider;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Collecting money for an order.
 *
 * The service owns the state machine; drivers only talk to providers. That
 * split is what keeps "when may a payment be marked paid" answerable by reading
 * one file, rather than by auditing every integration.
 *
 * Two rules that matter more than the rest:
 *
 *  1. **The amount comes from the order, never from the request.** A client
 *     posting `amount` would be naming their own price.
 *  2. **One live attempt at a time.** A mobile-money prompt sits on a handset
 *     for a minute or two; a client who taps "payer" again in that window must
 *     be shown the existing attempt, not debited a second time.
 */
class PaymentService
{
    /**
     * Resolves the driver for a provider.
     *
     * Everything currently maps to the manual driver — PRD decision D3, the
     * provider contract, is still open. When MTN or Airtel is signed, this
     * match arm is where it lands, and nothing else in this file changes.
     */
    public function driverFor(PaymentProvider $provider): PaymentDriver
    {
        return match ($provider) {
            PaymentProvider::MtnMomo,
            PaymentProvider::AirtelMoney,
            PaymentProvider::Cash => app(ManualPaymentDriver::class),
        };
    }

    /**
     * Starts a payment for an order.
     *
     * @throws RuntimeException when the order cannot be paid for
     */
    public function start(
        Order $order,
        Client $client,
        PaymentProvider $provider,
        ?string $payerPhone = null,
    ): Payment {
        return DB::transaction(function () use ($order, $client, $provider, $payerPhone) {
            // Locked for the duration so two taps cannot both pass the
            // "is anything in flight?" check below.
            $order = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            $amount = $this->amountFor($order);

            if ($amount <= 0) {
                throw new RuntimeException(
                    'Cette commande n’a pas encore de montant. Notre équipe vous transmet la facture.'
                );
            }

            if ($this->isPaid($order)) {
                throw new RuntimeException('Cette commande est déjà réglée.');
            }

            $existing = Payment::where('order_id', $order->id)->inFlight()->first();
            if ($existing) {
                // Not an error to the caller — the app shows the attempt that
                // is already running rather than starting a competing one.
                return $existing;
            }

            $payment = Payment::create([
                'order_id' => $order->id,
                'client_id' => $client->id,
                'provider' => $provider,
                'status' => PaymentStatus::Pending,
                // From the ORDER. See the class docblock.
                'amount' => $amount,
                'currency' => 'XAF',
                'payer_phone' => $provider->requiresPhone() ? $payerPhone : null,
            ]);

            $result = $this->driverFor($provider)->charge($payment);

            return $this->apply($payment, $result);
        });
    }

    /**
     * Records what a provider reported.
     *
     * Refuses to move a payment that has already reached a terminal state. A
     * late or duplicated webhook must never flip a refunded payment back to
     * paid, and providers do re-deliver.
     */
    public function apply(Payment $payment, ChargeResult $result): Payment
    {
        if ($payment->status->isFinal()) {
            return $payment;
        }

        $now = CarbonImmutable::now();

        $payment->status = $result->status;
        $payment->provider_reference = $result->reference ?? $payment->provider_reference;
        $payment->meta = array_merge($payment->meta ?? [], $result->meta);

        match ($result->status) {
            PaymentStatus::Processing => $payment->processing_at = $now,
            PaymentStatus::Succeeded => $payment->paid_at = $now,
            PaymentStatus::Failed, PaymentStatus::Cancelled => tap($payment, function ($p) use ($now, $result) {
                $p->failed_at = $now;
                $p->failure_reason = $result->message;
            }),
            default => null,
        };

        $payment->save();

        return $payment->fresh();
    }

    /**
     * What this order costs, in whole francs.
     *
     * The confirmed reservation price wins; before conversion it falls back to
     * what was quoted at submission. Both are server-set values — neither has
     * ever been through the client.
     */
    public function amountFor(Order $order): int
    {
        $reservation = $order->reservation;

        if ($reservation && $reservation->price_total > 0) {
            return (int) round((float) $reservation->price_total);
        }

        return (int) round((float) ($order->quoted_total ?? 0));
    }

    public function isPaid(Order $order): bool
    {
        return Payment::where('order_id', $order->id)->succeeded()->exists();
    }

    /**
     * Whether the client may pay yet.
     *
     * Deliberately NOT "as soon as an order exists". A pending request has not
     * been checked for vehicle availability, and taking money for a trip that
     * may not be dispatchable creates a refund instead of a booking.
     */
    public function isPayable(Order $order): bool
    {
        if ($this->amountFor($order) <= 0) {
            return false;
        }

        if ($this->isPaid($order)) {
            return false;
        }

        $reservationStatus = $order->reservation?->status;

        return in_array($order->status, ['contacted', 'converted'], true)
            || in_array($reservationStatus, ['confirmed', 'in_progress'], true);
    }
}
