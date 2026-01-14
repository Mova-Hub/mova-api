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
        ->notCancelled()
            ->with(['reservation.buses'])
                ->latest()
                    ->get();

        return OrderHistoryResource::collection($orders);
    }
}
