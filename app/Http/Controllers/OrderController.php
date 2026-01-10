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
     * Action: Convert Order to Reservation.
     * This is the "Magic" button on your dashboard.
     */
    public function convertToReservation(Request $request, $id)
    {
        $order = Order::findOrFail($id);

        if ($order->status === 'converted' || $order->reservation()->exists()) {
            return response()->json(['message' => 'Order already converted.'], 400);
        }

        // 1. Validate Admin Inputs
        // The client requested "2 Coasters", but the Admin must assign SPECIFIC buses now.
        $data = $request->validate([
            'price_total' => 'required|numeric|min:0',
            'bus_ids'     => 'required|array|min:1',
            'bus_ids.*'   => 'exists:buses,id', // Ensure real buses
            'notes'       => 'nullable|string'
        ]);

        return DB::transaction(function () use ($order, $data) {

            // 2. Prepare Date/Time
            // Merge date and time string into a Carbon instance
            $tripDateTime = Carbon::parse($order->pickup_date->format('Y-m-d') . ' ' . $order->pickup_time);

            // 3. Create the Reservation
            $reservation = Reservation::create([
                'order_id'        => $order->id, // The Link!
                'trip_date'       => $tripDateTime,

                // Map Location
                'from_location'   => $order->origin,
                'to_location'     => $order->destination,

                // Map Passenger
                'passenger_name'  => $order->contact_name,
                'passenger_phone' => $order->contact_phone,
                'passenger_email' => $order->client->email ?? null, // Fallback to client profile email

                // Map Details
                'event'           => $order->event_type,
                'seats'           => 0, // You might calculate this based on assigned buses or order fleet
                'price_total'     => $data['price_total'],
                'status'          => 'confirmed', // Immediately confirmed
            ]);

            // 4. Assign the specific Buses selected by Admin
            if (!empty($data['bus_ids'])) {
                $reservation->buses()->sync($data['bus_ids']);
            }

            // 5. Update Order Status
            $order->update([
                'status' => 'converted',
                'internal_notes' => $order->internal_notes . "\n[System]: Converted to Reservation #{$reservation->code}"
            ]);

            // 6. Trigger Notification
            $order->client->notify(new OrderStatusUpdated(
                $order,
                "Votre devis est prêt : " . number_format($data['price_total']) . " FCFA. Cliquez pour voir les détails."
            ));

            return response()->json([
                'status' => true,
                'message' => 'Order converted successfully',
                'data' => [
                    'reservation_id' => $reservation->id,
                    'reservation_code' => $reservation->code
                ]
            ]);
        });
    }
}
