<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\Client;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\TripExpired;
use App\Notifications\TripNeedsClosing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The two lifecycle sweeps, and what they tell people.
 *
 * `orders:expire` used to touch leads only and notify nobody. `trips:sweep` did
 * not exist, so a trip a coordinator forgot to close stayed `in_progress` for
 * ever: `started_at` and `completed_at` are only ever written by a human.
 *
 * The tests that matter most here are the notification ones. The whole reason a
 * confirmed trip may now be expired at all is that it is expired LOUDLY: the old
 * behaviour refused to touch it precisely because a silent relabel would hide a
 * refund obligation, and these assertions are what keep that promise.
 */
class TripSweepTest extends TestCase
{
    use RefreshDatabase;

    private int $clients = 0;

    private function client(): Client
    {
        $this->clients++;

        return Client::create([
            'name' => 'Test Client',
            'phone' => '+24206407'.str_pad((string) $this->clients, 4, '0', STR_PAD_LEFT),
            'email' => 'c'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
        ]);
    }

    private function staff(): User
    {
        return User::create([
            'name' => 'Ops',
            'email' => 'u'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function order(Client $client, array $attributes = []): Order
    {
        return Order::create(array_merge([
            'client_id' => $client->id,
            'status' => 'converted',
            'event_type' => 'wedding',
            'origin' => 'Brazzaville',
            'destination' => 'Pointe-Noire',
            'pickup_date' => now()->subDays(5)->toDateString(),
            'pickup_time' => '06:00',
            'passengers' => 40,
            'quoted_total' => 500000,
            'fleet_requirements' => [],
            'contact_name' => 'Test Client',
            'contact_phone' => '+242064074926',
        ], $attributes));
    }

    private function reservation(Order $order, string $status, array $attributes = []): Reservation
    {
        $reservation = Reservation::create(array_merge([
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
        ], $attributes));

        $bus = Bus::create([
            'plate' => 'BZV-'.fake()->unique()->numerify('####'),
            'model' => 'Coaster',
            'capacity' => 30,
            'status' => 'active',
        ]);
        $reservation->buses()->attach($bus->id);

        return $reservation->fresh();
    }

    /* ─────────────────── orders:expire, confirmed trips ─────────────────── */

    /**
     * The behaviour change, and the test it replaces.
     *
     * `TripLifecycleTest::test_a_confirmed_booking_that_never_ran_is_left_alone`
     * asserted the opposite and has been rewritten, not deleted: the concern it
     * encoded is still right, and it is now expressed as "expired AND everybody
     * told" rather than "never touched".
     */
    public function test_a_confirmed_trip_whose_date_passed_without_starting_expires(): void
    {
        Notification::fake();

        $order = $this->order($this->client());
        $reservation = $this->reservation($order, 'confirmed');

        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertSame('expired', $reservation->fresh()->status);
        $this->assertSame(Order::STATUS_EXPIRED, $order->fresh()->status);
    }

    /** A trip that DID start is not expired, whatever its date. That is trips:sweep's job. */
    public function test_a_started_trip_is_never_expired(): void
    {
        Notification::fake();

        $order = $this->order($this->client());
        $reservation = $this->reservation($order, 'in_progress', ['started_at' => now()->subDays(4)]);

        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertSame('in_progress', $reservation->fresh()->status);
    }

    public function test_a_future_confirmed_trip_is_left_alone(): void
    {
        Notification::fake();

        $order = $this->order($this->client(), ['pickup_date' => now()->addDays(5)->toDateString()]);
        $reservation = $this->reservation($order, 'confirmed', ['trip_date' => now()->addDays(5)]);

        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertSame('confirmed', $reservation->fresh()->status);
    }

    /**
     * The condition on which expiring a confirmed trip is acceptable at all.
     *
     * If this assertion ever goes, the sweep is back to hiding refund
     * obligations overnight and should be reverted rather than patched.
     */
    public function test_expiring_a_confirmed_trip_notifies_the_client_and_staff(): void
    {
        Notification::fake();

        $staff = $this->staff();
        $client = $this->client();
        $reservation = $this->reservation($this->order($client), 'confirmed');

        $this->artisan('orders:expire')->assertSuccessful();

        Notification::assertSentTo($client, TripExpired::class, function (TripExpired $n) {
            return $n->wasConfirmed === true;
        });

        Notification::assertSentTo($staff, TripExpired::class);
    }

    /** A lapsed enquiry is routine. The client hears, staff do not. */
    public function test_an_expired_lead_notifies_only_the_client(): void
    {
        Notification::fake();

        $staff = $this->staff();
        $client = $this->client();

        // No reservation: this is the lead path the sweep always handled.
        $this->order($client, ['status' => 'pending']);

        $this->artisan('orders:expire')->assertSuccessful();

        Notification::assertSentTo($client, TripExpired::class, function (TripExpired $n) {
            return $n->wasConfirmed === false;
        });

        Notification::assertNotSentTo($staff, TripExpired::class);
    }

    /* ─────────────────────────── trips:sweep ─────────────────────────── */

    /**
     * Past the end, inside the grace period: flagged, not closed.
     *
     * The grace period is the point. A trip running late is not a trip that is
     * over, and closing at the end time would cut short a convoy in traffic.
     */
    public function test_a_trip_past_its_end_is_flagged_but_not_closed(): void
    {
        Notification::fake();

        $staff = $this->staff();
        $client = $this->client();

        // Today's trip, already past its default end.
        $order = $this->order($client, [
            'pickup_date' => now()->subHours(8)->toDateString(),
            'pickup_time' => now()->subHours(8)->format('H:i'),
        ]);
        $reservation = $this->reservation($order, 'in_progress', [
            'trip_date' => now()->subHours(8),
            'started_at' => now()->subHours(8),
        ]);

        $this->artisan('trips:sweep')->assertSuccessful();

        $fresh = $reservation->fresh();

        $this->assertSame('in_progress', $fresh->status);
        $this->assertNotNull($fresh->closing_notified_at);

        Notification::assertSentTo($client, TripNeedsClosing::class);
        Notification::assertSentTo($staff, TripNeedsClosing::class);
    }

    /** The flag fires once, not every night through the grace period. */
    public function test_the_closing_notice_is_not_repeated(): void
    {
        Notification::fake();

        $client = $this->client();
        $order = $this->order($client, [
            'pickup_date' => now()->subHours(8)->toDateString(),
            'pickup_time' => now()->subHours(8)->format('H:i'),
        ]);
        $this->reservation($order, 'in_progress', [
            'trip_date' => now()->subHours(8),
            'started_at' => now()->subHours(8),
            'closing_notified_at' => now()->subHour(),
        ]);

        $this->artisan('trips:sweep')->assertSuccessful();

        Notification::assertNotSentTo($client, TripNeedsClosing::class);
    }

    /** A day past the end, it closes, and it is marked as not-by-a-human. */
    public function test_a_trip_a_day_past_its_end_is_closed_automatically(): void
    {
        Notification::fake();

        $order = $this->order($this->client(), [
            'pickup_date' => now()->subDays(3)->toDateString(),
        ]);
        $reservation = $this->reservation($order, 'in_progress', [
            'trip_date' => now()->subDays(3),
            'started_at' => now()->subDays(3),
        ]);

        $this->artisan('trips:sweep')->assertSuccessful();

        $fresh = $reservation->fresh();

        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->completed_at);
        $this->assertNotNull($fresh->auto_closed_at);
    }

    /** A trip still within its window is untouched. */
    public function test_a_running_trip_inside_its_window_is_left_alone(): void
    {
        Notification::fake();

        $order = $this->order($this->client(), [
            'pickup_date' => now()->toDateString(),
            'pickup_time' => now()->format('H:i'),
        ]);
        $reservation = $this->reservation($order, 'in_progress', [
            'trip_date' => now(),
            'started_at' => now(),
        ]);

        $this->artisan('trips:sweep')->assertSuccessful();

        $this->assertSame('in_progress', $reservation->fresh()->status);
        $this->assertNull($reservation->fresh()->closing_notified_at);
    }

    public function test_dry_run_writes_nothing(): void
    {
        Notification::fake();

        $order = $this->order($this->client(), [
            'pickup_date' => now()->subDays(3)->toDateString(),
        ]);
        $reservation = $this->reservation($order, 'in_progress', [
            'trip_date' => now()->subDays(3),
            'started_at' => now()->subDays(3),
        ]);

        $this->artisan('trips:sweep --dry-run')->assertSuccessful();

        $this->assertSame('in_progress', $reservation->fresh()->status);
        Notification::assertNothingSent();
    }

    /* ─────────────────────── the state machine ─────────────────────── */

    public function test_expired_is_reachable_only_from_a_trip_that_never_left(): void
    {
        $order = $this->order($this->client());

        $pending = $this->reservation($order, 'pending');
        $this->assertTrue($pending->canTransitionTo('expired'));

        $confirmed = $this->reservation($this->order($this->client()), 'confirmed');
        $this->assertTrue($confirmed->canTransitionTo('expired'));

        // A trip that started is trips:sweep's problem, and it completes rather
        // than expires. Allowing this would let a journey that happened be
        // filed as one that never did.
        $running = $this->reservation($this->order($this->client()), 'in_progress');
        $this->assertFalse($running->canTransitionTo('expired'));

        $done = $this->reservation($this->order($this->client()), 'completed');
        $this->assertFalse($done->canTransitionTo('expired'));
    }
}
