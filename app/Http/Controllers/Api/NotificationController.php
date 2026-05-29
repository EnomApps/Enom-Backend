<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Reaction;
use App\Models\User;
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
        $userId = $request->user()->id;

        $notifications = Notification::where('user_id', $userId)
            ->latest()
            ->paginate(20);

        $unreadCount = Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->count();

        // Collect all referenced IDs to bulk-load (prevents N+1 queries)
        $fromUserIds = [];
        $postIds = [];
        $commentIds = [];

        foreach ($notifications as $n) {
            $data = is_array($n->data) ? $n->data : (array) $n->data;
            if (!empty($data['from_user_id'])) $fromUserIds[] = $data['from_user_id'];
            if (!empty($data['post_id']))     $postIds[]     = $data['post_id'];
            if (!empty($data['comment_id']))  $commentIds[]  = $data['comment_id'];
        }

        // Bulk-load users (profile picture)
        $users = User::whereIn('id', array_unique($fromUserIds))
            ->get(['id', 'name', 'username', 'profile_image'])
            ->keyBy('id');

        // Bulk-load posts (with media for thumbnail/image)
        $posts = Post::with(['media:id,post_id,type,url,thumbnail_url'])
            ->whereIn('id', array_unique($postIds))
            ->withCount(['reactions as likes_count', 'comments as comments_count'])
            ->get(['id', 'user_id', 'content'])
            ->keyBy('id');

        // Bulk-load comments (text + like count)
        $comments = Comment::whereIn('id', array_unique($commentIds))
            ->withCount('likes as likes_count')
            ->get(['id', 'content', 'post_id', 'parent_id'])
            ->keyBy('id');

        // Enrich each notification with related data
        $notifications->getCollection()->transform(function ($n) use ($users, $posts, $comments) {
            $data = is_array($n->data) ? $n->data : (array) $n->data;

            $enriched = [
                'from_user' => null,
                'post'      => null,
                'comment'   => null,
            ];

            // From user (profile picture)
            if (!empty($data['from_user_id']) && isset($users[$data['from_user_id']])) {
                $u = $users[$data['from_user_id']];
                $enriched['from_user'] = [
                    'id'                => $u->id,
                    'name'              => $u->name,
                    'username'          => $u->username,
                    'profile_image_url' => $u->profile_image_url,
                ];
            }

            // Post (with media + counts)
            if (!empty($data['post_id']) && isset($posts[$data['post_id']])) {
                $p = $posts[$data['post_id']];
                $firstMedia = $p->media->first();
                $enriched['post'] = [
                    'id'             => $p->id,
                    'content'        => $p->content ? \Illuminate\Support\Str::limit($p->content, 100) : null,
                    'likes_count'    => $p->likes_count,
                    'comments_count' => $p->comments_count,
                    'media_type'     => $firstMedia?->type,
                    'media_url'      => $firstMedia?->url,
                    'thumbnail_url'  => $firstMedia?->thumbnail_url,
                ];
            }

            // Comment (text + like count)
            if (!empty($data['comment_id']) && isset($comments[$data['comment_id']])) {
                $c = $comments[$data['comment_id']];
                $enriched['comment'] = [
                    'id'          => $c->id,
                    'text'        => $c->content,
                    'likes_count' => $c->likes_count,
                    'is_reply'    => !is_null($c->parent_id),
                ];
            }

            $n->related = $enriched;
            return $n;
        });

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
