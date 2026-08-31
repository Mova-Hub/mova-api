<?php

namespace App\Console\Commands;

use App\Domain\Messaging\MessagingService;
use App\Domain\Pass\Enums\SubscriptionStatus;
use App\Domain\Payment\PaymentService;
use App\Domain\Settings\Facades\Settings;
use App\Models\Order;
use App\Models\PassSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Nudges people who owe money, and people about to lose their pass.
 *
 * Three rules, all of which exist because dunning is the easiest way to make
 * an app feel like a debt collector:
 *
 *  1. **Fixed days, not "every day it is late".** D+1 and D+3 for an unpaid
 *     order, D-7 and D-1 for an expiring pass. A daily message teaches people
 *     to mute the sender, after which nothing gets through — including the
 *     messages that matter.
 *  2. **Capped per client per day**, across every reminder type. Someone with
 *     three unpaid orders gets one message, not three.
 *  3. **No amount over an unencrypted channel.** SMS is readable by anyone
 *     holding the handset, and "vous devez 340 000 FCFA" on a lock screen is
 *     the customer's business, not their neighbour's. The message says a
 *     payment is due and points at the app.
 */
class SendPaymentReminders extends Command
{
    protected $signature = 'payments:remind {--dry-run : List who would be contacted, send nothing}';

    protected $description = 'Remind clients about unpaid orders and expiring Mova Pass subscriptions';

    private int $sent = 0;

    private int $capped = 0;

    public function handle(MessagingService $messaging, PaymentService $payments): int
    {
        if (! Settings::bool('notifications.reminders_enabled', true)) {
            $this->info('Reminders are disabled in settings.');

            return self::SUCCESS;
        }

        $this->remindUnpaidOrders($messaging, $payments);
        $this->remindExpiringPasses($messaging);

        $this->info(sprintf(
            '%s %d reminder(s). %d suppressed by the daily cap.',
            $this->option('dry-run') ? 'Would send' : 'Sent',
            $this->sent,
            $this->capped,
        ));

        return self::SUCCESS;
    }

    private function remindUnpaidOrders(MessagingService $messaging, PaymentService $payments): void
    {
        $dueDays = Settings::int('rules.payment_due_days', 3);

        // Only orders confirmed long enough ago to be genuinely late, and only
        // on the two chosen days — a range would fire every day in between.
        $targets = [1, $dueDays];

        // `reservation.buses` is loaded because `isPayable()` now counts the
        // assigned vehicles. Without it this chunk fires one COUNT per order.
        Order::with(['client', 'reservation.buses'])
            ->whereIn('status', ['contacted', 'converted'])
            ->whereNotNull('client_id')
            ->chunkById(200, function ($orders) use ($messaging, $payments, $targets) {
                foreach ($orders as $order) {
                    $age = (int) $order->updated_at->diffInDays(now());

                    if (! in_array($age, $targets, true)) {
                        continue;
                    }

                    if (! $order->isPayable() || $payments->paidTotal($order) > 0) {
                        continue;
                    }

                    $this->dispatch(
                        $messaging,
                        $order->client,
                        'reminder',
                        sprintf(
                            'Mova : votre réservation vers %s est confirmée et attend votre règlement. Ouvrez l’application pour payer.',
                            $order->destination,
                        ),
                    );
                }
            });
    }

    private function remindExpiringPasses(MessagingService $messaging): void
    {
        PassSubscription::with(['client', 'plan'])
            ->where('status', SubscriptionStatus::Active->value)
            ->whereNotNull('client_id')
            ->whereBetween('expires_at', [now(), now()->addDays(7)->endOfDay()])
            ->chunkById(200, function ($subscriptions) use ($messaging) {
                foreach ($subscriptions as $subscription) {
                    $days = $subscription->daysRemaining();

                    if (! in_array($days, [7, 1], true)) {
                        continue;
                    }

                    $this->dispatch(
                        $messaging,
                        $subscription->client,
                        'reminder',
                        $days === 1
                            ? 'Mova : votre pass expire demain. Renouvelez-le depuis l’application pour continuer à voyager.'
                            : 'Mova : votre pass expire dans 7 jours. Renouvelez-le depuis l’application.',
                    );
                }
            });
    }

    /**
     * Sends one reminder, subject to the daily cap.
     *
     * The cap key is the client, not the message — so the three reminder types
     * compete for one slot rather than each having their own.
     */
    private function dispatch(MessagingService $messaging, $client, string $event, string $body): void
    {
        if (! $client?->phone) {
            return;
        }

        $key = 'reminder:sent:' . $client->id . ':' . now()->toDateString();

        if (Cache::has($key)) {
            $this->capped++;

            return;
        }

        if ($this->option('dry-run')) {
            $this->line('  would remind client #' . $client->id);
            $this->sent++;

            return;
        }

        try {
            $result = $messaging->send($client->phone, $event, $body);

            if ($result->ok) {
                Cache::put($key, true, now()->endOfDay());
                $this->sent++;
            }
        } catch (Throwable $e) {
            // One unreachable client must not stop the sweep.
            report($e);
        }
    }
}
