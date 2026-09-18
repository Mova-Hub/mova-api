<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The coordinator a passenger can call, on the booking itself.
 *
 * This was reported three times as "the coordinator section does not show even
 * though one is assigned", and each previous fix looked at the wrong end.
 *
 * The name only ever lived on `GET /orders/{id}/tracking`, which the app calls
 * when the trip is confirmed or running. But `Reservation` defaults `status` to
 * `pending` and ops assign a coordinator at conversion, so for the entire window
 * between "assigned" and "confirmed" the app never asked, and a client with a
 * coordinator was shown exactly what an unassigned trip shows: nothing.
 *
 * The window is not an edge case. It is the night before the trip, which is
 * precisely when somebody wants to ring and ask where to wait.
 */
class OrderCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    /** `clients.phone` is unique, so each one gets its own. */
    private int $clients = 0;

    private function client(): Client
    {
        $this->clients++;

        return Client::create([
            'name' => 'Passager',
            'phone' => '+2420640749'.str_pad((string) $this->clients, 2, '0', STR_PAD_LEFT),
            'email' => 'c'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
        ]);
    }

    /** `users.phone` is unique too. */
    private int $coordinators = 0;

    private function coordinator(): User
    {
        $this->coordinators++;

        return User::create([
            'name' => 'Aline Coordinatrice',
            'email' => 'u'.uniqid().'@example.test',
            'phone' => '+2420600000'.str_pad((string) $this->coordinators, 2, '0', STR_PAD_LEFT),
            'password' => bcrypt('secret'),
            'role' => 'coordinator',
            'status' => 'active',
        ]);
    }

    private function order(Client $client): Order
    {
        return Order::create([
            'client_id' => $client->id,
            'status' => 'converted',
            'event_type' => 'wedding',
            'origin' => 'Brazzaville',
            'destination' => 'Pointe-Noire',
            'pickup_date' => now()->addDays(3)->toDateString(),
            'pickup_time' => '06:00',
            'fleet_requirements' => [],
            'contact_name' => 'Passager',
            'contact_phone' => '+242064074926',
        ]);
    }

    private function reservation(Order $order, ?User $coordinator, string $status): Reservation
    {
        return Reservation::create([
            'order_id' => $order->id,
            'client_id' => $order->client_id,
            'coordinator_id' => $coordinator?->id,
            'from_location' => $order->origin,
            'to_location' => $order->destination,
            'passenger_name' => $order->contact_name,
            'passenger_phone' => $order->contact_phone,
            'trip_date' => $order->pickup_date,
            'status' => $status,
            'seats' => 30,
            'price_total' => 500000,
        ]);
    }

    /**
     * The case that was broken, and the reason this file exists.
     *
     * A reservation sitting at its default status, with a coordinator assigned.
     * Every previous version of this returned nothing the app could show.
     */
    public function test_a_pending_reservation_still_names_its_coordinator(): void
    {
        Sanctum::actingAs($client = $this->client());

        $order = $this->order($client);
        $this->reservation($order, $this->coordinator(), 'pending');

        $this->getJson("/api/app/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('coordinator.name', 'Aline Coordinatrice')
            ->assertJsonPath('coordinator.phone', '+242060000001');
    }

    public function test_the_coordinator_is_present_at_every_status(): void
    {
        foreach (['pending', 'confirmed', 'in_progress', 'completed'] as $status) {
            Sanctum::actingAs($client = $this->client());

            $order = $this->order($client);
            $this->reservation($order, $this->coordinator(), $status);

            $this->getJson("/api/app/v1/orders/{$order->id}")
                ->assertOk()
                ->assertJsonPath('coordinator.name', 'Aline Coordinatrice');
        }
    }

    /**
     * Null, not absent.
     *
     * `whenLoaded` makes an unloaded relation vanish from the payload rather
     * than read as null, and the back-office already misread that once as
     * "nobody assigned" and offered an Assigner button on a trip that had one.
     * The key is always there.
     */
    public function test_an_unassigned_reservation_reports_null_rather_than_omitting_the_key(): void
    {
        Sanctum::actingAs($client = $this->client());

        $order = $this->order($client);
        $this->reservation($order, null, 'confirmed');

        $response = $this->getJson("/api/app/v1/orders/{$order->id}")->assertOk();

        $this->assertArrayHasKey('coordinator', $response->json());
        $this->assertNull($response->json('coordinator'));
    }

    public function test_an_order_with_no_reservation_reports_null(): void
    {
        Sanctum::actingAs($client = $this->client());

        $order = $this->order($client);

        $response = $this->getJson("/api/app/v1/orders/{$order->id}")->assertOk();

        $this->assertArrayHasKey('coordinator', $response->json());
        $this->assertNull($response->json('coordinator'));
    }

    /** The history list carries it too, so a card can show it without a second call. */
    public function test_the_history_list_carries_the_coordinator(): void
    {
        Sanctum::actingAs($client = $this->client());

        $order = $this->order($client);
        $this->reservation($order, $this->coordinator(), 'pending');

        /*
         * No `data.` prefix.
         *
         * `JsonResource::withoutWrapping()` is on, and this collection is not
         * paginated, so it emits a bare array. The field missions list DOES keep
         * a `data` wrapper because it IS paginated and the links and meta have
         * to live somewhere. Two endpoints, two shapes, and the difference is
         * the paginator rather than anything either controller decided.
         */
        $this->getJson('/api/app/v1/orders/history')
            ->assertOk()
            ->assertJsonPath('0.coordinator.name', 'Aline Coordinatrice');
    }

    /**
     * An employee's contact details on a customer's phone, kept to a minimum.
     *
     * A number to call is what the screen needs. The e-mail address and the
     * account id are not part of that, and shipping them would put a staff
     * directory in every passenger's app.
     */
    public function test_only_the_name_phone_and_avatar_are_exposed(): void
    {
        Sanctum::actingAs($client = $this->client());

        $order = $this->order($client);
        $this->reservation($order, $this->coordinator(), 'confirmed');

        $coordinator = $this->getJson("/api/app/v1/orders/{$order->id}")->json('coordinator');

        $this->assertSame(['name', 'phone', 'avatar_url'], array_keys($coordinator));
    }

    /** Somebody else's booking is still not readable. */
    public function test_another_clients_order_is_not_reachable(): void
    {
        $mine = $this->client();
        $theirs = $this->client();

        $order = $this->order($theirs);
        $this->reservation($order, $this->coordinator(), 'confirmed');

        Sanctum::actingAs($mine);

        $this->getJson("/api/app/v1/orders/{$order->id}")->assertNotFound();
    }
}
