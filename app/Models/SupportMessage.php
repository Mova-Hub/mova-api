<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One message in a support ticket.
 *
 * Deliberately the same shape as `TripMessage`, including the polymorphic
 * sender, so the two threads can share a component in the app and so neither
 * one grows a rule the other lacks.
 */
class SupportMessage extends Model
{
    protected $fillable = [
        'support_ticket_id',
        'sender_type',
        'sender_id',
        'body',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function sender(): MorphTo
    {
        return $this->morphTo();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportAttachment::class);
    }

    /** Written by the client, as opposed to by staff. */
    public function fromClient(): bool
    {
        return $this->sender_type === Client::class;
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            // `from_client` rather than the raw morph class, matching
            // TripMessage: the app has no business knowing our model names, and
            // leaking them invites a client written against the literal string
            // `App\Models\Client`.
            'from_client' => $this->fromClient(),
            'sender_name' => $this->sender?->name,
            'sender_avatar' => $this->fromClient() ? null : $this->sender?->avatar_url,
            /*
             * Metadata only. No URL.
             *
             * A link here would have to be either permanent, which defeats the
             * signed-route design, or minted on every thread read, which means
             * generating signatures for images nobody opens. The app calls
             * `/support/attachments/{id}/link` when a picture is actually
             * tapped.
             */
            'attachments' => $this->attachments->map(fn (SupportAttachment $a) => [
                'id' => $a->id,
                'name' => $a->original_name,
                'mime_type' => $a->mime_type,
                'size_bytes' => $a->size_bytes,
            ])->values(),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
