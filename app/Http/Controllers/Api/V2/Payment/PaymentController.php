<?php

namespace App\Http\Controllers\Api\V2\Payment;

use App\Domain\Payment\Enums\PaymentProvider;
use App\Domain\Payment\PaymentService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Payment\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Paying for an order from the app.
 *
 * Every route scopes to `$request->user()`. An order id in a URL is a claim by
 * the caller, never an authorisation — without the scope, any authenticated
 * client could pay for, or read the price of, a stranger's booking.
 */
class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    /**
     * What can be paid, and how.
     *
     * The app asks before showing the sheet rather than deciding locally,
     * because "may this be paid yet" depends on reservation status the client
     * does not hold — and a Payer button that fails on tap is worse than one
     * that was never offered.
     */
    public function options(Request $request, int $id)
    {
        $order = Order::with('reservation')->where('client_id', $request->user()->id)->findOrFail($id);

        $providers = collect(PaymentProvider::cases())->map(fn (PaymentProvider $p) => [
            'id' => $p->value,
            'label' => $p->label(),
            'requires_phone' => $p->requiresPhone(),
            'phone_prefixes' => $p->phonePrefixes(),
        ]);

        return response()->json([
            'status' => true,
            'data' => [
                'amount' => $this->payments->amountFor($order),
                'currency' => 'XAF',
                'is_paid' => $this->payments->isPaid($order),
                'is_payable' => $this->payments->isPayable($order),
                'providers' => $providers,
                // An attempt already running, so the sheet resumes it instead
                // of starting a second prompt on the same handset.
                'pending' => PaymentResource::make(
                    Payment::where('order_id', $order->id)->inFlight()->latest()->first()
                ),
            ],
        ]);
    }

    /** Starts a payment. Idempotent while one is in flight — see PaymentService. */
    public function store(Request $request, int $id)
    {
        $order = Order::with('reservation')->where('client_id', $request->user()->id)->findOrFail($id);

        $data = $request->validate([
            'provider' => ['required', Rule::in(array_column(PaymentProvider::cases(), 'value'))],
            // E.164. The prompt is pushed to this number, which is often not
            // the account's — people pay from a spouse's or employer's wallet.
            'payer_phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
        ], [
            'payer_phone.regex' => 'Numéro invalide. Format attendu : +242 06 123 4567.',
        ]);

        $provider = PaymentProvider::from($data['provider']);

        if ($provider->requiresPhone() && empty($data['payer_phone'])) {
            return response()->json([
                'status' => false,
                'message' => 'Indiquez le numéro Mobile Money à débiter.',
            ], 422);
        }

        // NOTE: the amount is never read from the request. See PaymentService.
        try {
            $payment = $this->payments->start(
                $order,
                $request->user(),
                $provider,
                $data['payer_phone'] ?? null,
            );
        } catch (RuntimeException $e) {
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
    public function show(Request $request, int $id, string $uuid)
    {
        $payment = Payment::where('client_id', $request->user()->id)
            ->where('order_id', $id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        // Re-asks the provider only while there is something to learn.
        if (! $payment->status->isFinal()) {
            $driver = $this->payments->driverFor($payment->provider);
            $payment = $this->payments->apply($payment, $driver->status($payment));
        }

        return response()->json([
            'status' => true,
            'data' => new PaymentResource($payment),
        ]);
    }

    /** Payment history for an order. */
    public function index(Request $request, int $id)
    {
        $payments = Payment::where('client_id', $request->user()->id)
            ->where('order_id', $id)
            ->orderByDesc('id')
            ->get();

        return PaymentResource::collection($payments);
    }
}
