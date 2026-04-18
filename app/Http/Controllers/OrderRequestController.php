<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User; // <-- Import the User model
use App\Notifications\OrderStatusUpdated;
use App\Notifications\NewOrderAdminNotification;
use Illuminate\Http\Request;
use App\Models\Client;
use Illuminate\Support\Facades\Notification;

class OrderRequestController extends Controller
{
    public function store(Request $request)
    {
        /** @var Client $client */
        $client = $request->user();

        $data = $request->validate([
            'event_type'   => 'required|string',
            'from_city'    => 'required|string',
            'to_city'      => 'required|string',
            'waypoints'    => 'nullable|array',
            'distance_km'  => 'nullable|numeric',
            'date'         => 'required|date',
            'time'         => 'required|string',
            'fleet'        => 'required|array',
            'contact_name' => 'required|string',
            'phone'        => 'required|string',
        ]);

        $order = Order::create([
            'client_id'          => $client->id,
            'event_type'         => $data['event_type'],
            'origin'             => $data['from_city'],
            'destination'        => $data['to_city'],
            'waypoints'          => $data['waypoints'] ?? null,
            'distance_km'        => $data['distance_km'] ?? null,
            'pickup_date'        => $data['date'],
            'pickup_time'        => $data['time'],
            'fleet_requirements' => $data['fleet'],
            'contact_name'       => $data['contact_name'],
            'contact_phone'      => $data['phone'],
            'status'             => 'pending'
        ]);


        // 1. SECURE ADMIN & AGENT NOTIFICATION

        // Fetch all staff members who are admins or agents AND are currently active
        $staffMembers = User::whereIn('role', ['admin', 'agent'])
            ->where('status', 'active')
            ->get();

        if ($staffMembers->isNotEmpty()) {
            // Notification::send() automatically loops through the collection
            // sending both the email and saving a database record for EACH user securely.
            Notification::send($staffMembers, new NewOrderAdminNotification($order));
        } else {
            // Fallback Security: If for some reason there are 0 active admins in the DB,
            // we still send the lead to the master email so it doesn't get lost.
            Notification::route('mail', 'reservation@mova-mobility.com')
                ->notify(new NewOrderAdminNotification($order));
        }


        // 2. CLIENT NOTIFICATION

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
