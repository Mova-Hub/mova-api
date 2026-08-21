<?php

namespace App\Domain\Pass\Services;

use App\Domain\Pass\DTOs\Entitlement;
use App\Domain\Pass\Enums\SubscriptionStatus;
use App\Domain\Pass\Exceptions\PassException;
use App\Models\Client;
use App\Models\PassPlan;
use App\Models\PassSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Buying, renewing and ending a Mova Pass.
 *
 * The subscription is the authority on whether someone may travel — not the
 * card. That resolves PRD open decision D2 in favour of the server snapshot:
 * renewing extends a row here, and inspectors validate against a downloaded
 * copy of it, so a subscriber who renews in the app never has to remember to
 * re-tap their card to make the chip agree.
 */
class SubscriptionService
{
    public function __construct(
        private EntitlementSigner $signer,
        private CardPayloadCodec $codec,
    ) {}

    /**
     * Starts or extends a client's subscription to a plan.
     *
     * Idempotent per (client, plan, pending) is deliberately NOT attempted:
     * buying two months in a row is a legitimate thing to do. What IS
     * guaranteed is that concurrent calls cannot both read the same expiry and
     * both extend from it — the client row is locked for the duration.
     *
     * @param  bool  $activate  false while payment is pending; the row exists but confers nothing.
     */
    public function subscribe(Client $client, PassPlan $plan, bool $activate = false): PassSubscription
    {
        if (! $plan->is_active) {
            throw PassException::planUnavailable();
        }

        return DB::transaction(function () use ($client, $plan, $activate) {
            // Serialises concurrent renewals for this client. Without it, two
            // requests arriving together both read the old expiry and the
            // second silently overwrites the first — a month the client paid
            // for and never receives.
            $client = Client::whereKey($client->getKey())->lockForUpdate()->firstOrFail();

            $now = CarbonImmutable::now();
            $current = $this->currentSubscription($client);

            /*
             * Renewing early extends from the CURRENT expiry, not from today.
             *
             * The alternative throws away the unused remainder every time
             * somebody renews before they lapse — punishing precisely the
             * customers who renew on time.
             */
            $startsAt = ($current && config('pass.subscriptions.extend_from_current_expiry', true))
                ? $current->expires_at->max($now)
                : $now;

            $subscription = new PassSubscription([
                'uuid' => (string) Str::uuid(),
                'client_id' => $client->id,
                'pass_plan_id' => $plan->id,
                'status' => $activate ? SubscriptionStatus::Active : SubscriptionStatus::Pending,
                'starts_at' => $startsAt,
                'expires_at' => CarbonImmutable::instance($plan->expiryFrom($startsAt)),
                // Copied, not read through the relation: a plan whose price or
                // trip count changes must not retroactively rewrite what
                // someone already bought.
                'trips_remaining' => $plan->trips,
                'price_paid' => $plan->price,
                'currency' => $plan->currency,
            ]);

            $subscription->save();

            if ($activate) {
                $this->signEntitlement($client, $subscription);
            }

            return $subscription->fresh(['plan']);
        });
    }

    /**
     * Marks a pending subscription paid.
     *
     * Separate from `subscribe` because payment is asynchronous — a mobile-money
     * callback lands minutes later, and the entitlement must not be signed
     * before the money is real.
     */
    public function activate(PassSubscription $subscription): PassSubscription
    {
        return DB::transaction(function () use ($subscription) {
            $subscription = PassSubscription::whereKey($subscription->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($subscription->status === SubscriptionStatus::Active) {
                return $subscription;
            }

            $subscription->status = SubscriptionStatus::Active;
            $subscription->save();

            $this->signEntitlement($subscription->client, $subscription);

            return $subscription->fresh(['plan']);
        });
    }

    public function cancel(PassSubscription $subscription, ?string $reason = null): PassSubscription
    {
        $subscription->forceFill([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => CarbonImmutable::now(),
            'cancel_reason' => $reason,
        ])->save();

        return $subscription;
    }

    /**
     * The subscription that decides whether this client travels today.
     *
     * Latest expiry wins when several overlap, which is what stacked renewals
     * produce.
     */
    public function currentSubscription(Client $client): ?PassSubscription
    {
        return PassSubscription::where('client_id', $client->id)
            ->current()
            ->with('plan')
            ->first();
    }

    /**
     * Moves lapsed rows to `expired`.
     *
     * A maintenance sweep, NOT the thing that decides a fare. Every read path
     * checks the expiry date itself (`isCurrentlyValid`), so a scheduler that
     * is hours behind delays a label, never grants a free ride.
     *
     * @return int rows updated
     */
    public function expireLapsed(): int
    {
        return PassSubscription::where('status', SubscriptionStatus::Active->value)
            ->where('expires_at', '<', now())
            ->update(['status' => SubscriptionStatus::Expired->value]);
    }

    /**
     * Signs the entitlement this subscription represents.
     *
     * Stored on the subscription rather than only on the card so that a
     * renewal produces a fresh signed snapshot without anyone touching the
     * chip — the point of choosing D2 option (b).
     */
    public function signEntitlement(Client $client, PassSubscription $subscription): PassSubscription
    {
        $passUuid = $this->ensurePassUuid($client);
        $keyId = $this->signer->activeKeyId();

        $entitlement = Entitlement::fromDate(
            (string) config('pass.payload.version', '1'),
            $keyId,
            $this->codec->subscriberIdFor($passUuid),
            $subscription->expires_at ?? CarbonImmutable::now(),
        );

        $subscription->forceFill([
            'key_id' => $keyId,
            'signature' => $this->signer->sign($entitlement),
        ])->save();

        return $subscription;
    }

    /**
     * The client's Pass identifier, created on first use.
     *
     * Lazy because the overwhelming majority of clients only ever charter a
     * bus and will never own a card — there is no reason to mint an identifier
     * for all of them.
     */
    public function ensurePassUuid(Client $client): string
    {
        if (empty($client->pass_uuid)) {
            $client->forceFill(['pass_uuid' => (string) Str::uuid()])->save();
        }

        return $client->pass_uuid;
    }
}
