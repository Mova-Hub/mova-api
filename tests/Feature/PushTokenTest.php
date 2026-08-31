<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientFcmToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Push token registration and, more importantly, deregistration.
 *
 * The scoping test is the one that matters: a token arrives in a request body,
 * so without a `client_id` scope any signed-in client could post a token they
 * had seen and silence somebody else's phone.
 */
class PushTokenTest extends TestCase
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

    public function test_an_expo_token_is_stored_as_expo(): void
    {
        // The server decides the type from the prefix, and the channels filter
        // on it. Stored as `fcm`, an Expo token reaches nobody.
        Sanctum::actingAs($client = $this->client());

        $this->postJson('/api/app/v1/fcm/token', [
            'fcm_token' => 'ExponentPushToken[abcdef123456]',
            'device_name' => 'Pixel 7 (android)',
        ])->assertOk();

        $this->assertDatabaseHas('client_fcm_tokens', [
            'client_id' => $client->id,
            'type' => 'expo',
        ]);

        $this->assertSame(['ExponentPushToken[abcdef123456]'], $client->fresh()->routeNotificationForExpo());
    }

    public function test_a_raw_token_is_stored_as_fcm(): void
    {
        Sanctum::actingAs($client = $this->client());

        $this->postJson('/api/app/v1/fcm/token', ['fcm_token' => 'raw-fcm-token-value'])->assertOk();

        $this->assertSame(['raw-fcm-token-value'], $client->fresh()->routeNotificationForFcm());
    }

    public function test_registering_again_does_not_duplicate(): void
    {
        Sanctum::actingAs($client = $this->client());

        foreach (range(1, 3) as $ignored) {
            $this->postJson('/api/app/v1/fcm/token', ['fcm_token' => 'ExponentPushToken[same]'])->assertOk();
        }

        $this->assertSame(1, ClientFcmToken::where('client_id', $client->id)->count());
    }

    public function test_a_shared_handset_moves_the_token_to_whoever_signed_in(): void
    {
        // Otherwise the previous account keeps receiving on a phone they have
        // signed out of.
        $first = $this->client('+242064074926');
        $second = $this->client('+242064074927');

        Sanctum::actingAs($first);
        $this->postJson('/api/app/v1/fcm/token', ['fcm_token' => 'ExponentPushToken[shared]'])->assertOk();

        Sanctum::actingAs($second);
        $this->postJson('/api/app/v1/fcm/token', ['fcm_token' => 'ExponentPushToken[shared]'])->assertOk();

        $this->assertSame([], $first->fresh()->routeNotificationForExpo());
        $this->assertSame(['ExponentPushToken[shared]'], $second->fresh()->routeNotificationForExpo());
    }

    /* ─────────────────────── Deregistration ─────────────────────── */

    public function test_signing_out_drops_this_devices_token(): void
    {
        Sanctum::actingAs($client = $this->client());

        $this->postJson('/api/app/v1/fcm/token', ['fcm_token' => 'ExponentPushToken[mine]'])->assertOk();

        $this->deleteJson('/api/app/v1/fcm/token', ['fcm_token' => 'ExponentPushToken[mine]'])
            ->assertOk();

        $this->assertSame([], $client->fresh()->routeNotificationForExpo());
    }

    public function test_one_client_cannot_delete_another_clients_token(): void
    {
        // A token is a device identifier arriving in a request body. Without
        // the client scope this endpoint would silence any phone whose token
        // somebody had seen.
        $victim = $this->client('+242064074926');
        $attacker = $this->client('+242064074927');

        Sanctum::actingAs($victim);
        $this->postJson('/api/app/v1/fcm/token', ['fcm_token' => 'ExponentPushToken[victim]'])->assertOk();

        Sanctum::actingAs($attacker);
        $this->deleteJson('/api/app/v1/fcm/token', ['fcm_token' => 'ExponentPushToken[victim]'])
            // Quiet on purpose: it reports success without deleting, so the
            // caller learns nothing about whose token exists.
            ->assertOk();

        $this->assertSame(
            ['ExponentPushToken[victim]'],
            $victim->fresh()->routeNotificationForExpo(),
            'The victim must still be reachable.',
        );
    }

    public function test_deleting_an_unknown_token_is_not_an_error(): void
    {
        // Called during logout, where a 404 would be noise.
        Sanctum::actingAs($this->client());

        $this->deleteJson('/api/app/v1/fcm/token', ['fcm_token' => 'ExponentPushToken[never-seen]'])
            ->assertOk();
    }

    public function test_the_endpoints_need_authentication(): void
    {
        $this->postJson('/api/app/v1/fcm/token', ['fcm_token' => 'x'])->assertUnauthorized();
        $this->deleteJson('/api/app/v1/fcm/token', ['fcm_token' => 'x'])->assertUnauthorized();
    }
}
