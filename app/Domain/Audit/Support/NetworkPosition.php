<?php

namespace App\Domain\Audit\Support;

use Stevebauman\Location\Position;

/**
 * A position that also carries WHO announces the address.
 *
 * The package's `Position` has no field for it, and on an audit page the
 * network operator is often more useful than the coordinates: "MTN Congo"
 * tells you the action came from a mobile handset on a carrier network, which
 * is the fact that explains why the city is unreliable in the first place.
 *
 * Wired in via `config('location.position')`, which the package provides for
 * exactly this.
 */
class NetworkPosition extends Position
{
    /** The ISP or carrier, e.g. "AS37559 MTN CONGO S.A". */
    public ?string $organisation = null;
}
