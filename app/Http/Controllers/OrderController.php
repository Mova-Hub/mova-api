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
            'internal_notes' => 'nullable|string'
        ]);

        return DB::transaction(function () use ($order, $data) {
            // 1. Create the Reservation
            $reservation = Reservation::create([
                'order_id'        => $order->id,
                'client_id'       => $order->client_id,
                'trip_date'       => $data['trip_date'],
                'from_location'   => $data['from_location'],
                'to_location'     => $data['to_location'],
                'passenger_name'  => $data['passenger_name'],
                'passenger_phone' => $data['passenger_phone'],
                'passenger_email' => $data['passenger_email'],
                'price_total'     => $data['price_total'],
                'status'          => 'confirmed',
                'event'           => $data['event'] ?? $order->event_type,
                'waypoints'       => $data['waypoints'],
                'distance_km'     => $data['distance_km'],
                'seats'           => 0, // Pivot logic handles actual capacity
            ]);

            // 2. Sync specific Buses
            $reservation->buses()->sync($data['bus_ids']);

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
