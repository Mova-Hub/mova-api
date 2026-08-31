<?php

namespace App\Models;

use App\Domain\Pass\Enums\SubscriptionStatus;
use App\Domain\Pass\Services\SubscriptionService;
use App\Domain\Payment\Concerns\HasPayments;
use App\Domain\Payment\Contracts\Payable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PassSubscription extends Model implements Payable
{
    use HasPayments;

    protected $fillable = [
        'uuid', 'client_id', 'pass_plan_id', 'status',
        'starts_at', 'expires_at', 'notified_expiring_at', 'trips_remaining',
        'price_paid', 'currency', 'auto_renew',
        'key_id', 'signature',
        'cancelled_at', 'cancel_reason', 'metadata',
    ];

    protected $casts = [
        'status' => SubscriptionStatus::class,
        'starts_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'notified_expiring_at' => 'datetime',
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

    /* ─────────────────────────── Payable ─────────────────────────── */

    /**
     * What the subscription costs.
     *
     * `price_paid` is stamped from the plan when the subscription is created,
     * so it is the price the client was actually shown — a later plan price
     * change must not silently re-price a purchase already in progress. The
     * plan is only a fallback for rows predating that stamp.
     */
    public function paymentAmount(): int
    {
        return (int) ($this->price_paid ?: $this->plan?->price ?: 0);
    }

    public function paymentCurrency(): string
    {
        return $this->currency ?: 'XAF';
    }

    public function paymentDescription(): string
    {
        return 'Mova Pass · ' . ($this->plan?->name ?? 'Abonnement');
    }

    /**
     * Only a subscription awaiting payment may be paid for.
     *
     * An active one is already settled, and an expired one must be renewed —
     * which creates a NEW subscription rather than paying for the dead one, so
     * that the purchase history stays a list of distinct periods.
     */
    public function isPayable(): bool
    {
        return $this->status === SubscriptionStatus::Pending
            && $this->paymentAmount() > 0
            && ! $this->isFullyPaid();
    }

    public function paymentClient(): ?Client
    {
        return $this->client;
    }

    /**
     * Payment is what activates a Pass.
     *
     * Routed through SubscriptionService rather than flipping the column here,
     * because activation also signs the Ed25519 entitlement the offline
     * inspector app verifies. A subscription marked active without that
     * signature is one that fails at the roadside.
     *
     * Only on FULL payment: a deposit on a subscription buys nothing until the
     * balance lands, unlike a charter where a deposit secures the vehicle.
     */
    public function onPaymentSucceeded(Payment $payment): void
    {
        if (! $this->isFullyPaid()) {
            return;
        }

        app(SubscriptionService::class)->activate($this);
    }
}
