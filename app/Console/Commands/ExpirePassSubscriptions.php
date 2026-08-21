<?php

namespace App\Console\Commands;

use App\Domain\Pass\Services\SubscriptionService;
use Illuminate\Console\Command;

/**
 * Moves lapsed subscriptions to `expired`.
 *
 * Housekeeping, NOT a fare control. Every read path checks the expiry date
 * itself, so a scheduler hours behind delays a label and never grants a free
 * ride — which is the property that makes it safe to run this once a day.
 */
class ExpirePassSubscriptions extends Command
{
    protected $signature = 'pass:expire';

    protected $description = 'Mark lapsed Mova Pass subscriptions as expired';

    public function handle(SubscriptionService $subscriptions): int
    {
        $count = $subscriptions->expireLapsed();

        $this->info("{$count} subscription(s) marked expired.");

        return self::SUCCESS;
    }
}
