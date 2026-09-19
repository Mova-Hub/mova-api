<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Domain\Pass\Enums\SubscriptionStatus;
use App\Domain\Pass\Exceptions\PassException;
use App\Domain\Pass\Services\SubscriptionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Pass\PassSubscriptionResource;
use App\Models\Client;
use App\Models\PassPlan;
use App\Models\PassScan;
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
            $query->paginate($this->perPage($request, 25))
        );
    }

    public function show(int $id)
    {
        return new PassSubscriptionResource(
            PassSubscription::with(['plan', 'client'])->findOrFail($id)
        );
    }

    /**
     * Where this subscription has actually been used.
     *
     * The scans table already records every tap against a subscription id, and
     * nothing exposed them to staff: the only reader was the subscriber's own
     * `/app/v1/pass/scans`. So an agent investigating "my card was refused this
     * morning" had the evidence in the database and no way to look at it.
     *
     * **Refusals are the point.** A list of accepted taps says the product
     * works; the verdict and its reason are what explain a complaint, which is
     * why they are the two fields that always come back in full.
     */
    public function scans(Request $request, int $id)
    {
        $subscription = PassSubscription::findOrFail($id);

        $scans = PassScan::where('pass_subscription_id', $subscription->id)
            ->with(['inspector:id,name'])
            ->latest('scanned_at')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'status' => true,
            'data' => $scans->through(fn (PassScan $scan) => [
                'id' => $scan->id,
                'verdict' => $scan->verdict?->value,
                'verdict_label' => $scan->verdict?->label(),
                'reason' => $scan->reason,
                'source' => $scan->source?->value,
                'bus_line' => $scan->bus_line,
                // The inspector's NAME only. A scan record is not a reason to
                // hand a staff directory to whoever opens the page.
                'inspector' => $scan->inspector?->name,
                'scanned_at' => $scan->scanned_at?->toIso8601String(),
                /*
                 * How long the device held this before syncing.
                 *
                 * A tap recorded offline and uploaded six hours later is not
                 * the same evidence as a live one, and a dispute about "when"
                 * turns on exactly that.
                 */
                'offline_duration_minutes' => $scan->offline_duration_minutes,
            ])->items(),
            'meta' => [
                'current_page' => $scans->currentPage(),
                'last_page' => $scans->lastPage(),
                'total' => $scans->total(),
            ],
        ]);
    }

    /**
     * Sells a subscription at the counter.
     *
     * `activate: true` is the cash-in-hand case, the agent has the money, so
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

    /** Marks a pending subscription paid, the mobile-money callback, by hand. */
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
