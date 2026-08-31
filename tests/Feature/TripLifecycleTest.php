<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\Client;
use App\Models\Order;
use App\Models\Reservation;
use App\Notifications\TripReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The trip lifecycle: when money may be taken, when a request lapses, and when
 * a client is reminded.
 *
 * These three used to be one loose rule ("an order exists, so show a button"),
 * and the tests are written the way the bugs presented: a Payer button on a
 * quote still being negotiated, a months-old request sat at the top of the
 * upcoming list, and no reminder at all before a trip departed.
 */
class TripLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        return Client::create([
            'name' => 'Test Client',
            'phone' => '+242064074926',
            'email' => 'client@example.test',
            'password' => bcrypt('secret'),
        ]);
    }

    private function order(Client $client, array $attributes = []): Order
    {
        return Order::create(array_merge([
            'client_id' => $client->id,
            'status' => 'pending',
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
    }

    private function bus(): Bus
    {
        return Bus::create([
            'plate' => 'BZV-'.fake()->unique()->numerify('####'),
            'model' => 'Coaster',
            'capacity' => 30,
            'status' => 'active',
        ]);
    }

    private function reservation(Order $order, string $status, bool $withBus = true): Reservation
    {
        $reservation = Reservation::create([
            'order_id' => $order->id,
            'client_id' => $order->client_id,
            'from_location' => $order->origin,
            'to_location' => $order->destination,
            'passenger_name' => $order->contact_name,
            'passenger_phone' => $order->contact_phone,
            'trip_date' => $order->pickup_date,
            'status' => $status,
            'seats' => 30,
            'price_total' => 500000,
        ]);

        if ($withBus) {
            $reservation->buses()->sync([$this->bus()->id]);
        }

        $order->update(['status' => 'converted']);

        return $reservation->fresh();
    }

    /* ───────────────────────── Payability ───────────────────────── */

    public function test_a_quote_still_being_negotiated_is_not_payable(): void
    {
        // The reported bug: "Payer" appeared while the devis was en cours.
        $order = $this->order($this->client(), ['status' => 'contacted']);

        $this->assertFalse($order->isPayable());
    }

    public function test_a_pending_request_is_not_payable(): void
    {
        $this->assertFalse($this->order($this->client())->isPayable());
    }

    public function test_a_confirmed_reservation_with_no_vehicles_is_not_payable(): void
    {
        $order = $this->order($this->client());
        $this->reservation($order, 'confirmed', withBus: false);

        $this->assertFalse($order->fresh()->isPayable());
    }

    public function test_a_confirmed_reservation_with_vehicles_is_payable(): void
    {
        $order = $this->order($this->client());
        $this->reservation($order, 'confirmed');

        $this->assertTrue($order->fresh()->isPayable());
    }

    public function test_a_running_trip_is_still_payable(): void
    {
        $order = $this->order($this->client());
        $this->reservation($order, 'in_progress');

        $this->assertTrue($order->fresh()->isPayable());
    }

    public function test_an_expired_order_is_never_payable(): void
    {
        $order = $this->order($this->client());
        $this->reservation($order, 'confirmed');

        $order->update(['status' => Order::STATUS_EXPIRED]);

        $this->assertFalse($order->fresh()->isPayable());
    }

    /* ────────────────────────── Expiry sweep ────────────────────────── */

    public function test_a_past_dated_lead_expires(): void
    {
        $order = $this->order($this->client(), [
            'status' => 'contacted',
            'pickup_date' => now()->subDays(10)->toDateString(),
        ]);

        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertSame(Order::STATUS_EXPIRED, $order->fresh()->status);
    }

    public function test_expiry_is_not_cancellation(): void
    {
        // The whole point of the separate status: ops are measured on
        // cancellations and a lapsed lead is not one.
        $order = $this->order($this->client(), [
            'pickup_date' => now()->subDays(10)->toDateString(),
        ]);

        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertNotSame('cancelled', $order->fresh()->status);
    }

    public function test_a_confirmed_booking_that_never_ran_is_left_alone(): void
    {
        // An operational incident with money attached. A cron must not quietly
        // relabel it overnight and hide it from whoever has to refund it.
        $order = $this->order($this->client(), [
            'pickup_date' => now()->subDays(10)->toDateString(),
        ]);
        $this->reservation($order, 'confirmed');

        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertSame('converted', $order->fresh()->status);
    }

    public function test_a_future_lead_is_left_alone(): void
    {
        $order = $this->order($this->client(), ['status' => 'contacted']);

        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertSame('contacted', $order->fresh()->status);
    }

    public function test_a_multi_day_charter_survives_until_its_return_date(): void
    {
        // Left yesterday, comes back tomorrow. Not stale.
        $order = $this->order($this->client(), [
            'status' => 'contacted',
            'pickup_date' => now()->subDay()->toDateString(),
            'return_date' => now()->addDay()->toDateString(),
        ]);

        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertSame('contacted', $order->fresh()->status);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $order = $this->order($this->client(), [
            'pickup_date' => now()->subDays(10)->toDateString(),
        ]);

        $this->artisan('orders:expire --dry-run')->assertSuccessful();

        $this->assertSame('pending', $order->fresh()->status);
    }

    /* ─────────────── The date the app is shown (issue #7.2) ─────────────── */

    public function test_the_history_resource_shows_the_agreed_date_not_the_requested_one(): void
    {
        /*
         * `date_iso` is what the app sorts on and what decides A venir versus
         * Historique. Reading it off the order meant a rescheduled trip was
         * filed under the wrong group and ordered by a date that had stopped
         * being true.
         *
         * The resource already preferred the reservation for waypoints and
         * distance. The dates were the inconsistency.
         */
        $order = $this->order($this->client(), [
            'pickup_date' => now()->addDays(5)->toDateString(),
        ]);

        $reservation = $this->reservation($order, 'confirmed');
        $agreed = now()->addDays(9)->setTime(7, 30);
        $reservation->update(['trip_date' => $agreed]);

        $payload = (new \App\Http\Resources\OrderHistoryResource($order->fresh()))
            ->toArray(request());

        $this->assertSame($agreed->toDateString(), $payload['itinerary']['date_iso']);
        $this->assertSame('07:30', $payload['itinerary']['time']);
    }

    public function test_the_history_resource_falls_back_to_the_order_with_no_reservation(): void
    {
        // A lead that was never converted has no agreed date, and the request
        // is all there is.
        $order = $this->order($this->client(), [
            'pickup_date' => now()->addDays(5)->toDateString(),
        ]);

        $payload = (new \App\Http\Resources\OrderHistoryResource($order->fresh()))
            ->toArray(request());

        $this->assertSame($order->pickup_date->toDateString(), $payload['itinerary']['date_iso']);
    }

    /* ───────────────────────── Trip reminders ───────────────────────── */

    public function test_a_trip_tomorrow_gets_the_eve_reminder(): void
    {
        Notification::fake();

        $order = $this->order($this->client(), [
            'pickup_date' => now()->addDay()->toDateString(),
        ]);
        $this->reservation($order, 'confirmed');

        $this->artisan('trips:remind')->assertSuccessful();

        Notification::assertSentTo(
            $order->client,
            fn (TripReminder $n) => $n->when === TripReminder::EVE,
        );
    }

    public function test_a_trip_today_gets_the_day_reminder(): void
    {
        Notification::fake();

        $order = $this->order($this->client(), [
            'pickup_date' => now()->toDateString(),
        ]);
        $this->reservation($order, 'confirmed');

        $this->artisan('trips:remind')->assertSuccessful();

        Notification::assertSentTo(
            $order->client,
            fn (TripReminder $n) => $n->when === TripReminder::DAY,
        );
    }

    public function test_a_second_run_the_same_day_sends_nothing(): void
    {
        Notification::fake();

        $order = $this->order($this->client(), [
            'pickup_date' => now()->addDay()->toDateString(),
        ]);
        $this->reservation($order, 'confirmed');

        $this->artisan('trips:remind')->assertSuccessful();
        $this->artisan('trips:remind')->assertSuccessful();

        Notification::assertSentToTimes($order->client, TripReminder::class, 1);
    }

    public function test_the_eve_and_the_morning_are_two_separate_sends(): void
    {
        Notification::fake();

        $order = $this->order($this->client(), [
            'pickup_date' => now()->addDay()->toDateString(),
        ]);
        $this->reservation($order, 'confirmed');

        // The eve.
        $this->artisan('trips:remind')->assertSuccessful();

        // The morning: the same order, a day later, so its pickup date is now
        // today and yesterday's stamp must not suppress the second message.
        $this->travel(1)->day();
        $this->artisan('trips:remind')->assertSuccessful();

        Notification::assertSentToTimes($order->client, TripReminder::class, 2);
    }

    public function test_an_unresourced_trip_is_not_reminded(): void
    {
        // Nobody should be sent to a car park to wait for a coach that was
        // never assigned.
        Notification::fake();

        $order = $this->order($this->client(), [
            'pickup_date' => now()->addDay()->toDateString(),
        ]);
        $this->reservation($order, 'confirmed', withBus: false);

        $this->artisan('trips:remind')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_an_unconfirmed_trip_is_not_reminded(): void
    {
        Notification::fake();

        $order = $this->order($this->client(), [
            'pickup_date' => now()->addDay()->toDateString(),
        ]);
        $this->reservation($order, 'pending');

        $this->artisan('trips:remind')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_reminder_reaches_push_mail_and_the_inbox(): void
    {
        $order = $this->order($this->client(), [
            'pickup_date' => now()->addDay()->toDateString(),
        ]);
        $this->reservation($order, 'confirmed');

        $channels = (new TripReminder($order->fresh()))->via($order->client);

        $this->assertContains('database', $channels);
        $this->assertContains('mail', $channels, 'The client has an email address.');
    }

    public function test_the_email_renders(): void
    {
        // Constructing a MailMessage proves nothing: the markdown view is only
        // compiled when it is rendered, so a broken line or a bad accent shows
        // up at send time, on a real client, and nowhere earlier.
        $order = $this->order($this->client(), [
            'pickup_date' => now()->addDay()->toDateString(),
        ]);
        $this->reservation($order, 'confirmed');

        $html = (new TripReminder($order->fresh(), TripReminder::EVE))
            ->toMail($order->client)
            ->render();

        $this->assertStringContainsString('Pointe-Noire', $html);
        $this->assertStringContainsString('06:00', $html);
    }

    public function test_the_push_payload_carries_a_route_the_app_can_open(): void
    {
        $order = $this->order($this->client(), [
            'pickup_date' => now()->addDay()->toDateString(),
        ]);
        $this->reservation($order, 'confirmed');

        $notification = new TripReminder($order->fresh(), TripReminder::DAY);

        // The two channels look for their own method name and skip the
        // notification silently when it is missing, so both are asserted.
        foreach ([$notification->toExpo($order->client), $notification->toFcm($order->client)] as $payload) {
            $this->assertNotEmpty($payload['title']);
            $this->assertNotEmpty($payload['body']);
            $this->assertSame('/trip/'.$order->id, $payload['data']['route']);
        }
    }
}
