<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class NotificationController extends Controller
{
    // ─────────────────────────────────────────
    // LIST NOTIFICATIONS
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/notifications',
        operationId: 'listNotifications',
        summary: 'Get user notifications',
        tags: ['Notifications'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Response(response: 200, description: 'Paginated notifications')]
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        $unreadCount = Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
        ]);
    }

    // ─────────────────────────────────────────
    // MARK AS READ
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/notifications/{id}/read',
        operationId: 'markNotificationRead',
        summary: 'Mark a notification as read',
        tags: ['Notifications'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Notification marked as read')]
    #[OA\Response(response: 404, description: 'Notification not found')]
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $notification = Notification::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $notification->update(['is_read' => true]);

        return response()->json(['message' => 'Notification marked as read.']);
    }

    // ─────────────────────────────────────────
    // MARK ALL AS READ
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/notifications/read-all',
        operationId: 'markAllNotificationsRead',
        summary: 'Mark all notifications as read',
        tags: ['Notifications'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'All notifications marked as read')]
    public function markAllAsRead(Request $request): JsonResponse
    {
        Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    // ─────────────────────────────────────────
    // DELETE NOTIFICATION
    // ─────────────────────────────────────────
    #[OA\Delete(
        path: '/api/notifications/{id}',
        operationId: 'deleteNotification',
        summary: 'Delete a notification',
        tags: ['Notifications'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Notification deleted')]
    public function destroy(Request $request, int $id): JsonResponse
    {
        $notification = Notification::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $notification->delete();

        return response()->json(['message' => 'Notification deleted successfully.']);
    }
}
