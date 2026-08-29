<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\ActivityLogger;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\UpdateReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Http\Resources\Payment\PaymentMethodResource;
use App\Http\Resources\Payment\PaymentResource;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Exceptions\PaymentException;
use App\Domain\Payment\PaymentService;
use App\Domain\Payment\Support\PhoneNumber;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Models\Reservation;
use App\Notifications\ReservationStatusUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB; // Imported for transactions
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    // ... [Previous methods: index, store, show, update, destroy, restore, setStatus, syncBuses, attachBus, detachBus, bulkStatus] ...
    // (Keep all existing methods exactly as they were in your provided code)

    // GET /api/reservations?search=...
    public function index(Request $request)
    {
        $q = Reservation::query();
        $trashed = $request->query('trashed');
        if ($trashed === 'with')      $q->withTrashed();
        elseif ($trashed === 'only')  $q->onlyTrashed();

        if ($with = $request->query('with')) {
            $rels = collect(explode(',', $with))->intersect(['buses'])->all();
            if ($rels) $q->with($rels);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $q->where(function ($qq) use ($search) {
                $qq->where('code', 'like', "%{$search}%")
                    ->orWhere('passenger_name', 'like', "%{$search}%")
                    ->orWhere('passenger_phone', 'like', "%{$search}%")
                    ->orWhere('from_location', 'like', "%{$search}%")
                    ->orWhere('to_location', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        $dateFrom = $request->query('date_from');
        $dateTo   = $request->query('date_to');
        if ($dateFrom) $q->whereDate('trip_date', '>=', $dateFrom);
        if ($dateTo)   $q->whereDate('trip_date', '<=', $dateTo);

        if ($busId = $request->query('bus_id')) {
            $q->whereHas('buses', fn($bq) => $bq->where('buses.id', $busId));
        }

        $orderBy = in_array($request->query('order_by'), [
            'created_at','updated_at','trip_date','price_total','seats','status'
        ], true) ? $request->query('order_by') : 'trip_date';

        $orderDir = $request->query('order_dir') === 'asc' ? 'asc' : 'desc';
        $q->orderBy($orderBy, $orderDir);

        $perPage = max((int) $request->query('per_page', 15), 1);

        return ReservationResource::collection($q->paginate($perPage));
    }

    public function store(StoreReservationRequest $request)
    {
        $data = $request->validated();
        Log::info('StoreReservation validated data', ['data' => $data]);
        $busIds = $data['bus_ids'] ?? null;
        unset($data['bus_ids']);
        $reservation = Reservation::create($data);
        if (is_array($busIds)) {
            $reservation->buses()->sync(array_values($busIds));
        }
        return (new ReservationResource($reservation->load('buses')))
            ->response()->setStatusCode(201);
    }

    public function show(Reservation $reservation)
    {
        // `client` and `order` too: a detail screen that cannot reach the
        // customer or the lead behind a booking is a dead end, and both are one
        // relation away.
        $reservation->load(['buses', 'client', 'order']);
        return new ReservationResource($reservation);
    }

    public function update(UpdateReservationRequest $request, Reservation $reservation)
    {
        $data   = $request->validated();
        Log::info('UpdateReservation validated data', ['data' => $data]);
        $busIds = array_key_exists('bus_ids', $data) ? ($data['bus_ids'] ?? null) : null;
        unset($data['bus_ids']);
        $reservation->update($data);
        if ($busIds !== null) {
            $reservation->buses()->sync(array_values($busIds));
            // The pivot wins. An edit that changes the vehicles and leaves the
            // old capacity behind is how "3 places" ends up on a booking with
            // two coaches attached — and nobody notices until boarding.
            $this->recomputeSeats($reservation);
        }
        Log::info('UpdateReservation validated payload', [
            'reservation_id' => $reservation->id,
            'data' => $data,
            'bus_ids' => $busIds,
        ]);
        return new ReservationResource($reservation->load('buses'));
    }

    public function destroy(Reservation $reservation)
    {
        $reservation->delete();
        return response()->noContent();
    }

    public function restore(string $reservation)
    {
        $model = Reservation::onlyTrashed()->findOrFail($reservation);
        $model->restore();
        return new ReservationResource($model->load('buses'));
    }

    public function setStatus(Request $request, Reservation $reservation)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'])],
        ]);

        $newStatus = $validated['status'];

        if ($newStatus === 'in_progress' && $reservation->status !== 'confirmed') {
            return response()->json(['error' => 'La réservation doit être confirmée avant de démarrer.'], 422);
        }
        if ($newStatus === 'completed' && $reservation->status !== 'in_progress') {
            return response()->json(['error' => 'La réservation doit être en cours avant de terminer.'], 422);
        }

        DB::transaction(function () use ($reservation, $newStatus) {
            $updates = ['status' => $newStatus];

            if ($newStatus === 'in_progress' && $reservation->started_at === null) {
                $updates['started_at'] = now();
            }

            if ($newStatus === 'completed' && $reservation->completed_at === null) {
                $updates['completed_at'] = now();
            }

            $reservation->update($updates);

            if ($newStatus === 'cancelled' && $reservation->order) {
                $reservation->order->update(['status' => 'cancelled']);
            }
        });

        // TRIGGER NOTIFICATION AFTER TRANSACTION
        // We load the client relationship if it's not already loaded
        $reservation->loadMissing('client');
        if ($reservation->client) {
            $message = match($newStatus) {
                'in_progress' => "Votre trajet vers {$reservation->to_location} vient de commencer. Bonne route !",
                'completed' => "Vous êtes arrivé à destination ({$reservation->to_location}). Merci d'avoir voyagé avec nous !",
                'cancelled' => "Votre réservation pour {$reservation->to_location} a été annulée.",
                default => "Le statut de votre réservation pour {$reservation->to_location} a été mis à jour."
            };

            $reservation->client->notify(new ReservationStatusUpdated($reservation, $message));
        }

        return new ReservationResource($reservation->fresh(['order']));
    }

    /*
     * These three validated bus ids as `uuid`.
     *
     * `reservations` uses HasUuids, `buses` does not — `buses.id` is a plain
     * auto-increment bigint (2025_10_20_023855_create_buses_table). So the rule
     * could never pass and all three endpoints have been permanently broken:
     * assigning a bus to a reservation was impossible through the API.
     *
     * `BusController@bulkStatus` had it right all along with `integer`, which is
     * why bulk operations worked while these did not.
     */

    public function syncBuses(Request $request, Reservation $reservation)
    {
        $validated = $request->validate([
            'bus_ids'   => ['required','array','min:0'],
            'bus_ids.*' => ['integer','distinct','exists:buses,id'],
        ]);
        $reservation->buses()->sync($validated['bus_ids']);
        $this->recomputeSeats($reservation);
        return new ReservationResource($reservation->load('buses'));
    }

    public function attachBus(Request $request, Reservation $reservation)
    {
        $validated = $request->validate([
            'bus_id' => ['required','integer','exists:buses,id'],
        ]);
        $reservation->buses()->syncWithoutDetaching([$validated['bus_id']]);
        $this->recomputeSeats($reservation);
        return new ReservationResource($reservation->load('buses'));
    }

    public function detachBus(Request $request, Reservation $reservation)
    {
        $validated = $request->validate([
            'bus_id' => ['required','integer','exists:buses,id'],
        ]);
        $reservation->buses()->detach($validated['bus_id']);
        $this->recomputeSeats($reservation);
        return new ReservationResource($reservation->load('buses'));
    }

    /**
     * Capacity, re-read from the vehicles actually attached.
     *
     * `seats` is a cached sum of the pivot, and a cache nobody refreshes is just
     * a wrong number. Every converted reservation used to read "Places : 0"
     * because `convertToReservation` wrote a literal zero and deferred to "pivot
     * logic" that was never written; attaching or detaching a vehicle afterwards
     * left the figure exactly as stale.
     *
     * Called from all three attribution endpoints and from `update()` — one
     * helper rather than four copies, because the copy that gets forgotten is
     * the one that silently reintroduces the drift.
     *
     * `saveQuietly` on purpose: the audit trail should carry the attribution the
     * agent actually made, not a second entry for a derived total.
     */
    private function recomputeSeats(Reservation $reservation): void
    {
        $reservation->seats = (int) $reservation->buses()->sum('capacity');
        $reservation->saveQuietly();
    }

    /**
     * Mass status change.
     *
     * Rewritten from a single `Builder::update()`. That was faster, but it
     * bypassed Eloquent entirely, which cost two things silently:
     *
     *  - **No client notification.** `setStatus` notifies on every transition;
     *    doing the same change in bulk told nobody, so whether a customer heard
     *    about their cancellation depended on which button an agent used.
     *  - **No audit trail.** Model events do not fire on a query-builder write,
     *    so the mass actions most worth auditing would have been the only ones
     *    missing from the log.
     *
     * A chunked loop is the correct trade: these are hand-selected rows from a
     * table view, so the counts are tens, not millions.
     */
    public function bulkStatus(Request $request)
    {
        $validated = $request->validate([
            'ids'    => ['required','array','min:1','max:200'],
            'ids.*'  => ['uuid','exists:reservations,id'],
            // Widened from pending|confirmed|cancelled to the full set, because
            // confirming a morning's trips and then marking them started is
            // exactly the work this endpoint exists for. The transition rules
            // below are what make that safe.
            'status' => ['required', Rule::in(['pending','confirmed','in_progress','completed','cancelled'])],
        ]);

        $updated = 0;
        $skipped = [];

        Reservation::whereIn('id', $validated['ids'])
            ->chunkById(100, function ($reservations) use ($validated, &$updated, &$skipped) {
                foreach ($reservations as $reservation) {
                    if ($reservation->status === $validated['status']) {
                        continue; // Nothing changed; do not file an empty entry.
                    }

                    /*
                     * The SAME state machine setStatus() enforces.
                     *
                     * A bulk endpoint that skips it is how a reservation
                     * reaches `completed` without ever having started — and the
                     * trip timestamps below would then be nonsense. Illegal
                     * transitions are reported back rather than silently
                     * dropped, so an operator knows which rows did not move.
                     */
                    if (! $this->canTransition($reservation->status, $validated['status'])) {
                        $skipped[] = $reservation->code ?? $reservation->id;
                        continue;
                    }

                    $reservation->status = $validated['status'];

                    // Stamped here too, or a bulk start leaves started_at null
                    // and the trip looks like it never happened.
                    if ($validated['status'] === 'in_progress' && ! $reservation->started_at) {
                        $reservation->started_at = now();
                    }
                    if ($validated['status'] === 'completed' && ! $reservation->completed_at) {
                        $reservation->completed_at = now();
                    }

                    $reservation->save(); // Fires observers → one audit row each.
                    $updated++;
                }
            });

        app(ActivityLogger::class)->log(
            'reservation.bulk_status',
            context: [
                'ids' => $validated['ids'],
                'status' => $validated['status'],
                'updated' => $updated,
                'skipped' => $skipped,
            ],
        );

        return response()->json([
            'updated' => $updated,
            'skipped' => $skipped,
            'message' => $skipped === []
                ? "{$updated} réservation(s) mise(s) à jour."
                : sprintf(
                    '%d mise(s) à jour. %d ignorée(s) — transition impossible depuis leur statut actuel : %s.',
                    $updated,
                    count($skipped),
                    implode(', ', array_slice($skipped, 0, 5)),
                ),
        ]);
    }

    /**
     * Whether a reservation may move from one status to another.
     *
     * Mirrors setStatus()'s guards so single and bulk paths cannot diverge —
     * which they would, the first time one of them was edited alone.
     */
    private function canTransition(string $from, string $to): bool
    {
        return match ($to) {
            'in_progress' => $from === 'confirmed',
            'completed' => $from === 'in_progress',
            // Cancelling is allowed from anything still open; a completed trip
            // is history and is not un-done by a checkbox.
            'cancelled' => in_array($from, ['pending', 'confirmed', 'in_progress'], true),
            'confirmed' => $from === 'pending',
            'pending' => $from === 'confirmed',
            default => false,
        };
    }

    // -------------------------------------------------------------------------
    // NEW: PAYMENT ENDPOINT
    // -------------------------------------------------------------------------

    // POST /api/reservations/{reservation}/payment
    public function payment(Request $request, Reservation $reservation)
    {
        $validated = $request->validate([
            'amount'    => ['required', 'numeric', 'min:1'],
            'method'    => ['required', 'string', Rule::in(['cash', 'mobile_money', 'bank_transfer', 'check'])],
            'note'      => ['nullable', 'string'],
            'reference' => ['required_unless:method,cash', 'nullable', 'string', 'max:255'],
        ]);

        /*
         * Writes into `payments`, not the old `transactions` table.
         *
         * Recorded as already `succeeded`: an agent entering this has the cash
         * or the transfer confirmation in front of them, so unlike an app
         * payment there is nothing to wait for. `channel = back_office` and
         * `created_by` are what keep it attributable.
         */
        DB::transaction(function () use ($reservation, $validated, $request) {
            $amount = (int) round((float) $validated['amount']);

            Payment::create([
                'payable_type'       => Reservation::class,
                'payable_id'         => $reservation->id,
                'client_id'          => $reservation->client_id,
                'provider_code'      => $this->providerCodeForMethod($validated['method']),
                'channel'            => 'back_office',
                'kind'               => 'full',
                'status'             => PaymentStatus::Succeeded,
                'amount'             => $amount,
                'net_amount'         => $amount,
                'currency'           => 'XAF',
                'provider_reference' => $validated['reference'] ?? null,
                'paid_at'            => now(),
                'meta'               => ['note' => $validated['note'] ?? null],
                'created_by'         => $request->user()?->id,
            ]);

            // Derived from the ledger rather than accumulated by hand, so a
            // corrected or deleted entry cannot leave the flag out of step.
            $reservation->refresh();
            $reservation->update([
                'payment_status' => $reservation->isFullyPaid() ? 'paid' : 'pending',
            ]);
        });

        // TRIGGER PAYMENT NOTIFICATION
        $reservation->loadMissing('client');
        if ($reservation->client) {
            $formattedAmount = number_format($validated['amount'], 0, ',', ' ');
            $message = "Nous avons bien reçu votre paiement de {$formattedAmount} FCFA pour votre réservation vers {$reservation->to_location}.";

            // Re-use the status updated notification, but pass the payment message
            $reservation->client->notify(new ReservationStatusUpdated($reservation, $message));
        }

        return new ReservationResource($reservation->refresh());
    }

    // -------------------------------------------------------------------------
    // PUSHING A PROMPT TO THE CLIENT'S HANDSET
    // -------------------------------------------------------------------------

    /**
     * What this reservation still owes, and which providers can collect it.
     *
     * The back-office counterpart of the app's `/payments/{type}/{id}/options`.
     * Staff needs the same three answers before it can offer a button — how much
     * is left, which methods accept that amount, and whether a prompt is already
     * sitting on somebody's phone — and had no endpoint that gave any of them.
     *
     * Deliberately NOT `admin/payment-providers`: that route is admin-only and
     * returns provider configuration, credentials tail included. An agent taking
     * a payment needs a list of labels, not the merchant's settings.
     */
    public function paymentOptions(Reservation $reservation)
    {
        $outstanding = $this->payments->amountDue($reservation, 'full');

        $methods = $this->payments
            ->availableProviders(max(1, $outstanding), $reservation->paymentCurrency())
            /*
             * Only providers that can actually REACH the client.
             *
             * `manual` drivers — cash, cheque, bank transfer — settle when a
             * human says so, which is precisely what the other endpoint
             * (`payment`) is for. Offering them here would let an agent
             * "request" cash: a pending payment against a provider that will
             * never call back, which then blocks the real attempt, because the
             * service allows only one live attempt per payable.
             *
             * Mova Credit is excluded for a different reason: it spends the
             * client's own balance, and consent for that is a tap in their app,
             * not a dropdown in a back-office.
             */
            ->reject(fn ($p) => $p->driver === 'manual' || $p->code === 'mova_credit')
            ->map(fn ($p) => new PaymentMethodResource($p, $outstanding))
            ->values();

        return response()->json([
            'status' => true,
            'data' => [
                'amount'        => $outstanding,
                'total_amount'  => $reservation->paymentAmount(),
                'paid_amount'   => $this->payments->paidTotal($reservation),
                'currency'      => $reservation->paymentCurrency(),
                'description'   => $reservation->paymentDescription(),
                'is_paid'       => $this->payments->isPaid($reservation),
                'is_payable'    => $reservation->isPayable(),
                'methods'       => $methods,
                // An attempt already running, so the dialog resumes it instead
                // of pushing a second prompt to the same handset.
                'pending'       => PaymentResource::maybe($this->payments->inFlightFor($reservation)),
                // Pre-fills the phone field. Almost always the right number, and
                // an agent retyping it from a screen is how digits get lost.
                // E.164 so the dialog can format it back for display.
                'default_phone' => PhoneNumber::toE164($reservation->passenger_phone),
            ],
        ]);
    }

    /**
     * Asks the client to pay — a real provider charge, initiated by staff.
     *
     * POST /api/reservations/{reservation}/charge
     *
     * The difference from `payment()` above is where the money is at the moment
     * the row is written. `payment()` RECORDS cash an agent already holds and is
     * `succeeded` on creation. This one STARTS a collection: the prompt goes to
     * a handset, the payment is `pending`, and only the provider decides.
     * Conflating the two would let a phone call become a paid booking.
     *
     * Not the client-facing `Api/V2/Payment/PaymentController`, which scopes
     * every lookup by `client_id` — correct for the app, and wrong here, where
     * the whole point is that staff acts for someone else. The amount still
     * comes from the payable, never from the request.
     */
    public function charge(Request $request, Reservation $reservation)
    {
        // Spaces out, country code in, before the regex ever sees it — the
        // same normalisation the app-facing endpoint applies. See PhoneNumber.
        if ($request->has('phone')) {
            $request->merge(['phone' => PhoneNumber::toE164($request->input('phone'))]);
        }

        $data = $request->validate([
            // Against the providers TABLE, so a method enabled this morning is
            // usable this morning without a deploy.
            'provider' => ['required', 'string', Rule::exists('payment_providers', 'code')->where('enabled', true)],
            'kind'     => ['nullable', Rule::in(['full', 'deposit', 'balance'])],
            // E.164. Often not the account's number — a company pays for its
            // staff, a parent for a school trip.
            'phone'    => ['nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
        ], [
            'provider.exists' => 'Ce moyen de paiement n’est pas disponible.',
            'phone.regex'     => 'Numéro invalide. Format attendu : +242 06 123 4567.',
        ]);

        /*
         * The same two exclusions `paymentOptions` applies, enforced here too.
         *
         * The list is what an honest UI offers; this is what the endpoint
         * accepts. A provider code in a request body is a claim by the caller,
         * and "the dropdown did not show it" has never been an access control.
         */
        if ($data['provider'] === 'mova_credit') {
            return response()->json([
                'status'  => false,
                'message' => 'Le solde Mova ne peut être utilisé que par le client, depuis l’application.',
            ], 422);
        }

        if (PaymentProvider::where('code', $data['provider'])->value('driver') === 'manual') {
            return response()->json([
                'status'  => false,
                // Not a scolding: it names the endpoint that DOES do this.
                'message' => 'Ce moyen se règle en direct. Utilisez « Encaisser » pour enregistrer le paiement reçu.',
            ], 422);
        }

        try {
            $payment = $this->payments->start(
                payable: $reservation,
                client: $reservation->client,
                providerCode: $data['provider'],
                // The booking's own number as the fallback, normalised too —
                // it was typed by an agent into a free-text field years before
                // anyone specified a format.
                fields: array_filter([
                    'phone' => $data['phone'] ?? PhoneNumber::toE164($reservation->passenger_phone),
                ]),
                kind: $data['kind'] ?? 'full',
                // Attributable: this collection was opened by staff, not by the
                // client tapping "payer". Reconciliation needs to tell them apart.
                channel: 'back_office',
                actorId: $request->user()?->id,
            );
        } catch (PaymentException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Demande de paiement envoyée au client.',
            'data'    => new PaymentResource($payment),
        ], 201);
    }

    /**
     * Where a staff-initiated collection stands.
     *
     * GET /api/reservations/{reservation}/payments/{uuid}
     *
     * Polled while the prompt sits on the handset. Goes through
     * `PaymentService::refresh()`, which re-asks the provider only while there
     * is something to learn and expires the attempt once its window closes —
     * webhooks get lost, and an agent watching "en cours" forever with no way to
     * refresh is how a client gets charged twice.
     *
     * Scoped to the reservation in the URL, so the uuid cannot be used to read
     * an unrelated payment.
     */
    public function paymentStatus(Reservation $reservation, string $uuid)
    {
        $payment = Payment::where('payable_type', Reservation::class)
            ->where('payable_id', $reservation->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        $payment = $this->payments->refresh($payment);

        // The flag follows the ledger, so a prompt that just succeeded is
        // reflected without waiting for the next page load.
        $reservation->refresh();
        $reservation->update([
            'payment_status' => $reservation->isFullyPaid() ? 'paid' : 'pending',
        ]);

        return response()->json([
            'status' => true,
            'data'   => new PaymentResource($payment),
            'reservation' => [
                'payment_status' => $reservation->payment_status,
                'paid_amount'    => $this->payments->paidTotal($reservation),
            ],
        ]);
    }

    /**
     * The back-office's payment methods, mapped to provider codes.
     *
     * These four are what an agent can actually record by hand. `mobile_money`
     * here is a MANUAL entry — money the client sent by MoMo and an agent
     * confirmed — which is a different thing from the `mtn_momo` provider that
     * pushes a prompt to a handset, and must not share its code or the two
     * would be indistinguishable in reconciliation.
     */
    private function providerCodeForMethod(string $method): string
    {
        return match ($method) {
            'cash'          => 'cash',
            'mobile_money'  => 'mobile_money_manual',
            'bank_transfer' => 'bank_transfer',
            'check'         => 'cheque',
            default         => 'cash',
        };
    }
}
