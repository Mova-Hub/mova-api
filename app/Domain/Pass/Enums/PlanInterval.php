<?php

namespace App\Domain\Pass\Enums;

use Carbon\CarbonInterface;

/**
 * Billing period unit.
 *
 * Kept as a unit plus a count on the plan (`interval` + `interval_count`)
 * rather than a fixed set of MONTHLY/ANNUAL cases. That is what lets ops add a
 * two-week student pass or a ten-day pilgrimage pass by inserting a row, with
 * no migration and no deploy.
 */
enum PlanInterval: string
{
    case Day   = 'day';
    case Week  = 'week';
    case Month = 'month';
    case Year  = 'year';

    /**
     * Advances a date by `count` of this interval.
     *
     * Carbon's month arithmetic is the non-obvious part: `addMonthsNoOverflow`
     * turns 31 January + 1 month into 28 February rather than overflowing into
     * 3 March. A subscriber who buys on the 31st should not silently receive
     * three extra days every short month.
     */
    public function advance(CarbonInterface $from, int $count = 1): CarbonInterface
    {
        $count = max(1, $count);

        return match ($this) {
            self::Day   => $from->copy()->addDays($count),
            self::Week  => $from->copy()->addWeeks($count),
            self::Month => $from->copy()->addMonthsNoOverflow($count),
            self::Year  => $from->copy()->addYearsNoOverflow($count),
        };
    }

    public function label(int $count = 1): string
    {
        $plural = $count > 1;

        return match ($this) {
            self::Day   => $plural ? 'jours' : 'jour',
            self::Week  => $plural ? 'semaines' : 'semaine',
            self::Month => $plural ? 'mois' : 'mois',
            self::Year  => $plural ? 'ans' : 'an',
        };
    }
}
