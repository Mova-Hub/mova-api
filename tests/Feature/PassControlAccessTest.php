<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who may download the Mova Pass fare-control data.
 *
 * These four endpoints spent their whole life inside the `staff` group, which
 * admits `admin` and `agent` and nobody else. That excluded the one role the
 * data exists for: a contrôleur signed in to Control, opened the sync screen,
 * pressed "Télécharger les données" and got 403. The blacklist could only be
 * downloaded by people who are never on a bus.
 *
 * Nothing would have caught it. Every route returned a valid response, the
 * middleware did exactly what it said, and the failure was only visible to
 * somebody holding the phone. Hence this file.
 *
 * The other half matters as much: `pass.control` is deliberately narrower than
 * `field`. These payloads carry every subscriber's card identifier, and a
 * coordinator running chartered trips has no use for them.
 */
class PassControlAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Every route the Control sync screen calls. */
    private const READ_ROUTES = [
        '/api/pass/keys',
        '/api/pass/blacklist',
        '/api/pass/snapshot',
    ];

    private function user(string $role, string $status = 'active'): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => substr($role, 0, 3).uniqid().'@example.test',
            'password' => bcrypt('secret'),
            'role' => $role,
            'status' => $status,
        ]);
    }

    public function test_a_controller_can_download_the_fare_control_data(): void
    {
        $this->actingAsField($this->user('controller'));

        foreach (self::READ_ROUTES as $route) {
            $this->getJson($route)->assertOk();
        }
    }

    /**
     * The back-office Contrôle page must keep working.
     *
     * This is a regression guard, not a formality. These four endpoints have TWO
     * legitimate callers: `control/` over a field token, and `manager/` over a
     * back-office one, see `manager/src/api/pass-control.ts`. When ability-scoped
     * tokens landed, adding a `field` ability check here would have looked
     * correct and silently broken a working back-office screen. It is asserted
     * with a BACK-OFFICE token for exactly that reason.
     */
    public function test_staff_keep_their_access_with_a_back_office_token(): void
    {
        foreach (['admin', 'agent'] as $role) {
            $this->actingAsBackOffice($this->user($role));

            foreach (self::READ_ROUTES as $route) {
                $this->getJson($route)->assertOk();
            }
        }
    }

    /** And the same staff account reaching it from the field app. */
    public function test_staff_keep_their_access_with_a_field_token(): void
    {
        $this->actingAsField($this->user('admin'));

        foreach (self::READ_ROUTES as $route) {
            $this->getJson($route)->assertOk();
        }
    }

    /**
     * The narrowing half of the change.
     *
     * A coordinator passes `field` and runs missions all day. That must not
     * hand them a copy of the subscriber card database.
     */
    public function test_a_coordinator_is_refused(): void
    {
        $this->actingAsField($this->user('coordinator'));

        foreach (self::READ_ROUTES as $route) {
            $this->getJson($route)->assertForbidden();
        }
    }

    public function test_a_suspended_controller_is_refused(): void
    {
        $this->actingAsField($this->user('controller', 'suspended'));

        foreach (self::READ_ROUTES as $route) {
            $this->getJson($route)->assertForbidden();
        }
    }

    /**
     * `auth:sanctum` alone is not the gate.
     *
     * `Client` owns tokens too, so a passenger holds a perfectly valid Sanctum
     * token. The middleware checks for a `User` instance before it looks at any
     * role, and this is what proves it.
     */
    public function test_a_passenger_is_refused(): void
    {
        $client = Client::create([
            'name' => 'Passager',
            'email' => 'p'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
        ]);

        Sanctum::actingAs($client);

        foreach (self::READ_ROUTES as $route) {
            $this->getJson($route)->assertForbidden();
        }
    }

    public function test_an_anonymous_request_is_unauthenticated(): void
    {
        foreach (self::READ_ROUTES as $route) {
            $this->getJson($route)->assertUnauthorized();
        }
    }

    public function test_the_bulk_scan_upload_uses_the_same_gate(): void
    {
        $this->actingAsField($this->user('coordinator'));
        $this->postJson('/api/pass/scans/bulk', ['scans' => []])->assertForbidden();

        $this->actingAsField($this->user('controller'));
        // Not asserting the body, only that the gate lets a contrôleur through.
        // An empty batch is a validation matter, not an authorisation one.
        $this->assertNotSame(
            403,
            $this->postJson('/api/pass/scans/bulk', ['scans' => []])->status()
        );
    }
}
