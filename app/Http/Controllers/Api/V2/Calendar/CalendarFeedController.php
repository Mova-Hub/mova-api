<?php

namespace App\Http\Controllers\Api\V2\Calendar;

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
    public function feed(string $token)
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

        foreach ($this->trips($client) as $order) {
            $this->addTrip($calendar, $order);
        }

        return response($calendar->render(), Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="mova.ics"',
            // Calendar clients poll on their own schedule and ignore most cache
            // hints, but this stops any proxy in between holding a stale copy
            // of somebody's itinerary.
            'Cache-Control' => 'no-store, private',
            // The URL is a credential. Keeping it out of referrer headers stops
            // it leaking to any host the document happens to reference.
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
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
        return Order::query()
            ->where('client_id', $client->id)
            ->with(['reservation.buses'])
            ->whereHas('reservation', fn ($q) => $q->whereIn('status', ['confirmed', 'in_progress', 'completed']))
            ->whereDate('pickup_date', '>=', now()->subDays(self::HISTORY_DAYS)->toDateString())
            ->orderBy('pickup_date')
            // A hard ceiling. Without it one client with a long history makes
            // this endpoint generate a multi-megabyte document on every poll.
            ->limit(200)
            ->get();
    }

    private function addTrip(IcsCalendar $calendar, Order $order): void
    {
        $reservation = $order->reservation;

        $time = $this->parseTime($order->pickup_time);
        $allDay = $time === null;

        $start = $order->pickup_date->copy()->setTime($time['h'] ?? 0, $time['m'] ?? 0);

        /*
         * The end.
         *
         * A return date makes this a multi-day charter and the entry spans it.
         * Otherwise four hours from departure: a charter here is typically a
         * half day out and back, and an entry that is too short is worse than
         * one that is too long, because it tells somebody they are free at noon
         * when they are on a coach.
         */
        $end = $order->return_date
            ? $order->return_date->copy()->setTime(
                $this->parseTime($order->return_time)['h'] ?? 23,
                $this->parseTime($order->return_time)['m'] ?? 59,
            )
            : $start->copy()->addHours(4);

        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->copy()->addHours(4);
        }

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
            // Always CONFIRMED. The feed only carries confirmed, running and
            // completed trips, and a past trip is still something that
            // happened, so there is no state here that maps to TENTATIVE.
            // Cancelled ones are excluded by the query rather than published
            // with STATUS:CANCELLED, so they vanish on the next fetch.
            status: 'CONFIRMED',
            // Two hours, and only on a timed event. An all-day entry has no
            // departure to count back from, so an alarm on it fires at
            // midnight and tells somebody nothing.
            reminderMinutes: $allDay ? null : 120,
        );
    }

    /**
     * `pickup_time` is free text on this table and older rows really do hold
     * things like "tot le matin". Anything unrecognised makes the entry
     * all-day rather than inventing an hour somebody would plan around.
     *
     * @return array{h: int, m: int}|null
     */
    private function parseTime(?string $value): ?array
    {
        if (! $value || ! preg_match('/^(\d{1,2})\s*[:hH]?\s*(\d{2})?/', trim($value), $m)) {
            return null;
        }

        $h = (int) $m[1];
        $min = isset($m[2]) ? (int) $m[2] : 0;

        return ($h > 23 || $min > 59) ? null : ['h' => $h, 'm' => $min];
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
