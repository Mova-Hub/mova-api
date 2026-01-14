<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Reservation extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'reservations';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'order_id',
        'code',
        'trip_date',
        'from_location',
        'to_location',
        'passenger_name',
        'passenger_phone',
        'passenger_email',
        'seats',
        'price_total',
        'status',
        'waypoints',
        'distance_km',
        'event',
        'internal_notes',
        // 'trip_id', // uncomment if/when you add a trips table
    ];

    protected $casts = [
        'trip_date' => 'datetime',
        'waypoints'      => 'array',     // [{lat,lng,label},...]
        'price_total'    => 'decimal:2',
        'distance_km'    => 'decimal:2',
        'deleted_at'     => 'datetime',
        // 'created_at'    => 'datetime', // Eloquent already casts timestamps
        // 'updated_at'    => 'datetime',
    ];

    /**
     * Relationships
     */

     public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
}
