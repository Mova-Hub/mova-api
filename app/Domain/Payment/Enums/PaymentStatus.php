<?php

namespace App\Domain\Payment\Enums;

enum PaymentStatus: string
{
    /** Created, nothing sent to the provider yet. */
    case Pending = 'pending';

    /** The provider has been asked; the client is being prompted on their phone. */
    case Processing = 'processing';

    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** Money left our side again. */
    case Refunded = 'refunded';

    /** Terminal states — no further callback should change them. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled, self::Refunded], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending    => 'En attente',
            self::Processing => 'Paiement en cours',
            self::Succeeded  => 'Payé',
            self::Failed     => 'Échoué',
            self::Cancelled  => 'Annulé',
            self::Refunded   => 'Remboursé',
        };
    }
}
