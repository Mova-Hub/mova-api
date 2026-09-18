<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One conversation between a client and Mova.
 *
 * See the migration for why this hangs off the client rather than off a booking,
 * and for what the three statuses mean.
 */
class SupportTicket extends Model
{
    /** What a client may say a ticket is about. Order is the order the app shows. */
    public const CATEGORIES = ['booking', 'payment', 'pass', 'account', 'other'];

    protected $fillable = [
        'client_id',
        'subject',
        'category',
        'status',
        'assigned_to',
        'last_message_at',
        'resolved_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('created_at');
    }

    /**
     * Records that somebody spoke, and moves the status to match.
     *
     * Status is DERIVED here rather than set at each call site, because there
     * are four places a message can be created and the one that forgets is the
     * one that drops a customer out of the queue. A client message always
     * reopens the ticket, including a resolved one: somebody who writes again
     * has not been helped, whatever the last agent concluded.
     */
    public function recordMessage(SupportMessage $message): void
    {
        $this->forceFill([
            'last_message_at' => $message->created_at ?? now(),
            'status' => $message->fromClient() ? 'open' : 'answered',
            // Cleared on a reopen, so "resolved 3 days ago" never sits on a
            // ticket that is live again.
            'resolved_at' => $message->fromClient() ? null : $this->resolved_at,
        ])->save();
    }

    /**
     * How a ticket is shown, to either side.
     *
     * The assignee's NAME is the only thing exposed about them, matching
     * `TripMessage::toWire()`: a client should know which human is answering and
     * nothing else about that employee belongs on a customer's phone.
     *
     * @return array<string, mixed>
     */
    public function toWire(bool $withMessages = false): array
    {
        $wire = [
            'id' => $this->id,
            'subject' => $this->subject,
            'category' => $this->category,
            'status' => $this->status,
            'assignee_name' => $this->assignee?->name,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        if ($withMessages) {
            $wire['messages'] = $this->messages->map->toWire()->values();
        }

        return $wire;
    }
}
