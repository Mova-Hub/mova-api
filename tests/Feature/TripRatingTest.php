<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\TripRating;
use App\Models\User;
use App\Notifications\RateYourTrip;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rating a trip.
 *
 * The tests that matter most are the refusals. A rating feeds a coordinator's
 * average, so who may leave one and on which trip is not a UI detail: it is the
 * difference between a service metric and a number anybody can push around.
 */
class TripRatingTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function client(): Client
    {
        $this->seq++;

        return Client::create([
            'name' => 'Passager',
            'phone' => '+24206407'.str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'email' => 'c'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
        ]);
    }

    private function coordinator(): User
    {
        $this->seq++;

        return User::create([
            'name' => 'Coordinateur',
            'email' => 'u'.uniqid().'@example.test',
            'phone' => '+2420600'.str_pad((string) $this->seq, 5, '0', STR_PAD_LEFT),
            'password' => bcrypt('secret'),
            'role' => 'coordinator',
            'status' => 'active',
        ]);
    }

    private function staff(): User
    {
        return User::create([
            'name' => 'Ops',
            'email' => 'a'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function trip(Client $client, string $status = 'completed', array $extra = []): Order
    {
        $order = Order::create([
            'client_id' => $client->id,
            'status' => 'converted',
            'event_type' => 'wedding',
            'origin' => 'Brazzaville',
            'destination' => 'Pointe-Noire',
            'pickup_date' => now()->subDays(2)->toDateString(),
            'pickup_time' => '06:00',
            'fleet_requirements' => [],
            'contact_name' => 'Passager',
            'contact_phone' => '+242064074926',
        ]);

        Reservation::create(array_merge([
            'order_id' => $order->id,
            'client_id' => $client->id,
            'coordinator_id' => $this->coordinator()->id,
            'from_location' => $order->origin,
            'to_location' => $order->destination,
            'passenger_name' => $order->contact_name,
            'passenger_phone' => $order->contact_phone,
            'trip_date' => $order->pickup_date,
            'status' => $status,
            'seats' => 30,
            'price_total' => 500000,
            'started_at' => $status === 'completed' ? now()->subDays(2) : null,
            'completed_at' => $status === 'completed' ? now()->subDay() : null,
        ], $extra));

        return $order->fresh();
    }

    /* ─────────────────────────── Submitting ─────────────────────────── */

    public function test_a_passenger_can_rate_a_completed_trip(): void
    {
        Sanctum::actingAs($client = $this->client());
        $order = $this->trip($client);

        $this->postJson("/api/app/v1/orders/{$order->id}/rating", [
            'stars' => 5,
            'punctuality' => 4,
            'comment' => 'Chauffeur ponctuel.',
        ])->assertCreated()->assertJsonPath('data.rating.stars', 5);

        $this->assertDatabaseCount('trip_ratings', 1);
    }

    /** The criteria are optional. Demanding five taps is how a prompt gets dismissed. */
    public function test_stars_alone_is_a_valid_rating(): void
    {
        Sanctum::actingAs($client = $this->client());
        $order = $this->trip($client);

        $this->postJson("/api/app/v1/orders/{$order->id}/rating", ['stars' => 3])
            ->assertCreated();
    }

    public function test_stars_are_required_and_bounded(): void
    {
        Sanctum::actingAs($client = $this->client());
        $order = $this->trip($client);

        $this->postJson("/api/app/v1/orders/{$order->id}/rating", [])->assertStatus(422);
        $this->postJson("/api/app/v1/orders/{$order->id}/rating", ['stars' => 0])->assertStatus(422);
        $this->postJson("/api/app/v1/orders/{$order->id}/rating", ['stars' => 6])->assertStatus(422);
    }

    /* ─────────────────────────── Refusals ─────────────────────────── */

    public function test_a_trip_can_only_be_rated_once(): void
    {
        Sanctum::actingAs($client = $this->client());
        $order = $this->trip($client);

        $this->postJson("/api/app/v1/orders/{$order->id}/rating", ['stars' => 5])->assertCreated();
        $this->postJson("/api/app/v1/orders/{$order->id}/rating", ['stars' => 1])->assertStatus(422);

        $this->assertDatabaseCount('trip_ratings', 1);
    }

    /**
     * And the database enforces it too, not just the controller.
     *
     * A double tap on a slow connection sends two requests, and check-then-insert
     * loses that race. The unique index is what actually holds.
     */
    public function test_the_database_refuses_a_second_rating(): void
    {
        $client = $this->client();
        $order = $this->trip($client);
        $reservation = $order->reservation;

        TripRating::create([
            'reservation_id' => $reservation->id,
            'order_id' => $order->id,
            'client_id' => $client->id,
            'stars' => 5,
        ]);

        $this->expectException(QueryException::class);

        TripRating::create([
            'reservation_id' => $reservation->id,
            'order_id' => $order->id,
            'client_id' => $client->id,
            'stars' => 1,
        ]);
    }

    public function test_a_trip_that_has_not_finished_cannot_be_rated(): void
    {
        Sanctum::actingAs($client = $this->client());
        $order = $this->trip($client, 'in_progress');

        $this->postJson("/api/app/v1/orders/{$order->id}/rating", ['stars' => 5])
            ->assertStatus(422);
    }

    /**
     * A trip a cron closed is not a trip anybody finished.
     *
     * `trips:sweep` sets `auto_closed_at` when it gives up on a trip nobody
     * closed. Asking for a rating there invites one star about a journey that
     * may have gone perfectly well, and it would land on a coordinator's
     * average.
     */
    public function test_an_auto_closed_trip_cannot_be_rated(): void
    {
        Sanctum::actingAs($client = $this->client());
        $order = $this->trip($client, 'completed', ['auto_closed_at' => now()]);

        $this->postJson("/api/app/v1/orders/{$order->id}/rating", ['stars' => 5])
            ->assertStatus(422);
    }

    /** An id in a URL is a claim, never a permission. */
    public function test_a_passenger_cannot_rate_someone_elses_trip(): void
    {
        $mine = $this->client();
        $theirs = $this->client();
        $order = $this->trip($theirs);

        Sanctum::actingAs($mine);

        $this->postJson("/api/app/v1/orders/{$order->id}/rating", ['stars' => 1])
            ->assertNotFound();
        $this->getJson("/api/app/v1/orders/{$order->id}/rating")->assertNotFound();
    }

    /* ─────────────────────────── Reading ─────────────────────────── */

    public function test_the_screen_payload_says_whether_rating_is_possible(): void
    {
        Sanctum::actingAs($client = $this->client());
        $order = $this->trip($client);

        $this->getJson("/api/app/v1/orders/{$order->id}/rating")
            ->assertOk()
            ->assertJsonPath('data.can_rate', true)
            ->assertJsonPath('data.rating', null)
            ->assertJsonPath('data.coordinator.name', 'Coordinateur');
    }

    /**
     * The coordinator reaches the phone as a name, and nothing else.
     *
     * "How was your trip" does not need an employee's phone number, e-mail or
     * account id on a customer's device.
     */
    public function test_the_coordinator_is_reduced_to_a_name(): void
    {
        Sanctum::actingAs($client = $this->client());
        $order = $this->trip($client);

        $coordinator = $this->getJson("/api/app/v1/orders/{$order->id}/rating")
            ->json('data.coordinator');

        $this->assertSame(['name', 'avatar_url'], array_keys($coordinator));
    }

    public function test_an_existing_rating_comes_back_with_the_reason(): void
    {
        Sanctum::actingAs($client = $this->client());
        $order = $this->trip($client);

        $this->postJson("/api/app/v1/orders/{$order->id}/rating", ['stars' => 4])->assertCreated();

        $this->getJson("/api/app/v1/orders/{$order->id}/rating")
            ->assertOk()
            ->assertJsonPath('data.can_rate', false)
            ->assertJsonPath('data.rating.stars', 4)
            ->assertJsonPath('data.reason', 'Vous avez déjà noté ce trajet.');
    }

    /* ────────────────────── The review request sweep ────────────────────── */

    public function test_a_completed_trip_gets_a_review_request(): void
    {
        Notification::fake();

        $client = $this->client();
        $this->trip($client);

        $this->artisan('trips:request-reviews')->assertSuccessful();

        Notification::assertSentTo($client, RateYourTrip::class);
    }

    public function test_the_request_is_sent_once(): void
    {
        Notification::fake();

        $client = $this->client();
        $this->trip($client);

        $this->artisan('trips:request-reviews')->assertSuccessful();
        $this->artisan('trips:request-reviews')->assertSuccessful();

        Notification::assertSentToTimes($client, RateYourTrip::class, 1);
    }

    public function test_an_auto_closed_trip_is_never_asked_about(): void
    {
        Notification::fake();

        $client = $this->client();
        $this->trip($client, 'completed', ['auto_closed_at' => now()]);

        $this->artisan('trips:request-reviews')->assertSuccessful();

        Notification::assertNotSentTo($client, RateYourTrip::class);
    }

    public function test_an_already_rated_trip_is_not_asked_about(): void
    {
        Notification::fake();

        $client = $this->client();
        $order = $this->trip($client);

        TripRating::create([
            'reservation_id' => $order->reservation->id,
            'order_id' => $order->id,
            'client_id' => $client->id,
            'stars' => 5,
        ]);

        $this->artisan('trips:request-reviews')->assertSuccessful();

        Notification::assertNotSentTo($client, RateYourTrip::class);
    }

    /** The ceiling stops a first run mass-mailing everybody who ever travelled. */
    public function test_an_old_trip_is_not_asked_about(): void
    {
        Notification::fake();

        $client = $this->client();
        $this->trip($client, 'completed', ['completed_at' => now()->subMonths(3)]);

        $this->artisan('trips:request-reviews')->assertSuccessful();

        Notification::assertNotSentTo($client, RateYourTrip::class);
    }

    public function test_the_notification_carries_a_route_to_the_rating_screen(): void
    {
        Notification::fake();

        $client = $this->client();
        $this->trip($client);

        $this->artisan('trips:request-reviews')->assertSuccessful();

        Notification::assertSentTo($client, RateYourTrip::class, function (RateYourTrip $n) use ($client) {
            $payload = $n->toArray($client);

            // Both keys: the in-app inbox reads `message` while older
            // notifications emit `body`.
            return str_starts_with($payload['route'], '/rate/')
                && $payload['message'] !== ''
                && $payload['message'] === $payload['body'];
        });
    }

    /* ─────────────────────────── Back office ─────────────────────────── */

    public function test_staff_can_list_ratings(): void
    {
        $client = $this->client();
        $order = $this->trip($client);

        TripRating::create([
            'reservation_id' => $order->reservation->id,
            'order_id' => $order->id,
            'client_id' => $client->id,
            'coordinator_id' => $order->reservation->coordinator_id,
            'stars' => 4,
            'comment' => 'Bien.',
        ]);

        $this->actingAsBackOffice($this->staff());

        $this->getJson('/api/admin/ratings')
            ->assertOk()
            ->assertJsonPath('data.0.stars', 4)
            ->assertJsonPath('data.0.coordinator', 'Coordinateur');
    }

    public function test_a_passenger_token_cannot_read_the_ratings_list(): void
    {
        Sanctum::actingAs($this->client());

        $this->getJson('/api/admin/ratings')->assertForbidden();
    }

    public function test_the_analytics_tab_reports_an_average_and_a_full_distribution(): void
    {
        $client = $this->client();
        $order = $this->trip($client);

        TripRating::create([
            'reservation_id' => $order->reservation->id,
            'order_id' => $order->id,
            'client_id' => $client->id,
            'stars' => 5,
        ]);

        $this->actingAsBackOffice($this->staff());

        $response = $this->getJson('/api/admin/analytics/ratings')->assertOk();

        // Five buckets even though only one score exists: a missing bucket
        // renders a chart with a different axis, which reads as a bug.
        $this->assertCount(5, $response->json('distribution'));
        $this->assertSame(5.0, (float) $response->json('kpis.average.value'));
    }
}
