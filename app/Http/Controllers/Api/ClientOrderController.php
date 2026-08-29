<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Resources\OrderHistoryResource; // We will make this
use App\Models\Order;

class ClientOrderController extends Controller
{
    public function history(Request $request)
    {
        $client = $request->user();

        // Fetch orders for this client
        // Eager load the 'reservation' relationship to get price/status details
        // Also load 'reservation.buses' if you want vehicle info
        $orders = Order::where('client_id', $client->id)
        ->with(['reservation.buses.driver'])
            ->latest()
                ->get();

        return OrderHistoryResource::collection($orders);
    }

    public function show(Request $request, $id)
    {
        $client = $request->user();

        // Ensure the client can only view their own orders
        $order = Order::where('client_id', $client->id)
            ->with(['reservation.buses.driver'])
            ->findOrFail($id);

        return new OrderHistoryResource($order);
    }

    /**
     * Where is my bus?
     *
     * GET /app/v1/orders/{id}/tracking
     *
     * Positions arrive over Reverb while the socket is up, but a websocket
     * cannot paint the first frame and cannot survive a tunnel. This is the
     * initial state, the reconnect state, and the fallback a client on a bad
     * connection polls — the same payload either way, so the map component
     * never learns which one it got.
     *
     * **A position is served only while the trip is `in_progress`.** That is a
     * privacy rule, not a UI one: the stream is a named employee's live
     * location, and where the coordinator went after dropping everyone off is
     * their own business. Before and after, this returns the trip's state and a
     * null position rather than 404 — the screen still wants to say "en attente
     * du départ".
     */
    public function tracking(Request $request, $id)
    {
        $order = Order::where('client_id', $request->user()->id)
            ->with(['reservation.coordinator'])
            ->findOrFail($id);

        $reservation = $order->reservation;
        $isLive = $reservation?->status === 'in_progress';

        $position = $isLive
            ? $reservation->positions()->first()   // relation is already latest-first
            : null;

        return response()->json([
            'status' => true,
            'data' => [
                'trip_status' => $reservation?->status ?? $order->status,
                'is_live'     => $isLive,

                'position' => $position ? [
                    'lat'         => (float) $position->lat,
                    'lng'         => (float) $position->lng,
                    'heading'     => $position->heading !== null ? (float) $position->heading : null,
                    // The device's clock, so "mis à jour il y a Ns" tells the
                    // truth about a fix that sat in an offline queue.
                    'recorded_at' => $position->recorded_at?->toIso8601String(),
                ] : null,

                /*
                 * Name and phone only — the person to call if the coach is late.
                 * Shown to a customer, so nothing else about the employee goes
                 * out: no e-mail, no id, no role.
                 */
                'coordinator' => $reservation?->coordinator ? [
                    'name'  => $reservation->coordinator->name,
                    'phone' => $reservation->coordinator->phone,
                ] : null,

                'started_at'   => $reservation?->started_at?->toIso8601String(),
                'completed_at' => $reservation?->completed_at?->toIso8601String(),
            ],
        ]);
    }
}
