<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Repost;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class RepostController extends Controller
{
    // ─────────────────────────────────────────
    // TOGGLE REPOST (share/unshare)
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/posts/{postId}/repost',
        operationId: 'toggleRepost',
        summary: 'Repost or undo repost',
        tags: ['Repost'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'postId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: false,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'quote', type: 'string', nullable: true, example: 'Check this out!'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Repost toggled')]
    public function toggle(Request $request, int $postId): JsonResponse
    {
        $post = Post::findOrFail($postId);
        $userId = $request->user()->id;

        $existing = Repost::where('user_id', $userId)->where('post_id', $postId)->first();

        if ($existing) {
            $existing->delete();
            return response()->json([
                'message'  => 'Repost removed.',
                'reposted' => false,
                'reposts_count' => Repost::where('post_id', $postId)->count(),
            ]);
        }

        $request->validate(['quote' => ['nullable', 'string', 'max:2000']]);

        Repost::create([
            'user_id' => $userId,
            'post_id' => $postId,
            'quote'   => $request->input('quote'),
        ]);

        // Notify post owner
        if ($post->user_id !== $userId) {
            NotificationService::send($post->user_id, 'repost', [
                'from_user_id'   => $userId,
                'from_user_name' => $request->user()->name,
                'post_id'        => $postId,
            ]);
        }

        return response()->json([
            'message'  => 'Post reposted.',
            'reposted' => true,
            'reposts_count' => Repost::where('post_id', $postId)->count(),
        ]);
    }

    // ─────────────────────────────────────────
    // LIST WHO REPOSTED
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/posts/{postId}/reposts',
        operationId: 'listReposts',
        summary: 'Get users who reposted',
        tags: ['Repost'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'postId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'List of reposts')]
    public function index(int $postId): JsonResponse
    {
        Post::findOrFail($postId);

        $reposts = Repost::where('post_id', $postId)
            ->with('user:id,name,username,profile_image')
            ->latest()
            ->paginate(20);

        return response()->json($reposts);
    }
}
