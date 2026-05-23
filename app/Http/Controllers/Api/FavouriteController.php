<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\Favourite;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class FavouriteController extends Controller
{
    private const MAX_FAVOURITES = 50;

    // ─────────────────────────────────────────
    // TOGGLE FAVOURITE
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/users/{userId}/favourite',
        operationId: 'toggleFavourite',
        summary: 'Add or remove user from favourites',
        description: 'Private list - other users do not know they are your favourite. Max 50 favourites.',
        tags: ['Favourites'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Favourite toggled')]
    #[OA\Response(response: 422, description: 'Max favourites reached or self-favourite')]
    public function toggle(Request $request, int $userId): JsonResponse
    {
        $authUserId = $request->user()->id;

        if ($authUserId === $userId) {
            return response()->json(['message' => 'You cannot favourite yourself.'], 422);
        }

        // Ensure target user exists
        if (!User::where('id', $userId)->exists()) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $existing = Favourite::where('user_id', $authUserId)
            ->where('favourited_user_id', $userId)
            ->first();

        if ($existing) {
            $existing->delete();
            return response()->json([
                'message'    => 'Removed from favourites.',
                'favourited' => false,
            ]);
        }

        // Check max limit (50)
        $count = Favourite::where('user_id', $authUserId)->count();
        if ($count >= self::MAX_FAVOURITES) {
            return response()->json([
                'message' => 'Maximum ' . self::MAX_FAVOURITES . ' favourites reached. Remove one to add more.',
            ], 422);
        }

        Favourite::create([
            'user_id'            => $authUserId,
            'favourited_user_id' => $userId,
        ]);

        return response()->json([
            'message'    => 'Added to favourites.',
            'favourited' => true,
        ]);
    }

    // ─────────────────────────────────────────
    // CHECK FAVOURITE STATUS
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/users/{userId}/favourite-status',
        operationId: 'favouriteStatus',
        summary: 'Check if user is in your favourites',
        tags: ['Favourites'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Favourite status')]
    public function status(Request $request, int $userId): JsonResponse
    {
        $favourited = Favourite::where('user_id', $request->user()->id)
            ->where('favourited_user_id', $userId)
            ->exists();

        return response()->json([
            'favourited' => $favourited,
            'count'      => Favourite::where('user_id', $request->user()->id)->count(),
            'limit'      => self::MAX_FAVOURITES,
        ]);
    }

    // ─────────────────────────────────────────
    // LIST FAVOURITES
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/favourites',
        operationId: 'listFavourites',
        summary: 'List your favourite users',
        tags: ['Favourites'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'List of favourite users')]
    public function index(Request $request): JsonResponse
    {
        $favourites = Favourite::where('user_id', $request->user()->id)
            ->with('favouritedUser:id,name,username,profile_image,bio')
            ->latest()
            ->get();

        $users = $favourites->map(fn($fav) => $fav->favouritedUser);

        return response()->json([
            'data'  => $users,
            'count' => $users->count(),
            'limit' => self::MAX_FAVOURITES,
        ]);
    }

    // ─────────────────────────────────────────
    // FAVOURITES FEED
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/posts/favourites',
        operationId: 'favouritesFeed',
        summary: 'Get chronological feed from favourite users only',
        description: 'Returns posts from only your favourite users, ordered by newest first.',
        tags: ['Favourites'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15))]
    #[OA\Response(response: 200, description: 'Posts from favourite users')]
    public function feed(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 15), 1), 50);
        $authUserId = $request->user()->id;

        // Get favourite user IDs
        $favouriteIds = Favourite::where('user_id', $authUserId)
            ->pluck('favourited_user_id')
            ->toArray();

        if (empty($favouriteIds)) {
            return response()->json([
                'data'        => [],
                'next_cursor' => null,
                'has_more'    => false,
                'message'     => 'No favourites yet. Add some users to your favourites to see their posts here.',
            ]);
        }

        // Get blocked user IDs (filter out)
        $blockedIds = Block::where('blocker_id', $authUserId)->pluck('blocked_id')
            ->merge(Block::where('blocked_id', $authUserId)->pluck('blocker_id'))
            ->unique()->toArray();

        $posts = Post::with([
                'user:id,name,username,profile_image',
                'media:id,post_id,type,url,thumbnail_url,width,height',
                'hashtags:id,name',
                'reactions' => function ($q) use ($authUserId) {
                    $q->where('user_id', $authUserId)->select('id', 'post_id', 'type');
                },
            ])
            ->withCount(['comments', 'reactions', 'views', 'reposts'])
            ->whereIn('user_id', $favouriteIds)
            ->whereNotIn('user_id', $blockedIds)
            ->where('moderation_status', 'approved')
            ->where(function ($q) use ($authUserId, $favouriteIds) {
                // Public posts + posts from favourites who allow followers to see
                $q->where('visibility', 'public')
                  ->orWhere(function ($q2) use ($favouriteIds) {
                      $q2->where('visibility', 'followers')
                         ->whereIn('user_id', $favouriteIds);
                  });
            })
            ->orderByDesc('id')
            ->cursorPaginate($perPage);

        // Append user_reaction
        $posts->getCollection()->transform(function ($post) {
            $post->user_reaction = $post->reactions->first()?->type;
            unset($post->reactions);
            return $post;
        });

        return response()->json($posts);
    }
}
