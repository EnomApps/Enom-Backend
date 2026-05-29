<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotInterested;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class NotInterestedController extends Controller
{
    // ─────────────────────────────────────────
    // MARK POST AS NOT INTERESTED
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/posts/{postId}/not-interested',
        operationId: 'markNotInterested',
        summary: 'Mark a post as not interested',
        description: 'Hides this post from the user\'s feed and reduces similar content (same user, hashtags) in future recommendations.',
        tags: ['Feed Algorithm'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'postId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Post marked as not interested')]
    #[OA\Response(response: 404, description: 'Post not found')]
    public function store(Request $request, int $postId): JsonResponse
    {
        Post::findOrFail($postId);

        NotInterested::firstOrCreate([
            'user_id' => $request->user()->id,
            'post_id' => $postId,
        ]);

        return response()->json([
            'message' => "Thanks. We'll show you fewer posts like this.",
        ]);
    }

    // ─────────────────────────────────────────
    // UNDO - REMOVE FROM NOT INTERESTED LIST
    // ─────────────────────────────────────────
    #[OA\Delete(
        path: '/api/posts/{postId}/not-interested',
        operationId: 'undoNotInterested',
        summary: 'Undo "not interested" on a post',
        tags: ['Feed Algorithm'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'postId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Removed from not interested')]
    public function destroy(Request $request, int $postId): JsonResponse
    {
        NotInterested::where('user_id', $request->user()->id)
            ->where('post_id', $postId)
            ->delete();

        return response()->json(['message' => 'Removed from not interested list.']);
    }
}
