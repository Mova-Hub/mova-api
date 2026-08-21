<?php

namespace App\Models;

use App\Domain\Pass\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PassSubscription extends Model
{
    protected $fillable = [
        'uuid', 'client_id', 'pass_plan_id', 'status',
        'starts_at', 'expires_at', 'trips_remaining',
        'price_paid', 'currency', 'auto_renew',
        'key_id', 'signature',
        'cancelled_at', 'cancel_reason', 'metadata',
    ];

    protected $casts = [
        'status' => SubscriptionStatus::class,
        'starts_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'trips_remaining' => 'integer',
        'price_paid' => 'integer',
        'auto_renew' => 'boolean',
        'cancelled_at' => 'immutable_datetime',
        'metadata' => 'array',
    ];

    /** The signature is an internal artefact; nothing should serialise it. */
    protected $hidden = ['signature'];

    protected static function booted(): void
    {
        static::creating(function (self $subscription) {
            $subscription->uuid ??= (string) Str::uuid();
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PassPlan::class, 'pass_plan_id');
    }

    /**
     * Entitled to travel right now.
     *
     * Status AND expiry, never status alone. A row sits at `active` until the
     * sweep runs, so trusting the column by itself grants free rides for
     * however long the scheduler is behind.
     */
    public function isCurrentlyValid(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->status->grantsTravel()
            && $this->expires_at !== null
            && $this->expires_at->gte($now)
            && ($this->starts_at === null || $this->starts_at->lte($now));
    }

    public function isExpiringSoon(?CarbonImmutable $now = null): bool
    {
        if (! $this->isCurrentlyValid($now)) {
            return false;
        }

        $days = (int) config('pass.subscriptions.expiring_soon_days', 7);

        return $this->expires_at->lte(($now ?? CarbonImmutable::now())->addDays($days));
    }

    public function daysRemaining(?CarbonImmutable $now = null): int
    {
        if ($this->expires_at === null) {
            return 0;
        }

        $now ??= CarbonImmutable::now();

        return max(0, (int) ceil($now->diffInDays($this->expires_at, false)));
    }

    /** The one subscription that counts, when a client has several. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query
            ->where('status', SubscriptionStatus::Active->value)
            ->where('expires_at', '>=', now())
            ->orderByDesc('expires_at');
    }
}
