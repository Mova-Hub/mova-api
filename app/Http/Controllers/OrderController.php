<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Support\PerformsAuditedBulkUpdates;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Reservation; // Assuming you have this or will build it
use App\Notifications\OrderStatusUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    use PerformsAuditedBulkUpdates;

    /**
     * List orders — the lead pipeline.
     *
     * Two fixes here, both of which the back-office was working around:
     *
     *  1. **`status` is now an optional filter.** It used to default to
     *     `pending` and always apply, so there was literally no way to list
     *     every order — a request without the parameter silently returned a
     *     subset. The list page's "Tous" option could not work.
     *  2. **Returns a Resource collection**, so the response carries a `meta`
     *     block. `response()->json($paginator)` emits Laravel's raw paginator
     *     shape, which has no `meta` key at all, so the client's pagination
     *     read `undefined` and fell back to guessing.
     */
    public function index(Request $request)
    {
        $request->validate([
            'status' => 'nullable|in:pending,contacted,converted,cancelled',
            'search' => 'nullable|string|max:120',
            'event_type' => 'nullable|string|max:60',
        ]);

        $orders = Order::with('client')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('event_type'), fn ($q) => $q->where('event_type', $request->input('event_type')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->string('search') . '%';
                $q->where(fn ($w) => $w
                    ->where('contact_name', 'like', $term)
                    ->orWhere('contact_phone', 'like', $term)
                    ->orWhere('origin', 'like', $term)
                    ->orWhere('destination', 'like', $term));
            })
            ->latest()
            ->paginate((int) $request->input('per_page', 25));

        return OrderResource::collection($orders);
    }

    /**
     * One order, with everything a detail screen needs.
     *
     * Eager-loads the reservation and its buses: a converted lead whose
     * booking cannot be reached from it is a dead end, and "which reservation
     * did this become" is the first thing anyone asks.
     */
    public function show($id)
    {
        $order = Order::with(['client', 'reservation.buses'])->findOrFail($id);

        // Helper links for frontend actions
        $actions = [
            'call_link' => "tel:{$order->contact_phone}",
            'whatsapp_link' => "https://wa.me/" . str_replace('+', '', $order->contact_phone),
        ];

        return response()->json([
            // A Resource now, not the raw model — see OrderResource's docblock.
            'order' => new OrderResource($order),
            'actions' => $actions,
        ]);
    }

    /**
     * Moves several leads at once.
     *
     * Goes through the audited bulk helper rather than a query-builder
     * `update()`: model events have to fire so the activity log records one row
     * per order, not a single summary that names no ids.
     */
    public function bulkStatus(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'status' => ['required', 'in:pending,contacted,converted,cancelled'],
        ]);

        /*
         * `converted` is deliberately NOT settable here.
         *
         * Converting a lead creates a reservation, assigns vehicles and prices
         * the trip — that is `convertToReservation`, not a status flip. Allowing
         * it in bulk would mark orders converted with no booking behind them.
         */
        if ($data['status'] === 'converted') {
            return response()->json([
                'status' => false,
                'message' => 'La conversion se fait commande par commande, avec attribution des véhicules.',
            ], 422);
        }

        $updated = $this->auditedBulkUpdate(
            Order::whereIn('id', $data['ids']),
            ['status' => $data['status']],
            'order.bulk_status',
        );

        return response()->json([
            'status' => true,
            'message' => "{$updated} commande(s) mise(s) à jour.",
            'updated' => $updated,
        ]);
    }

    /**
     * Action: Mark as Contacted / Add Notes
     */
    public function update(Request $request, $id)
    {
        $order = Order::findOrFail($id);

        $data = $request->validate([
            'status' => 'nullable|in:pending,contacted,converted,cancelled',
            'internal_notes' => 'nullable|string'
        ]);

        $order->update($data);

        return response()->json(['message' => 'Commande mise à jour.', 'order' => $order]);
    }

    /**
     * Convert Order to Reservation with full details.
     */
    public function convertToReservation(Request $request, $id)
    {
        $order = Order::with('client')->findOrFail($id);

        if ($order->status === 'converted') {
            return response()->json(['message' => 'Cette demande est déjà convertie.'], 400);
        }

        // Comprehensive validation matching the Reservation requirements
        $data = $request->validate([
            'trip_date'      => 'required|date',
            // The return leg. Must not precede departure — a reservation that
            // comes back before it leaves is unschedulable, and dispatch would
            // only find out on the day.
            'return_date'    => 'nullable|date|after:trip_date',
            'from_location'  => 'required|string',
            'to_location'    => 'required|string',
            'passenger_name' => 'required|string',
            'passenger_phone'=> 'required|string',
            'passenger_email'=> 'nullable|email',
            'price_total'    => 'required|numeric|min:0',
            'bus_ids'        => 'required|array|min:1',
            'bus_ids.*'      => 'exists:buses,id',
            'waypoints'      => 'nullable|array',
            'distance_km'    => 'nullable|numeric',
            'event'          => 'nullable|string',
            // Head count. Defaults to the order's own figure below — the client
            // already told us, and re-asking an agent to retype it is how the
            // two records end up disagreeing.
            'passengers'     => 'nullable|integer|min:1|max:300',
            'internal_notes' => 'nullable|string'
        ]);

        return DB::transaction(function () use ($order, $data) {
            // 1. Create the Reservation
            $reservation = Reservation::create([
                'order_id'        => $order->id,
                'client_id'       => $order->client_id,
                'trip_date'       => $data['trip_date'],
                // Null = one way. The same rule the order path uses, so a
                // request and the booking it becomes agree on what was sold —
                // a round trip converted without this became a one-way booking
                // that dispatch had no way to know needed a return.
                'return_date'     => $data['return_date'] ?? null,
                'from_location'   => $data['from_location'],
                'to_location'     => $data['to_location'],
                'passenger_name'  => $data['passenger_name'],
                'passenger_phone' => $data['passenger_phone'],
                /*
                 * `?? null` on every optional key, and that is not belt and
                 * braces.
                 *
                 * `validate()` returns only the keys the request actually sent —
                 * a `nullable` rule does NOT put an absent field in the result.
                 * So converting an order with no e-mail (the field is optional
                 * in the dialog, and most leads have none) raised "Undefined
                 * array key" on every single conversion. The same shape of bug
                 * lost waypoint labels for a fortnight.
                 */
                'passenger_email' => $data['passenger_email'] ?? null,
                'price_total'     => $data['price_total'],
                'status'          => 'confirmed',
                'event'           => $data['event'] ?? $order->event_type,
                // Falls back to the order's own route rather than to null: the
                // customer drew it, and dropping it here is how a converted
                // booking loses the map the request came in with.
                'waypoints'       => $data['waypoints'] ?? $order->waypoints,
                'distance_km'     => $data['distance_km'] ?? $order->distance_km,

                /*
                 * The head count, carried across at last.
                 *
                 * Collected and validated at booking, then dropped here — the
                 * reservation had no column for it, so "how many people are
                 * travelling" was only answerable by joining back to the order.
                 */
                'passengers'      => $data['passengers'] ?? $order->passengers,

                // Set from the attached vehicles immediately below, once the
                // pivot exists. Never left at 0 — see the note there.
                'seats'           => 0,
            ]);

            // 2. Sync specific Buses
            $reservation->buses()->sync($data['bus_ids']);

            /*
             * 2b. Capacity, from the vehicles actually attached.
             *
             * This line used to read `'seats' => 0, // Pivot logic handles
             * actual capacity` — and that pivot logic never existed.
             * `reservation_buses` carries only the two foreign keys, and nothing
             * anywhere recomputed the figure, so EVERY converted reservation
             * showed "Places: 0" for good.
             *
             * Worse, `UpdateReservationRequest` demands `seats >= 1`, so the API
             * refused to accept the zero it had written itself: editing an
             * untouched converted booking failed validation on a field the agent
             * never touched.
             *
             * Must run after `sync()` — the relation is the source of truth, and
             * summing the request's ids instead would drift the moment a vehicle
             * is detached later.
             */
            $reservation->seats = (int) $reservation->buses()->sum('capacity');
            $reservation->save();

            // 3. Update Order Status
            $order->update([
                'status' => 'converted',
                'internal_notes' => ($order->internal_notes ? $order->internal_notes . "\n" : "") .
                                    "[System]: Converti en réservation #" . $reservation->code
            ]);

            // 4. Notify Client
            if ($order->client) {
                // Log for debugging
                Log::info("Envoi de notification au client ID : " . $order->client_id);

                $order->client->notify(new OrderStatusUpdated(
                    $order,
                    "Bonne nouvelle ! Votre réservation pour {$data['from_location']} -> {$data['to_location']} est confirmée."
                ));
            } else {
                Log::warning("Commande convertie sans client associé. ID commande : " . $order->id);
            }

            return response()->json([
                'status' => true,
                'message' => 'Demande convertie avec succès en réservation #' . $reservation->code,
                'data' => [
                    'reservation_id' => $reservation->id,
                    'reservation_code' => $reservation->code
                ]
            ]);
        });
    }
}
