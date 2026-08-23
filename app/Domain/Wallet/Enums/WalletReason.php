<?php

namespace App\Domain\Wallet\Enums;

/**
 * Why a credit exists.
 *
 * **This enum IS the compliance boundary.** Every value originates with Mova,
 * so no entry can represent customer funds received and held. That is precisely
 * what keeps Mova Credit outside the definition of electronic money under
 * Règlement 04/18/CEMAC/UMAC/COBAC — which would otherwise require an
 * établissement de paiement licence.
 *
 * **There is deliberately no `top_up`.** Adding one is not a feature, it is a
 * change of regulated status. Read MOVA-WALLET-AND-PAYMENTS.md §3.3 before
 * touching this file.
 */
enum WalletReason: string
{
    /* ── Credits. All originate with Mova. ────────────────────────────── */

    /** A trip Mova cancelled, returned as credit rather than cash. */
    case RefundIssued = 'refund_issued';

    /** Marketing. Usually carries an expiry. */
    case Promo = 'promo';

    case Referral = 'referral';

    /**
     * A company settles an invoice ahead of the bookings it covers.
     *
     * The closest of these to the regulatory line, because money genuinely
     * arrives first. It stays outside because it settles a commercial invoice
     * for identified future services with a named business counterparty — a
     * trade prepayment, not a deposit. If it ever becomes open-ended,
     * unallocated, or refundable in cash, the analysis fails.
     */
    case CorporatePrepayment = 'corporate_prepayment';

    /** Support gesture. Admin-granted, audited, ceiling-capped. */
    case Goodwill = 'goodwill';

    /** Reversal of a debit — a payment that used credit and then failed. */
    case SpendReversed = 'spend_reversed';

    /* ── Debits. ──────────────────────────────────────────────────────── */

    /** Credit used to pay for something. The only routine debit. */
    case Spend = 'spend';

    /** Promotional credit lapsing. Swept by `wallet:expire`. */
    case Expired = 'expired';

    /** Correction by an admin, audited. Requires a note. */
    case Adjustment = 'adjustment';

    public function isCredit(): bool
    {
        return in_array($this, [
            self::RefundIssued,
            self::Promo,
            self::Referral,
            self::CorporatePrepayment,
            self::Goodwill,
            self::SpendReversed,
        ], true);
    }

    /** Reasons an admin may grant by hand. Everything else is system-only. */
    public function isManuallyGrantable(): bool
    {
        return in_array($this, [
            self::Promo,
            self::CorporatePrepayment,
            self::Goodwill,
            self::Adjustment,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::RefundIssued => 'Remboursement',
            self::Promo => 'Crédit promotionnel',
            self::Referral => 'Parrainage',
            self::CorporatePrepayment => 'Avoir entreprise',
            self::Goodwill => 'Geste commercial',
            self::SpendReversed => 'Remise en solde',
            self::Spend => 'Paiement',
            self::Expired => 'Crédit expiré',
            self::Adjustment => 'Régularisation',
        };
    }
}
