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
                    /*
                     * `message`, falling back to `body`.
                     *
                     * This read `data['message']` alone, and not one
                     * notification in the app emits that key: TripReminder and
                     * NewTripMessage both emit `body`, which is also what the
                     * push payload uses. So every trip reminder and every new
                     * message has been rendering as a blank line in the in-app
                     * inbox, with the title above it looking fine.
                     *
                     * Fixed here rather than by rewriting the notifications,
                     * because rows ALREADY in `notifications` carry `body` and
                     * changing only the senders would leave the existing inbox
                     * just as empty.
                     */
                    'message' => $n->data['message'] ?? $n->data['body'] ?? '',
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
