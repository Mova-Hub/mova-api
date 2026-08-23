<?php

namespace App\Domain\Payment\Contracts;

use App\Domain\Payment\DTOs\ChargeResult;
use App\Domain\Payment\DTOs\DriverCapabilities;
use App\Domain\Payment\DTOs\HealthResult;
use App\Models\Payment;
use App\Models\PaymentProvider;

/**
 * One payment provider.
 *
 * MTN and Airtel work the same way — request a collection, the customer
 * approves on their handset, a webhook lands some seconds or minutes later —
 * and the differences are entirely in request shape and status vocabulary.
 * Putting that boundary here means adding a provider is a class, and swapping
 * an aggregator for a direct integration does not touch a controller.
 *
 * **A driver NEVER writes to the Payment model.** It talks to the provider and
 * reports back; the service owns the record. That is what keeps "when may a
 * payment be marked paid" answerable by reading one file, rather than by
 * auditing every integration.
 *
 * A driver is constructed with its PaymentProvider row, so credentials, mode
 * and fees come from the database rather than from config — two MTN merchant
 * accounts are two rows and one class.
 *
 * @see MOVA-WALLET-AND-PAYMENTS.md §5.4 for the add-a-provider checklist.
 */
interface PaymentDriver
{
    /** Binds the driver to its configuration row. Called by the registry. */
    public function using(PaymentProvider $provider): static;

    /** What this driver supports. See DriverCapabilities for why it is asked. */
    public function capabilities(): DriverCapabilities;

    /**
     * Ask the provider to collect.
     *
     * Returns immediately. For mobile money the honest answer is almost always
     * `processing` — see ChargeResult for why that is not a boolean.
     */
    public function charge(Payment $payment): ChargeResult;

    /**
     * Ask the provider where a payment stands.
     *
     * Needed because webhooks get lost, and a client staring at "en cours" with
     * no way to refresh is how support tickets are made. Driven by
     * `payments:reconcile` for any driver reporting `statusPoll`.
     */
    public function status(Payment $payment): ChargeResult;

    /**
     * Send money back.
     *
     * Only called when `capabilities()->refund` is true. Partial amounts are
     * allowed; the service records the refund as a child payment rather than
     * mutating the original, so the original attempt stays auditable.
     */
    public function refund(Payment $payment, int $amount): ChargeResult;

    /**
     * Verifies a webhook actually came from the provider.
     *
     * Must return false when it cannot tell. A driver that returns true by
     * default turns its webhook route into an endpoint anyone can use to mark
     * an order paid.
     */
    public function verifyCallback(array $payload, array $headers): bool;

    /**
     * Reads the provider's identity out of a callback body.
     *
     * Every provider names its reference differently, and the webhook
     * controller must not know which. Returns null when the payload carries no
     * reference it recognises.
     */
    public function referenceFromCallback(array $payload): ?string;

    /** Turns a callback into the same shape a `status()` call would return. */
    public function resultFromCallback(array $payload): ChargeResult;

    /**
     * Credential check for Settings → "Tester".
     *
     * Takes the credentials as an argument rather than reading the bound row,
     * so an operator can test a key BEFORE saving it. Testing only what is
     * already stored means the broken value has to be persisted first.
     */
    public function healthCheck(array $credentials): HealthResult;
}
