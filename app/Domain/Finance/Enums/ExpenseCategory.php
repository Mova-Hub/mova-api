<?php

namespace App\Domain\Finance\Enums;

/**
 * What money was spent on.
 *
 * **A fixed list, not a table.** Categories are the axis every month-on-month
 * comparison is drawn against, so an ops user renaming or deleting one silently
 * rewrites history: last quarter's "Carburant" line either vanishes or starts
 * meaning something else. Adding a category is a deploy, and that is the right
 * cost for a change that alters every past report.
 *
 * The `nature()` split is the other reason this is code. A P&L needs gross
 * margin before net, which means knowing which costs are incurred to run a
 * trip and which exist whether or not a wheel turns. That classification is an
 * accounting property of the category itself, not a presentation choice, so it
 * lives here rather than in a chart component where each caller would guess
 * differently.
 */
enum ExpenseCategory: string
{
    /* Direct costs: incurred because a trip ran. */

    case Fuel = 'carburant';

    /** Tyres, parts, garage labour, servicing. */
    case Maintenance = 'entretien';

    case Tolls = 'peage';

    /** Drivers and conductors, the crew on the vehicle. */
    case CrewWages = 'salaires_equipage';

    /**
     * What is owed to a bus owner for a trip their vehicle ran.
     *
     * Recordable by hand today, which is the honest state of things: the split
     * is computed at conversion time and shown in the back office, but nothing
     * persists it, so this is where a paid-out share is written down. Once
     * payouts are their own table, entries in this category should come from
     * that table rather than from a person typing, and this case becomes the
     * bridge between the two rather than the source.
     */
    case OperatorPayout = 'reversement_operateur';

    /* Overheads: incurred whether or not a trip ran. */

    case Salaries = 'salaires';

    case Insurance = 'assurance';

    case Rent = 'loyer';

    /** Licences, registration, fines, inspections. */
    case Administrative = 'administratif';

    /** Airtime, data, hosting, software. */
    case Telecom = 'telecom_it';

    case Marketing = 'marketing';

    /**
     * Bank charges and provider fees paid separately.
     *
     * NOT the mobile-money fee taken out of a collection: that is already on
     * `payments.fee_amount`, recorded at the time by the driver that charged it.
     * Recording it here too would double-count it in the P&L. This is for fees
     * that arrive as their own debit, a monthly bank charge or a transfer fee.
     */
    case BankCharges = 'frais_bancaires';

    case Other = 'divers';

    public function label(): string
    {
        return match ($this) {
            self::Fuel => 'Carburant',
            self::Maintenance => 'Entretien & réparations',
            self::Tolls => 'Péages & stationnement',
            self::CrewWages => 'Salaires équipage',
            self::OperatorPayout => 'Reversements opérateurs',
            self::Salaries => 'Salaires & charges',
            self::Insurance => 'Assurances',
            self::Rent => 'Loyers',
            self::Administrative => 'Frais administratifs',
            self::Telecom => 'Télécoms & informatique',
            self::Marketing => 'Marketing',
            self::BankCharges => 'Frais bancaires',
            self::Other => 'Divers',
        };
    }

    /**
     * `direct` sits above the gross-margin line, `overhead` below it.
     *
     * @return 'direct'|'overhead'
     */
    public function nature(): string
    {
        return match ($this) {
            self::Fuel,
            self::Maintenance,
            self::Tolls,
            self::CrewWages,
            self::OperatorPayout => 'direct',
            default => 'overhead',
        };
    }

    /**
     * Whether this cost is normally attributable to one vehicle.
     *
     * Advisory, not enforced. It drives a hint in the entry form so fuel gets
     * a plate against it while the office rent does not, because a cost-per-
     * vehicle figure is only as good as the habit of filling that field in.
     */
    public function expectsBus(): bool
    {
        return in_array($this, [self::Fuel, self::Maintenance, self::Tolls], true);
    }

    /** @return array<int, array{value:string, label:string, nature:string, expects_bus:bool}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => [
            'value' => $c->value,
            'label' => $c->label(),
            'nature' => $c->nature(),
            'expects_bus' => $c->expectsBus(),
        ], self::cases());
    }
}
