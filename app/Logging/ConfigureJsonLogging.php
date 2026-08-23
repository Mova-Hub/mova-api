<?php

namespace App\Logging;

use Illuminate\Log\Logger;

/**
 * Tap applied to a log channel to swap in the JSON formatter.
 *
 * A tap rather than a custom driver: it keeps Laravel's own channel handling —
 * daily rotation, permissions, the stack — and changes only the formatting.
 */
class ConfigureJsonLogging
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->setFormatter(new JsonFormatter());
        }
    }
}
