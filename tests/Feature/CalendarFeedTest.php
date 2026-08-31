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

    public function test_a_cancelled_trip_disappears_from_the_feed(): void
    {
        // The property that makes a subscription better than an export.
        $client = $this->client();
        $order = $this->confirmedOrder($client, ['destination' => 'Dolisie']);
        $url = $this->tokenFor($client);

        $this->assertStringContainsString('Dolisie', $this->get(parse_url($url, PHP_URL_PATH))->getContent());

        $order->reservation->update(['status' => 'cancelled']);

        $this->assertStringNotContainsString('Dolisie', $this->get(parse_url($url, PHP_URL_PATH))->getContent());
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
