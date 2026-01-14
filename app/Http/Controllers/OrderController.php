<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Reservation; // Assuming you have this or will build it
use App\Notifications\OrderStatusUpdated;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Client;

class OrderController extends Controller
{
    /**
     * List orders.
     * Useful for your "Lead Pipeline" board.
     */
    public function index(Request $request)
    {
        $status = $request->input('status', 'pending'); // pending, contacted, converted

        $orders = Order::with('client')
            ->where('status', $status)
            ->latest()
            ->paginate(15);

        return response()->json($orders);
    }

    /**
     * Show order details to perform actions (Call, Message).
     */
    public function show($id)
    {
        $order = Order::with('client')->findOrFail($id);

        // Helper links for frontend actions
        $actions = [
            'call_link' => "tel:{$order->contact_phone}",
            'whatsapp_link' => "https://wa.me/" . str_replace('+', '', $order->contact_phone),
        ];

        return response()->json([
            'order' => $order,
            'actions' => $actions
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

        return response()->json(['message' => 'Order updated', 'order' => $order]);
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
                $order->client->notify(new OrderStatusUpdated(
                    $order,
                    "Bonne nouvelle ! Votre réservation pour le trajet {$data['from_location']} → {$data['to_location']} est confirmée (Code: {$reservation->code})."
                ));
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
