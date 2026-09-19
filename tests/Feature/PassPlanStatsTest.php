<?php

namespace Tests\Feature;

use App\Domain\Pass\Enums\SubscriptionStatus;
use App\Models\Client;
use App\Models\PassPlan;
use App\Models\PassSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The plan detail page's numbers.
 *
 * Worth testing rather than eyeballing, because every figure here is a SQL
 * aggregate and the interesting ones are the two that are easy to get subtly
 * wrong: revenue taken from what was CHARGED rather than from the plan's
 * current price, and a status breakdown that includes the statuses nobody is
 * in.
 */
class PassPlanStatsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

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

    private function client(): Client
    {
        $this->seq++;

        return Client::create([
            'name' => 'Abonné',
            'phone' => '+24206407'.str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'email' => 'c'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
        ]);
    }

    private function plan(int $price = 15000): PassPlan
    {
        return PassPlan::create([
            'code' => 'mensuel-'.uniqid(),
            'name' => 'Mensuel',
            'price' => $price,
            'currency' => 'XAF',
            'interval' => 'month',
            'interval_count' => 1,
            'is_active' => true,
        ]);
    }

    private function subscribe(PassPlan $plan, string $status, int $pricePaid): PassSubscription
    {
        return PassSubscription::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $this->client()->id,
            'pass_plan_id' => $plan->id,
            'status' => $status,
            'price_paid' => $pricePaid,
            'currency' => 'XAF',
            'starts_at' => now()->subDays(2),
            'expires_at' => now()->addDays(28),
        ]);
    }

    public function test_totals_count_subscriptions_and_sum_what_was_actually_paid(): void
    {
        $this->actingAsBackOffice($this->staff());

        $plan = $this->plan(price: 15000);

        // Two sold at the old price, one after a price rise. Revenue must be
        // 10000 + 10000 + 20000, not 3 x the plan's current 15000.
        $this->subscribe($plan, SubscriptionStatus::Active->value, 10000);
        $this->subscribe($plan, SubscriptionStatus::Active->value, 10000);
        $this->subscribe($plan, SubscriptionStatus::Expired->value, 20000);

        $this->getJson("/api/admin/pass/plans/{$plan->id}/stats")
            ->assertOk()
            ->assertJsonPath('data.totals.subscriptions', 3)
            ->assertJsonPath('data.totals.active', 2)
            ->assertJsonPath('data.totals.revenue', 40000);
    }

    /** A status nobody is in is 0, not absent. */
    public function test_the_status_breakdown_is_zero_filled(): void
    {
        $this->actingAsBackOffice($this->staff());

        $plan = $this->plan();
        $this->subscribe($plan, SubscriptionStatus::Active->value, 15000);

        $this->getJson("/api/admin/pass/plans/{$plan->id}/stats")
            ->assertOk()
            ->assertJsonPath('data.by_status.active', 1)
            ->assertJsonPath('data.by_status.cancelled', 0)
            ->assertJsonPath('data.by_status.suspended', 0);
    }

    /**
     * Every day in the window, including the quiet ones.
     *
     * A series that skips days compresses time and flatters the trend.
     */
    public function test_the_daily_series_covers_the_whole_window(): void
    {
        $this->actingAsBackOffice($this->staff());

        $plan = $this->plan();

        $response = $this->getJson("/api/admin/pass/plans/{$plan->id}/stats?days=7")->assertOk();

        // 7 days back through today, inclusive.
        $this->assertCount(8, $response->json('data.daily'));
    }

    public function test_the_window_is_clamped(): void
    {
        $this->actingAsBackOffice($this->staff());

        $plan = $this->plan();

        $this->getJson("/api/admin/pass/plans/{$plan->id}/stats?days=99999")
            ->assertOk()
            ->assertJsonPath('data.window.days', 365);
    }

    public function test_a_plan_can_be_fetched_on_its_own(): void
    {
        $this->actingAsBackOffice($this->staff());

        $plan = $this->plan();

        $this->getJson("/api/admin/pass/plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('name', 'Mensuel');
    }

    public function test_a_client_token_cannot_read_plan_stats(): void
    {
        Sanctum::actingAs($this->client());

        $plan = $this->plan();

        $this->getJson("/api/admin/pass/plans/{$plan->id}/stats")->assertForbidden();
    }

    public function test_scans_are_scoped_to_the_subscription_and_staff_only(): void
    {
        $plan = $this->plan();
        $subscription = $this->subscribe($plan, SubscriptionStatus::Active->value, 15000);

        Sanctum::actingAs($this->client());
        $this->getJson("/api/admin/pass/subscriptions/{$subscription->id}/scans")->assertForbidden();

        $this->actingAsBackOffice($this->staff());
        $this->getJson("/api/admin/pass/subscriptions/{$subscription->id}/scans")
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }
}
