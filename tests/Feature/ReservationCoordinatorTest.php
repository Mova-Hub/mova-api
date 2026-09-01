<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The coordinator on a reservation, as the back office sees it.
 *
 * `ReservationResource` emits `coordinator` under `whenLoaded`, which means an
 * unloaded relation is not null in the payload: the key is absent entirely.
 * That is a silent failure with no error anywhere, and the back office read it
 * as "nobody assigned" and offered an Assigner button on a trip that already
 * had a coordinator.
 *
 * These tests exist because nothing else would have caught it: the endpoint
 * returned 200, the schema was valid, and only a human looking at the screen
 * could tell.
 */
class ReservationCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::create([
            'name' => 'Agent',
            'email' => 'a'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function coordinator(): User
    {
        return User::create([
            'name' => 'Coordinateur Test',
            'email' => 'c'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
            'role' => 'coordinator',
            'status' => 'active',
        ]);
    }

    private function reservation(?User $coordinator): Reservation
    {
        $client = Client::create([
            'name' => 'Client',
            'phone' => '+2420'.random_int(10000000, 99999999),
            'email' => 'cl'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
        ]);

        return Reservation::create([
            'client_id' => $client->id,
            'coordinator_id' => $coordinator?->id,
            'from_location' => 'Brazzaville',
            'to_location' => 'Pointe-Noire',
            'passenger_name' => 'Client',
            'passenger_phone' => '+242064074926',
            'trip_date' => now()->addDays(3),
            'status' => 'confirmed',
            'seats' => 30,
            'price_total' => 500000,
        ]);
    }

    public function test_the_detail_endpoint_names_the_assigned_coordinator(): void
    {
        // The reported bug: assigned, and the screen still said "Non assigné"
        // with an Assigner button, because show() never loaded the relation.
        Sanctum::actingAs($this->staff());

        $coordinator = $this->coordinator();
        $reservation = $this->reservation($coordinator);

        $this->getJson("/api/reservations/{$reservation->id}")
            ->assertOk()
            ->assertJsonPath('coordinator_id', $coordinator->id)
            ->assertJsonPath('coordinator.name', 'Coordinateur Test');
    }

    public function test_the_list_endpoint_names_the_assigned_coordinator(): void
    {
        Sanctum::actingAs($this->staff());

        $coordinator = $this->coordinator();
        $this->reservation($coordinator);

        $this->getJson('/api/reservations')
            ->assertOk()
            ->assertJsonPath('data.0.coordinator.name', 'Coordinateur Test');
    }

    public function test_an_unassigned_reservation_reports_null_rather_than_omitting_the_key(): void
    {
        /*
         * The distinction that matters. A missing key and a null value look the
         * same to a careless client, but only one of them is honest: the back
         * office has to be able to tell "nobody is assigned" from "the server
         * did not say".
         */
        Sanctum::actingAs($this->staff());

        $reservation = $this->reservation(null);

        $response = $this->getJson("/api/reservations/{$reservation->id}")->assertOk();

        $this->assertNull($response->json('coordinator_id'));
        $this->assertArrayHasKey('coordinator', $response->json());
        $this->assertNull($response->json('coordinator'));
    }

    public function test_the_coordinator_survives_a_status_change(): void
    {
        // Every write returns the resource and the screen refetches from it, so
        // an omitted relation on any one of them makes an assigned trip look
        // unassigned again the moment anything else is touched.
        Sanctum::actingAs($this->staff());

        $coordinator = $this->coordinator();
        $reservation = $this->reservation($coordinator);

        $this->postJson("/api/reservations/{$reservation->id}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('coordinator.name', 'Coordinateur Test');
    }
}
