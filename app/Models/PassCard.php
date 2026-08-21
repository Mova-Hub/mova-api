<?php

namespace App\Models;

use App\Domain\Pass\Enums\CardStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PassCard extends Model
{
    protected $fillable = [
        'uuid', 'client_id', 'chip_uid', 'printed_serial', 'status',
        'key_id', 'signature', 'entitlement_expires_at',
        'activated_at', 'blocked_at', 'blocked_reason', 'replaced_by_id',
        'last_seen_at', 'issued_by', 'metadata',
    ];

    protected $casts = [
        'status' => CardStatus::class,
        'entitlement_expires_at' => 'immutable_datetime',
        'activated_at' => 'immutable_datetime',
        'blocked_at' => 'immutable_datetime',
        'last_seen_at' => 'immutable_datetime',
        'metadata' => 'array',
    ];

    /**
     * The serial is an activation credential, not an identifier.
     *
     * Anyone holding it can attempt to bind the card to their own account, so
     * it must never leak through a careless `toArray()` on a list endpoint.
     * The card's own owner is shown it explicitly, by the resource.
     */
    protected $hidden = ['signature', 'printed_serial'];

    protected static function booted(): void
    {
        static::creating(function (self $card) {
            $card->uuid ??= (string) Str::uuid();
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(PassScan::class);
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    public function isBlocked(): bool
    {
        return $this->status === CardStatus::Blocked;
    }

    /**
     * The downloadable blacklist (PRD §6.3), derived rather than duplicated.
     *
     * REPLACED counts, not only BLOCKED. A card reported lost is blocked and
     * then replaced, and the replacement overwrites its status — so a scope
     * matching `blocked` alone would quietly drop every lost card from the
     * export the moment a new one was issued, which is exactly the card most
     * likely to be presented by somebody who should not have it.
     */
    public function scopeBlacklisted(Builder $query): Builder
    {
        return $query->whereIn('status', [
            CardStatus::Blocked->value,
            CardStatus::Replaced->value,
        ]);
    }

    /**
     * Lookup by UID or printed serial, in one place.
     *
     * Both are treated as the same kind of secret so callers cannot
     * accidentally build an enumeration oracle by handling them differently.
     */
    public function scopeMatching(Builder $query, ?string $chipUid = null, ?string $serial = null): Builder
    {
        return $query->where(function (Builder $q) use ($chipUid, $serial) {
            if ($chipUid !== null && $chipUid !== '') {
                $q->orWhere('chip_uid', $chipUid);
            }
            if ($serial !== null && $serial !== '') {
                $q->orWhere('printed_serial', strtoupper($serial));
            }
        });
    }
}
