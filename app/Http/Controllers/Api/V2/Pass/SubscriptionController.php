<?php

namespace App\Http\Controllers\Api\V2\Pass;

use App\Domain\Pass\Exceptions\PassException;
use App\Domain\Pass\Services\CardService;
use App\Domain\Pass\Services\SubscriptionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\V2\Pass\SubscribeRequest;
use App\Http\Resources\Pass\PassCardResource;
use App\Http\Resources\Pass\PassSubscriptionResource;
use App\Models\PassPlan;
use App\Models\PassSubscription;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private CardService $cards,
    ) {}

    /** Purchase history, newest first. */
    public function index(Request $request)
    {
        $subscriptions = PassSubscription::where('client_id', $request->user()->id)
            ->with('plan')
            ->orderByDesc('id')
            ->get();

        return PassSubscriptionResource::collection($subscriptions);
    }

    /**
     * Everything the Pass tab needs, in one request.
     *
     * Deliberately one round trip rather than three: this is the screen's cold
     * start, and three sequential calls on a Brazzaville mobile connection is
     * the difference between a screen that appears and one that assembles
     * itself in front of the user.
     */
    public function current(Request $request)
    {
        $client = $request->user();
        $subscription = $this->subscriptions->currentSubscription($client);
        $card = $this->cards->currentCard($client);

        return response()->json([
            'status' => true,
            'data' => [
                'subscription' => $subscription
                    ? new PassSubscriptionResource($subscription->loadMissing('plan'))
                    : null,
                'card' => $card ? new PassCardResource($card) : null,
                // The single flag the app gates its UI on, decided here so the
                // rule cannot drift between client and server.
                'is_active' => (bool) $subscription?->isCurrentlyValid(),
            ],
        ]);
    }

    /**
     * Subscribes to a plan.
     *
     * Created as PENDING and conferring nothing until payment settles. That is
     * not a placeholder for missing payment code — it is the correct shape:
     * mobile-money confirmation is asynchronous and arrives minutes later, so
     * an endpoint that granted travel synchronously would be handing out free
     * subscriptions to anyone who abandons the payment sheet.
     */
    public function store(SubscribeRequest $request)
    {
        $plan = PassPlan::where('code', $request->string('plan_code'))->firstOrFail();

        try {
            $subscription = $this->subscriptions->subscribe($request->user(), $plan);
        } catch (PassException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'error' => $e->errorCode,
            ], $e->status);
        }

        if ($request->boolean('auto_renew')) {
            $subscription->forceFill(['auto_renew' => true])->save();
        }

        return response()->json([
            'status' => true,
            'message' => 'Abonnement créé. Il sera actif dès la confirmation du paiement.',
            'data' => new PassSubscriptionResource($subscription->loadMissing('plan')),
        ], 201);
    }

    public function show(Request $request, int $id)
    {
        // Scoped to the caller: an id in the URL is not authorisation.
        $subscription = PassSubscription::where('client_id', $request->user()->id)
            ->with('plan')
            ->findOrFail($id);

        return new PassSubscriptionResource($subscription);
    }

    public function cancel(Request $request, int $id)
    {
        $subscription = PassSubscription::where('client_id', $request->user()->id)->findOrFail($id);

        $this->subscriptions->cancel($subscription, 'client_request');

        return response()->json([
            'status' => true,
            'message' => 'Abonnement annulé.',
            'data' => new PassSubscriptionResource($subscription->fresh(['plan'])),
        ]);
    }
}
