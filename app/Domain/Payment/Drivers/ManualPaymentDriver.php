<?php

namespace App\Domain\Payment\Drivers;

use App\Domain\Payment\DTOs\ChargeResult;
use App\Domain\Payment\DTOs\DriverCapabilities;
use App\Domain\Payment\DTOs\HealthResult;
use App\Models\Payment;
use Illuminate\Support\Str;

/**
 * Money collected by a human — cash, bank transfer, cheque.
 *
 * Not a stub. This is how the business already collects most of its money, and
 * it is worth having in the app because a client who has pressed "payer" and
 * been given a reference is materially closer to paying than one who has been
 * told to phone. It also backs every back-office collection, which is what the
 * old `transactions` table used to do.
 *
 * What it very deliberately does NOT do is report success. `Processing` is the
 * truth: nobody has been debited, and a driver returning `Succeeded` here would
 * mark orders paid on nothing but a tap. An agent confirms it in the
 * back-office, and that confirmation is the only thing that settles it.
 */
class ManualPaymentDriver extends BaseDriver
{
    protected function key(): string
    {
        return 'manual';
    }

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            collect: true,
            // Handing cash back is real, but no API does it. Reported false so
            // the back-office shows "à traiter manuellement" rather than a
            // button that pretends to move money.
            refund: false,
            // Nothing to poll: the status only ever changes because a person
            // changed it. Reconciliation therefore leaves these alone rather
            // than expiring an attempt an agent is still working on.
            statusPoll: false,
            webhook: false,
            synchronous: false,
        );
    }

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
     * False rather than true matters: if a webhook route is ever pointed here
     * by mistake, it refuses instead of accepting an unsigned request that
     * could mark an order paid.
     */
    public function verifyCallback(array $payload, array $headers): bool
    {
        return false;
    }

    public function healthCheck(array $credentials): HealthResult
    {
        return HealthResult::ok('Paiement manuel — aucun service externe à joindre.');
    }
}
