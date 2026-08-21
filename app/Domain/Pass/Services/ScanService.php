<?php

namespace App\Domain\Pass\Services;

use App\Domain\Pass\Enums\CardStatus;
use App\Domain\Pass\Enums\ScanSource;
use App\Domain\Pass\Enums\ScanVerdict;
use App\Models\PassCard;
use App\Models\PassScan;
use App\Models\PassSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Deciding a scan, and recording every one of them.
 *
 * The verdict order below is not arbitrary — it runs from "we are certain this
 * card is bad" to "this card is fine but the subscription lapsed", so the worst
 * true statement always wins. A blocked card whose subscription is also expired
 * must read BLOCKED, never EXPIRED, or a stolen card gets shown to the
 * inspector as an innocent oversight.
 *
 * Every scan is logged, accepted or refused. The refusals are the valuable
 * rows: they are the only signal available for the cloning risk PRD §4.3
 * accepts rather than prevents — the same subscriber on two buses at once shows
 * up here or nowhere.
 */
class ScanService
{
    public function __construct(
        private EntitlementSigner $signer,
        private CardPayloadCodec $codec,
    ) {}

    /**
     * Verdict for a presented card, plus the log row.
     *
     * @param  string|null  $payload  the raw card URI, when the reader could read one.
     * @return array{verdict: ScanVerdict, card: ?PassCard, subscription: ?PassSubscription, scan: PassScan}
     */
    public function record(
        ?string $chipUid,
        ?string $payload,
        ScanSource $source,
        array $context = [],
    ): array {
        $now = CarbonImmutable::now();

        // A payload that decodes gives us a chip-independent identity, which is
        // what makes verification possible offline. Online we still prefer the
        // database — see below.
        $decoded = $payload !== null ? $this->codec->decode($payload) : null;

        $card = $chipUid !== null && $chipUid !== ''
            ? PassCard::where('chip_uid', $chipUid)->with('client')->first()
            : null;

        [$verdict, $reason, $subscription] = $this->judge($card, $decoded, $now);

        $scan = $this->log(
            card: $card,
            subscription: $subscription,
            chipUid: $chipUid,
            source: $source,
            verdict: $verdict,
            reason: $reason,
            context: $context,
            now: $now,
        );

        // Only touch the chip's last-seen on a real reader event. The
        // subscriber checking their own card at home is not a sighting in the
        // field and should not look like one in the audit trail.
        if ($card && $source !== ScanSource::App) {
            $card->forceFill(['last_seen_at' => $now])->save();
        }

        return [
            'verdict' => $verdict,
            'card' => $card,
            'subscription' => $subscription,
            'scan' => $scan,
        ];
    }

    /**
     * The verdict itself, with no side effects.
     *
     * Split out so it can be reasoned about and tested without a database
     * write, and so the ordering below is visible in one place.
     *
     * @return array{0: ScanVerdict, 1: ?string, 2: ?PassSubscription}
     */
    private function judge(?PassCard $card, ?array $decoded, CarbonImmutable $now): array
    {
        /*
         * 1. Forgery first.
         *
         * If the reader gave us a payload, its signature decides whether the
         * data is Mova's before anything else is considered. A card whose
         * expiry has been rewritten with a public NFC app fails here — that is
         * criterion A1, and it is the whole reason the scheme is asymmetric.
         *
         * Note the check runs even when the chip UID is unknown to us: an
         * unrecognised card with a BAD signature is a forgery attempt, which is
         * a materially different thing from an unrecognised card.
         */
        if ($decoded !== null) {
            if (! $this->signer->verify($decoded['entitlement'], $decoded['signature'])) {
                return [ScanVerdict::Invalid, 'signature_failed', null];
            }
        }

        // 2. Do we know this chip at all?
        if (! $card) {
            return [ScanVerdict::Unknown, 'card_not_registered', null];
        }

        /*
         * 3. Blocked beats everything that follows — including expiry.
         *
         * `blocked_at` is checked as well as the status, not instead of it: a
         * card reported lost is blocked and then REPLACED, and the replacement
         * overwrites its status. Reading status alone would downgrade a stolen
         * card from "Bloquée" to a generic "Carte invalide" the moment its
         * owner was issued a new one — telling the inspector far less about the
         * card most likely to be in the wrong hands.
         */
        if ($card->status === CardStatus::Blocked || $card->blocked_at !== null) {
            return [ScanVerdict::Blocked, $card->blocked_reason ?? 'blocked', null];
        }

        // 4. Encoded but never claimed, or superseded by a replacement.
        if (! $card->status->isUsable()) {
            return [ScanVerdict::Invalid, 'card_' . $card->status->value, null];
        }

        if ($card->client_id === null) {
            return [ScanVerdict::Invalid, 'card_unclaimed', null];
        }

        /*
         * 5. The subscription decides, not the chip.
         *
         * This is PRD decision D2 option (b) in one line: the server's record
         * is the authority, so renewing online works without the subscriber
         * re-tapping their card. The chip's signed expiry is the offline
         * fallback, checked at step 1 for authenticity only.
         */
        $subscription = PassSubscription::where('client_id', $card->client_id)
            ->current()
            ->with('plan')
            ->first();

        if (! $subscription || ! $subscription->isCurrentlyValid($now)) {
            return [ScanVerdict::Expired, 'no_active_subscription', $subscription];
        }

        return [ScanVerdict::Accepted, null, $subscription];
    }

    /**
     * Writes the log row.
     *
     * Idempotent on `client_reference`. Mova Control uploads a shift's scans in
     * bulk over a connection that will drop, and those uploads get retried — so
     * without a device-generated key, one retry doubles the day's boardings.
     * That is criterion A6.
     */
    private function log(
        ?PassCard $card,
        ?PassSubscription $subscription,
        ?string $chipUid,
        ScanSource $source,
        ScanVerdict $verdict,
        ?string $reason,
        array $context,
        CarbonImmutable $now,
    ): PassScan {
        $reference = $context['client_reference'] ?? null;

        $attributes = [
            'pass_card_id' => $card?->id,
            'client_id' => $card?->client_id,
            'pass_subscription_id' => $subscription?->id,
            // Kept even when no card matched — that is the row fraud analysis
            // needs most.
            'chip_uid' => $chipUid,
            'source' => $source,
            'verdict' => $verdict,
            'reason' => $reason,
            'inspector_id' => $context['inspector_id'] ?? null,
            'bus_line' => $context['bus_line'] ?? null,
            'device_id' => $context['device_id'] ?? null,
            'latitude' => $context['latitude'] ?? null,
            'longitude' => $context['longitude'] ?? null,
            // Device clock — untrusted, see PRD §4.4 — defaulting to ours.
            'scanned_at' => $context['scanned_at'] ?? $now,
            'synced_at' => $now,
            'offline_duration_minutes' => $context['offline_duration_minutes'] ?? null,
        ];

        if ($reference === null) {
            return PassScan::create($attributes);
        }

        return DB::transaction(
            fn () => PassScan::firstOrCreate(
                ['client_reference' => $reference],
                $attributes,
            )
        );
    }
}
