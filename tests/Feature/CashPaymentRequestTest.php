<?php

namespace Tests\Feature;

use App\Domain\Payment\PaymentService;
use App\Models\Bus;
use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ManualPaymentRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Requesting to pay in cash.
 *
 * The reported bug: the app says a request has been sent, and nothing ever
 * appears in the back office. The cause was a fifteen minute expiry designed
 * for a mobile-money prompt being applied to a request that a human settles.
 */
class CashPaymentRequestTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        return Client::create([
            'name' => 'Test Client',
            'phone' => '+242064074926',
            'email' => 'c'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
        ]);
    }

    private function payableOrder(Client $client): Order
    {
        $order = Order::create([
            'client_id' => $client->id,
            'status' => 'converted',
            'event_type' => 'wedding',
            'origin' => 'Brazzaville',
            'destination' => 'Pointe-Noire',
            'pickup_date' => now()->addDays(5)->toDateString(),
            'pickup_time' => '06:00',
            'passengers' => 40,
            'quoted_total' => 500000,
            'fleet_requirements' => [],
            'contact_name' => 'Test Client',
            'contact_phone' => '+242064074926',
        ]);

        $reservation = Reservation::create([
            'order_id' => $order->id,
            'client_id' => $client->id,
            'from_location' => $order->origin,
            'to_location' => $order->destination,
            'passenger_name' => $order->contact_name,
            'passenger_phone' => $order->contact_phone,
            'trip_date' => $order->pickup_date,
            'status' => 'confirmed',
            'seats' => 30,
            'price_total' => 500000,
        ]);

        $bus = Bus::create([
            'plate' => 'BZV-'.fake()->unique()->numerify('####'),
            'model' => 'Coaster',
            'capacity' => 30,
            'status' => 'active',
        ]);

        $reservation->buses()->sync([$bus->id]);

        return $order->fresh();
    }

    private function cashProvider(): PaymentProvider
    {
        return PaymentProvider::updateOrCreate(
            ['code' => 'cash'],
            [
                'driver' => 'manual',
                'label' => 'Espèces',
                'enabled' => true,
                'mode' => 'live',
                'fee_percent' => 0,
                'min_amount' => 0,
                'currencies' => ['XAF'],
                'countries' => ['CG'],
                'sort_order' => 90,
            ],
        );
    }

    public function test_a_cash_request_is_created_and_waiting_for_an_agent(): void
    {
        $this->cashProvider();
        Sanctum::actingAs($client = $this->client());
        $order = $this->payableOrder($client);

        $this->postJson("/api/app/v1/payment/order/{$order->id}", ['provider' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'processing');

        $this->assertDatabaseHas('payments', [
            'payable_id' => $order->id,
            'provider_code' => 'cash',
            'status' => 'processing',
        ]);
    }

    public function test_a_cash_request_survives_the_mobile_money_prompt_window(): void
    {
        /*
         * The bug, exactly.
         *
         * The app polls the payment while the sheet is open. That poll ran
         * `refresh()`, which expired any non-pollable attempt past its fifteen
         * minute window, so a quarter of an hour after tapping Especes the
         * request marked itself failed and disappeared from the back office's
         * Paiements list, which filters on `processing`.
         */
        $this->cashProvider();
        Sanctum::actingAs($client = $this->client());
        $order = $this->payableOrder($client);

        $uuid = $this->postJson("/api/app/v1/payment/order/{$order->id}", ['provider' => 'cash'])
            ->json('data.uuid');

        $this->travel(45)->minutes();

        $this->getJson("/api/app/v1/payments/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'processing');
    }

    public function test_reconciliation_leaves_a_cash_request_alone(): void
    {
        // The cron already had this right. The service is what disagreed.
        $this->cashProvider();
        Sanctum::actingAs($client = $this->client());
        $order = $this->payableOrder($client);

        $uuid = $this->postJson("/api/app/v1/payment/order/{$order->id}", ['provider' => 'cash'])
            ->json('data.uuid');

        $this->travel(45)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame('processing', Payment::where('uuid', $uuid)->first()->status->value);
    }

    public function test_the_request_stays_visible_to_the_back_office(): void
    {
        // The manager's Paiements page filters on `processing`. This is the
        // query behind what ops actually look at.
        $this->cashProvider();
        Sanctum::actingAs($client = $this->client());
        $order = $this->payableOrder($client);

        $this->postJson("/api/app/v1/payment/order/{$order->id}", ['provider' => 'cash'])->assertCreated();

        $this->travel(45)->minutes();
        $this->getJson("/api/app/v1/payment/order/{$order->id}/options")->assertOk();

        $this->assertSame(
            1,
            Payment::where('payable_id', $order->id)->where('status', 'processing')->count(),
            'Ops must still see a pending cash request after the prompt window.',
        );
    }

    public function test_a_cash_request_does_eventually_lapse(): void
    {
        /*
         * Not immortal. An in-flight attempt blocks a new one, so a request
         * nobody ever actions has to expire or the client is stuck with a
         * method they cannot change.
         */
        $this->cashProvider();
        Sanctum::actingAs($client = $this->client());
        $order = $this->payableOrder($client);

        $uuid = $this->postJson("/api/app/v1/payment/order/{$order->id}", ['provider' => 'cash'])
            ->json('data.uuid');

        $this->travel(3)->days();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $payment = app(PaymentService::class)->expireIfStale(Payment::where('uuid', $uuid)->first());

        $this->assertTrue($payment->status->isFinal(), 'A two day old cash request should have lapsed.');
    }

    /* ─────────────────── Making it visible to ops ─────────────────── */

    public function test_staff_are_alerted_when_a_cash_request_arrives(): void
    {
        // Nothing used to say a cash request existed. Ops had to be looking at
        // the Paiements page at the right moment, which is how the expiry bug
        // above stayed hidden: the only evidence was an absence.
        NotificationFacade::fake();

        $agent = User::create([
            'name' => 'Agent',
            'email' => 'agent'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
            'role' => 'agent',
            'status' => 'active',
        ]);

        $this->cashProvider();
        Sanctum::actingAs($client = $this->client());
        $order = $this->payableOrder($client);

        $this->postJson("/api/app/v1/payment/order/{$order->id}", ['provider' => 'cash'])->assertCreated();

        NotificationFacade::assertSentTo($agent, ManualPaymentRequested::class);
    }

    public function test_a_mobile_money_attempt_does_not_alert_staff(): void
    {
        /*
         * Every mobile-money attempt also passes through `processing` and
         * resolves itself within a minute or two. Alerting an agent each time
         * somebody taps MTN would make this notification worthless within a
         * day, so the test is on the driver's capability rather than on a list
         * of provider codes.
         */
        NotificationFacade::fake();

        $agent = User::create([
            'name' => 'Agent',
            'email' => 'agent'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
            'role' => 'agent',
            'status' => 'active',
        ]);

        $client = $this->client();
        $order = $this->payableOrder($client);

        app(PaymentService::class)->apply(
            Payment::create([
                'payable_type' => Order::class,
                'payable_id' => $order->id,
                'client_id' => $client->id,
                'provider_code' => 'mtn_momo',
                'channel' => 'app',
                'status' => 'pending',
                'amount' => 500000,
                'currency' => 'XAF',
                'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            ]),
            \App\Domain\Payment\DTOs\ChargeResult::processing('REF-1', 'en cours'),
        );

        NotificationFacade::assertNotSentTo($agent, ManualPaymentRequested::class);
    }

    public function test_the_pending_count_powers_the_sidebar_badge(): void
    {
        $this->cashProvider();
        Sanctum::actingAs($client = $this->client());
        $order = $this->payableOrder($client);

        $this->postJson("/api/app/v1/payment/order/{$order->id}", ['provider' => 'cash'])->assertCreated();

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/payments/pending-count')
            ->assertOk()
            ->assertJsonPath('data.pending', 1);
    }

    public function test_the_pending_count_route_is_not_swallowed_by_the_id_route(): void
    {
        // `/{id}` sits directly after it. Without the numeric constraint and
        // the declaration order, "pending-count" is read as an id and 404s.
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/payments/pending-count')->assertOk();
    }

    public function test_a_mobile_money_prompt_still_expires_on_the_short_clock(): void
    {
        // The short window is right for a prompt sitting on a handset, and
        // this change must not have relaxed it.
        $provider = PaymentProvider::updateOrCreate(
            ['code' => 'mtn_momo'],
            [
                'driver' => 'mtn_momo',
                'label' => 'MTN Mobile Money',
                'enabled' => true,
                'mode' => 'test',
                'currencies' => ['XAF'],
                'countries' => ['CG'],
                'min_amount' => 100,
            ],
        );

        $client = $this->client();
        $order = $this->payableOrder($client);

        $payment = Payment::create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'client_id' => $client->id,
            'provider_code' => $provider->code,
            'channel' => 'app',
            'status' => 'processing',
            'amount' => 500000,
            'currency' => 'XAF',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'expires_at' => now()->subMinute(),
        ]);

        $after = app(PaymentService::class)->expireIfStale($payment);

        $this->assertTrue($after->status->isFinal());
    }
}
