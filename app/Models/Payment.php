<?php

namespace App\Models;

use App\Domain\Payment\Enums\PaymentProvider;
use App\Domain\Payment\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payment extends Model
{
    protected $fillable = [
        'uuid', 'order_id', 'client_id',
        'provider', 'status', 'amount', 'currency',
        'payer_phone', 'provider_reference', 'failure_reason',
        'processing_at', 'paid_at', 'failed_at', 'meta',
    ];

    protected $casts = [
        'provider' => PaymentProvider::class,
        'status' => PaymentStatus::class,
        'amount' => 'integer',
        'processing_at' => 'immutable_datetime',
        'paid_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
        'meta' => 'array',
    ];

    /** Raw provider payloads can contain identifiers; never serialise them. */
    protected $hidden = ['meta'];

    protected static function booted(): void
    {
        static::creating(function (self $payment) {
            $payment->uuid ??= (string) Str::uuid();
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * A payment that is still going somewhere.
     *
     * Used to stop a second attempt while one is live — mobile money prompts
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
}
