<?php

namespace Tests\Feature;

use App\Events\TripMessageSent;
use App\Models\Client;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\TripMessage;
use App\Models\User;
use App\Notifications\NewTripMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The trip conversation.
 *
 * The tests that matter most here are the scoping ones. Two different
 * authenticatable models can reach this feature through two different gates,
 * and the whole safety of it rests on each side being scoped after the gate
 * rather than by it.
 */
class TripMessagingTest extends TestCase
{
    use RefreshDatabase;

    private function client(string $phone = '+242064074926'): Client
    {
        return Client::create([
            'name' => 'Passenger',
            'phone' => $phone,
            'email' => 'c'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
        ]);
    }

    private function coordinator(string $email = null): User
    {
        return User::create([
            'name' => 'Coordinator',
            'email' => $email ?: 'u'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
            'role' => 'coordinator',
            'status' => 'active',
        ]);
    }

    private function trip(Client $client, ?User $coordinator = null, string $status = 'in_progress'): Order
    {
        $order = Order::create([
            'client_id' => $client->id,
            'status' => 'converted',
            'event_type' => 'wedding',
            'origin' => 'Brazzaville',
            'destination' => 'Pointe-Noire',
            'pickup_date' => now()->toDateString(),
            'pickup_time' => '06:00',
            'fleet_requirements' => [],
            'contact_name' => 'Passenger',
            'contact_phone' => '+242064074926',
        ]);

        Reservation::create([
            'order_id' => $order->id,
            'client_id' => $client->id,
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

        return $order->fresh();
    }

    /* ─────────────────────── Passenger side ─────────────────────── */

    public function test_a_passenger_can_send_and_read(): void
    {
        Sanctum::actingAs($client = $this->client());
        $order = $this->trip($client, $this->coordinator());

        $this->postJson("/api/app/v1/orders/{$order->id}/messages", ['body' => 'On arrive dans 10 min ?'])
            ->assertCreated()
            ->assertJsonPath('data.message.from_client', true)
            ->assertJsonPath('data.message.body', 'On arrive dans 10 min ?');

        $this->getJson("/api/app/v1/orders/{$order->id}/messages")
            ->assertOk()
            ->assertJsonCount(1, 'data.messages');
    }

    public function test_a_passenger_cannot_read_another_clients_conversation(): void
    {
        $mine = $this->client('+242064074926');
        $theirs = $this->client('+242064074927');

        $order = $this->trip($theirs, $this->coordinator());

        // A Client, not a User: passengers hold no ability-scoped token, and the
        // client routes have no ability gate.
        Sanctum::actingAs($mine);

        $this->getJson("/api/app/v1/orders/{$order->id}/messages")->assertNotFound();
        $this->postJson("/api/app/v1/orders/{$order->id}/messages", ['body' => 'hello'])->assertNotFound();
    }

    public function test_an_empty_message_is_refused(): void
    {
        Sanctum::actingAs($client = $this->client());
        $order = $this->trip($client, $this->coordinator());

        $this->postJson("/api/app/v1/orders/{$order->id}/messages", ['body' => ''])
            ->assertStatus(422);
    }

    public function test_a_finished_trip_is_readable_but_closed(): void
    {
        // The record of what was agreed stays; a message to a coordinator who
        // finished last Tuesday reaches nobody.
        Sanctum::actingAs($client = $this->client());
        $order = $this->trip($client, $this->coordinator(), status: 'completed');

        $this->getJson("/api/app/v1/orders/{$order->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.can_send', false);

        $this->postJson("/api/app/v1/orders/{$order->id}/messages", ['body' => 'hello'])
            ->assertStatus(422);
    }

    public function test_a_trip_with_no_reservation_has_no_conversation(): void
    {
        Sanctum::actingAs($client = $this->client());

        $order = Order::create([
            'client_id' => $client->id,
            'status' => 'pending',
            'event_type' => 'wedding',
            'origin' => 'Brazzaville',
            'destination' => 'Oyo',
            'pickup_date' => now()->toDateString(),
            'pickup_time' => '06:00',
            'fleet_requirements' => [],
            'contact_name' => 'P',
            'contact_phone' => '+242064074926',
        ]);

        $this->getJson("/api/app/v1/orders/{$order->id}/messages")->assertNotFound();
    }

    public function test_an_unassigned_trip_still_accepts_messages(): void
    {
        // Ops read these from the back office. Telling a passenger there is
        // nobody to talk to is worse than a message that waits.
        Sanctum::actingAs($client = $this->client());
        $order = $this->trip($client, coordinator: null);

        $this->postJson("/api/app/v1/orders/{$order->id}/messages", ['body' => 'Bonjour'])
            ->assertCreated();
    }

    /* ─────────────────────── Coordinator side ─────────────────────── */

    public function test_a_coordinator_can_reply_on_their_own_mission(): void
    {
        $client = $this->client();
        $coordinator = $this->coordinator();
        $order = $this->trip($client, $coordinator);
        $reservation = $order->reservation;

        $this->actingAsField($coordinator);

        $this->postJson("/api/field/missions/{$reservation->id}/messages", ['body' => 'Nous sommes la'])
            ->assertCreated()
            ->assertJsonPath('data.message.from_client', false);
    }

    public function test_a_coordinator_cannot_touch_someone_elses_mission(): void
    {
        // The `field` gate says they may use the app. It does not say this
        // mission is theirs, and this is the scope that says so.
        $client = $this->client();
        $order = $this->trip($client, $this->coordinator());
        $reservation = $order->reservation;

        $this->actingAsField($this->coordinator());

        $this->getJson("/api/field/missions/{$reservation->id}/messages")->assertNotFound();
        $this->postJson("/api/field/missions/{$reservation->id}/messages", ['body' => 'x'])->assertNotFound();
    }

    public function test_a_passenger_token_cannot_reach_the_field_endpoint(): void
    {
        // Both models own Sanctum tokens, so auth alone proves nothing here.
        $client = $this->client();
        $order = $this->trip($client, $this->coordinator());

        Sanctum::actingAs($client);

        $this->getJson("/api/field/missions/{$order->reservation->id}/messages")
            ->assertForbidden();
    }

    /* ─────────────────────── Read state ─────────────────────── */

    public function test_reading_marks_the_other_sides_messages_read_and_never_your_own(): void
    {
        $client = $this->client();
        $coordinator = $this->coordinator();
        $order = $this->trip($client, $coordinator);

        $fromCoordinator = TripMessage::create([
            'reservation_id' => $order->reservation->id,
            'sender_type' => User::class,
            'sender_id' => $coordinator->id,
            'body' => 'Nous partons',
        ]);

        $fromClient = TripMessage::create([
            'reservation_id' => $order->reservation->id,
            'sender_type' => Client::class,
            'sender_id' => $client->id,
            'body' => 'Merci',
        ]);

        Sanctum::actingAs($client);
        $this->getJson("/api/app/v1/orders/{$order->id}/messages")->assertOk();

        $this->assertNotNull($fromCoordinator->fresh()->read_at);
        $this->assertNull($fromClient->fresh()->read_at, 'Marking your own message read is meaningless.');
    }

    public function test_the_tracking_endpoint_reports_unread_messages(): void
    {
        $client = $this->client();
        $coordinator = $this->coordinator();
        $order = $this->trip($client, $coordinator);

        TripMessage::create([
            'reservation_id' => $order->reservation->id,
            'sender_type' => User::class,
            'sender_id' => $coordinator->id,
            'body' => 'Nous partons',
        ]);

        Sanctum::actingAs($client);

        $this->getJson("/api/app/v1/orders/{$order->id}/tracking")
            ->assertOk()
            ->assertJsonPath('data.unread_messages', 1)
            ->assertJsonPath('data.coordinator.name', 'Coordinator');
    }

    /* ─────────────────── Delivery and privacy ─────────────────── */

    public function test_sending_broadcasts_and_notifies_the_other_side(): void
    {
        Event::fake([TripMessageSent::class]);
        Notification::fake();

        $client = $this->client();
        $coordinator = $this->coordinator();
        $order = $this->trip($client, $coordinator);

        Sanctum::actingAs($client);
        $this->postJson("/api/app/v1/orders/{$order->id}/messages", ['body' => 'Bonjour'])->assertCreated();

        Event::assertDispatched(TripMessageSent::class);
        Notification::assertSentTo($coordinator, NewTripMessage::class);
    }

    public function test_the_message_notification_never_goes_out_by_mail(): void
    {
        // An email twenty minutes late about a conversation that has moved on
        // is noise. This is why NotifiesClient is deliberately not used.
        $client = $this->client();
        $coordinator = $this->coordinator();
        $order = $this->trip($client, $coordinator);

        $message = TripMessage::create([
            'reservation_id' => $order->reservation->id,
            'sender_type' => Client::class,
            'sender_id' => $client->id,
            'body' => 'Bonjour',
        ]);

        $this->assertNotContains('mail', (new NewTripMessage($message))->via($coordinator));
    }

    public function test_the_wire_shape_leaks_no_model_names(): void
    {
        Sanctum::actingAs($client = $this->client());
        $order = $this->trip($client, $this->coordinator());

        $body = $this->postJson("/api/app/v1/orders/{$order->id}/messages", ['body' => 'Bonjour'])
            ->getContent();

        $this->assertStringNotContainsString('App\\\\Models', $body);
        $this->assertStringNotContainsString('sender_type', $body);
    }

    public function test_the_thread_reads_oldest_first(): void
    {
        $client = $this->client();
        $coordinator = $this->coordinator();
        $order = $this->trip($client, $coordinator);

        foreach (['un', 'deux', 'trois'] as $body) {
            TripMessage::create([
                'reservation_id' => $order->reservation->id,
                'sender_type' => User::class,
                'sender_id' => $coordinator->id,
                'body' => $body,
            ]);
        }

        Sanctum::actingAs($client);

        $this->getJson("/api/app/v1/orders/{$order->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.messages.0.body', 'un')
            ->assertJsonPath('data.messages.2.body', 'trois');
    }
}
