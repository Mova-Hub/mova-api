<?php

namespace App\Http\Controllers\Api\V2\Calendar;

use App\Domain\Booking\TripSchedule;
use App\Domain\Calendar\IcsCalendar;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Subscribing a phone's calendar to a client's trips.
 *
 * A SUBSCRIPTION, not an export. The alternative was writing each trip into the
 * device calendar with `expo-calendar`, which needs a native module and a fresh
 * build for every user, and produces a snapshot: move a trip and the client's
 * calendar keeps the old time for good. A feed is re-fetched by Google and
 * Apple on their own schedule, so a rescheduled coach corrects itself, a
 * cancelled one disappears, and the app never needed the permission at all.
 *
 * ## Why this route has no auth middleware
 *
 * Google's and Apple's servers fetch the URL, not the app. They send no
 * Authorization header and hold no session, so the URL itself must be the
 * credential. That is how every calendar feed works, and it puts the whole
 * weight of access control on the token:
 *
 *  - 48 random characters from `Str::random`, so it cannot be guessed or walked
 *  - unique and indexed, so the lookup is a single exact match and there is no
 *    partial or prefix comparison anywhere
 *  - rotatable, so a client who has pasted the link somewhere public can revoke
 *    it without losing their account
 *  - rate limited on the route, so the space cannot be swept
 *  - **minimal content**: route, dates, reference and plates. No price, no
 *    phone number, no coordinator, no passenger name. This document ends up
 *    cached on Google's servers, and the rule is that nothing goes in it that
 *    would matter if the URL leaked
 *
 * The failure mode of a leaked token is therefore "a stranger learns this
 * person travels to Pointe-Noire on Tuesday", which is the same thing a leaked
 * calendar link exposes anywhere else, and no worse.
 */
class CalendarFeedController extends Controller
{
    /**
     * How far back the feed reaches.
     *
     * Some history is what makes a calendar useful to look back at, but a feed
     * that carries every trip a client has ever taken grows without limit and
     * is re-fetched in full every few hours. Sixty days is a season.
     */
    private const HISTORY_DAYS = 60;

    /**
     * The client's own subscription URLs.
     *
     * Behind `auth:sanctum`. Generates the token on first ask, so an account
     * that never opens the calendar sheet never has one.
     */
    public function show(Request $request)
    {
        /** @var Client $client */
        $client = $request->user();

        return response()->json([
            'status' => true,
            'data' => $this->urls($this->tokenFor($client)),
        ]);
    }

    /**
     * Issues a new token and invalidates the old link.
     *
     * The one recovery available when a link has been shared by accident.
     * Every calendar already subscribed to the old URL stops updating, which
     * is the point, so the app has to say so before calling this.
     */
    public function rotate(Request $request)
    {
        /** @var Client $client */
        $client = $request->user();

        $client->forceFill(['calendar_token' => $this->newToken()])->save();

        return response()->json([
            'status' => true,
            'message' => 'Ancien lien révoqué. Réabonnez vos calendriers avec le nouveau lien.',
            'data' => $this->urls($client->calendar_token),
        ]);
    }

    /**
     * The feed itself. Public, and the token is the only thing guarding it.
     */
    public function feed(Request $request, string $token)
    {
        /*
         * A length check before the query.
         *
         * Not security in itself, the exact match below is that. It keeps
         * obviously malformed requests off the database while somebody is
         * spraying the route, and it means the query only ever runs with a
         * plausible token.
         */
        if (strlen($token) !== 48) {
            abort(404);
        }

        $client = Client::where('calendar_token', $token)->first();

        if (! $client) {
            abort(404);
        }

        $calendar = new IcsCalendar(
            name: 'Mova',
            description: 'Vos trajets Mova',
        );

        $trips = $this->trips($client);

        foreach ($trips as $order) {
            $this->addTrip($calendar, $order);
        }

        $body = $calendar->render();

        /*
         * Conditional requests.
         *
         * A calendar client polls this endpoint forever, and until now every
         * poll transferred the whole document. The ETag is a hash of the bytes
         * we would have sent, so an unchanged feed answers 304 with no body.
         *
         * This only works because `DTSTAMP` is now anchored to each booking's
         * own timestamp rather than to `now()`. While it was the render time
         * the bytes changed on every request and no ETag could ever match.
         */
        $etag = '"'.md5($body).'"';
        $lastModified = $this->lastModified($trips);

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', Response::HTTP_NOT_MODIFIED, [
                'ETag' => $etag,
                'Cache-Control' => 'private, max-age=0, must-revalidate',
            ]);
        }

        return response($body, Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="mova.ics"',
            'ETag' => $etag,
            'Last-Modified' => $lastModified->toRfc7231String(),
            /*
             * `must-revalidate`, not `no-store`.
             *
             * `no-store` forbade caching outright, which ruled out the 304
             * above. This lets a client hold the document and ask whether it
             * is still current, while `private` still keeps any shared proxy
             * from holding somebody's itinerary.
             */
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            // The URL is a credential. Keeping it out of referrer headers stops
            // it leaking to any host the document happens to reference.
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * The most recent change across everything in the feed.
     *
     * The reservation's timestamp counts as much as the order's, because a
     * reschedule edits the reservation and leaves the order untouched. Using
     * the order alone would report a feed as unchanged at exactly the moment
     * it changed most.
     *
     * @param  \Illuminate\Support\Collection<int, Order>  $trips
     */
    private function lastModified($trips): \Illuminate\Support\Carbon
    {
        return $trips
            ->flatMap(fn (Order $order) => [$order->updated_at, $order->reservation?->updated_at])
            ->filter()
            ->max() ?? now();
    }

    /**
     * Which trips belong in the feed.
     *
     * Confirmed and running reservations only. A request still being quoted has
     * no agreed date to publish, and putting it in somebody's calendar would
     * block out a day for a trip that may never be priced. Cancelled ones are
     * excluded so they disappear from the calendar on the next fetch, which is
     * the behaviour that makes a subscription better than an export.
     *
     * @return \Illuminate\Support\Collection<int, Order>
     */
    private function trips(Client $client)
    {
        $since = now()->subDays(self::HISTORY_DAYS)->startOfDay();

        return Order::query()
            ->where('client_id', $client->id)
            ->with(['reservation.buses'])
            ->whereHas('reservation', fn ($q) => $q->whereIn(
                'status',
                // `cancelled` is included so a cancellation can be PUBLISHED as
                // a tombstone rather than silently vanishing. See `addTrip`.
                ['confirmed', 'in_progress', 'completed', 'cancelled'],
            ))
            /*
             * Filtered on the AGREED date, not the requested one.
             *
             * `reservations.trip_date` is what ops confirmed and what they edit
             * when a trip moves; `orders.pickup_date` is the original request
             * and is never rewritten. COALESCE so a reservation that somehow
             * has no date still falls back rather than disappearing.
             */
            ->whereRaw(
                'COALESCE((select trip_date from reservations where reservations.order_id = orders.id limit 1), orders.pickup_date) >= ?',
                [$since->toDateTimeString()],
            )
            /*
             * Newest first, and this is a correctness fix rather than a
             * preference.
             *
             * It was ascending with the same limit, so a client with more than
             * 200 trips in the window got the OLDEST 200 and lost every
             * upcoming one, which are the only ones that matter. Taking the
             * most recent 200 keeps the future and drops the far past, which
             * is the right end to lose.
             */
            ->orderByDesc('pickup_date')
            // A hard ceiling. Without it one client with a long history makes
            // this endpoint generate a multi-megabyte document on every poll.
            ->limit(200)
            ->get();
    }

    private function addTrip(IcsCalendar $calendar, Order $order): void
    {
        $reservation = $order->reservation;

        /*
         * The AGREED schedule, from `TripSchedule`.
         *
         * This used to read `orders.pickup_date` and the free text
         * `orders.pickup_time` directly, which is the date the client
         * requested on the form and is never rewritten. A trip confirmed for a
         * different day, or rescheduled later, kept its original date in
         * everybody's calendar for good, which defeats the whole point of
         * publishing a subscription rather than a one-off export.
         */
        $schedule = TripSchedule::for($order);

        if (! $schedule) {
            return;
        }

        $start = $schedule->start;
        $end = $schedule->end;
        $allDay = $schedule->allDay;

        $description = collect([
            $reservation?->code ? 'Référence : '.$reservation->code : null,
            $order->passengers ? $order->passengers.' passagers' : null,
            $reservation && $reservation->buses->isNotEmpty()
                ? 'Véhicules : '.$reservation->buses->pluck('plate')->filter()->implode(', ')
                : null,
        ])->filter()->implode("\n");

        $calendar->event(
            /*
             * Stable for the life of the booking, and keyed on the ORDER id,
             * which never changes. Keying on the reservation would break the
             * moment a booking is recreated, and every subscriber would gain a
             * duplicate entry rather than an update.
             */
            uid: 'mova-order-'.$order->id.'@mova.cg',
            summary: sprintf('Mova : %s vers %s', $order->origin, $order->destination),
            start: $start,
            end: $end,
            location: (string) $order->origin,
            description: $description,
            allDay: $allDay,
            /*
             * A cancelled trip is PUBLISHED as cancelled, not dropped.
             *
             * Dropping it does usually remove the entry, because most clients
             * reconcile the feed against what they hold. Most is not all, and
             * a client that only merges what it is sent keeps a stale event
             * for a coach that is not coming. A tombstone with the same UID is
             * the form every client acts on.
             *
             * It stays in the feed for as long as the history window, then
             * ages out with everything else.
             */
            status: $reservation?->status === 'cancelled' ? 'CANCELLED' : 'CONFIRMED',
            // Two hours, and only on a timed event. An all-day entry has no
            // departure to count back from, so an alarm on it fires at
            // midnight and tells somebody nothing. Never on a cancellation.
            reminderMinutes: $allDay || $reservation?->status === 'cancelled' ? null : 120,
            /*
             * What makes a reschedule actually land.
             *
             * The reservation's timestamp, because that is the row ops edit
             * when a trip moves; the order is untouched by a reschedule, so
             * using it would report the booking as unchanged at exactly the
             * moment it changed.
             */
            lastModified: $reservation?->updated_at ?? $order->updated_at,
            sequence: $this->sequenceFor($order),
        );
    }

    /**
     * A revision number that goes up when the booking is edited.
     *
     * Seconds between creation and the last edit, so it starts at 0 and only
     * ever increases for a given event. Derived rather than stored because
     * there is nothing to keep in step: any edit moves `updated_at`, and an
     * untouched booking keeps emitting the same number, which is what lets the
     * document stay byte-identical between polls.
     *
     * Two edits inside the same second produce the same value. `LAST-MODIFIED`
     * carries the difference, and no client relies on SEQUENCE alone.
     */
    private function sequenceFor(Order $order): int
    {
        $row = $order->reservation ?? $order;

        if (! $row->created_at || ! $row->updated_at) {
            return 0;
        }

        return max(0, $row->updated_at->getTimestamp() - $row->created_at->getTimestamp());
    }

    private function tokenFor(Client $client): string
    {
        if (! $client->calendar_token) {
            $client->forceFill(['calendar_token' => $this->newToken()])->save();
        }

        return $client->calendar_token;
    }

    private function newToken(): string
    {
        // `Str::random` is backed by `random_bytes`, so this is CSPRNG output
        // and not `mt_rand`. It is the difference between a token and a
        // sequence somebody can predict from another one.
        return Str::random(48);
    }

    /**
     * @return array<string, string>
     */
    private function urls(string $token): array
    {
        /*
         * `route()`, not `url()`.
         *
         * `routes/api.php` is mounted under an `/api` prefix that `url()` knows
         * nothing about, so building the path by hand produced
         * `https://host/calendar/TOKEN.ics` and every subscription 404'd. The
         * named route is the only thing that stays correct if that prefix ever
         * moves.
         */
        $https = route('calendar.feed', ['token' => $token]);
        $webcal = preg_replace('#^https?://#', 'webcal://', $https);

        return [
            /*
             * Three URLs for one feed, because the two platforms want different
             * schemes for the same document.
             *
             * `webcal://` is what makes iOS hand the link to Calendar and offer
             * to subscribe, rather than opening a text file in Safari. Google
             * wants the https URL wrapped in its own add-by-URL page. The plain
             * https one is what a client pastes into anything else.
             */
            'url' => $https,
            'webcal_url' => $webcal,
            'google_url' => 'https://calendar.google.com/calendar/r?cid='.urlencode($webcal),
        ];
    }
}
