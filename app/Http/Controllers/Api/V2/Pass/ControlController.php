<?php

namespace App\Http\Controllers\Api\V2\Pass;

use App\Domain\Pass\Enums\CardStatus;
use App\Domain\Pass\Enums\ScanSource;
use App\Domain\Pass\Enums\SubscriptionStatus;
use App\Domain\Pass\Services\EntitlementSigner;
use App\Domain\Pass\Services\ScanService;
use App\Models\PassCard;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sync surface for Mova Control (PRD §6.3).
 *
 * Everything here is designed around one constraint: **the inspector's phone is
 * offline when it matters.** These endpoints are what it downloads at the depot
 * in the morning and what it uploads when a signal comes back — nothing here is
 * on the critical path of a fare decision.
 */
class ControlController extends Controller
{
    public function __construct(
        private EntitlementSigner $signer,
        private ScanService $scans,
    ) {}

    /**
     * Public keys, so the app can verify signatures offline.
     *
     * Only public halves, and that is the whole point of choosing Ed25519: this
     * response can be intercepted, cached, decompiled out of the APK or posted
     * publicly and an attacker gains nothing. With the HMAC scheme the source
     * brief proposed, this endpoint could not exist at all.
     *
     * All keys, not just the active one — cards signed under a retired key must
     * keep verifying until they age out (criterion A4).
     */
    public function keys()
    {
        return response()->json([
            'status' => true,
            'data' => [
                'active_key_id' => $this->signer->activeKeyId(),
                'keys' => $this->signer->publicKeys(),
            ],
        ]);
    }

    /**
     * Cards that must be refused on sight.
     *
     * Derived from `pass_cards.status`, not a second table. Two stores that
     * must agree about whether a card is usable is one too many — the moment
     * they diverge, one of them is telling an inspector the wrong thing.
     *
     * Supports `since` so a phone with a recent sync downloads a delta rather
     * than the whole list; this file has to stay small enough to fetch on a
     * weak connection at a depot every morning.
     */
    public function blacklist(Request $request)
    {
        $request->validate(['since' => 'nullable|date']);

        // The scope is the single definition of "blacklisted" — the model and
        // this export must never be able to disagree about it.
        $query = PassCard::query()
            ->blacklisted()
            ->select(['chip_uid', 'status', 'blocked_reason', 'blocked_at', 'updated_at']);

        if ($since = $request->input('since')) {
            $query->where('updated_at', '>', CarbonImmutable::parse($since));
        }

        $cards = $query->orderBy('updated_at')->get();

        return response()->json([
            'status' => true,
            // The device stores this and refuses to operate if its own clock
            // reads earlier (PRD §4.4) — a monotonic check against tampering.
            'synced_at' => CarbonImmutable::now()->toIso8601String(),
            'max_age_hours' => (int) config('pass.control.max_sync_age_hours', 24),
            'count' => $cards->count(),
            'data' => $cards->map(fn (PassCard $card) => [
                'chip_uid' => $card->chip_uid,
                'reason' => $card->blocked_reason ?? $card->status->value,
                'blocked_at' => $card->blocked_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Entitlement snapshot: chip UID → expiry.
     *
     * This is PRD decision D2 option (b), and it is what makes renewal work.
     * If inspectors validated only the chip's signed payload, renewing online
     * would leave the card asserting a stale date and every subscriber would
     * have to remember to re-tap it against their phone after paying. With a
     * snapshot, the card is a pure identifier and renewal is server-side.
     *
     * The estimate in §5.3 holds: roughly 1 MB for 100,000 subscribers.
     */
    public function snapshot(Request $request)
    {
        $request->validate(['since' => 'nullable|date']);

        $query = DB::table('pass_cards')
            ->join('pass_subscriptions', function ($join) {
                $join->on('pass_subscriptions.client_id', '=', 'pass_cards.client_id')
                    ->where('pass_subscriptions.status', SubscriptionStatus::Active->value);
            })
            ->where('pass_cards.status', CardStatus::Active->value)
            ->where('pass_subscriptions.expires_at', '>=', now())
            ->select([
                'pass_cards.chip_uid',
                'pass_subscriptions.expires_at',
                'pass_subscriptions.trips_remaining',
                'pass_subscriptions.updated_at',
            ]);

        if ($since = $request->input('since')) {
            $query->where('pass_subscriptions.updated_at', '>', CarbonImmutable::parse($since));
        }

        $rows = $query->orderBy('pass_subscriptions.updated_at')->get();

        return response()->json([
            'status' => true,
            'synced_at' => CarbonImmutable::now()->toIso8601String(),
            'count' => $rows->count(),
            'data' => $rows->map(fn ($row) => [
                'chip_uid' => $row->chip_uid,
                'expires_at' => CarbonImmutable::parse($row->expires_at)->toIso8601String(),
                // Present but NOT offline-decrementable — see PRD §6. A bundle
                // needs shared state, so two buses would each accept the last
                // trip. Control must treat this as advisory until that is
                // resolved.
                'trips_remaining' => $row->trips_remaining,
            ]),
        ]);
    }

    /**
     * Bulk upload of a shift's scans.
     *
     * Idempotent on `client_reference`, which the device generates per scan.
     * Uploads happen over a connection that drops and WILL be retried; without
     * that key one retry doubles the day's boardings. Criterion A6 is exactly
     * this, and it is the reason the field is required here rather than
     * optional.
     */
    public function uploadScans(Request $request)
    {
        $data = $request->validate([
            'scans' => 'required|array|min:1|max:500',
            'scans.*.client_reference' => 'required|uuid',
            'scans.*.chip_uid' => 'nullable|string|max:64',
            'scans.*.payload' => 'nullable|string|max:512',
            'scans.*.scanned_at' => 'required|date',
            'scans.*.bus_line' => 'nullable|string|max:64',
            'scans.*.device_id' => 'nullable|string|max:128',
            'scans.*.latitude' => 'nullable|numeric|between:-90,90',
            'scans.*.longitude' => 'nullable|numeric|between:-180,180',
            'scans.*.offline_duration_minutes' => 'nullable|integer|min:0',
        ]);

        $inspectorId = $request->user()?->id;
        $accepted = 0;

        foreach ($data['scans'] as $scan) {
            $this->scans->record(
                chipUid: $scan['chip_uid'] ?? null,
                payload: $scan['payload'] ?? null,
                source: ScanSource::Control,
                context: [
                    'client_reference' => $scan['client_reference'],
                    'inspector_id' => $inspectorId,
                    'bus_line' => $scan['bus_line'] ?? null,
                    'device_id' => $scan['device_id'] ?? null,
                    'latitude' => $scan['latitude'] ?? null,
                    'longitude' => $scan['longitude'] ?? null,
                    'scanned_at' => $scan['scanned_at'],
                    'offline_duration_minutes' => $scan['offline_duration_minutes'] ?? null,
                ],
            );

            $accepted++;
        }

        return response()->json([
            'status' => true,
            'accepted' => $accepted,
            'synced_at' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }
}
