<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Domain\Pass\Enums\PlanInterval;
use App\Http\Controllers\Controller;
use App\Http\Resources\Pass\PassPlanResource;
use App\Models\PassPlan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The subscription catalogue.
 *
 * Editable by ops on purpose — the plan model is `interval` + `interval_count`
 * rather than a MONTHLY/ANNUAL enum precisely so a two-week student pass or a
 * ten-day pilgrimage pass is a row, not a migration and a deploy.
 */
class PassPlanController extends Controller
{
    public function index(Request $request)
    {
        $query = PassPlan::query()->orderBy('sort_order')->orderBy('price');

        // Unlike the client-facing endpoint, staff see inactive plans too —
        // that is how a plan gets brought back.
        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return PassPlanResource::collection($query->get());
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
     * purchase history references it. Deactivating is the normal path — it
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
            // flags as NOT offline-verifiable — decrementing a counter needs
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
