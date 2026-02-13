<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Notifications\OrderStatusUpdated;
use Illuminate\Http\Request;
use App\Models\Client;

class OrderRequestController extends Controller
{
    public function store(Request $request)
    {
        /** @var Client $client */
        $client = $request->user();

        $data = $request->validate([
            'event_type' => 'required|string',
            'from_city'  => 'required|string',
            'to_city'    => 'required|string',
            'date'       => 'required|date',
            'time'       => 'required|string',
            // Validate that fleet is an array (e.g., ["coaster" => 2])
            'fleet'      => 'required|array',
            'contact_name' => 'required|string',
            'phone'      => 'required|string',
        ]);

        $order = Order::create([
            'client_id'          => $client->id, // Auth User
            'event_type'         => $data['event_type'],
            'origin'             => $data['from_city'],
            'destination'        => $data['to_city'],
            'pickup_date'        => $data['date'],
            'pickup_time'        => $data['time'],
            'fleet_requirements' => $data['fleet'],
            'contact_name'       => $data['contact_name'],
            'contact_phone'      => $data['phone'],
            'status'             => 'pending'
        ]);

        // Todo: Send Email Notification to Admin here

        // 2. Send Notification to Client
        // We pass a custom message to confirm receipt immediately.
        $client->notify(new OrderStatusUpdated(
            $order,
            "Nous avons bien reçu votre demande pour {$data['to_city']}. Notre équipe va l'analyser et vous recontacter très vite."
        ));

        return response()->json([
            'status' => true,
            'message' => 'Order received',
            'id' => $order->id
        ], 201);
    }
}
