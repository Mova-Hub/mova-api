<?php

namespace App\Domain\Pass\Enums;

enum SubscriptionStatus: string
{
    /** Created, awaiting payment. Confers no travel rights. */
    case Pending = 'pending';

    case Active = 'active';

    /** Ran past its expiry. Kept, not deleted — it is the purchase history. */
    case Expired = 'expired';

    /** Ended early by the subscriber. */
    case Cancelled = 'cancelled';

    /** Ended early by Mova (abuse, chargeback). */
    case Suspended = 'suspended';

    /**
     * Whether this status alone permits travel.
     *
     * Status is necessary but NOT sufficient — the expiry is checked separately,
     * because a row can sit at `active` until the nightly sweep runs. Anything
     * deciding a fare must ask the subscription, not this enum.
     */
    public function grantsTravel(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'En attente de paiement',
            self::Active    => 'Actif',
            self::Expired   => 'Expiré',
            self::Cancelled => 'Annulé',
            self::Suspended => 'Suspendu',
        };
    }
}
