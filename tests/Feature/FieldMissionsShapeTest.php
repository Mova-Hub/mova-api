<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The wire shape of GET /api/field/missions, pinned.
 *
 * Control reads the list as `data?.data ?? []`. That works because
 * `MissionResource::collection()` is handed a paginator, and a paginated
 * resource collection keeps its `data` wrapper even though
 * `JsonResource::withoutWrapping()` is on: the pagination links and meta have to
 * live somewhere, so the rows stay nested.
 *
 * That is a subtle thing to rely on, and it is one refactor away from breaking.
 * Swapping `paginate()` for `get()` would emit a bare array, `data.data` would
 * be undefined, and the app would show "aucune mission assignée" to a
 * coordinator who has three trips that morning. No error, no failing request,
 * nothing in a log. This test is here so that change fails loudly instead.
 *
 * Written while chasing a report of the missions screen hanging. The payload
 * turned out to be correct, which is worth keeping proof of.
 */
class FieldMissionsShapeTest extends TestCase
{
    use RefreshDatabase;

    private function coordinator(): User
    {
        return User::create([
            'name' => 'Coordinateur',
            'email' => 'c'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
            'role' => 'coordinator',
            'status' => 'active',
        ]);
    }

    private function mission(User $coordinator, string $status = 'confirmed'): Reservation
    {
        return Reservation::create([
            'coordinator_id' => $coordinator->id,
            'from_location' => 'Brazzaville',
            'to_location' => 'Pointe-Noire',
            'passenger_name' => 'Client Test',
            'passenger_phone' => '+242060000000',
            'trip_date' => now()->addDay(),
            'status' => $status,
            'seats' => 30,
            'passengers' => 24,
        ]);
    }

    public function test_the_list_keeps_its_data_wrapper(): void
    {
        $coordinator = $this->coordinator();
        $this->mission($coordinator);

        $this->actingAsField($coordinator);

        $response = $this->getJson('/api/field/missions')->assertOk();

        // The three keys the app's `data?.data ?? []` depends on existing.
        $response->assertJsonStructure([
            'data' => [['id', 'status', 'from', 'to', 'trip_date', 'seats', 'contact']],
            'links',
            'meta',
        ]);

        $this->assertCount(1, $response->json('data'));
    }

    /**
     * The id is a string.
     *
     * `keyExtractor` in the app returns it directly, and the route parameter is
     * built from it. A numeric id would still work by coercion and would be a
     * silent type drift, so it is asserted rather than assumed.
     */
    public function test_the_id_is_a_string_uuid(): void
    {
        $coordinator = $this->coordinator();
        $this->mission($coordinator);

        $this->actingAsField($coordinator);

        $id = $this->getJson('/api/field/missions')->json('data.0.id');

        $this->assertIsString($id);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $id);
    }

    /**
     * Only statuses the app has a label for.
     *
     * `missionStatusLabel()` falls back to the raw value now, so an unknown one
     * is no longer a crash, but it would still put an untranslated English word
     * in front of a coordinator. This is the server side of that contract.
     */
    public function test_only_known_statuses_reach_the_field(): void
    {
        $coordinator = $this->coordinator();
        $this->mission($coordinator, 'confirmed');
        $this->mission($coordinator, 'in_progress');

        $this->actingAsField($coordinator);

        $statuses = $this->getJson('/api/field/missions')->json('data.*.status');

        foreach ($statuses as $status) {
            $this->assertContains($status, ['confirmed', 'in_progress', 'completed', 'cancelled']);
        }
    }

    /**
     * Somebody else's mission is not in the list.
     *
     * The `field` gate says who may use the app. It does not say whose trips
     * these are, and that scoping is what stops one coordinator reading every
     * client's name and number.
     */
    public function test_the_list_is_scoped_to_the_signed_in_coordinator(): void
    {
        $mine = $this->coordinator();
        $theirs = $this->coordinator();

        $this->mission($mine);
        $this->mission($theirs);

        $this->actingAsField($mine);

        $this->assertCount(1, $this->getJson('/api/field/missions')->json('data'));
    }
}
