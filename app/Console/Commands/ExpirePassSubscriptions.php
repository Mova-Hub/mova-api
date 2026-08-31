<?php

namespace App\Console\Commands;

use App\Domain\Pass\Services\SubscriptionService;
use Illuminate\Console\Command;

/**
 * Moves lapsed subscriptions to `expired`.
 *
 * Housekeeping, NOT a fare control. Every read path checks the expiry date
 * itself, so a scheduler hours behind delays a label and never grants a free
 * ride, which is the property that makes it safe to run this once a day.
 */
class ExpirePassSubscriptions extends Command
{
    protected $signature = 'pass:expire';

    protected $description = 'Mark lapsed Mova Pass subscriptions as expired';

    public function handle(SubscriptionService $subscriptions): int
    {
        /*
         * Warn BEFORE sweeping, and in that order.
         *
         * Reversed, the sweep would expire a Pass and the warning would then
         * find nothing still active to warn about, so a client would only ever
         * hear "it expired" and never "it is about to". The whole value of the
         * warning is that it can still be acted on.
         */
        $warned = $subscriptions->warnExpiring();
        $count = $subscriptions->expireLapsed();

        $this->info("{$warned} subscription(s) warned, {$count} marked expired.");

        return self::SUCCESS;
    }
}
