<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A passenger's verdict on one trip.
 *
 * One row per reservation, enforced by a unique index rather than by a check in
 * the controller: a double tap on a slow connection sends two requests, and
 * check-then-insert loses that race.
 *
 * The four criteria are nullable on purpose. Somebody who taps five stars and
 * nothing else has still told us something, and demanding four more taps is how
 * a rating prompt gets dismissed instead of answered.
 */
class TripRating extends Model
{
    use HasFactory;

    protected $fillable = [
        'reservation_id',
        'order_id',
        'client_id',
        'coordinator_id',
        'stars',
        'punctuality',
        'cleanliness',
        'coordinator_score',
        'comfort',
        'tags',
        'comment',
    ];

    protected $casts = [
        'tags' => 'array',
        'stars' => 'integer',
        'punctuality' => 'integer',
        'cleanliness' => 'integer',
        'coordinator_score' => 'integer',
        'comfort' => 'integer',
    ];

    /** The four named criteria, in the order the app shows them. */
    public const CRITERIA = ['punctuality', 'cleanliness', 'coordinator_score', 'comfort'];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    /**
     * What a passenger's phone is allowed to see.
     *
     * Follows `TripMessage::toWire()`, which is the house pattern for deciding
     * how much of a record reaches a customer's device. The rating is the
     * client's own, so most of it comes back, but the coordinator is reduced to
     * a name: their id, e-mail and role are not part of "how was your trip".
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'id' => $this->id,
            'stars' => $this->stars,
            'punctuality' => $this->punctuality,
            'cleanliness' => $this->cleanliness,
            'coordinator_score' => $this->coordinator_score,
            'comfort' => $this->comfort,
            'tags' => $this->tags ?? [],
            'comment' => $this->comment,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
