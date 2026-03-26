<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\Hashtag;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class SearchController extends Controller
{
    // ─────────────────────────────────────────
    // SEARCH ALL (users, posts, hashtags)
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/search',
        operationId: 'searchAll',
        summary: 'Search users, posts, and hashtags',
        tags: ['Search'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'q', in: 'query', required: true, schema: new OA\Schema(type: 'string', minLength: 1), description: 'Search query')]
    #[OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['users', 'posts', 'hashtags']), description: 'Filter by type')]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15))]
    #[OA\Response(response: 200, description: 'Search results')]
    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:1']]);

        $query = $request->input('q');
        $type = $request->input('type');
        $perPage = min((int) $request->input('per_page', 15), 50);
        $authUserId = $request->user()->id;

        // Get blocked user IDs
        $blockedIds = Block::where('blocker_id', $authUserId)->pluck('blocked_id')
            ->merge(Block::where('blocked_id', $authUserId)->pluck('blocker_id'))
            ->unique()->toArray();

        $results = [];

        // Search Users
        if (!$type || $type === 'users') {
            $results['users'] = User::where(function ($q) use ($query) {
                    $q->where('name', 'like', "%{$query}%")
                      ->orWhere('username', 'like', "%{$query}%");
                })
                ->whereNotIn('id', $blockedIds)
                ->select('id', 'name', 'username', 'profile_image', 'bio')
                ->limit($perPage)
                ->get();
        }

        // Search Posts
        if (!$type || $type === 'posts') {
            $results['posts'] = Post::where('content', 'like', "%{$query}%")
                ->where('visibility', 'public')
                ->whereNotIn('user_id', $blockedIds)
                ->with(['user:id,name,username,profile_image', 'media:id,post_id,type,url'])
                ->withCount(['comments', 'reactions', 'views'])
                ->latest()
                ->limit($perPage)
                ->get();
        }

        // Search Hashtags
        if (!$type || $type === 'hashtags') {
            $results['hashtags'] = Hashtag::where('name', 'like', "%{$query}%")
                ->orderByDesc('posts_count')
                ->limit($perPage)
                ->get();
        }

        return response()->json($results);
    }

    // ─────────────────────────────────────────
    // GET POSTS BY HASHTAG
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/hashtags/{name}/posts',
        operationId: 'hashtagPosts',
        summary: 'Get posts for a hashtag',
        tags: ['Search'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'name', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15))]
    #[OA\Response(response: 200, description: 'Posts with this hashtag')]
    public function hashtagPosts(Request $request, string $name): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 15), 50);
        $authUserId = $request->user()->id;

        $blockedIds = Block::where('blocker_id', $authUserId)->pluck('blocked_id')
            ->merge(Block::where('blocked_id', $authUserId)->pluck('blocker_id'))
            ->unique()->toArray();

        $hashtag = Hashtag::where('name', strtolower($name))->firstOrFail();

        $posts = $hashtag->posts()
            ->where('visibility', 'public')
            ->whereNotIn('user_id', $blockedIds)
            ->with(['user:id,name,username,profile_image', 'media:id,post_id,type,url'])
            ->withCount(['comments', 'reactions', 'views'])
            ->orderByDesc('id')
            ->cursorPaginate($perPage);

        return response()->json([
            'hashtag' => $hashtag,
            'posts'   => $posts,
        ]);
    }

    // ─────────────────────────────────────────
    // TRENDING HASHTAGS
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/trending/hashtags',
        operationId: 'trendingHashtags',
        summary: 'Get trending hashtags',
        tags: ['Search'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Response(response: 200, description: 'Trending hashtags')]
    public function trendingHashtags(Request $request): JsonResponse
    {
        $limit = min((int) $request->input('limit', 20), 50);

        $hashtags = Hashtag::where('posts_count', '>', 0)
            ->orderByDesc('posts_count')
            ->limit($limit)
            ->get();

        return response()->json(['hashtags' => $hashtags]);
    }
}
