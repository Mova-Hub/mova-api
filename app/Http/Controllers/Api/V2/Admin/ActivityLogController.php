<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Audit\ActivityLogResource;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Reading the audit trail.
 *
 * **Read-only, and there is no write route anywhere in the application.** An
 * audit log an operator can edit or delete proves nothing — the first thing
 * anyone covering their tracks would reach for is the delete button. Rows leave
 * only by ageing out, through `activity:prune`, on a schedule.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'actor_type' => ['nullable', 'string', 'max:120'],
            'actor_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:120'],
            'subject_id' => ['nullable', 'integer'],
            'request_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'kind' => ['nullable', 'in:mutations,access'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $query = ActivityLog::query()->latest('id');

        // Mutations and reads are separated by default in the UI, because a
        // hundred invoice views should not push one price change off page one.
        if ($kind = $request->input('kind')) {
            $kind === 'access' ? $query->accessOnly() : $query->mutationsOnly();
        }

        foreach (['action', 'actor_type', 'actor_id', 'subject_type', 'subject_id', 'request_id'] as $field) {
            if (($value = $request->input($field)) !== null && $value !== '') {
                // `action` is a prefix match so `order` finds `order.updated`,
                // `order.created` and so on without listing every verb.
                $field === 'action'
                    ? $query->where('action', 'like', $value . '%')
                    : $query->where($field, $value);
            }
        }

        if ($from = $request->input('from')) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->where('created_at', '<=', $to);
        }

        if ($search = $request->string('search')->trim()->toString()) {
            // Against the denormalised labels, not a join — which is the whole
            // reason they are stored: entries belonging to a deleted staff
            // member stay searchable.
            $query->where(function ($q) use ($search) {
                $q->where('actor_label', 'like', "%{$search}%")
                  ->orWhere('subject_label', 'like', "%{$search}%");
            });
        }

        return ActivityLogResource::collection(
            $query->paginate((int) $request->input('per_page', 50))
        );
    }

    public function show(int $id)
    {
        return new ActivityLogResource(ActivityLog::findOrFail($id));
    }

    /**
     * Approximate origin of the request, from its IP.
     *
     * **This is network-level, not a position.** An IP resolves to whoever
     * announces the address block, which on MTN Congo or Airtel Congo is a
     * carrier gateway that may be in a different city — or a different country
     * — from the person holding the phone. A VPN moves it anywhere at all.
     *
     * That distinction matters more here than almost anywhere else in the
     * product, because this figure appears on an audit record: a dot on a map
     * beside someone's name reads as evidence of where they were, and it is
     * nothing of the sort. The response therefore always carries
     * `is_approximate` and an `accuracy_km`, and the client is expected to draw
     * a radius rather than a pin.
     *
     * **Disabled unless a provider is configured, deliberately.** Turning it on
     * by default would send customers' and staff members' IP addresses to a
     * third party on every page view, silently. That is a decision for whoever
     * runs the deployment, not a default.
     */
    public function location(int $id)
    {
        $log = ActivityLog::findOrFail($id);

        if (! $log->ip) {
            return response()->json(['status' => true, 'data' => [
                'resolvable' => false,
                'reason' => 'no_ip',
            ]]);
        }

        // Loopback, RFC1918 and friends. A private address is the operator's
        // own network and geolocating it is meaningless, not merely imprecise.
        if (! filter_var($log->ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return response()->json(['status' => true, 'data' => [
                'resolvable' => false,
                'reason' => 'private_ip',
                'ip' => $log->ip,
            ]]);
        }

        $provider = config('services.ip_geolocation.provider');

        if (! $provider) {
            return response()->json(['status' => true, 'data' => [
                'resolvable' => false,
                'reason' => 'not_configured',
                'ip' => $log->ip,
            ]]);
        }

        /*
         * Cached for a week, keyed on the IP.
         *
         * An audit record never changes, so the same lookup would otherwise
         * repeat on every open — paying a third-party call, and re-disclosing
         * the same address, to learn something already known.
         */
        $data = Cache::remember(
            'ipgeo:' . md5($log->ip),
            now()->addWeek(),
            fn () => $this->resolveIp($log->ip, $provider),
        );

        return response()->json(['status' => true, 'data' => $data]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveIp(string $ip, string $provider): array
    {
        try {
            $response = match ($provider) {
                'ipapi' => Http::timeout(4)->get("https://ipapi.co/{$ip}/json/"),
                'ipinfo' => Http::timeout(4)
                    ->withToken((string) config('services.ip_geolocation.key'))
                    ->get("https://ipinfo.io/{$ip}/json"),
                default => null,
            };

            if (! $response || ! $response->successful()) {
                return ['resolvable' => false, 'reason' => 'lookup_failed', 'ip' => $ip];
            }

            $body = $response->json();

            // ipinfo packs coordinates into one "lat,lng" string; ipapi returns
            // them as separate numbers.
            if ($provider === 'ipinfo') {
                [$lat, $lng] = array_pad(explode(',', (string) ($body['loc'] ?? '')), 2, null);
            } else {
                $lat = $body['latitude'] ?? null;
                $lng = $body['longitude'] ?? null;
            }

            if ($lat === null || $lng === null || $lat === '') {
                return ['resolvable' => false, 'reason' => 'lookup_failed', 'ip' => $ip];
            }

            return [
                'resolvable' => true,
                'ip' => $ip,
                'latitude' => (float) $lat,
                'longitude' => (float) $lng,
                'city' => $body['city'] ?? null,
                'region' => $body['region'] ?? null,
                'country' => $body['country_name'] ?? $body['country'] ?? null,
                'organisation' => $body['org'] ?? $body['asn'] ?? null,
                // Never a point. City-level resolution is tens of kilometres at
                // best, and the client draws this as a circle so nobody reads
                // the centre as a location.
                'accuracy_km' => 50,
                'is_approximate' => true,
                'provider' => $provider,
            ];
        } catch (\Throwable) {
            // A geolocation outage must never break the audit page. The entry
            // itself is the record; this is decoration on top of it.
            return ['resolvable' => false, 'reason' => 'lookup_failed', 'ip' => $ip];
        }
    }

    /**
     * Everything that happened in one request.
     *
     * The pivot from a Sentry event or a log line into the mutations that
     * request produced — the reason `request_id` exists on all three.
     */
    public function byRequest(string $requestId)
    {
        return ActivityLogResource::collection(
            ActivityLog::where('request_id', $requestId)->orderBy('id')->get()
        );
    }

    /** The distinct verbs present, for populating a filter without hardcoding. */
    public function actions()
    {
        return response()->json([
            'status' => true,
            'data' => ActivityLog::query()
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action'),
        ]);
    }
}
