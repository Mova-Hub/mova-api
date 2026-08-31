<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Domain\Payment\DTOs\ChargeResult;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Exceptions\PaymentException;
use App\Domain\Payment\PaymentService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Payment\PaymentResource;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Settling payments by hand.
 *
 * This is the other half of `ManualPaymentDriver`: the driver records that a
 * client intends to pay and hands ops a reference; this is where ops says the
 * money arrived. It exists because PRD decision D3 — the mobile-money provider
 * contract — is still open, and it stays useful afterwards for the cash and
 * bank-transfer cases a provider will never cover.
 *
 * **Every write goes through `PaymentService::apply()`.** Setting
 * `$payment->status` directly would bypass the terminal-state guard, and a
 * refunded payment flipped back to paid by a careless click is a reconciliation
 * problem nobody finds until the month-end.
 */
class AdminPaymentController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(array_column(PaymentStatus::cases(), 'value'))],
            // Against the TABLE, not an enum — providers are rows now, so a
            // method added this morning is filterable this morning.
            'provider' => ['nullable', 'string', Rule::exists('payment_providers', 'code')],
            'payable_type' => ['nullable', Rule::in(['order', 'subscription', 'reservation'])],
            /*
             * One payable's payments, which is what a detail screen wants.
             *
             * Without it the only way to narrow to a single booking was
             * `search`, and `search` matches the provider reference, the payer's
             * phone and the client's name. It has never matched a reservation
             * or order code, so the back office's Paiements tab was querying
             * for something that could not match and rendered an empty table
             * for every booking.
             *
             * A string rather than an integer: `orders` is keyed on a bigint
             * but `reservations` uses a UUID, and this filter has to serve both.
             */
            'payable_id' => ['nullable', 'string', 'max:64'],
            'channel' => ['nullable', Rule::in(['app', 'back_office', 'system'])],
            'search' => ['nullable', 'string', 'max:64'],
        ]);

        $query = Payment::with(['client', 'payable', 'provider'])->latest('id');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($provider = $request->input('provider')) {
            $query->where('provider_code', $provider);
        }

        if ($channel = $request->input('channel')) {
            $query->where('channel', $channel);
        }

        if ($type = $request->input('payable_type')) {
            $query->where('payable_type', match ($type) {
                'order' => \App\Models\Order::class,
                'subscription' => \App\Models\PassSubscription::class,
                'reservation' => \App\Models\Reservation::class,
            });
        }

        if ($payableId = $request->input('payable_id')) {
            $query->where('payable_id', $payableId);
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('provider_reference', 'like', "%{$search}%")
                  ->orWhere('payer_phone', 'like', "%{$search}%")
                  ->orWhereHas('client', fn ($c) => $c
                      ->where('name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        return PaymentResource::collection(
            $query->paginate((int) $request->input('per_page', 25))
        );
    }

    public function show(int $id)
    {
        return new PaymentResource(Payment::with(['client', 'payable', 'provider'])->findOrFail($id));
    }

    /**
     * Confirms that money arrived.
     *
     * The reference is REQUIRED, and that is not bureaucracy: a payment marked
     * succeeded with nothing to reconcile it against is indistinguishable from
     * a mistake, and this is the one action in the system that decides an order
     * is paid for.
     */
    public function confirm(Request $request, int $id)
    {
        $payment = Payment::findOrFail($id);

        $data = $request->validate([
            'reference' => ['required', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'reference.required' => 'Indiquez la référence de la transaction (SMS Mobile Money, reçu, bordereau).',
        ]);

        if ($payment->status->isFinal()) {
            return response()->json([
                'status' => false,
                'message' => 'Ce paiement est déjà clôturé (' . $payment->status->label() . ').',
            ], 409);
        }

        $payment = $this->payments->apply($payment, new ChargeResult(
            PaymentStatus::Succeeded,
            $data['reference'],
            null,
            // Who settled it, so the audit trail has an actor even before the
            // activity log lands.
            ['confirmed_by' => $request->user()->id, 'note' => $data['note'] ?? null],
        ));

        return response()->json([
            'status' => true,
            'message' => 'Paiement confirmé.',
            'data' => new PaymentResource($payment),
        ]);
    }

    /** Marks an attempt failed — the client abandoned it, or the transfer bounced. */
    public function fail(Request $request, int $id)
    {
        $payment = Payment::findOrFail($id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if ($payment->status->isFinal()) {
            return response()->json([
                'status' => false,
                'message' => 'Ce paiement est déjà clôturé (' . $payment->status->label() . ').',
            ], 409);
        }

        $payment = $this->payments->apply($payment, ChargeResult::failed(
            $data['reason'],
            ['failed_by' => $request->user()->id],
        ));

        return response()->json([
            'status' => true,
            'message' => 'Paiement marqué comme échoué.',
            'data' => new PaymentResource($payment),
        ]);
    }

    /**
     * Refunds a settled payment.
     *
     * Two paths, and the response says which one ran — an ops user who believes
     * this moved money will not then go and actually move it.
     *
     *  • **Driver supports refunds** (Airtel, Mova Credit): the money really
     *    goes back, recorded as a CHILD payment so the original collection
     *    stays readable.
     *  • **It does not** (cash, MTN until Disbursements is contracted): the
     *    decision is recorded and a human has to make the transfer.
     */
    public function refund(Request $request, int $id)
    {
        $payment = Payment::findOrFail($id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            // Partial refunds are real: a client who cancels one of three
            // vehicles is owed a third, not nothing and not everything.
            'amount' => ['nullable', 'integer', 'min:1', 'max:' . $payment->amount],
        ]);

        if ($payment->status !== PaymentStatus::Succeeded) {
            return response()->json([
                'status' => false,
                'message' => 'Seul un paiement encaissé peut être remboursé.',
            ], 409);
        }

        try {
            $refund = $this->payments->refund($payment, $data['amount'] ?? null, $request->user()->id);

            return response()->json([
                'status' => true,
                'message' => 'Remboursement effectué.',
                'data' => new PaymentResource($refund),
            ]);
        } catch (PaymentException $e) {
            /*
             * No automatic path. Record the decision so the ledger reflects
             * reality, and say plainly that a person still has to send the
             * money — silence here is how a customer never gets refunded.
             *
             * Written with forceFill rather than apply(): Succeeded is
             * terminal, and that guard is exactly what protects against a
             * replayed webhook. This is a deliberate staff decision, so it
             * bypasses the guard explicitly and only from Succeeded.
             */
            $payment->forceFill([
                'status' => PaymentStatus::Refunded,
                'failure_reason' => $data['reason'],
                'meta' => array_merge($payment->meta ?? [], [
                    'refunded_by' => $request->user()->id,
                    'refunded_at' => now()->toIso8601String(),
                    'refund_amount' => $data['amount'] ?? $payment->amount,
                    'manual_reason' => $e->getMessage(),
                ]),
            ])->save();

            return response()->json([
                'status' => true,
                'message' => 'Remboursement enregistré. ' . $e->getMessage()
                    . ' Le virement doit être effectué manuellement auprès de l’opérateur.',
                'data' => new PaymentResource($payment->fresh()),
            ]);
        }
    }
}
