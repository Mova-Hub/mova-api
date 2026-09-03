<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Signing in to `control/`.
 *
 * The bug this covers is as bad as they get: **no coordinator and no controller
 * could obtain a token anywhere in the system.** `POST /api/auth/login` was the
 * only login endpoint and it ends with a hard check for `admin` or `agent`, so
 * every field account got 403 and the field app was unreachable by the two roles
 * it exists for. `EnsureField` admitted them, every `/api/field/*` route waited
 * for them, and nothing could issue them a credential.
 *
 * Nothing caught it because nothing tested it. Every field test in this suite
 * used `Sanctum::actingAs()`, which mints a token directly and never goes near
 * the login endpoint, so the whole surface was proved to work for a token that
 * could not be obtained.
 */
class FieldAuthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();

        // The login route is throttled, and the limiter persists between tests
        // in the same process. Without this, whichever test runs sixth gets a
        // 429 and the failure looks like a login bug.
        RateLimiter::clear('');
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    private function user(string $role, string $status = 'active'): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => substr($role, 0, 4).uniqid().'@example.test',
            'password' => Hash::make(self::PASSWORD),
            'role' => $role,
            'status' => $status,
        ]);
    }

    private function login(User $user, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/field/auth/login', array_merge([
            'email' => $user->email,
            'password' => self::PASSWORD,
            'device' => 'pixel-test',
        ], $overrides));
    }

    /* ─────────────────────── The bug itself ─────────────────────── */

    public function test_a_coordinator_can_sign_in(): void
    {
        $user = $this->user('coordinator');

        $response = $this->login($user)->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $response->assertJsonPath('user.role', 'coordinator');
        $response->assertJsonPath('abilities', ['field']);
    }

    public function test_a_controller_can_sign_in(): void
    {
        $this->login($this->user('controller'))
            ->assertOk()
            ->assertJsonPath('user.role', 'controller');
    }

    public function test_staff_can_sign_in_to_reproduce_a_field_report(): void
    {
        foreach (['admin', 'agent'] as $role) {
            $this->login($this->user($role))->assertOk();
        }
    }

    /* ──────────────── The back-office stays shut ──────────────── */

    /**
     * The reason this endpoint exists rather than a widened `/api/auth/login`.
     *
     * `manager/src/api/auth.ts` posts to that one. If field roles were admitted
     * there, a coordinator could sign in to the web back-office, which holds the
     * clients list and the payments ledger.
     */
    public function test_the_back_office_endpoint_still_refuses_field_roles(): void
    {
        foreach (['coordinator', 'controller'] as $role) {
            $user = $this->user($role);

            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => self::PASSWORD,
            ])->assertForbidden();
        }
    }

    /* ─────────────────────── Refusals ─────────────────────── */

    public function test_a_wrong_password_is_refused_without_saying_which_half(): void
    {
        $user = $this->user('coordinator');

        $this->login($user, ['password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Identifiants incorrects.');

        // An unknown address gets the identical message, so the endpoint cannot
        // be used to discover which accounts exist.
        $this->postJson('/api/field/auth/login', [
            'email' => 'nobody'.uniqid().'@example.test',
            'password' => self::PASSWORD,
        ])->assertStatus(422)->assertJsonPath('message', 'Identifiants incorrects.');
    }

    /**
     * A suspended account cannot mint a fresh token.
     *
     * Middleware stops an existing token. Without the check at login, suspending
     * somebody would leave them able to sign in again, so the suspension would
     * never actually take effect.
     */
    public function test_a_suspended_field_account_is_refused(): void
    {
        $this->login($this->user('coordinator', 'suspended'))->assertForbidden();
    }

    public function test_a_fleet_role_is_refused(): void
    {
        // Drivers and conductors are records of people, not accounts.
        $this->login($this->user('driver'))->assertForbidden();
    }

    /* ─────────────────── The token that comes out ─────────────────── */

    /**
     * The minted token carries `field` and nothing else.
     *
     * This is what stops an admin's handset reaching the back-office. Asserted
     * on the database row rather than the response, because the response is a
     * claim and the row is the fact.
     */
    public function test_the_token_is_scoped_to_the_field_ability(): void
    {
        $user = $this->user('admin');

        $this->login($user)->assertOk();

        $token = $user->tokens()->latest('id')->first();

        $this->assertSame(['field'], $token->abilities);
    }

    public function test_signing_in_twice_on_one_device_leaves_one_token(): void
    {
        $user = $this->user('coordinator');

        $this->login($user)->assertOk();
        $this->login($user)->assertOk();

        $this->assertSame(1, $user->tokens()->where('name', 'pixel-test')->count());
    }

    public function test_a_second_device_keeps_its_own_token(): void
    {
        $user = $this->user('coordinator');

        $this->login($user, ['device' => 'phone-a'])->assertOk();
        $this->login($user, ['device' => 'phone-b'])->assertOk();

        $this->assertSame(2, $user->tokens()->count());
    }

    /* ─────────────────────── me and logout ─────────────────────── */

    public function test_me_reports_the_account_and_its_capabilities(): void
    {
        $this->actingAsField($this->user('controller'));

        $this->getJson('/api/field/auth/me')
            ->assertOk()
            ->assertJsonPath('user.role', 'controller')
            // A controller syncs Pass data and is never assigned charters.
            ->assertJsonPath('capabilities.pass_control', true)
            ->assertJsonPath('capabilities.missions', false);
    }

    public function test_a_coordinator_has_the_opposite_capabilities(): void
    {
        $this->actingAsField($this->user('coordinator'));

        $this->getJson('/api/field/auth/me')
            ->assertOk()
            ->assertJsonPath('capabilities.pass_control', false)
            ->assertJsonPath('capabilities.missions', true);
    }

    /**
     * `auth:sanctum` alone is not the gate.
     *
     * `Client` owns tokens too, so a passenger holds a perfectly valid Sanctum
     * token. `EnsureField` checks for a `User` instance before it looks at any
     * role, and this is what proves it.
     */
    public function test_a_passenger_token_cannot_reach_the_field_session(): void
    {
        $client = Client::create([
            'name' => 'Passager',
            'email' => 'p'.uniqid().'@example.test',
            'password' => Hash::make(self::PASSWORD),
        ]);

        Sanctum::actingAs($client);

        $this->getJson('/api/field/auth/me')->assertForbidden();
    }

    /**
     * Logging out ends this device's session and no other.
     *
     * An ops lead can carry two handsets. Signing out of one must not strand the
     * other mid-trip.
     */
    public function test_logout_revokes_only_the_calling_token(): void
    {
        $user = $this->user('coordinator');

        $this->login($user, ['device' => 'phone-a'])->assertOk();
        $other = $this->login($user, ['device' => 'phone-b'])->json('token');

        $this->assertSame(2, $user->tokens()->count());

        // Sign out using the second device's real token, not a mock, so the
        // "current" token is genuinely the one being revoked.
        $this->withHeader('Authorization', 'Bearer '.$other)
            ->postJson('/api/field/auth/logout')
            ->assertOk();

        $this->assertSame(1, $user->fresh()->tokens()->count());
        $this->assertSame('phone-a', $user->fresh()->tokens()->first()->name);
    }

    /**
     * The login endpoint is rate limited.
     *
     * It is unauthenticated and it checks a password, which makes it the one
     * route on the field surface worth guessing at. Every other sensitive route
     * in `routes/api.php` already carries a limit and this one did not.
     *
     * `setUp` disables the throttle for every other test here, because the
     * limiter counts across a whole test class and whichever test ran sixth
     * would otherwise fail with a 429 that looks like a login bug. This one
     * turns it back on deliberately.
     */
    public function test_the_login_endpoint_is_throttled(): void
    {
        $this->withMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $user = $this->user('coordinator');

        // Five wrong attempts are allowed through and refused on their merits.
        for ($i = 0; $i < 5; $i++) {
            $this->login($user, ['password' => 'wrong'])->assertStatus(422);
        }

        // The sixth never reaches the controller.
        $this->login($user, ['password' => 'wrong'])->assertStatus(429);

        // And the limit is on attempts, not on failures: the correct password
        // does not buy a way past it either.
        $this->login($user)->assertStatus(429);
    }

    public function test_a_revoked_token_stops_working(): void
    {
        $user = $this->user('coordinator');
        $token = $this->login($user)->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/field/auth/logout')->assertOk();

        /*
         * The guard memoises the user it resolved, and the application is only
         * rebuilt between tests, not between requests inside one. Without this
         * the second call is answered from that cache and returns 200 against a
         * token that no longer exists, which would have made this test pass for
         * the wrong reason.
         */
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/field/auth/me')->assertUnauthorized();
    }
}
