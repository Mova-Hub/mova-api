<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ClientNotificationController extends Controller
{
    public function index(Request $request)
    {
        // Return unread notifications first
        $notifications = $request->user()->notifications()->latest()->take(20)->get();

        return response()->json([
            'data' => $notifications->map(function($n) {
                return [
                    'id' => $n->id,
                    'read_at' => $n->read_at,
                    // The data stored in toArray() above
                    'type' => $n->data['type'] ?? 'info',
                    'title' => $n->data['title'] ?? 'Notification',
                    'message' => $n->data['message'] ?? '',
                    'trip' => $n->data['trip_name'] ?? '',
                    'statusColor' => $n->data['status_color'] ?? '#64748B',
                    'icon' => $n->data['icon'] ?? 'bell',
                    'time' => $n->created_at->diffForHumans(),
                    'order_id' => $n->data['order_id'] ?? null,
                ];
            }),
            'unread_count' => $request->user()->unreadNotifications()->count()
        ]);
    }

    public function markAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();
        return response()->json(['message' => 'All read']);
    }
}
