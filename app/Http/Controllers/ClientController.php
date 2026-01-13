<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    // List all clients with basic stats
    public function index(Request $request)
    {
        $query = Client::withCount('orders')->latest();

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
        }

        // return response()->json($query->paginate(20));
        return ClientResource::collection($query->paginate(20));
    }

    // Show specific client history
    public function show($id)
    {
        $client = Client::with(['orders' => function($q) {
            $q->latest();
        }])->findOrFail($id);

        return response()->json($client);
    }
}
