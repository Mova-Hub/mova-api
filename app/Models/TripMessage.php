<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One message in a trip's conversation.
 *
 * See the migration for why the thread hangs off the reservation rather than
 * off the two people, and why `sender` is polymorphic.
 */
class TripMessage extends Model
{
    protected $fillable = [
        'reservation_id',
        'sender_type',
        'sender_id',
        'body',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function sender(): MorphTo
    {
        return $this->morphTo();
    }

    /** Written by the passenger, as opposed to by the coordinator. */
    public function fromClient(): bool
    {
        return $this->sender_type === Client::class;
    }

    /**
     * How this message is shown to whoever is reading the thread.
     *
     * **The staff member's name is deliberately the only thing exposed about
     * them**, and only when they are the sender. A passenger needs to know
     * which human is answering, and nothing else about that employee belongs on
     * a customer's phone. The avatar is included because a face beside a
     * message is the difference between a chat and a ticketing system, and the
     * coordinator's photo is already shown on the tracking screen.
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            // `from_client` rather than the raw morph class. The app has no
            // business knowing our model names, and leaking them invites a
            // client to be written against `App\Models\Client` as a string.
            'from_client' => $this->fromClient(),
            'sender_name' => $this->sender?->name,
            'sender_avatar' => $this->fromClient() ? null : $this->sender?->avatar_url,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
