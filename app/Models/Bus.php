<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bus extends Model
{
    use HasFactory;

    protected $fillable = [
        'plate',
        'brand',
        'capacity',
        'name',
        'type',
        'status',
        'model',
        'year',
        'energy_type',
        'first_registration_year',
        'chassis_number',
        'mileage_km',
        'last_service_date',
        'insurance_provider',
        'insurance_policy_number',
        'insurance_valid_until',
        'operator_id',
        'assigned_driver_id',
        'assigned_conductor_id',
    ];

    // Casts
    protected $casts = [
        'last_service_date' => 'date',
        'insurance_valid_until' => 'date',
    ];

    // -------------------------------
    // Relationships
    // -------------------------------

    /**
     * Owner of the bus
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /**
     * Assigned driver
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_id');
    }

    /**
     * Assigned conductor
     */
    public function conductor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_conductor_id');
    }

    public function reservations(): BelongsToMany
    {
        return $this->belongsToMany(Reservation::class, 'reservation_buses', 'bus_id', 'reservation_id')
            ->using(ReservationBus::class)
                ->withTimestamps();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(BusDocument::class, 'bus_id');
    }

    // -------------------------------
    // Scopes
    // -------------------------------

    /**
     * Only active buses
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Filter by bus type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
