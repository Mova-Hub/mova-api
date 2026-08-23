<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Domain\Pass\Enums\SubscriptionStatus;
use App\Domain\Pass\Exceptions\PassException;
use App\Domain\Pass\Services\SubscriptionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Pass\PassSubscriptionResource;
use App\Models\Client;
use App\Models\PassPlan;
use App\Models\PassSubscription;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Subscription administration.
 *
 * Exists mainly because payment is not automated yet (PRD decision D3 is open):
 * a counter agent takes cash or confirms a transfer, and something has to turn
 * that into an active subscription. Every write goes through
 * `SubscriptionService`, so the renewal-extends-from-current-expiry rule and
 * the entitlement signing happen the same way whoever triggers them.
 */
class PassSubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions) {}

    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(array_column(SubscriptionStatus::cases(), 'value'))],
            'client_id' => ['nullable', 'integer'],
            'expiring_within_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $query = PassSubscription::with(['plan', 'client'])->latest('id');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($clientId = $request->input('client_id')) {
            $query->where('client_id', $clientId);
        }

        // The renewals worklist: who lapses soon and has not renewed.
        if ($days = $request->input('expiring_within_days')) {
            $query->where('status', SubscriptionStatus::Active->value)
                ->whereBetween('expires_at', [now(), now()->addDays((int) $days)]);
        }

        return PassSubscriptionResource::collection(
            $query->paginate((int) $request->input('per_page', 25))
        );
    }

    public function show(int $id)
    {
        return new PassSubscriptionResource(
            PassSubscription::with(['plan', 'client'])->findOrFail($id)
        );
    }

    /**
     * Sells a subscription at the counter.
     *
     * `activate: true` is the cash-in-hand case — the agent has the money, so
     * the subscription starts immediately. Left false it behaves exactly like
     * the app: created `pending`, conferring nothing until payment settles.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'plan_code' => ['required', 'string', 'exists:pass_plans,code'],
            'activate' => ['nullable', 'boolean'],
        ]);

        $client = Client::findOrFail($data['client_id']);
        $plan = PassPlan::where('code', $data['plan_code'])->firstOrFail();

        try {
            $subscription = $this->subscriptions->subscribe(
                $client,
                $plan,
                $request->boolean('activate'),
            );
        } catch (PassException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'error' => $e->errorCode,
            ], $e->status);
        }

        return response()->json([
            'status' => true,
            'message' => $request->boolean('activate')
                ? 'Abonnement créé et activé.'
                : 'Abonnement créé, en attente de paiement.',
            'data' => new PassSubscriptionResource($subscription->loadMissing(['plan', 'client'])),
        ], 201);
    }

    /** Marks a pending subscription paid — the mobile-money callback, by hand. */
    public function activate(int $id)
    {
        $subscription = PassSubscription::findOrFail($id);

        try {
            $subscription = $this->subscriptions->activate($subscription);
        } catch (PassException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'error' => $e->errorCode,
            ], $e->status);
        }

        return response()->json([
            'status' => true,
            'message' => 'Abonnement activé.',
            'data' => new PassSubscriptionResource($subscription->loadMissing(['plan', 'client'])),
        ]);
    }

    public function cancel(Request $request, int $id)
    {
        $subscription = PassSubscription::findOrFail($id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->subscriptions->cancel($subscription, $data['reason']);

        return response()->json([
            'status' => true,
            'message' => 'Abonnement annulé.',
            'data' => new PassSubscriptionResource(
                $subscription->fresh()->loadMissing(['plan', 'client'])
            ),
        ]);
    }
}
