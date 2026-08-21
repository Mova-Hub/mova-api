<?php

namespace App\Domain\Pass\Enums;

/**
 * Physical card lifecycle.
 *
 * A card leaves the counter ENCODED, not ACTIVE — PRD §5.1. That is what makes
 * a stolen blank batch worthless: the chip carries a valid signed payload, but
 * no account owns it and the server refuses it until a real subscriber binds it
 * to themselves.
 */
enum CardStatus: string
{
    /** Written and verified at the counter; not yet claimed by a subscriber. */
    case Encoded = 'encoded';

    /** Bound to a client and usable. */
    case Active = 'active';

    /** Reported lost, stolen, or withdrawn for fraud. Never reusable. */
    case Blocked = 'blocked';

    /** Superseded by a replacement card. Kept for the audit trail. */
    case Replaced = 'replaced';

    /** Can this card be presented for travel at all? */
    public function isUsable(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Encoded  => 'Encodée',
            self::Active   => 'Active',
            self::Blocked  => 'Bloquée',
            self::Replaced => 'Remplacée',
        };
    }
}
