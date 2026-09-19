<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a bus form is actually allowed to save.
 *
 * These exist because four fillable columns had no validation rule, and
 * `validated()` returns only keys that have one. The fields were accepted,
 * dropped, and lost with no error, which is the quietest kind of bug: the form
 * says saved, the row says nothing.
 */
class BusFieldsTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_creating_a_bus_persists_every_descriptive_field(): void
    {
        $this->actingAsBackOffice($this->staff());

        $this->postJson('/api/buses', [
            'plate' => 'BZV-1234',
            'capacity' => 30,
            'type' => 'coaster',
            'brand' => 'Toyota',
            'model' => 'Coaster',
            'energy_type' => 'diesel',
            'first_registration_year' => 2019,
            'chassis_number' => 'JTGFB518X01000001',
        ])->assertCreated();

        $bus = Bus::firstOrFail();

        // The four that used to vanish.
        $this->assertSame('Toyota', $bus->brand);
        $this->assertSame('diesel', $bus->energy_type);
        $this->assertSame(2019, (int) $bus->first_registration_year);
        $this->assertSame('JTGFB518X01000001', $bus->chassis_number);
    }

    public function test_updating_a_bus_persists_them_too(): void
    {
        $this->actingAsBackOffice($this->staff());

        $bus = Bus::create(['plate' => 'BZV-9999', 'capacity' => 18, 'status' => 'active']);

        $this->putJson("/api/buses/{$bus->id}", [
            'brand' => 'Toyota',
            'chassis_number' => 'CHANGED-0001',
        ])->assertOk();

        $bus->refresh();

        $this->assertSame('Toyota', $bus->brand);
        $this->assertSame('CHANGED-0001', $bus->chassis_number);
    }

    /**
     * The fleet is HiAce and Coaster, and the server says so.
     *
     * Worth pinning: the back office's own `BusType` union lists seven values,
     * five of which this endpoint has always refused. The form offers only
     * these two.
     */
    public function test_an_unsupported_vehicle_type_is_refused(): void
    {
        $this->actingAsBackOffice($this->staff());

        $this->postJson('/api/buses', [
            'plate' => 'BZV-4321',
            'capacity' => 50,
            'type' => 'coach',
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_a_client_token_cannot_create_a_bus(): void
    {
        $client = \App\Models\Client::create([
            'name' => 'Passager',
            'phone' => '+242064074926',
            'email' => 'c'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($client);

        $this->postJson('/api/buses', ['plate' => 'BZV-0001', 'capacity' => 10])
            ->assertForbidden();
    }
}
