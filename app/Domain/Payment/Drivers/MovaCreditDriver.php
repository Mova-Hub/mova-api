<?php

namespace App\Domain\Payment\Drivers;

use App\Domain\Payment\DTOs\ChargeResult;
use App\Domain\Payment\DTOs\DriverCapabilities;
use App\Domain\Payment\DTOs\HealthResult;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Wallet\Exceptions\WalletException;
use App\Domain\Wallet\WalletService;
use App\Models\Payment;

/**
 * Spending Mova Credit.
 *
 * A driver rather than a special case in PaymentService, so credit appears in
 * the one ledger exactly like any other payment — same row, same statuses, same
 * invoice line. A client who paid half with credit and half with MoMo has two
 * payment records, not one payment and one mysterious discount.
 *
 * **The only synchronous driver.** There is no handset to prompt and nothing to
 * wait for, so it returns `Succeeded` immediately. The payment sheet reads
 * `capabilities()->synchronous` to skip its "confirmez sur votre téléphone"
 * screen, which would otherwise flash for a fifth of a second and read as a bug.
 *
 * @see MOVA-WALLET-AND-PAYMENTS.md §3 — closed-loop, and why there is no top-up.
 */
class MovaCreditDriver extends BaseDriver
{
    public function __construct(private WalletService $wallet) {}

    protected function key(): string
    {
        return 'mova_credit';
    }

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            collect: true,
            // Reversible in full: putting credit back is a ledger entry, not a
            // network call to anyone.
            refund: true,
            // Nothing to poll and no callback to receive — it either debited
            // inside our own transaction or it did not.
            statusPoll: false,
            webhook: false,
            synchronous: true,
        );
    }

    public function charge(Payment $payment): ChargeResult
    {
        $client = $payment->client;

        if (! $client) {
            return ChargeResult::failed('Le solde Mova nécessite un compte client.');
        }

        try {
            $entry = $this->wallet->spend(
                $client,
                $payment->amount,
                $payment,
                $payment->payable?->paymentDescription(),
            );
        } catch (WalletException $e) {
            // Already French and client-safe — WalletException exists for
            // exactly this, so it passes through rather than being flattened
            // into a generic failure that hides "solde insuffisant".
            return ChargeResult::failed($e->getMessage());
        }

        return new ChargeResult(
            PaymentStatus::Succeeded,
            $entry->uuid,
            'Réglé avec votre solde Mova.',
            ['wallet_entry' => $entry->uuid],
        );
    }

    public function status(Payment $payment): ChargeResult
    {
        // Settled the moment charge() returned. There is nobody to ask.
        return new ChargeResult($payment->status, $payment->provider_reference);
    }

    public function refund(Payment $payment, int $amount): ChargeResult
    {
        $client = $payment->client;

        if (! $client) {
            return ChargeResult::failed('Compte client introuvable.');
        }

        $entry = $this->wallet->reverse($client, $amount, $payment);

        return new ChargeResult(
            PaymentStatus::Succeeded,
            $entry->uuid,
            'Le montant a été recrédité sur votre solde Mova.',
        );
    }

    public function healthCheck(array $credentials): HealthResult
    {
        // Nothing external to reach. Saying so beats a "Tester" button that
        // spins and then claims success without having done anything.
        return HealthResult::ok('Le solde Mova est interne — aucun service externe à joindre.');
    }
}
