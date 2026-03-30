<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Reaction;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ReactionController extends Controller
{
    // ─────────────────────────────────────────
    // QUICK LIKE (double-tap, no body needed)
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/posts/{postId}/like',
        operationId: 'toggleLike',
        summary: 'Quick like/unlike (double-tap style)',
        description: 'Simple like toggle. No body needed. Tap once to like, tap again to unlike.',
        tags: ['Reactions'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'postId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Like toggled',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'liked', type: 'boolean', example: true),
            new OA\Property(property: 'reaction_type', type: 'string', nullable: true, example: 'like'),
            new OA\Property(property: 'likes_count', type: 'integer', example: 42),
        ])
    )]
    public function toggleLike(Request $request, int $postId): JsonResponse
    {
        $post = Post::findOrFail($postId);
        $userId = $request->user()->id;

        $existing = Reaction::where('user_id', $userId)->where('post_id', $postId)->first();

        if ($existing) {
            $existing->delete();
            return response()->json([
                'liked'         => false,
                'reaction_type' => null,
                'likes_count'   => Reaction::where('post_id', $postId)->count(),
            ]);
        }

        Reaction::create([
            'user_id' => $userId,
            'post_id' => $postId,
            'type'    => 'like',
        ]);

        if ($post->user_id !== $userId) {
            NotificationService::send($post->user_id, 'like', [
                'from_user_id'   => $userId,
                'from_user_name' => $request->user()->name,
                'post_id'        => $postId,
                'reaction_type'  => 'like',
            ]);
        }

        return response()->json([
            'liked'         => true,
            'reaction_type' => 'like',
            'likes_count'   => Reaction::where('post_id', $postId)->count(),
        ]);
    }

    // ─────────────────────────────────────────
    // REACT (long-press, Facebook style)
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/posts/{postId}/react',
        operationId: 'toggleReaction',
        summary: 'Add/change/remove reaction (Facebook style)',
        description: 'Long-press to show reactions. Send type to react. Send same type again to remove. Send different type to change.',
        tags: ['Reactions'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'postId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['type'],
            properties: [
                new OA\Property(property: 'type', type: 'string', enum: ['like', 'love', 'haha', 'wow', 'sad', 'angry'], example: 'love'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Reaction toggled',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'reacted', type: 'boolean', example: true),
            new OA\Property(property: 'reaction_type', type: 'string', nullable: true, example: 'love'),
            new OA\Property(property: 'likes_count', type: 'integer', example: 42),
            new OA\Property(property: 'reactions_summary', type: 'object', example: '{"like": 20, "love": 15, "haha": 5}'),
        ])
    )]
    public function toggleReaction(Request $request, int $postId): JsonResponse
    {
        $post = Post::findOrFail($postId);
        $userId = $request->user()->id;

        $request->validate([
            'type' => ['required', 'in:like,love,haha,wow,sad,angry'],
        ]);

        $type = $request->input('type');
        $existing = Reaction::where('user_id', $userId)->where('post_id', $postId)->first();

        // Same type → remove
        if ($existing && $existing->type === $type) {
            $existing->delete();
            return response()->json([
                'reacted'           => false,
                'reaction_type'     => null,
                'likes_count'       => Reaction::where('post_id', $postId)->count(),
                'reactions_summary' => $this->getSummary($postId),
            ]);
        }

        // Different type → update
        if ($existing) {
            $existing->update(['type' => $type]);
            return response()->json([
                'reacted'           => true,
                'reaction_type'     => $type,
                'likes_count'       => Reaction::where('post_id', $postId)->count(),
                'reactions_summary' => $this->getSummary($postId),
            ]);
        }

        // New reaction
        Reaction::create([
            'user_id' => $userId,
            'post_id' => $postId,
            'type'    => $type,
        ]);

        if ($post->user_id !== $userId) {
            NotificationService::send($post->user_id, 'like', [
                'from_user_id'   => $userId,
                'from_user_name' => $request->user()->name,
                'post_id'        => $postId,
                'reaction_type'  => $type,
            ]);
        }

        return response()->json([
            'reacted'           => true,
            'reaction_type'     => $type,
            'likes_count'       => Reaction::where('post_id', $postId)->count(),
            'reactions_summary' => $this->getSummary($postId),
        ]);
    }

    // ─────────────────────────────────────────
    // CHECK LIKE/REACTION STATUS
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/posts/{postId}/like-status',
        operationId: 'likeStatus',
        summary: 'Check if current user liked/reacted to a post',
        tags: ['Reactions'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'postId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Like/reaction status')]
    public function status(Request $request, int $postId): JsonResponse
    {
        Post::findOrFail($postId);

        $userReaction = Reaction::where('user_id', $request->user()->id)
            ->where('post_id', $postId)
            ->first();

        return response()->json([
            'liked'             => $userReaction !== null,
            'reaction_type'     => $userReaction?->type,
            'likes_count'       => Reaction::where('post_id', $postId)->count(),
            'reactions_summary' => $this->getSummary($postId),
        ]);
    }

    // ─────────────────────────────────────────
    // LIST WHO REACTED (with reaction type)
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/posts/{postId}/likes',
        operationId: 'listLikes',
        summary: 'Get users who reacted to a post',
        description: 'Returns all users who reacted with their reaction type. Filter by type using query param.',
        tags: ['Reactions'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'postId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['like', 'love', 'haha', 'wow', 'sad', 'angry']), description: 'Filter by reaction type')]
    #[OA\Response(response: 200, description: 'List of users who reacted')]
    public function index(Request $request, int $postId): JsonResponse
    {
        Post::findOrFail($postId);

        $query = Reaction::where('post_id', $postId)
            ->with('user:id,name,username,profile_image');

        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        $reactions = $query->latest()->paginate(20);

        return response()->json([
            'reactions'         => $reactions,
            'reactions_summary' => $this->getSummary($postId),
        ]);
    }

    // ─────────────────────────────────────────
    // HELPER: Get reaction summary
    // ─────────────────────────────────────────
    private function getSummary(int $postId): array
    {
        return Reaction::where('post_id', $postId)
            ->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();
    }
}
