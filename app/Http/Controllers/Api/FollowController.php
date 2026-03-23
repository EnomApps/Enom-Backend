<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Follow;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class FollowController extends Controller
{
    // ─────────────────────────────────────────
    // FOLLOW / UNFOLLOW TOGGLE
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/users/{userId}/follow',
        operationId: 'toggleFollow',
        summary: 'Follow or unfollow a user',
        tags: ['Follow'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Follow toggled')]
    #[OA\Response(response: 400, description: 'Cannot follow yourself')]
    #[OA\Response(response: 404, description: 'User not found')]
    public function toggle(Request $request, int $userId): JsonResponse
    {
        $authUser = $request->user();

        if ($authUser->id === $userId) {
            return response()->json(['message' => 'You cannot follow yourself.'], 400);
        }

        User::findOrFail($userId);

        $existing = Follow::where('follower_id', $authUser->id)
            ->where('following_id', $userId)
            ->first();

        if ($existing) {
            $existing->delete();
            return response()->json([
                'message'     => 'Unfollowed successfully.',
                'is_following' => false,
            ]);
        }

        Follow::create([
            'follower_id'  => $authUser->id,
            'following_id' => $userId,
        ]);

        NotificationService::send($userId, 'follow', [
            'from_user_id'   => $authUser->id,
            'from_user_name' => $authUser->name,
        ]);

        return response()->json([
            'message'     => 'Followed successfully.',
            'is_following' => true,
        ]);
    }

    // ─────────────────────────────────────────
    // CHECK FOLLOW STATUS
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/users/{userId}/follow-status',
        operationId: 'checkFollowStatus',
        summary: 'Check if you follow a user',
        tags: ['Follow'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Follow status')]
    public function status(Request $request, int $userId): JsonResponse
    {
        $isFollowing = Follow::where('follower_id', $request->user()->id)
            ->where('following_id', $userId)
            ->exists();

        return response()->json(['is_following' => $isFollowing]);
    }

    // ─────────────────────────────────────────
    // GET FOLLOWERS
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/users/{userId}/followers',
        operationId: 'getFollowers',
        summary: 'Get followers of a user',
        tags: ['Follow'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Response(response: 200, description: 'Paginated followers')]
    public function followers(int $userId): JsonResponse
    {
        User::findOrFail($userId);

        $followers = Follow::where('following_id', $userId)
            ->with('follower:id,name,username,profile_image')
            ->latest()
            ->paginate(20);

        return response()->json($followers);
    }

    // ─────────────────────────────────────────
    // GET FOLLOWING
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/users/{userId}/following',
        operationId: 'getFollowing',
        summary: 'Get users that a user is following',
        tags: ['Follow'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Response(response: 200, description: 'Paginated following')]
    public function following(int $userId): JsonResponse
    {
        User::findOrFail($userId);

        $following = Follow::where('follower_id', $userId)
            ->with('following:id,name,username,profile_image')
            ->latest()
            ->paginate(20);

        return response()->json($following);
    }

    // ─────────────────────────────────────────
    // GET FOLLOW COUNTS
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/users/{userId}/follow-counts',
        operationId: 'getFollowCounts',
        summary: 'Get follower and following counts',
        tags: ['Follow'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Follow counts')]
    public function counts(int $userId): JsonResponse
    {
        User::findOrFail($userId);

        return response()->json([
            'followers_count' => Follow::where('following_id', $userId)->count(),
            'following_count' => Follow::where('follower_id', $userId)->count(),
        ]);
    }
}
