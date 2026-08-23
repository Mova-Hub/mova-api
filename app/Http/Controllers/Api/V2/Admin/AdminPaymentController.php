<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Domain\Payment\DTOs\ChargeResult;
use App\Domain\Payment\Enums\PaymentProvider;
use App\Domain\Payment\Enums\PaymentStatus;
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
            'provider' => ['nullable', Rule::in(array_column(PaymentProvider::cases(), 'value'))],
            'order_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:64'],
        ]);

        $query = Payment::with(['client', 'order'])->latest('id');

        foreach (['status', 'provider', 'order_id'] as $field) {
            if ($value = $request->input($field)) {
                $query->where($field, $value);
            }
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
        return new PaymentResource(Payment::with(['client', 'order'])->findOrFail($id));
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
     * Records the decision; it does not move money — no provider integration
     * exists to move it with. Saying so plainly in the response matters, because
     * an ops user who believes this refunded a customer will not then go and
     * actually refund them.
     */
    public function refund(Request $request, int $id)
    {
        $payment = Payment::findOrFail($id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if ($payment->status !== PaymentStatus::Succeeded) {
            return response()->json([
                'status' => false,
                'message' => 'Seul un paiement encaissé peut être remboursé.',
            ], 409);
        }

        // Not via apply(): Succeeded is terminal, and that guard is exactly
        // what protects against a replayed webhook. A refund is a deliberate
        // staff decision, so it is written here, explicitly, and only from
        // Succeeded.
        $payment->forceFill([
            'status' => PaymentStatus::Refunded,
            'failure_reason' => $data['reason'],
            'meta' => array_merge($payment->meta ?? [], [
                'refunded_by' => $request->user()->id,
                'refunded_at' => now()->toIso8601String(),
            ]),
        ])->save();

        return response()->json([
            'status' => true,
            'message' => 'Remboursement enregistré. Le virement doit être effectué manuellement auprès de l’opérateur.',
            'data' => new PaymentResource($payment->fresh()),
        ]);
    }
}
