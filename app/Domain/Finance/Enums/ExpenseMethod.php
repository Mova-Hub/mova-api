<?php

namespace App\Domain\Finance\Enums;

/**
 * How the money physically left.
 *
 * Deliberately NOT the `payment_providers` registry. That table describes ways
 * money comes IN, and it is ops-editable by design so a new collection method
 * ships without a deploy. Outflows are a short, stable list, and pointing them
 * at the same registry would mean disabling a collection provider silently
 * changed how last month's expenses are labelled.
 *
 * The distinction that earns its keep is `especes`: cash out of a till is the
 * only method with no counterparty record of its own, so it is the one that has
 * to reconcile against a physical drawer rather than against a statement.
 */
enum ExpenseMethod: string
{
    case Cash = 'especes';
    case MobileMoney = 'mobile_money';
    case BankTransfer = 'virement';
    case Cheque = 'cheque';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Espèces',
            self::MobileMoney => 'Mobile Money',
            self::BankTransfer => 'Virement bancaire',
            self::Cheque => 'Chèque',
        };
    }

    /**
     * Whether a reference is worth insisting on.
     *
     * A transfer, a cheque and a Mobile Money debit all leave a number
     * somewhere; cash does not, and demanding one would only teach people to
     * type a placeholder.
     */
    public function hasExternalReference(): bool
    {
        return $this !== self::Cash;
    }

    /** @return array<int, array{value:string, label:string, expects_reference:bool}> */
    public static function options(): array
    {
        return array_map(fn (self $m) => [
            'value' => $m->value,
            'label' => $m->label(),
            'expects_reference' => $m->hasExternalReference(),
        ], self::cases());
    }
}
