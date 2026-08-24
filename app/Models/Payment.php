<?php

namespace App\Models;

use App\Domain\Payment\Contracts\Payable;
use App\Domain\Payment\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One attempt to move money — the single ledger.
 *
 * Polymorphic on purpose: an Order, a PassSubscription and a Reservation are
 * all Payables, and one payment flow serves all three. See
 * App\Domain\Payment\Contracts\Payable.
 *
 * `provider_code` is a string rather than an enum cast because providers are
 * rows in `payment_providers`, added by ops without a deploy. An enum here
 * would put a code change between ops and a new payment method.
 */
class Payment extends Model
{
    protected $fillable = [
        'uuid', 'payable_type', 'payable_id', 'client_id',
        'provider_code', 'channel', 'kind', 'parent_payment_id',
        'status', 'amount', 'fee_amount', 'net_amount', 'currency',
        'payer_phone', 'idempotency_key', 'provider_reference', 'failure_reason',
        'processing_at', 'paid_at', 'failed_at', 'expires_at', 'meta', 'created_by',
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
        'amount' => 'integer',
        'fee_amount' => 'integer',
        'net_amount' => 'integer',
        'processing_at' => 'immutable_datetime',
        'paid_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'meta' => 'array',
    ];

    /**
     * Raw provider payloads can contain identifiers; never serialise them.
     * `idempotency_key` is hidden too — it is sent to providers as a request
     * id, so it is a credential of sorts and has no business in a response.
     */
    protected $hidden = ['meta', 'idempotency_key'];

    protected static function booted(): void
    {
        static::creating(function (self $payment) {
            $payment->uuid ??= (string) Str::uuid();
            $payment->idempotency_key ??= (string) Str::uuid();
        });
    }

    /** Order | PassSubscription | Reservation — anything Payable. */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** The provider row, for label, logo and fees at render time. */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'provider_code', 'code');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_payment_id');
    }

    /* ─────────────────────────── Scopes ─────────────────────────── */

    public function scopeForPayable(Builder $query, Payable&Model $payable): Builder
    {
        return $query
            ->where('payable_type', $payable::class)
            ->where('payable_id', $payable->getKey());
    }

    /**
     * A payment that is still going somewhere.
     *
     * Used to stop a second attempt while one is live — mobile-money prompts
     * sit on a handset for a minute or two, and a client who taps again in that
     * window must not be debited twice.
     */
    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PaymentStatus::Pending->value,
            PaymentStatus::Processing->value,
        ]);
    }

    public function scopeSucceeded(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Succeeded->value);
    }

    /**
     * Money that actually arrived in a window — the revenue rule, in one place.
     *
     * Three conditions that must always travel together, and were being
     * written out by hand at every call site:
     *
     *  - **`paid_at`, not `created_at`.** An attempt started on the 30th and
     *    confirmed on the 1st is revenue for the month it landed in.
     *  - **Refund rows excluded.** A refund flips its parent out of
     *    `succeeded`, so counting both deducts the same money twice.
     *  - Succeeded only, obviously.
     *
     * Getting any one of them wrong produces a plausible number that is quietly
     * incorrect, which is the worst kind.
     */
    public function scopeSucceededBetween(Builder $query, $start, $end): Builder
    {
        return $query->where('status', PaymentStatus::Succeeded->value)
            ->where('kind', '!=', 'refund')
            ->whereBetween('paid_at', [$start, $end]);
    }

    /** Attempts reconciliation should ask a provider about. */
    public function scopeStale(Builder $query, int $olderThanSeconds): Builder
    {
        return $query->inFlight()
            ->where('kind', '!=', 'refund')
            ->where('created_at', '<=', now()->subSeconds($olderThanSeconds));
    }

    /* ─────────────────────────── Helpers ─────────────────────────── */

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    /**
     * The number, last four digits only.
     *
     * Shown back to the payer so they can confirm which wallet was charged,
     * without the response carrying a full phone number that would then live
     * in a log, a cache and a crash report.
     */
    public function maskedPayerPhone(): ?string
    {
        if (! $this->payer_phone) {
            return null;
        }

        return str_repeat('•', max(0, strlen($this->payer_phone) - 4))
            . substr($this->payer_phone, -4);
    }
}
