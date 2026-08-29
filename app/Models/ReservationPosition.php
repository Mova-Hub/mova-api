<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One GPS fix on a running trip.
 *
 * Written by the coordinator's phone through `POST /field/missions/{id}/position`
 * and broadcast to the passenger the moment it lands. See the migration for why
 * `bus_id` is nullable and why `recorded_at` is the device's clock rather than
 * the server's.
 */
class ReservationPosition extends Model
{
    protected $fillable = [
        'reservation_id',
        'bus_id',
        'user_id',
        'lat',
        'lng',
        'heading',
        'speed',
        'accuracy',
        'recorded_at',
    ];

    protected $casts = [
        /*
         * `float`, deliberately, even though the column is `decimal`.
         *
         * Laravel's `decimal:7` cast returns a STRING, and a string reaches the
         * broadcast payload as `"-4.2634000"` — which a map library will happily
         * plot as NaN. The column stays decimal so the database does not drift;
         * the cast makes the JSON a number.
         */
        'lat'         => 'float',
        'lng'         => 'float',
        'heading'     => 'float',
        'speed'       => 'float',
        'accuracy'    => 'float',
        'recorded_at' => 'datetime',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
