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
        'internal_notes'
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
    ];

    public function scopeNotCancelled($query)
    {
        return $query->where('status', '!=', 'cancelled');
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
     */
    public function isPayable(): bool
    {
        if ($this->paymentAmount() <= 0 || $this->isFullyPaid()) {
            return false;
        }

        return in_array($this->status, ['contacted', 'converted'], true)
            || in_array($this->reservation?->status, ['confirmed', 'in_progress'], true);
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
