<?php

namespace App\Logging;

use Monolog\Formatter\JsonFormatter as MonologJsonFormatter;
use Monolog\LogRecord;

/**
 * One JSON object per line.
 *
 * Laravel's default is a human-readable multi-line format with stack traces
 * spanning dozens of lines. That is fine for `tail -f` and useless to anything
 * that has to search it: a single exception becomes forty unrelated log
 * entries, and `request_id` cannot be filtered on because it is buried in prose.
 *
 * One line per record means `grep`, `jq`, and any hosted log product can all
 * read it, and the correlation id is a field rather than a substring.
 */
class JsonFormatter extends MonologJsonFormatter
{
    public function format(LogRecord $record): string
    {
        $normalised = $record->toArray();

        // Flattened out of `extra`/`context` to the top level, because these
        // are the fields anyone actually filters on.
        $normalised['request_id'] = $record->context['request_id']
            ?? $record->extra['request_id']
            ?? null;

        $normalised['level'] = $record->level->getName();
        $normalised['timestamp'] = $record->datetime->format('c');

        unset($normalised['datetime'], $normalised['level_name']);

        return $this->toJson($normalised, true) . "\n";
    }
}
