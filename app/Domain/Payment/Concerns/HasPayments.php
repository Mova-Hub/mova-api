<?php

namespace App\Domain\Payment\Concerns;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * The bookkeeping half of Payable.
 *
 * Every payable answers "how much has been collected" and "is anything in
 * flight" identically, so those live here rather than being written three times
 * with three chances to disagree about whether a refund counts.
 *
 * The half that genuinely differs per model — what is owed, and whether
 * collection may start — stays in the model, where the answer depends on
 * reservation status or subscription state.
 */
trait HasPayments
{
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable')->latest('id');
    }

    /**
     * Everything successfully collected, in whole francs.
     *
     * Refund rows are excluded rather than subtracted: a refund flips its
     * parent to `refunded`, which already drops it out of this sum. Counting
     * both would deduct the same money twice.
     */
    public function paidAmount(): int
    {
        return (int) $this->payments()
            ->where('status', PaymentStatus::Succeeded->value)
            ->where('kind', '!=', 'refund')
            ->sum('amount');
    }

    public function outstandingAmount(): int
    {
        return max(0, $this->paymentAmount() - $this->paidAmount());
    }

    public function isFullyPaid(): bool
    {
        return $this->paymentAmount() > 0 && $this->outstandingAmount() === 0;
    }

    /** True once anything has been collected, even a deposit. */
    public function isPartiallyPaid(): bool
    {
        $paid = $this->paidAmount();

        return $paid > 0 && $paid < $this->paymentAmount();
    }

    public function pendingPayment(): ?Payment
    {
        return $this->payments()->inFlight()->first();
    }
}
