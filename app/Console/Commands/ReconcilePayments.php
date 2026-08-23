<?php

namespace App\Console\Commands;

use App\Domain\Payment\PaymentDriverRegistry;
use App\Domain\Payment\PaymentService;
use App\Models\Payment;
use Illuminate\Console\Command;
use Throwable;

/**
 * Asks providers about payments we never heard back about.
 *
 * **Not optional, and not a nicety.** Mobile-money webhooks get lost — dropped
 * in transit, delivered while the API was restarting, sent to a URL that was
 * briefly wrong. Without this, those payments sit at `processing` forever: the
 * client is debited, the order stays unpaid, and the in-flight guard blocks any
 * retry. That is the single worst state this system can be in, and it resolves
 * itself only because this job runs.
 *
 * Scheduled every five minutes. Deliberately not every minute: the poll only
 * starts two minutes after the attempt, providers rate-limit, and a client
 * watching the sheet is already polling for themselves.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--limit=200}';

    protected $description = 'Poll providers for payments still in flight, and expire the stale ones';

    public function handle(PaymentService $payments, PaymentDriverRegistry $registry): int
    {
        $grace = (int) config('payment.poll_after_seconds', 120);

        $stale = Payment::stale($grace)
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($stale->isEmpty()) {
            $this->info('Nothing in flight.');

            return self::SUCCESS;
        }

        $polled = $expired = $settled = $skipped = 0;

        foreach ($stale as $payment) {
            try {
                $driver = $registry->driver($payment->provider_code);
            } catch (Throwable $e) {
                // A provider row deleted out from under a live payment. Left
                // alone rather than failed: a human should look at it, and
                // failing it would tell a client a payment failed that may
                // well have succeeded.
                $this->warn("#{$payment->id}: {$e->getMessage()}");
                $skipped++;

                continue;
            }

            /*
             * Manual and credit payments are skipped, not expired. Their status
             * only ever changes because a person changed it, so expiring them
             * would cancel work an agent is still doing.
             */
            if (! $driver->capabilities()->statusPoll) {
                $skipped++;

                continue;
            }

            try {
                $before = $payment->status;
                $after = $payments->refresh($payment);
                $polled++;

                if ($after->status !== $before) {
                    $after->status->isFinal() ? $settled++ : null;
                    $this->line("#{$payment->id} {$before->value} → {$after->status->value}");
                }

                if ($after->status->value === 'failed' && $after->failure_reason && str_contains($after->failure_reason, 'expiré')) {
                    $expired++;
                }
            } catch (Throwable $e) {
                // One unreachable provider must not stop the sweep.
                report($e);
                $this->error("#{$payment->id}: {$e->getMessage()}");
            }
        }

        $this->info("Polled {$polled}, settled {$settled}, expired {$expired}, skipped {$skipped}.");

        return self::SUCCESS;
    }
}
