<?php

namespace App\Domain\Wallet;

use App\Domain\Settings\Facades\Settings;
use App\Domain\Wallet\Enums\WalletReason;
use App\Domain\Wallet\Exceptions\WalletException;
use App\Models\Client;
use App\Models\WalletAccount;
use App\Models\WalletEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Mova Credit.
 *
 * **Read MOVA-WALLET-AND-PAYMENTS.md §3 before changing this file.**
 *
 * Closed-loop by construction. There is no `topUp()` method — not commented
 * out, not feature-flagged, ABSENT — because a method that accepts customer
 * funds in exchange for balance is electronic money issuance, and under
 * Règlement 04/18/CEMAC/UMAC/COBAC that needs a licence roughly seven
 * organisations in the CEMAC zone hold.
 *
 * The three changes that would cross that line, none of which belong here:
 *   1. Accepting customer funds as balance (top-up)
 *   2. Paying balance back out as money (cash-out)
 *   3. Moving balance between clients (transfer)
 *
 * Everything mutating runs under `lockForUpdate`. Two devices spending the same
 * credit at the same instant must produce one success and one refusal, and
 * optimistic checks do not deliver that.
 */
class WalletService
{
    /** The account, created on first sight. */
    public function accountFor(Client $client): WalletAccount
    {
        return WalletAccount::firstOrCreate(
            ['client_id' => $client->id],
            ['balance' => 0, 'currency' => 'XAF', 'status' => 'active'],
        );
    }

    public function balanceFor(Client $client): int
    {
        return $this->accountFor($client)->balance;
    }

    /**
     * Adds credit.
     *
     * @param  WalletReason  $reason  Must be a credit reason — see the enum.
     * @param  Model|null  $source  What caused it (a Payment, an Order…).
     *
     * @throws WalletException
     */
    public function grant(
        Client $client,
        int $amount,
        WalletReason $reason,
        ?Model $source = null,
        ?string $note = null,
        ?\DateTimeInterface $expiresAt = null,
        ?int $grantedBy = null,
    ): WalletEntry {
        if ($amount <= 0) {
            throw new WalletException('Le montant doit être positif.');
        }

        if (! $reason->isCredit()) {
            // A debit reason reaching grant() would silently invert a
            // correction into a gift. Refused rather than coerced.
            throw new WalletException("« {$reason->label()} » n’est pas un motif de crédit.");
        }

        $ceiling = Settings::int('wallet.max_manual_grant', 500_000);
        if ($reason->isManuallyGrantable() && $grantedBy !== null && $amount > $ceiling) {
            throw new WalletException(
                sprintf('Le plafond par octroi manuel est de %s FCFA.', number_format($ceiling, 0, ',', ' '))
            );
        }

        return $this->write($client, 'credit', $amount, $reason, $source, $note, $expiresAt, $grantedBy);
    }

    /**
     * Spends credit.
     *
     * Called by MovaCreditDriver, never straight from a controller — spending
     * has to go through the payment state machine so it appears in the one
     * ledger like any other payment.
     *
     * @throws WalletException on insufficient or frozen funds
     */
    public function spend(Client $client, int $amount, ?Model $source = null, ?string $note = null): WalletEntry
    {
        if ($amount <= 0) {
            throw new WalletException('Le montant doit être positif.');
        }

        return $this->write($client, 'debit', $amount, WalletReason::Spend, $source, $note);
    }

    /** Puts credit back after a payment that used it did not complete. */
    public function reverse(Client $client, int $amount, ?Model $source = null): WalletEntry
    {
        return $this->write($client, 'credit', $amount, WalletReason::SpendReversed, $source);
    }

    /**
     * The most credit that may be applied to one payment.
     *
     * Two limits: the balance itself, and a settings cap on the share of a
     * payment credit may cover. The cap exists so promotional credit cannot
     * make a booking entirely free by accident, which is a fraud vector rather
     * than a generous gesture.
     */
    public function spendableAgainst(Client $client, int $amount): int
    {
        $account = $this->accountFor($client);

        if (! $account->isSpendable()) {
            return 0;
        }

        $maxShare = Settings::float('wallet.max_share_of_payment', 1.0);
        $cap = (int) floor($amount * max(0.0, min(1.0, $maxShare)));

        return max(0, min($account->balance, $cap));
    }

    /**
     * The single writer.
     *
     * Everything above funnels here so the lock, the sufficiency check and the
     * `balance_after` stamp exist in exactly one place. A second write path is
     * how a ledger acquires an entry with the wrong running balance.
     */
    private function write(
        Client $client,
        string $direction,
        int $amount,
        WalletReason $reason,
        ?Model $source = null,
        ?string $note = null,
        ?\DateTimeInterface $expiresAt = null,
        ?int $createdBy = null,
    ): WalletEntry {
        $this->accountFor($client);

        return DB::transaction(function () use (
            $client, $direction, $amount, $reason, $source, $note, $expiresAt, $createdBy
        ) {
            /** @var WalletAccount $account */
            $account = WalletAccount::where('client_id', $client->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($direction === 'debit') {
                if (! $account->isSpendable()) {
                    throw new WalletException('Votre solde Mova est momentanément indisponible.');
                }

                if (! $account->hasSufficientFunds($amount)) {
                    throw new WalletException('Solde Mova insuffisant.');
                }
            }

            $balanceAfter = $account->balance + ($direction === 'credit' ? $amount : -$amount);

            $entry = WalletEntry::create([
                'wallet_account_id' => $account->id,
                'direction' => $direction,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'reason' => $reason,
                'source_type' => $source ? $source::class : null,
                'source_id' => $source?->getKey(),
                'note' => $note,
                'expires_at' => $expiresAt,
                'created_by' => $createdBy,
            ]);

            // The projection, updated inside the same lock as the entry that
            // justifies it. Outside it, a crash between the two leaves a
            // balance that no ledger supports.
            $account->update(['balance' => $balanceAfter]);

            return $entry;
        });
    }

    /**
     * Sweeps lapsed promotional credit.
     *
     * Writes an explicit `expired` DEBIT rather than deleting the original
     * credit. The ledger is append-only, and "this credit was removed on 3
     * September because it expired" is a question a client will ask.
     *
     * @return int Francs expired.
     */
    public function expire(): int
    {
        $total = 0;

        WalletEntry::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->where('direction', 'credit')
            // Not already swept: an expiry debit records the entry it cancels.
            ->whereNotExists(function ($q) {
                $q->selectRaw(1)
                    ->from('wallet_entries as sweep')
                    ->whereColumn('sweep.source_id', 'wallet_entries.id')
                    ->where('sweep.source_type', WalletEntry::class)
                    ->where('sweep.reason', WalletReason::Expired->value);
            })
            ->with('account.client')
            ->chunkById(100, function ($entries) use (&$total) {
                foreach ($entries as $entry) {
                    $client = $entry->account?->client;
                    if (! $client) {
                        continue;
                    }

                    // Never expire more than is actually left, or a client who
                    // already spent promotional credit goes negative.
                    $amount = min($entry->amount, $entry->account->balance);
                    if ($amount <= 0) {
                        continue;
                    }

                    $this->write(
                        $client, 'debit', $amount, WalletReason::Expired, $entry,
                        'Crédit arrivé à échéance',
                    );

                    $total += $amount;
                }
            });

        return $total;
    }

    /**
     * Re-derives balances from entries and reports drift.
     *
     * The entries are the truth; this is what proves the cache still agrees
     * with them. Reports rather than silently repairing — a mismatch is a bug
     * worth reading before it is papered over.
     *
     * @return array<int, array{client_id:int, cached:int, derived:int}>
     */
    public function reconcile(bool $repair = false): array
    {
        $drift = [];

        WalletAccount::query()->chunkById(200, function ($accounts) use (&$drift, $repair) {
            foreach ($accounts as $account) {
                $derived = (int) WalletEntry::where('wallet_account_id', $account->id)
                    ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) as total")
                    ->value('total');

                if ($derived === $account->balance) {
                    continue;
                }

                $drift[] = [
                    'client_id' => $account->client_id,
                    'cached' => $account->balance,
                    'derived' => $derived,
                ];

                if ($repair) {
                    $account->update(['balance' => $derived]);
                }
            }
        });

        return $drift;
    }
}
