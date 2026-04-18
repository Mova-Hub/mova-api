<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'client_id', 'status', 'event_type',
        'origin', 'destination', 'waypoints', 'distance_km', 'pickup_date', 'pickup_time',
        'fleet_requirements', 'contact_name', 'contact_phone',
        'internal_notes'
    ];

    // Automatically convert JSON DB column to PHP Array
    protected $casts = [
        'fleet_requirements' => 'array',
        'pickup_date' => 'date',
        'waypoints' => 'array',
        'distance_km' => 'decimal:2',
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
}
