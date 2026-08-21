<?php

namespace App\Domain\Pass\Services;

use App\Domain\Pass\DTOs\Entitlement;
use App\Domain\Pass\Enums\CardStatus;
use App\Domain\Pass\Exceptions\PassException;
use App\Models\Client;
use App\Models\PassCard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issuing, activating, blocking and replacing physical cards.
 *
 * Two rules run through everything here:
 *
 *  1. **A card leaves the counter unowned.** It is written and verified, then
 *     sits at ENCODED until a real subscriber claims it. That is what makes a
 *     stolen blank batch worthless — the chips carry valid signed payloads, but
 *     the server refuses every one of them.
 *  2. **Failures about someone else's card are indistinguishable.** "Already
 *     activated", "blocked" and "belongs to another account" all return the
 *     same message, because telling them apart turns this endpoint into an
 *     oracle for probing which serials exist and which are live.
 */
class CardService
{
    /** Crockford base32: no I, L, O or U, so a hand-copied serial cannot be misread. */
    private const SERIAL_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function __construct(
        private EntitlementSigner $signer,
        private CardPayloadCodec $codec,
        private SubscriptionService $subscriptions,
    ) {}

    /**
     * Registers a blank chip against a client and returns the payload to write.
     *
     * Called by the counter, which then hands the URI to the bridge script. The
     * private key never leaves this server — the back-office asks for a
     * signature, it does not produce one (PRD §4.1).
     *
     * @return array{card: PassCard, payload: string}
     */
    public function issue(string $chipUid, ?Client $client = null, ?int $issuedBy = null): array
    {
        return DB::transaction(function () use ($chipUid, $client, $issuedBy) {
            // A chip can only be registered once. Re-encoding an existing chip
            // is `reencode()`, which keeps the audit trail; a second row for
            // the same UID would let two subscribers hold "different" cards
            // that are physically the same object.
            if (PassCard::where('chip_uid', $chipUid)->exists()) {
                throw new PassException(
                    'Cette puce est déjà enregistrée.',
                    409,
                    'chip_already_registered',
                );
            }

            $card = PassCard::create([
                'uuid' => (string) Str::uuid(),
                'client_id' => $client?->id,
                'chip_uid' => $chipUid,
                'printed_serial' => $this->generateSerial(),
                'status' => CardStatus::Encoded,
                'issued_by' => $issuedBy,
            ]);

            $payload = $this->writePayload($card, $client);

            return ['card' => $card->fresh(), 'payload' => $payload];
        });
    }

    /**
     * Binds a card to the authenticated client.
     *
     * Matched by chip UID (tapped) or printed serial (typed — PA-2, the only
     * route in on an iPhone whose owner dismissed Apple's scan sheet).
     *
     * Idempotent: re-activating a card the same client already owns returns it
     * rather than erroring, because a flaky connection retrying a successful
     * activation should not look like a failure.
     */
    public function activate(Client $client, ?string $chipUid, ?string $serial): PassCard
    {
        return DB::transaction(function () use ($client, $chipUid, $serial) {
            $card = PassCard::matching($chipUid, $serial)->lockForUpdate()->first();

            if (! $card) {
                throw PassException::cardNotFound();
            }

            // Blocked is called out by name: it is the one case the rightful
            // owner needs to understand, and they already know the card exists
            // because they are holding it.
            if ($card->status === CardStatus::Blocked) {
                throw PassException::cardBlocked();
            }

            if ($card->client_id !== null && $card->client_id !== $client->id) {
                // Deliberately vague — see the class docblock.
                throw PassException::cardUnavailable();
            }

            if ($card->status === CardStatus::Replaced) {
                throw PassException::cardUnavailable();
            }

            if ($card->status === CardStatus::Active && $card->client_id === $client->id) {
                return $card;
            }

            $card->forceFill([
                'client_id' => $client->id,
                'status' => CardStatus::Active,
                'activated_at' => CarbonImmutable::now(),
            ])->save();

            // The chip was encoded before anyone owned it, so its payload
            // carries no subscriber. Re-sign it now that it does.
            $this->writePayload($card->fresh(), $client);

            return $card->fresh();
        });
    }

    /**
     * Blocks a card. Self-service (PC-1) or staff.
     *
     * Terminal by design: a card reported stolen is never un-blocked, it is
     * replaced. "Unblocking" would mean a thief who returns a card can put it
     * back into circulation, and it makes the blacklist a moving target that
     * offline inspectors cannot reason about.
     */
    public function block(PassCard $card, string $reason = 'lost'): PassCard
    {
        $card->forceFill([
            'status' => CardStatus::Blocked,
            'blocked_at' => CarbonImmutable::now(),
            'blocked_reason' => $reason,
        ])->save();

        return $card;
    }

    /**
     * Issues a replacement and retires the old card.
     *
     * The old one goes to REPLACED, not deleted: it stays on the blacklist
     * export and in the scan history, so a card that turns up later is still
     * recognisable rather than "unknown".
     *
     * @return array{card: PassCard, payload: string}
     */
    public function replace(PassCard $old, string $newChipUid, ?int $issuedBy = null): array
    {
        return DB::transaction(function () use ($old, $newChipUid, $issuedBy) {
            $client = $old->client;

            $issued = $this->issue($newChipUid, $client, $issuedBy);

            $old->forceFill([
                'status' => CardStatus::Replaced,
                'replaced_by_id' => $issued['card']->id,
            ])->save();

            if ($client) {
                $issued['card'] = $this->activateSilently($issued['card'], $client);
                $issued['payload'] = $this->writePayload($issued['card'], $client);
            }

            return $issued;
        });
    }

    /**
     * Re-signs a card and returns the URI to write to the chip.
     *
     * Used when the entitlement changes and the chip should catch up. Note this
     * is an OPTIMISATION, not a requirement: inspectors validate against the
     * server snapshot (D2 option b), so a chip carrying a stale expiry is a
     * fallback that is merely out of date, not a card that stops working.
     */
    public function reencode(PassCard $card): string
    {
        return $this->writePayload($card, $card->client);
    }

    /** The current card for a client, if any. */
    public function currentCard(Client $client): ?PassCard
    {
        return PassCard::where('client_id', $client->id)
            ->whereIn('status', [CardStatus::Active->value, CardStatus::Encoded->value])
            ->orderByDesc('activated_at')
            ->orderByDesc('id')
            ->first();
    }

    private function activateSilently(PassCard $card, Client $client): PassCard
    {
        $card->forceFill([
            'client_id' => $client->id,
            'status' => CardStatus::Active,
            'activated_at' => CarbonImmutable::now(),
        ])->save();

        return $card->fresh();
    }

    /**
     * Signs the card's entitlement and records what was written.
     *
     * An unowned card is signed against its own uuid with a zero expiry, so the
     * chip is never blank — but it entitles nobody until activation replaces
     * that payload.
     */
    private function writePayload(PassCard $card, ?Client $client): string
    {
        $expiresAt = CarbonImmutable::now();
        $subscriberId = $this->codec->subscriberIdFor($card->uuid);

        if ($client) {
            $subscriberId = $this->codec->subscriberIdFor($this->subscriptions->ensurePassUuid($client));
            $subscription = $this->subscriptions->currentSubscription($client);

            if ($subscription?->expires_at) {
                $expiresAt = $subscription->expires_at;
            }
        }

        $keyId = $this->signer->activeKeyId();

        $entitlement = Entitlement::fromDate(
            (string) config('pass.payload.version', '1'),
            $keyId,
            $subscriberId,
            $expiresAt,
        );

        $signature = $this->signer->sign($entitlement);

        $card->forceFill([
            'key_id' => $keyId,
            'signature' => $signature,
            'entitlement_expires_at' => $expiresAt,
        ])->save();

        return $this->codec->encode($entitlement, $signature);
    }

    /**
     * A random, non-sequential serial.
     *
     * `random_int` and not `rand`: this is a credential, and a predictable one
     * would let anyone enumerate cards and try to claim them. 12 Crockford
     * characters is ~60 bits, and the endpoint is rate-limited on top.
     */
    private function generateSerial(): string
    {
        $length = (int) config('pass.cards.serial_length', 12);
        $max = strlen(self::SERIAL_ALPHABET) - 1;

        do {
            $serial = '';
            for ($i = 0; $i < $length; $i++) {
                $serial .= self::SERIAL_ALPHABET[random_int(0, $max)];
            }
        } while (PassCard::where('printed_serial', $serial)->exists());

        return $serial;
    }
}
