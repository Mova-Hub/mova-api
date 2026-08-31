<?php

namespace App\Models;

use App\Domain\Payment\Concerns\HasPayments;
use App\Domain\Payment\Contracts\Payable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model implements Payable
{
    use HasPayments;

    protected $fillable = [
        'client_id', 'status', 'event_type',
        'origin', 'destination', 'waypoints', 'distance_km', 'pickup_date', 'pickup_time',
        'return_date', 'return_time',
        'passengers', 'quoted_total',
        'fleet_requirements', 'contact_name', 'contact_phone',
        'internal_notes', 'trip_reminded_at'
    ];

    // Automatically convert JSON DB column to PHP Array
    protected $casts = [
        'fleet_requirements' => 'array',
        'pickup_date' => 'date',
        'return_date' => 'date',
        'waypoints' => 'array',
        'distance_km' => 'decimal:2',
        'passengers' => 'integer',
        'quoted_total' => 'decimal:2',
        'trip_reminded_at' => 'datetime',
    ];

    /**
     * The request lapsed: its travel date passed while it was still being
     * quoted, so it is never going to run.
     *
     * Deliberately NOT `cancelled`. Cancelling is something a person DOES, and
     * the back-office reads that status as "someone pulled out" when measuring
     * lost business. A request that simply ran out of time is a different
     * outcome, and filing it under cancellations would inflate the churn figure
     * with trips nobody ever declined.
     *
     * Terminal. `OrderController` refuses to move an order out of it, and
     * `isPayable()` refuses money for it.
     */
    public const STATUS_EXPIRED = 'expired';

    /** Statuses past which nothing further happens to an order. */
    public const CLOSED_STATUSES = ['cancelled', self::STATUS_EXPIRED];

    public function scopeNotCancelled($query)
    {
        return $query->where('status', '!=', 'cancelled');
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    /**
     * Has the travel date passed?
     *
     * Compared on the DATE, not the timestamp. `pickup_time` is a free-text
     * string ("06:00", but also "tôt le matin" in older rows), so building a
     * datetime out of it would throw on data that already exists. A trip is
     * stale once its whole day is behind us, which is the conservative reading.
     */
    public function tripDateHasPassed(): bool
    {
        $date = $this->return_date ?? $this->pickup_date;

        return $date !== null && $date->endOfDay()->isPast();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function reservation(): HasOne
    {
        return $this->hasOne(Reservation::class);
    }

    /* ─────────────────────────── Payable ─────────────────────────── */

    /**
     * What this charter costs.
     *
     * The confirmed reservation price wins; before conversion it falls back to
     * what was quoted at submission. Both are SERVER-SET values — neither has
     * ever been through a client — which is the property that lets
     * PaymentService trust this number.
     */
    public function paymentAmount(): int
    {
        $reservation = $this->reservation;

        if ($reservation && $reservation->price_total > 0) {
            return (int) round((float) $reservation->price_total);
        }

        return (int) round((float) ($this->quoted_total ?? 0));
    }

    public function paymentCurrency(): string
    {
        return 'XAF';
    }

    public function paymentDescription(): string
    {
        return trim(sprintf('Mova · %s → %s', $this->origin, $this->destination));
    }

    /**
     * Whether the client may pay yet.
     *
     * Deliberately NOT "as soon as an order exists". A pending request has not
     * been checked for vehicle availability, and taking money for a trip that
     * may not be dispatchable creates a refund instead of a booking.
     *
     * This used to accept an order whose own status was `contacted` or
     * `converted`, which put a "Payer" button on the app's trip screen while
     * the quote was still being negotiated. Two things were wrong with it. The
     * price shown at that point is `quoted_total`, an estimate that moves the
     * moment ops price the real vehicles, so the client could pay one figure
     * and owe another. And `contacted` is reachable without any reservation at
     * all, so the money had nothing to attach to.
     *
     * The rule is now the one the business actually runs on: **a confirmed
     * reservation with vehicles on it**. Both halves matter. The status says
     * ops agreed to run the trip; the vehicles say they found the buses. A
     * confirmed reservation with an empty fleet is a promise, not a booking,
     * and it is exactly the case where a refund follows.
     */
    public function isPayable(): bool
    {
        if ($this->paymentAmount() <= 0 || $this->isFullyPaid()) {
            return false;
        }

        // Cancelled and expired are terminal. Nothing is owed on a trip that
        // is not going to happen.
        if (in_array($this->status, self::CLOSED_STATUSES, true)) {
            return false;
        }

        $reservation = $this->reservation;

        if (! $reservation || ! in_array($reservation->status, ['confirmed', 'in_progress'], true)) {
            return false;
        }

        // Uses the loaded relation when the caller eager-loaded it. Without
        // this branch `SendPaymentReminders` would fire one COUNT per order
        // while chunking through two hundred at a time.
        return ($reservation->relationLoaded('buses')
            ? $reservation->buses->count()
            : $reservation->buses()->count()) > 0;
    }

    public function paymentClient(): ?Client
    {
        return $this->client;
    }

    /**
     * Keeps the reservation's own payment_status in step.
     *
     * That column predates the payments table and is what the back-office
     * lists filter on, so leaving it stale would make a paid booking look
     * unpaid to the very people chasing it.
     */
    public function onPaymentSucceeded(Payment $payment): void
    {
        $reservation = $this->reservation;

        if (! $reservation) {
            return;
        }

        $reservation->update([
            'payment_status' => $this->isFullyPaid() ? 'paid' : 'pending',
        ]);
    }
}
