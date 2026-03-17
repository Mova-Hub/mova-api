<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    /**
     * Fetch all notifications for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        // Get the authenticated user (admin or agent)
        $user = $request->user();

        // Paginate the notifications (Laravel automatically sorts them by latest)
        $notifications = $user->notifications()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'unread_count' => $user->unreadNotifications()->count(),
            ]
        ]);
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(Request $request, $id): JsonResponse
    {
        $notification = $request->user()->notifications()->find($id);

        if ($notification) {
            $notification->markAsRead();
            return response()->json(['success' => true, 'message' => 'Notification marquée comme lue.']);
        }

        return response()->json(['success' => false, 'message' => 'Notification introuvable.'], 404);
    }

    /**
     * Mark all notifications as read for the authenticated user.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['success' => true, 'message' => 'Toutes les notifications sont marquées comme lues.']);
    }

    /**
     * Delete a specific notification (Optional utility).
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $notification = $request->user()->notifications()->find($id);

        if ($notification) {
            $notification->delete();
            return response()->json(['success' => true, 'message' => 'Notification supprimée.']);
        }

        return response()->json(['success' => false, 'message' => 'Notification introuvable.'], 404);
    }
}
