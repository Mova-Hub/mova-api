<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One recorded action.
 *
 * Append-only by design: there is no `updated_at`, nothing in the application
 * writes to a row twice, and the back-office surface is read-only. An audit
 * entry that can be edited is not an audit entry.
 */
class ActivityLog extends Model
{
    /** Rows are never updated, so Eloquent must not look for `updated_at`. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'uuid',
        'actor_type', 'actor_id', 'actor_label',
        'action',
        'subject_type', 'subject_id', 'subject_label',
        'before', 'after', 'changed',
        'ip', 'user_agent',
        'request_id', 'route', 'method', 'status_code', 'duration_ms',
        'context',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'changed' => 'array',
        'context' => 'array',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
        'created_at' => 'immutable_datetime',
    ];

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** Everything that ever happened to one record. */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey());
    }

    /** Everything one person did. */
    public function scopeByActor(Builder $query, string $type, int|string $id): Builder
    {
        return $query->where('actor_type', $type)->where('actor_id', $id);
    }

    /**
     * Reads only — the sensitive-access half of the log.
     *
     * Kept apart from mutations because it is retained for a shorter period and
     * because mixing the two buries the entries that matter: a hundred invoice
     * views should not push one price change off the first page.
     */
    public function scopeAccessOnly(Builder $query): Builder
    {
        return $query->where('action', 'like', '%.accessed');
    }

    public function scopeMutationsOnly(Builder $query): Builder
    {
        return $query->where('action', 'not like', '%.accessed');
    }
}
