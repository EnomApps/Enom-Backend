<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PostViewController extends Controller
{
    // ─────────────────────────────────────────
    // RECORD VIEW
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/posts/{postId}/view',
        operationId: 'recordPostView',
        summary: 'Record a view on a post',
        tags: ['Post Views'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'postId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'View recorded')]
    #[OA\Response(response: 404, description: 'Post not found')]
    public function store(Request $request, int $postId): JsonResponse
    {
        Post::findOrFail($postId);

        PostView::firstOrCreate([
            'user_id' => $request->user()->id,
            'post_id' => $postId,
        ]);

        $viewsCount = PostView::where('post_id', $postId)->count();

        return response()->json([
            'message'     => 'View recorded.',
            'views_count' => $viewsCount,
        ]);
    }

    // ─────────────────────────────────────────
    // GET VIEW COUNT
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/posts/{postId}/views',
        operationId: 'getPostViewCount',
        summary: 'Get view count for a post',
        tags: ['Post Views'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'postId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'View count')]
    public function count(Request $request, int $postId): JsonResponse
    {
        Post::findOrFail($postId);

        $viewsCount = PostView::where('post_id', $postId)->count();
        $viewed = PostView::where('user_id', $request->user()->id)
            ->where('post_id', $postId)
            ->exists();

        return response()->json([
            'views_count' => $viewsCount,
            'viewed'      => $viewed,
        ]);
    }
}
