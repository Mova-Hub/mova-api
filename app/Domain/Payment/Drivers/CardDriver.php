<?php

namespace App\Domain\Payment\Drivers;

use App\Domain\Payment\DTOs\ChargeResult;
use App\Domain\Payment\DTOs\DriverCapabilities;
use App\Domain\Payment\DTOs\HealthResult;
use App\Models\Payment;

/**
 * Bank cards — for the diaspora.
 *
 * **A complete contract with no acquirer behind it yet**, and that is a
 * deliberate decision rather than unfinished work.
 *
 * Neither MTN nor Airtel does cards: their APIs debit a wallet held in-country,
 * and a French card is not that. Acceptance therefore needs a separate
 * acquirer, and choosing one is a commercial and regulatory decision, not a
 * technical one — see MOVA-WALLET-AND-PAYMENTS.md §2 for the three routes and
 * why a pan-African aggregator is the realistic one.
 *
 * The slot exists now because building it costs a day and retrofitting it costs
 * a refactor of the payment sheet, the provider table and the invoice. When the
 * acquirer is signed, this class gets a body and a `payment_providers` row gets
 * credentials. **Nothing else changes.** That is the test of whether the
 * abstraction above it was worth having.
 *
 * Two rules for whoever fills it in:
 *
 *  1. **Mova must never touch a PAN.** Hosted fields or a redirect, so card
 *     data never reaches this server and PCI-DSS scope stays where it belongs.
 *  2. **Cards are an inbound cross-border flow** under Règlement
 *     02/18/CEMAC/UMAC/CM. Confirm the declaration position before enabling.
 */
class CardDriver extends BaseDriver
{
    protected function key(): string
    {
        return 'card';
    }

    public function capabilities(): DriverCapabilities
    {
        // `collect: false` is what makes this honest. The registry filters on
        // the provider row's `enabled` flag, but a driver that cannot collect
        // says so, so nothing offers it by accident.
        return new DriverCapabilities(
            collect: false,
            refund: false,
            statusPoll: false,
            webhook: false,
        );
    }

    public function charge(Payment $payment): ChargeResult
    {
        return ChargeResult::failed(
            'Le paiement par carte n’est pas encore disponible. Utilisez Mobile Money ou contactez notre équipe.'
        );
    }

    public function status(Payment $payment): ChargeResult
    {
        return new ChargeResult($payment->status, $payment->provider_reference);
    }

    public function healthCheck(array $credentials): HealthResult
    {
        return HealthResult::fail(
            'Aucun prestataire carte n’est configuré. Voir MOVA-WALLET-AND-PAYMENTS.md §2.'
        );
    }
}
