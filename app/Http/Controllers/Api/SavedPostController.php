<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\SavedPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class SavedPostController extends Controller
{
    // ─────────────────────────────────────────
    // TOGGLE SAVE / UNSAVE POST
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/posts/{postId}/save',
        operationId: 'toggleSavePost',
        summary: 'Save or unsave a post',
        tags: ['Saved Posts'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'postId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Post saved/unsaved')]
    #[OA\Response(response: 404, description: 'Post not found')]
    public function toggle(Request $request, int $postId): JsonResponse
    {
        Post::findOrFail($postId);

        $existing = SavedPost::where('user_id', $request->user()->id)
            ->where('post_id', $postId)
            ->first();

        if ($existing) {
            $existing->delete();
            return response()->json(['message' => 'Post unsaved.', 'saved' => false]);
        }

        SavedPost::create([
            'user_id' => $request->user()->id,
            'post_id' => $postId,
        ]);

        return response()->json(['message' => 'Post saved.', 'saved' => true]);
    }

    // ─────────────────────────────────────────
    // CHECK SAVE STATUS
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/posts/{postId}/save-status',
        operationId: 'checkSaveStatus',
        summary: 'Check if a post is saved',
        tags: ['Saved Posts'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'postId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Save status')]
    public function status(Request $request, int $postId): JsonResponse
    {
        $saved = SavedPost::where('user_id', $request->user()->id)
            ->where('post_id', $postId)
            ->exists();

        return response()->json(['saved' => $saved]);
    }

    // ─────────────────────────────────────────
    // LIST SAVED POSTS
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/saved-posts',
        operationId: 'listSavedPosts',
        summary: 'Get all saved posts',
        tags: ['Saved Posts'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Response(response: 200, description: 'Paginated saved posts')]
    public function index(Request $request): JsonResponse
    {
        $posts = SavedPost::where('user_id', $request->user()->id)
            ->with(['post' => function ($q) {
                $q->with(['user:id,name,username,profile_image', 'media'])
                    ->withCount(['comments', 'reactions']);
            }])
            ->latest()
            ->paginate(15);

        return response()->json($posts);
    }
}
