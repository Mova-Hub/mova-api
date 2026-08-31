<?php

namespace Tests\Feature;

use App\Domain\Calendar\IcsCalendar;
use App\Models\Bus;
use App\Models\Client;
use App\Models\Order;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The calendar subscription feed.
 *
 * Two things are being protected here. The ICS document has to be one a real
 * calendar client will accept, which is a format with sharp edges and no error
 * reporting: Apple Calendar answers a malformed feed with an empty
 * subscription and nothing else. And the feed route has no auth middleware, so
 * every access rule rests on the token, which makes those the tests that
 * matter most.
 */
class CalendarFeedTest extends TestCase
{
    use RefreshDatabase;

    private function client(string $phone = '+242064074926'): Client
    {
        return Client::create([
            'name' => 'Test Client',
            'phone' => $phone,
            'email' => 'c'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
        ]);
    }

    private function confirmedOrder(Client $client, array $attributes = []): Order
    {
        $order = Order::create(array_merge([
            'client_id' => $client->id,
            'status' => 'converted',
            'event_type' => 'wedding',
            'origin' => 'Brazzaville',
            'destination' => 'Pointe-Noire',
            'pickup_date' => now()->addDays(5)->toDateString(),
            'pickup_time' => '06:00',
            'passengers' => 40,
            'quoted_total' => 500000,
            'fleet_requirements' => [],
            'contact_name' => 'Test Client',
            'contact_phone' => '+242064074926',
        ], $attributes));

        $reservation = Reservation::create([
            'order_id' => $order->id,
            'client_id' => $client->id,
            'from_location' => $order->origin,
            'to_location' => $order->destination,
            'passenger_name' => $order->contact_name,
            'passenger_phone' => $order->contact_phone,
            'trip_date' => $order->pickup_date,
            'status' => $attributes['reservation_status'] ?? 'confirmed',
            'seats' => 30,
            'price_total' => 500000,
        ]);

        $bus = Bus::create([
            'plate' => 'BZV-'.fake()->unique()->numerify('####'),
            'model' => 'Coaster',
            'capacity' => 30,
            'status' => 'active',
        ]);

        $reservation->buses()->sync([$bus->id]);

        return $order->fresh();
    }

    private function tokenFor(Client $client): string
    {
        Sanctum::actingAs($client);

        return $this->getJson('/api/app/v1/calendar/feed')->json('data.url');
    }

    /* ─────────────────────────── The URLs ─────────────────────────── */

    public function test_a_client_gets_three_urls_for_one_feed(): void
    {
        Sanctum::actingAs($client = $this->client());

        $data = $this->getJson('/api/app/v1/calendar/feed')
            ->assertOk()
            ->json('data');

        $this->assertStringContainsString('.ics', $data['url']);
        $this->assertStringStartsWith('webcal://', $data['webcal_url']);
        $this->assertStringStartsWith('https://calendar.google.com/', $data['google_url']);
    }

    public function test_the_token_is_generated_once_and_reused(): void
    {
        Sanctum::actingAs($client = $this->client());

        $first = $this->getJson('/api/app/v1/calendar/feed')->json('data.url');
        $second = $this->getJson('/api/app/v1/calendar/feed')->json('data.url');

        $this->assertSame($first, $second, 'A stable URL, or every fetch would orphan the last subscription.');
    }

    public function test_rotating_revokes_the_old_link(): void
    {
        Sanctum::actingAs($client = $this->client());

        $old = $this->getJson('/api/app/v1/calendar/feed')->json('data.url');
        $new = $this->postJson('/api/app/v1/calendar/feed/rotate')->assertOk()->json('data.url');

        $this->assertNotSame($old, $new);
        $this->get(parse_url($old, PHP_URL_PATH))->assertNotFound();
        $this->get(parse_url($new, PHP_URL_PATH))->assertOk();
    }

    public function test_the_token_never_leaks_through_serialization(): void
    {
        // The whole access model rests on this token staying secret, and the
        // easiest way to lose it is a resource that dumps the model.
        $client = $this->client();
        $client->forceFill(['calendar_token' => str_repeat('a', 48)])->save();

        $this->assertArrayNotHasKey('calendar_token', $client->fresh()->toArray());
    }

    /* ─────────────────────────── Access ─────────────────────────── */

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->get('/api/calendar/'.str_repeat('z', 48).'.ics')->assertNotFound();
    }

    public function test_a_malformed_token_is_not_found(): void
    {
        $this->get('/api/calendar/short.ics')->assertNotFound();
    }

    public function test_the_feed_needs_no_authentication(): void
    {
        // Google and Apple fetch this with no credentials at all. If it ever
        // starts requiring a session, every subscription silently stops
        // updating and nobody finds out.
        $client = $this->client();
        $this->confirmedOrder($client);
        $url = $this->tokenFor($client);

        app('auth')->forgetGuards();

        $this->get(parse_url($url, PHP_URL_PATH))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
    }

    public function test_one_client_never_sees_another_clients_trips(): void
    {
        $mine = $this->client('+242064074926');
        $theirs = $this->client('+242064074927');

        $this->confirmedOrder($theirs, ['destination' => 'Dolisie']);
        $url = $this->tokenFor($mine);

        $body = $this->get(parse_url($url, PHP_URL_PATH))->getContent();

        $this->assertStringNotContainsString('Dolisie', $body);
    }

    /* ─────────────────────── What the feed carries ─────────────────────── */

    public function test_a_confirmed_trip_appears(): void
    {
        $client = $this->client();
        $order = $this->confirmedOrder($client);
        $url = $this->tokenFor($client);

        $body = $this->get(parse_url($url, PHP_URL_PATH))->getContent();

        $this->assertStringContainsString('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('mova-order-'.$order->id.'@mova.cg', $body);
        $this->assertStringContainsString('Pointe-Noire', $body);
    }

    public function test_a_lead_still_being_quoted_does_not_appear(): void
    {
        // No agreed date to publish, and blocking out a day for a trip that may
        // never be priced is worse than showing nothing.
        $client = $this->client();

        Order::create([
            'client_id' => $client->id,
            'status' => 'pending',
            'event_type' => 'wedding',
            'origin' => 'Brazzaville',
            'destination' => 'Oyo',
            'pickup_date' => now()->addDays(5)->toDateString(),
            'pickup_time' => '06:00',
            'fleet_requirements' => [],
            'contact_name' => 'Test',
            'contact_phone' => '+242064074926',
        ]);

        $url = $this->tokenFor($client);
        $body = $this->get(parse_url($url, PHP_URL_PATH))->getContent();

        $this->assertStringNotContainsString('Oyo', $body);
    }

    public function test_a_cancelled_trip_is_published_as_cancelled_rather_than_dropped(): void
    {
        /*
         * This test asserted the opposite until the robustness pass, and the
         * old behaviour was the weaker one. Dropping the event does remove it
         * in most clients, because most reconcile the feed against what they
         * hold. Most is not all, and a client that only merges what it is sent
         * keeps a stale entry for a coach that is not coming. A tombstone under
         * the same UID is the form every client acts on.
         */
        $client = $this->client();
        $order = $this->confirmedOrder($client, ['destination' => 'Dolisie']);
        $url = $this->tokenFor($client);

        $body = $this->get(parse_url($url, PHP_URL_PATH))->getContent();
        $this->assertStringContainsString('Dolisie', $body);
        $this->assertStringNotContainsString('STATUS:CANCELLED', $body);

        $order->reservation->update(['status' => 'cancelled']);

        $body = $this->get(parse_url($url, PHP_URL_PATH))->getContent();
        $this->assertStringContainsString('mova-order-'.$order->id.'@mova.cg', $body);
        $this->assertStringContainsString('STATUS:CANCELLED', $body);
        // No alarm on a trip that is not happening.
        $this->assertStringNotContainsString('BEGIN:VALARM', $body);
    }

    public function test_the_feed_carries_no_price_and_no_phone_number(): void
    {
        // This document is cached on Google's servers. Nothing goes in it that
        // would matter if the URL leaked.
        $client = $this->client();
        $this->confirmedOrder($client);
        $url = $this->tokenFor($client);

        $body = $this->get(parse_url($url, PHP_URL_PATH))->getContent();

        $this->assertStringNotContainsString('500000', $body);
        $this->assertStringNotContainsString('064074926', $body);
        $this->assertStringNotContainsString('Test Client', $body);
    }

    public function test_an_unparseable_pickup_time_becomes_an_all_day_entry(): void
    {
        $client = $this->client();
        $this->confirmedOrder($client, ['pickup_time' => 'tôt le matin']);
        $url = $this->tokenFor($client);

        $body = $this->get(parse_url($url, PHP_URL_PATH))->getContent();

        $this->assertStringContainsString('DTSTART;VALUE=DATE:', $body);
    }

    /* ──────────────── Issue #7.1: the agreed date wins ──────────────── */

    public function test_the_event_uses_the_agreed_date_not_the_requested_one(): void
    {
        // The bug: the feed read `orders.pickup_date`, which is what the client
        // asked for on the form and is never rewritten. Ops confirmed a
        // different day here, which is the ordinary case.
        $client = $this->client();
        $order = $this->confirmedOrder($client, [
            'pickup_date' => now()->addDays(5)->toDateString(),
        ]);

        $agreed = now()->addDays(9)->setTime(7, 30);
        $order->reservation->update(['trip_date' => $agreed]);

        $body = $this->get(parse_url($this->tokenFor($client), PHP_URL_PATH))->getContent();

        $this->assertStringContainsString('DTSTART:'.$agreed->clone()->utc()->format('Ymd\THis\Z'), $body);
        $this->assertStringNotContainsString($order->pickup_date->format('Ymd').'T', $body);
    }

    public function test_rescheduling_moves_the_event(): void
    {
        // The whole argument for a subscription over an export. It did not
        // work before, because a reschedule edits the reservation and the feed
        // was reading the order.
        $client = $this->client();
        $order = $this->confirmedOrder($client);
        $url = parse_url($this->tokenFor($client), PHP_URL_PATH);

        $order->reservation->update(['trip_date' => now()->addDays(20)->setTime(9, 0)]);

        $body = $this->get($url)->getContent();

        $this->assertStringContainsString(
            'DTSTART:'.now()->addDays(20)->setTime(9, 0)->utc()->format('Ymd\THis\Z'),
            $body,
        );
    }

    public function test_a_trip_confirmed_at_midnight_falls_back_to_the_stated_time(): void
    {
        // `trip_date` is a dateTime, but a date-only form stores midnight. A
        // charter does not depart at midnight, so that is a missing time
        // rather than a real one, and the order's own clock is used instead.
        $client = $this->client();
        $order = $this->confirmedOrder($client, ['pickup_time' => '06:00']);

        $order->reservation->update(['trip_date' => now()->addDays(4)->startOfDay()]);

        $body = $this->get(parse_url($this->tokenFor($client), PHP_URL_PATH))->getContent();

        $this->assertStringContainsString(
            'DTSTART:'.now()->addDays(4)->setTime(6, 0)->utc()->format('Ymd\THis\Z'),
            $body,
        );
    }

    /* ──────────────── Issue #7.3: robustness ──────────────── */

    public function test_an_unchanged_feed_is_byte_identical_between_polls(): void
    {
        // DTSTAMP used to be the render time, so every poll produced a
        // different document and no conditional request could ever match.
        $client = $this->client();
        $this->confirmedOrder($client);
        $url = parse_url($this->tokenFor($client), PHP_URL_PATH);

        $this->assertSame($this->get($url)->getContent(), $this->get($url)->getContent());
    }

    public function test_an_unchanged_feed_answers_304(): void
    {
        $client = $this->client();
        $this->confirmedOrder($client);
        $url = parse_url($this->tokenFor($client), PHP_URL_PATH);

        $etag = $this->get($url)->assertOk()->headers->get('ETag');

        $this->assertNotNull($etag);

        $this->withHeaders(['If-None-Match' => $etag])
            ->get($url)
            ->assertStatus(304);
    }

    public function test_a_changed_feed_does_not_answer_304(): void
    {
        $client = $this->client();
        $order = $this->confirmedOrder($client);
        $url = parse_url($this->tokenFor($client), PHP_URL_PATH);

        $etag = $this->get($url)->headers->get('ETag');

        $order->reservation->update(['trip_date' => now()->addDays(30)->setTime(8, 0)]);

        $this->withHeaders(['If-None-Match' => $etag])->get($url)->assertOk();
    }

    public function test_a_reschedule_raises_last_modified_and_the_sequence(): void
    {
        // Without these two, a client that already holds the event keeps the
        // version it has. Outlook is the strict one.
        $client = $this->client();
        $order = $this->confirmedOrder($client);
        $url = parse_url($this->tokenFor($client), PHP_URL_PATH);

        $before = $this->sequenceIn($this->get($url)->getContent());

        $this->travel(2)->hours();
        $order->reservation->update(['trip_date' => now()->addDays(12)->setTime(8, 0)]);

        $after = $this->get($url)->getContent();

        $this->assertGreaterThan($before, $this->sequenceIn($after));
        $this->assertStringContainsString('LAST-MODIFIED:', $after);
    }

    public function test_the_feed_tells_clients_how_often_to_refresh(): void
    {
        // Without a hint Google can sit on its own default for a day or more,
        // which for a trip leaving in the morning is no use at all.
        $body = (new IcsCalendar('Test'))->render();

        $this->assertStringContainsString('REFRESH-INTERVAL;VALUE=DURATION:PT1H', $body);
        $this->assertStringContainsString('X-PUBLISHED-TTL:PT1H', $body);
    }

    public function test_a_client_with_more_trips_than_the_ceiling_keeps_the_upcoming_ones(): void
    {
        /*
         * The query was ascending with the same limit, so it took the OLDEST
         * rows in the window and dropped every future trip, which are the only
         * ones anybody subscribes for. Uses a small ceiling by proxy: three
         * old trips and one upcoming, asserting the upcoming one survives
         * ordering rather than relying on creating 201 rows.
         */
        $client = $this->client();

        foreach ([50, 40, 30] as $daysAgo) {
            $this->confirmedOrder($client, [
                'pickup_date' => now()->subDays($daysAgo)->toDateString(),
                'destination' => 'Ancien-'.$daysAgo,
            ]);
        }

        $this->confirmedOrder($client, [
            'pickup_date' => now()->addDays(10)->toDateString(),
            'destination' => 'Futur',
        ]);

        $body = $this->get(parse_url($this->tokenFor($client), PHP_URL_PATH))->getContent();

        // The upcoming trip comes first, so a ceiling can never cut it.
        $this->assertLessThan(
            strpos($body, 'Ancien-50'),
            strpos($body, 'Futur'),
            'The upcoming trip must be emitted before the oldest history.',
        );
    }

    /** The SEQUENCE of the first event in a document. */
    private function sequenceIn(string $body): int
    {
        preg_match('/SEQUENCE:(\d+)/', $body, $m);

        return (int) ($m[1] ?? -1);
    }

    /* ─────────────────────── The format itself ─────────────────────── */

    public function test_lines_end_with_crlf(): void
    {
        // Apple Calendar rejects bare LF by showing an empty subscription and
        // no error, which is close to impossible to diagnose from the app.
        $body = (new IcsCalendar('Test'))->render();

        $this->assertStringContainsString("\r\n", $body);
        $this->assertSame(0, preg_match("/(?<!\r)\n/", $body), 'Found a bare LF.');
    }

    public function test_commas_and_semicolons_are_escaped(): void
    {
        // An unescaped comma truncates a SUMMARY at that comma, silently,
        // because comma is the value separator in this format.
        $body = (new IcsCalendar('Test'))->event(
            uid: 'x@mova.cg',
            summary: 'Brazzaville, Congo; via Kintélé',
            start: new \DateTimeImmutable('2026-09-01 06:00:00'),
            end: new \DateTimeImmutable('2026-09-01 10:00:00'),
        )->render();

        $this->assertStringContainsString('Brazzaville\\, Congo\\; via', $body);
    }

    public function test_long_lines_fold_without_splitting_a_character(): void
    {
        // The fold limit is in octets, not characters. Cutting an accented
        // character in half produces a file Apple rejects without saying why.
        $body = (new IcsCalendar('Test'))->event(
            uid: 'x@mova.cg',
            summary: str_repeat('é', 200),
            start: new \DateTimeImmutable('2026-09-01 06:00:00'),
            end: new \DateTimeImmutable('2026-09-01 10:00:00'),
        )->render();

        foreach (explode("\r\n", $body) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), 'A line overran the 75 octet limit.');
        }

        // Unfolding is "remove CRLF plus the one leading space".
        $unfolded = str_replace("\r\n ", '', $body);

        $this->assertStringContainsString(str_repeat('é', 200), $unfolded);
        $this->assertSame(1, preg_match('//u', $unfolded), 'The document is no longer valid UTF-8.');
    }

    public function test_an_all_day_event_ends_the_following_day(): void
    {
        // DTEND is exclusive for DATE values. Ending on the start day renders
        // as a zero length event and vanishes from the grid.
        $body = (new IcsCalendar('Test'))->event(
            uid: 'x@mova.cg',
            summary: 'Trip',
            start: new \DateTimeImmutable('2026-09-01'),
            end: new \DateTimeImmutable('2026-09-01'),
            allDay: true,
        )->render();

        $this->assertStringContainsString('DTSTART;VALUE=DATE:20260901', $body);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20260902', $body);
    }
}
