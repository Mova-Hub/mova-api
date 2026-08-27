<?php

namespace App\Http\Controllers\Api\V2\Payment;

use App\Domain\Payment\Contracts\Payable;
use App\Domain\Payment\Exceptions\PaymentException;
use App\Domain\Payment\PaymentService;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Payment\PaymentMethodResource;
use App\Http\Resources\Payment\PaymentResource;
use App\Models\Client;
use App\Models\Order;
use App\Models\PassSubscription;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Paying, from the app.
 *
 * **Generic over payables.** One controller serves charter orders and Pass
 * subscriptions, because both implement Payable — the routes carry a `type`
 * and an `id` rather than being duplicated per product. Adding a third payable
 * is a line in `resolvePayable()`.
 *
 * Every lookup scopes to `$request->user()`. An id in a URL is a claim by the
 * caller, never an authorisation — without the scope, any authenticated client
 * could pay for, or read the price of, a stranger's booking.
 */
class PaymentController extends Controller
{
    /**
     * The payable types the app may address.
     *
     * An allow-list, not `$request->type` resolved to a class. Accepting a
     * caller-supplied class name is how a morph parameter becomes an arbitrary
     * model read.
     */
    private const PAYABLE_TYPES = [
        'order' => Order::class,
        'subscription' => PassSubscription::class,
    ];

    public function __construct(
        private PaymentService $payments,
        private WalletService $wallet,
    ) {}

    /**
     * What can be paid, and how.
     *
     * The app asks before showing the sheet rather than deciding locally,
     * because "may this be paid yet" depends on state the client does not hold
     * — and a "Payer" button that fails on tap is worse than one that was
     * never offered.
     */
    public function options(Request $request, string $type, string $id)
    {
        /** @var Client $client */
        $client = $request->user();
        $payable = $this->resolvePayable($client, $type, $id);

        $outstanding = $this->payments->amountDue($payable, 'full');
        $balance = $this->wallet->spendableAgainst($client, max(1, $outstanding));

        $methods = $this->payments
            ->availableProviders($outstanding ?: 1, $payable->paymentCurrency())
            ->map(fn ($p) => new PaymentMethodResource($p, $outstanding, $balance))
            // A zero balance makes Mova Credit a row that can only fail.
            ->reject(fn ($m) => $m->code === 'mova_credit' && $balance <= 0)
            ->values();

        return response()->json([
            'status' => true,
            'data' => [
                'amount' => $outstanding,
                'total_amount' => $payable->paymentAmount(),
                'paid_amount' => $this->payments->paidTotal($payable),
                'currency' => $payable->paymentCurrency(),
                'description' => $payable->paymentDescription(),

                'is_paid' => $this->payments->isPaid($payable),
                'is_payable' => $payable->isPayable(),

                // Deposit is offered only when settings allow it, nothing has
                // been collected yet, and the amount is worth splitting.
                'allows_deposit' => $this->payments->allowsDeposit($payable)
                    && $outstanding >= \App\Domain\Settings\Facades\Settings::int('rules.deposit_min_amount', 50_000),
                'deposit_amount' => $this->payments->amountDue($payable, 'deposit'),
                'deposit_percent' => $this->payments->depositShare(),

                'wallet_balance' => $this->wallet->balanceFor($client),
                'methods' => $methods,

                // An attempt already running, so the sheet resumes it instead
                // of starting a second prompt on the same handset.
                // `maybe`, not `make`: `make(null)` fatals on serialization —
                // see PaymentResource. This endpoint threw a 500 for every
                // payable with nothing in flight, which is the normal case.
                'pending' => PaymentResource::maybe($this->payments->inFlightFor($payable)),
            ],
        ]);
    }

    /**
     * Every method this client could use, independent of a purchase.
     *
     * Backs the account screen's "moyens de paiement" list. Separate from
     * `options()` because that one answers "how do I pay THIS", and conflating
     * the two would make the account screen invent an amount.
     */
    public function methods(Request $request)
    {
        /** @var Client $client */
        $client = $request->user();
        $balance = $this->wallet->balanceFor($client);

        $methods = $this->payments->availableProviders(1)
            ->map(fn ($p) => new PaymentMethodResource($p, 0, $balance))
            ->values();

        return response()->json([
            'status' => true,
            'data' => ['methods' => $methods, 'wallet_balance' => $balance],
        ]);
    }

    /** Starts a payment. Idempotent while one is in flight — see PaymentService. */
    public function store(Request $request, string $type, string $id)
    {
        /** @var Client $client */
        $client = $request->user();
        $payable = $this->resolvePayable($client, $type, $id);

        $data = $request->validate([
            // Validated against the providers TABLE, so a method enabled five
            // minutes ago is accepted without a deploy — the whole point.
            'provider' => ['required', 'string', Rule::exists('payment_providers', 'code')->where('enabled', true)],
            'kind' => ['nullable', Rule::in(['full', 'deposit', 'balance'])],
            // E.164. The prompt is pushed to this number, which is often not
            // the account's — people pay from a spouse's or employer's wallet.
            'phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
        ], [
            'provider.exists' => 'Ce moyen de paiement n’est pas disponible.',
            'phone.regex' => 'Numéro invalide. Format attendu : +242 06 123 4567.',
        ]);

        // NOTE: no amount is accepted. See PaymentService — the payable owns it.
        try {
            $payment = $this->payments->start(
                payable: $payable,
                client: $client,
                providerCode: $data['provider'],
                fields: array_filter(['phone' => $data['phone'] ?? null]),
                kind: $data['kind'] ?? 'full',
                channel: 'app',
            );
        } catch (PaymentException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => true,
            'message' => 'Paiement initié.',
            'data' => new PaymentResource($payment),
        ], 201);
    }

    /**
     * Where a payment stands.
     *
     * The app polls this while the prompt is on the handset. Webhooks get lost,
     * and a client staring at "en cours" with no way to refresh is how support
     * tickets are made.
     */
    public function show(Request $request, string $uuid)
    {
        $payment = Payment::where('client_id', $request->user()->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        // Re-asks the provider only while there is something to learn, and
        // expires the attempt if its window has closed.
        $payment = $this->payments->refresh($payment);

        return response()->json(['status' => true, 'data' => new PaymentResource($payment)]);
    }

    /** Payment history for one payable. */
    public function index(Request $request, string $type, string $id)
    {
        $payable = $this->resolvePayable($request->user(), $type, $id);

        return PaymentResource::collection($payable->payments()->with('provider')->get());
    }

    /**
     * Resolves `{type}/{id}` to a model the caller actually owns.
     *
     * The `client_id` scope is the authorisation. `findOrFail` on the id alone
     * would let any signed-in client read the price of, and pay for, anyone's
     * booking — an id in a URL is a claim, not a permission.
     */
    private function resolvePayable(Client $client, string $type, string $id): Payable&Model
    {
        $class = self::PAYABLE_TYPES[$type] ?? abort(404, 'Type de paiement inconnu.');

        /** @var Payable&Model $payable */
        $payable = $class::where('client_id', $client->id)->findOrFail($id);

        return $payable;
    }
}
