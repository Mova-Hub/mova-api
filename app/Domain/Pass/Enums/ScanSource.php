<?php

namespace App\Domain\Pass\Enums;

/**
 * Who performed the scan.
 *
 * It matters for more than reporting: only `Control` scans are fare events. A
 * subscriber checking their own card in the app must never consume a trip from
 * a bundle or appear in the fraud analysis as a boarding.
 */
enum ScanSource: string
{
    /** The subscriber reading their own card in mobile/. */
    case App = 'app';

    /** An inspector on board, via Mova Control. This is the fare event. */
    case Control = 'control';

    /** A counter agent verifying a card after encoding it. */
    case Counter = 'counter';

    public function isFareEvent(): bool
    {
        return $this === self::Control;
    }
}
