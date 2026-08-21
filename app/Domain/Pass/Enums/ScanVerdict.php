<?php

namespace App\Domain\Pass\Enums;

/**
 * The five outcomes of presenting a card (PRD §5.4).
 *
 * EXPIRED is deliberately separate from BLOCKED and INVALID. It is the common,
 * innocent case — somebody forgot to renew — and showing it as theft to the
 * rider and the inspector is both wrong and needlessly hostile. The apps
 * colour it amber for that reason.
 */
enum ScanVerdict: string
{
    case Accepted = 'accepted';
    case Expired  = 'expired';
    case Blocked  = 'blocked';
    case Invalid  = 'invalid';
    case Unknown  = 'unknown';

    public function isAccepted(): bool
    {
        return $this === self::Accepted;
    }

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Accepté',
            self::Expired  => 'Abonnement expiré',
            self::Blocked  => 'Carte bloquée',
            self::Invalid  => 'Carte invalide',
            self::Unknown  => 'Carte inconnue',
        };
    }

    /** Colour intent for clients. Never the ONLY signal — see PRD §7. */
    public function tone(): string
    {
        return match ($this) {
            self::Accepted => 'success',
            self::Expired  => 'warning',
            default        => 'danger',
        };
    }
}
