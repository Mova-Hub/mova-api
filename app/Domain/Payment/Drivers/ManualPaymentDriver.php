<?php

namespace App\Domain\Payment\Drivers;

use App\Domain\Payment\Contracts\PaymentDriver;
use App\Domain\Payment\DTOs\ChargeResult;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Str;

/**
 * The driver in use until a provider contract is signed (PRD decision D3).
 *
 * It records the client's INTENT to pay and hands the reference to the ops team,
 * who confirm the transfer by hand in the back-office. That is not a stub — it
 * is how the business already collects money today, and it is worth having in
 * the app because a client who has pressed "payer" and been given a reference
 * is materially closer to paying than one who has been told to phone.
 *
 * What it very deliberately does NOT do is report success. `Processing` is the
 * truth: nobody has been debited, and a driver that returned `Succeeded` here
 * would mark orders paid on nothing but a tap.
 */
class ManualPaymentDriver implements PaymentDriver
{
    public function charge(Payment $payment): ChargeResult
    {
        return ChargeResult::processing(
            'MOVA-' . strtoupper(Str::random(8)),
            'Notre équipe vous contacte pour finaliser le paiement.',
        );
    }

    public function status(Payment $payment): ChargeResult
    {
        // Whatever ops last set. There is no provider to ask.
        return new ChargeResult($payment->status, $payment->provider_reference);
    }

    /**
     * No callbacks exist for this driver, so nothing can be authentic.
     *
     * Returning false rather than true matters: if a webhook route is ever
     * pointed here by mistake, it refuses instead of accepting an unsigned
     * request that could mark an order paid.
     */
    public function verifyCallback(array $payload, array $headers): bool
    {
        return false;
    }
}
