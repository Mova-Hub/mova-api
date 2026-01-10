<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class FcmController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'device_name' => 'nullable|string'
        ]);

        // updateOrCreate ensures we don't duplicate tokens, just update timestamp
        $request->user()->fcmTokens()->updateOrCreate(
            ['fcm_token' => $request->token],
            [
                'device_name' => $request->device_name,
                'last_used_at' => now()
            ]
        );

        return response()->json(['message' => 'Token saved']);
    }
}
