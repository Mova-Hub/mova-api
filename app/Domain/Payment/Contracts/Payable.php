<?php

namespace App\Domain\Payment\Contracts;

use App\Models\Client;

/**
 * Something money can be collected for.
 *
 * Implemented by Order, PassSubscription and Reservation. This interface is the
 * entire reason one payment flow serves a charter booking and a Pass
 * subscription without a branch anywhere: PaymentService never asks what it is
 * collecting for, only what it costs and whether it may be collected yet.
 *
 * The alternative — a foreign key to `orders` — is what the schema had, and it
 * meant a subscription could never be paid for at all without a second payment
 * system. Two payment systems is how a codebase ends up with three ledgers that
 * disagree about revenue.
 */
interface Payable
{
    /**
     * What is owed, in whole francs.
     *
     * **Server-derived, always.** Never a value that has been through a
     * client — a request carrying its own `amount` is a client naming its own
     * price. Returns 0 when nothing is owed yet, which the service treats as
     * not-yet-payable rather than free.
     */
    public function paymentAmount(): int;

    public function paymentCurrency(): string;

    /** Shown to the payer, and sent to the provider where it supports one. */
    public function paymentDescription(): string;

    /**
     * Whether collection may start.
     *
     * Deliberately not "does it have a price". A pending charter request has
     * not been checked for vehicle availability, and taking money for a trip
     * that may not be dispatchable creates a refund instead of a booking.
     */
    public function isPayable(): bool;

    /** The account to credit or notify. Null for walk-in back-office work. */
    public function paymentClient(): ?Client;

    /**
     * Called once, when a payment for this reaches `succeeded`.
     *
     * Where an order flips to paid, or a subscription activates. Runs inside
     * the same transaction as the status change, so a subscription cannot be
     * activated by a payment that then fails to save.
     */
    public function onPaymentSucceeded(\App\Models\Payment $payment): void;
}
