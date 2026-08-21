<?php

namespace App\Domain\Payment\Enums;

/**
 * How a client can pay.
 *
 * Mobile money first, and by a wide margin: card penetration in Congo-Brazzaville
 * is low and MTN MoMo / Airtel Money are how people actually move money. Cash on
 * the day is kept because a corporate client booking a wedding shuttle very
 * often settles with the driver, and pretending otherwise would push those
 * bookings out of the app entirely.
 */
enum PaymentProvider: string
{
    case MtnMomo = 'mtn_momo';
    case AirtelMoney = 'airtel_money';
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::MtnMomo     => 'MTN Mobile Money',
            self::AirtelMoney => 'Airtel Money',
            self::Cash        => 'Espèces',
        };
    }

    /** Providers that need a phone number to push a payment prompt to. */
    public function requiresPhone(): bool
    {
        return $this !== self::Cash;
    }

    /**
     * National prefixes, after the +242 country code.
     *
     * Used to warn — never to block. Number ranges get reallocated between
     * operators, and a client who has ported their number would otherwise be
     * told their own phone number is wrong.
     */
    public function phonePrefixes(): array
    {
        return match ($this) {
            self::MtnMomo     => ['06'],
            self::AirtelMoney => ['05', '04'],
            self::Cash        => [],
        };
    }
}
