<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Domain\Pass\Enums\PlanInterval;
use App\Domain\Pass\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Pass\PassPlanResource;
use App\Models\PassPlan;
use App\Models\PassSubscription;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The subscription catalogue.
 *
 * Editable by ops on purpose, the plan model is `interval` + `interval_count`
 * rather than a MONTHLY/ANNUAL enum precisely so a two-week student pass or a
 * ten-day pilgrimage pass is a row, not a migration and a deploy.
 */
class PassPlanController extends Controller
{
    public function index(Request $request)
    {
        $query = PassPlan::query()->orderBy('sort_order')->orderBy('price');

        // Unlike the client-facing endpoint, staff see inactive plans too,
        // that is how a plan gets brought back.
        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return PassPlanResource::collection($query->get());
    }

    /**
     * One plan.
     *
     * The catalogue is small enough that the back office could have found a
     * plan in the index response it already holds. It gets its own endpoint
     * anyway, because a detail page reached by URL must work on a cold load,
     * and "find it in a list you may not have fetched" is not a contract.
     */
    public function show(int $id)
    {
        return new PassPlanResource(PassPlan::findOrFail($id));
    }

    /**
     * How a plan is actually performing.
     *
     * Every figure is a SQL aggregate over `pass_subscriptions`, not a count of
     * rows fetched into PHP. The difference matters the moment a plan has a few
     * thousand subscribers, which is the point at which somebody starts opening
     * this page daily.
     *
     * Revenue comes from `price_paid` on the subscription, NOT from the plan's
     * current price multiplied by a count. Those diverge the first time
     * somebody edits the price, and the second number would quietly rewrite
     * history every time they did.
     */
    public function stats(Request $request, int $id)
    {
        $plan = PassPlan::findOrFail($id);

        $days = min(max((int) $request->input('days', 30), 7), 365);
        $since = now()->subDays($days)->startOfDay();

        $base = PassSubscription::where('pass_plan_id', $plan->id);

        $byStatus = (clone $base)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        /*
         * Zero-filled, so a status nobody is in renders as 0 rather than
         * vanishing from the chart and making the remaining bars look like the
         * whole population.
         */
        $statuses = array_fill_keys(
            array_map(fn (SubscriptionStatus $s) => $s->value, SubscriptionStatus::cases()),
            0,
        );

        foreach ($byStatus as $status => $count) {
            $key = $status instanceof SubscriptionStatus ? $status->value : (string) $status;
            $statuses[$key] = (int) $count;
        }

        $daily = (clone $base)
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total, COALESCE(SUM(price_paid), 0) as revenue')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        // Every day in the window, including the empty ones. A line chart that
        // skips quiet days compresses time and flatters the trend.
        $series = [];
        for ($cursor = $since->copy(); $cursor <= now(); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $row = $daily->get($key);

            $series[] = [
                'date' => $key,
                'count' => (int) ($row->total ?? 0),
                'revenue' => (int) ($row->revenue ?? 0),
            ];
        }

        $active = (clone $base)->where('status', SubscriptionStatus::Active->value)->count();

        return response()->json([
            'status' => true,
            'data' => [
                'plan' => new PassPlanResource($plan),
                'window' => ['days' => $days, 'from' => $since->toDateString()],
                'totals' => [
                    'subscriptions' => (clone $base)->count(),
                    'active' => $active,
                    // Lifetime, and from what was actually charged.
                    'revenue' => (int) (clone $base)->sum('price_paid'),
                    'auto_renew' => (clone $base)->where('auto_renew', true)->count(),
                    'expiring_7d' => (clone $base)
                        ->where('status', SubscriptionStatus::Active->value)
                        ->whereBetween('expires_at', [now(), now()->addDays(7)])
                        ->count(),
                ],
                'by_status' => $statuses,
                'daily' => $series,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $plan = PassPlan::create($this->validated($request));

        return response()->json([
            'status' => true,
            'message' => 'Formule créée.',
            'data' => new PassPlanResource($plan),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $plan = PassPlan::findOrFail($id);

        /*
         * Editing a plan does NOT touch subscriptions already sold.
         *
         * `pass_subscriptions` copies `price_paid` and `trips_remaining` at
         * purchase time for exactly this reason: raising the monthly price must
         * not retroactively rewrite what somebody already paid, and shortening
         * an interval must not cut short a subscription in flight.
         */
        $plan->update($this->validated($request, $plan->id));

        return response()->json([
            'status' => true,
            'message' => 'Formule mise à jour.',
            'data' => new PassPlanResource($plan->fresh()),
        ]);
    }

    /**
     * Retires a plan.
     *
     * Soft delete, and `restrictOnDelete` on the subscription foreign key backs
     * it up: a plan that has ever been sold cannot be erased, because the
     * purchase history references it. Deactivating is the normal path, it
     * removes the plan from the app immediately while leaving every existing
     * subscriber untouched.
     */
    public function destroy(int $id)
    {
        $plan = PassPlan::findOrFail($id);

        if ($plan->subscriptions()->exists()) {
            $plan->update(['is_active' => false]);

            return response()->json([
                'status' => true,
                'message' => 'Formule désactivée : elle a déjà été souscrite et son historique est conservé.',
                'data' => new PassPlanResource($plan->fresh()),
            ]);
        }

        $plan->delete();

        return response()->json(['status' => true, 'message' => 'Formule supprimée.']);
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'code' => [
                $ignoreId ? 'sometimes' : 'required',
                'string', 'max:40', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('pass_plans', 'code')->ignore($ignoreId),
            ],
            'name' => [$ignoreId ? 'sometimes' : 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            // Whole francs. XAF has no subunit.
            'price' => [$ignoreId ? 'sometimes' : 'required', 'integer', 'min:0', 'max:10000000'],
            'interval' => [$ignoreId ? 'sometimes' : 'required', Rule::in(array_column(PlanInterval::cases(), 'value'))],
            'interval_count' => ['nullable', 'integer', 'min:1', 'max:60'],
            // NULL = unlimited. A number makes this a trip bundle, which PRD §6
            // flags as NOT offline-verifiable, decrementing a counter needs
            // shared state an inspector's phone does not have.
            'trips' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'metadata' => ['nullable', 'array'],
        ], [
            'code.regex' => 'Le code ne peut contenir que des minuscules, des chiffres et des underscores.',
        ]);
    }
}
