<?php

namespace App\Domain\Payment\Contracts;

use App\Domain\Payment\DTOs\ChargeResult;
use App\Models\Payment;

/**
 * One mobile-money provider.
 *
 * The interface exists before any real integration on purpose. MTN and Airtel
 * both work the same way — request a collection, the customer approves on their
 * handset, a webhook lands some seconds or minutes later — and the differences
 * are entirely in request shape and status vocabulary. Putting that boundary in
 * now means adding a provider is a class, and swapping an aggregator for a
 * direct integration does not touch a controller.
 *
 * A driver NEVER writes to the Payment model. It talks to the provider and
 * reports back; the service owns the record. That keeps the state machine in
 * one place instead of spread across every integration.
 */
interface PaymentDriver
{
    /** Ask the provider to collect. Returns immediately — see ChargeResult. */
    public function charge(Payment $payment): ChargeResult;

    /**
     * Ask the provider where a payment stands.
     *
     * Needed because webhooks get lost, and a client staring at "en cours"
     * with no way to refresh is how support tickets are made.
     */
    public function status(Payment $payment): ChargeResult;

    /** Verifies a webhook actually came from the provider. */
    public function verifyCallback(array $payload, array $headers): bool;
}
