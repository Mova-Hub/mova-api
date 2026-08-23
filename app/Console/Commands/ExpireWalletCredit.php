<?php

namespace App\Console\Commands;

use App\Domain\Wallet\WalletService;
use Illuminate\Console\Command;

/**
 * Sweeps lapsed promotional credit, and checks the ledger still adds up.
 *
 * Two jobs in one command because they belong to the same nightly pass over the
 * wallet, and running the reconciliation right after the sweep is when drift is
 * most likely to show.
 *
 * Reconciliation REPORTS by default and repairs only when asked. A silent
 * auto-repair would hide the bug that caused the drift, and drift in a money
 * ledger is worth a person's attention.
 */
class ExpireWalletCredit extends Command
{
    protected $signature = 'wallet:expire {--repair : Also correct any cached balance that has drifted}';

    protected $description = 'Expire lapsed Mova Credit and verify balances against the ledger';

    public function handle(WalletService $wallet): int
    {
        $expired = $wallet->expire();

        $this->info($expired > 0
            ? sprintf('Expired %s FCFA of credit.', number_format($expired, 0, ',', ' '))
            : 'No credit to expire.');

        $drift = $wallet->reconcile($this->option('repair'));

        if ($drift === []) {
            $this->info('All balances agree with the ledger.');

            return self::SUCCESS;
        }

        // Loud. A cached balance that disagrees with its entries is either a
        // bug in the writer or something worse, and neither should scroll past.
        $this->error(sprintf('%d wallet(s) drifted from the ledger:', count($drift)));

        foreach ($drift as $row) {
            $this->line(sprintf(
                '  client #%d — cached %d, derived %d',
                $row['client_id'], $row['cached'], $row['derived'],
            ));
        }

        if ($this->option('repair')) {
            $this->warn('Balances corrected. Investigate the cause — the repair hides it otherwise.');
        }

        return self::FAILURE;
    }
}
