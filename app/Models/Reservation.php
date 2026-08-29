<?php

namespace App\Models;

use App\Domain\Payment\Concerns\HasPayments;
use App\Domain\Payment\Contracts\Payable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Reservation extends Model implements Payable
{
    use HasFactory, HasPayments, HasUuids, SoftDeletes;

    protected $table = 'reservations';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'order_id',
        'client_id',
        // Who is responsible for delivering this trip — gathers the vehicles,
        // meets the client, rides with the convoy. See the migration.
        'coordinator_id',
        'code',
        'trip_date',
        // The return leg. Null = one way, which is also what the pricing engine
        // reads to decide whether to bill the road twice.
        'return_date',
        'from_location',
        'to_location',
        'passenger_name',
        'passenger_phone',
        'passenger_email',
        // Capacity attached (`seats`) and head count expected (`passengers`).
        // Two different facts — see the migration that added `passengers`.
        'seats',
        'passengers',
        'price_total',
        'status',
        'payment_status',
        'waypoints',
        'distance_km',
        'event',
        'internal_notes',
        'started_at',
        'completed_at',
        // 'trip_id', // uncomment if/when you add a trips table
    ];

    protected $casts = [
        'trip_date' => 'datetime',
        'return_date' => 'datetime',
        'waypoints'      => 'array',     // [{lat,lng,label},...]
        'seats'          => 'integer',
        'passengers'     => 'integer',
        'price_total'    => 'decimal:2',
        'distance_km'    => 'decimal:2',
        'deleted_at'     => 'datetime',
        'started_at'     => 'datetime',
        'completed_at'   => 'datetime',
    ];

    // Default status
    protected $attributes = [
        'status' => 'pending',
        'payment_status' => 'pending',
    ];

    /**
     * Relationships
     */

     public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The person accountable for this trip actually happening.
     *
     * A `User`, not a `Client` — a coordinator is staff with a login, and the
     * one whose phone reports the convoy's position while the trip runs.
     */
    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    /**
     * Where the convoy has been, newest first.
     *
     * A trail, not a single point: `latest('recorded_at')->first()` answers
     * "where is it now", and the rest is what makes a completed trip auditable
     * when a client disputes a route.
     */
    public function positions(): HasMany
    {
        return $this->hasMany(ReservationPosition::class)->latest('recorded_at');
    }

    // Many reservation ↔ many buses
    public function buses(): BelongsToMany
    {
        // If you later introduce a Pivot model, use ->using(ReservationBus::class)
        return $this->belongsToMany(Bus::class, 'reservation_buses', 'reservation_id', 'bus_id')
            ->using(ReservationBus::class)
                ->withTimestamps();
    }

    // If/when you add a Trip model:
    // public function trip(): BelongsTo
    // {
    //     return $this->belongsTo(Trip::class);
    // }

    /**
     * Scopes
     */

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeBetweenDates($query, ?string $from, ?string $to)
    {
        if ($from) $query->whereDate('trip_date', '>=', $from);
        if ($to)   $query->whereDate('trip_date', '<=', $to);
        return $query;
    }

    public function scopeSearch($query, ?string $q)
    {
        $q = trim((string) $q);
        if ($q === '') return $query;

        return $query->where(function ($qq) use ($q) {
            $qq->where('code', 'like', "%{$q}%")
               ->orWhere('passenger_name', 'like', "%{$q}%")
               ->orWhere('passenger_phone', 'like', "%{$q}%")
               ->orWhere('from_location', 'like', "%{$q}%")
               ->orWhere('to_location', 'like', "%{$q}%");
        });
    }

    /* ─────────────────────── The status state machine ─────────────────────── */

    /**
     * May this reservation move to `$to` from where it is now?
     *
     * **The single copy.** This lived as a private method on
     * `ReservationController`, which was fine while the back-office was the only
     * thing that moved a reservation. It is not any more: a coordinator now
     * starts and completes trips from `control/`, and a second copy of these
     * rules is a second copy that gets edited alone — the first time somebody
     * relaxes one, a mission reaches `completed` without ever having started and
     * `started_at` is null on a trip that supposedly ran.
     *
     * `cancelled` is reachable from anything still open; a completed trip is
     * history and is not un-done by a button.
     */
    public function canTransitionTo(string $to): bool
    {
        $from = (string) $this->status;

        return match ($to) {
            'in_progress' => $from === 'confirmed',
            'completed'   => $from === 'in_progress',
            'cancelled'   => in_array($from, ['pending', 'confirmed', 'in_progress'], true),
            'confirmed'   => $from === 'pending',
            'pending'     => $from === 'confirmed',
            default       => false,
        };
    }

    /**
     * Boot: ensure UUID + human code if not provided.
     */
    protected static function booted(): void
    {
        static::creating(function (Reservation $model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
            if (empty($model->code)) {
                // e.g., BZV-000123 — adapt prefix to your locale/brand
                $model->code = self::generateCode();
            }
        });
    }

    public static function generateCode(string $prefix = 'BZV'): string
    {
        // Keep attempts low; DB unique constraint guarantees final uniqueness
        for ($i = 0; $i < 5; $i++) {
            $candidate = sprintf('%s-%06d', $prefix, random_int(0, 999999));
            if (! static::where('code', $candidate)->exists()) {
                return $candidate;
            }
        }
        // Fallback with UUID fragment to avoid rare collisions
        return sprintf('%s-%s', $prefix, Str::upper(Str::random(8)));
    }

    /* ─────────────────────────── Payable ─────────────────────────── */

    /**
     * A reservation is Payable so that BACK-OFFICE collections have somewhere
     * to land.
     *
     * Cash taken at a counter used to become a `transactions` row, invisible to
     * the payments ledger — which is why the dashboard's revenue figure could
     * never see an app payment and vice versa. Both now write here.
     */
    public function paymentAmount(): int
    {
        return (int) round((float) ($this->price_total ?? 0));
    }

    public function paymentCurrency(): string
    {
        return 'XAF';
    }

    public function paymentDescription(): string
    {
        return trim(sprintf('Mova · %s (%s → %s)', $this->code, $this->from_location, $this->to_location));
    }

    /**
     * Cancelled reservations refuse money; everything else accepts it.
     *
     * Looser than Order's rule on purpose — an agent taking cash at a counter
     * is looking at the client, and the app-side guards (is the vehicle
     * available, has ops confirmed) have already been exercised by the fact
     * that a human is standing there.
     */
    public function isPayable(): bool
    {
        return $this->paymentAmount() > 0
            && ! in_array($this->status, ['cancelled'], true)
            && ! $this->isFullyPaid();
    }

    public function paymentClient(): ?Client
    {
        return $this->client;
    }

    public function onPaymentSucceeded(Payment $payment): void
    {
        $this->update([
            'payment_status' => $this->isFullyPaid() ? 'paid' : 'pending',
        ]);
    }
}
