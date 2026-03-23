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
    // TOGGLE REACTION (add/update/remove)
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/posts/{postId}/reactions',
        operationId: 'toggleReaction',
        summary: 'Toggle reaction on a post',
        description: 'Adds a reaction. If same type exists, removes it. If different type exists, updates it.',
        tags: ['Reactions'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'postId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['type'],
            properties: [
                new OA\Property(property: 'type', type: 'string', enum: ['like', 'love', 'haha', 'wow'], example: 'like'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Reaction toggled')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function toggle(Request $request, int $postId): JsonResponse
    {
        Post::findOrFail($postId);

        $request->validate([
            'type' => ['required', 'in:like,love,haha,wow'],
        ]);

        $userId = $request->user()->id;
        $type   = $request->input('type');

        $existing = Reaction::where('user_id', $userId)->where('post_id', $postId)->first();

        if ($existing) {
            if ($existing->type === $type) {
                $existing->delete();
                return response()->json([
                    'message'  => 'Reaction removed.',
                    'reacted'  => false,
                    'total'    => Reaction::where('post_id', $postId)->count(),
                ]);
            }
            $existing->update(['type' => $type]);
            return response()->json([
                'message'  => 'Reaction updated.',
                'reacted'  => true,
                'type'     => $type,
                'total'    => Reaction::where('post_id', $postId)->count(),
            ]);
        }

        Reaction::create([
            'user_id' => $userId,
            'post_id' => $postId,
            'type'    => $type,
        ]);

        $post = Post::find($postId);
        NotificationService::send($post->user_id, 'like', [
            'from_user_id'   => $userId,
            'from_user_name' => $request->user()->name,
            'post_id'        => $postId,
            'reaction_type'  => $type,
        ]);

        return response()->json([
            'message'  => 'Reaction added.',
            'reacted'  => true,
            'type'     => $type,
            'total'    => Reaction::where('post_id', $postId)->count(),
        ]);
    }

    // ─────────────────────────────────────────
    // GET REACTIONS FOR A POST
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/posts/{postId}/reactions',
        operationId: 'getReactions',
        summary: 'Get reactions for a post',
        tags: ['Reactions'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'postId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Reactions list')]
    public function index(Request $request, int $postId): JsonResponse
    {
        Post::findOrFail($postId);

        $reactions = Reaction::where('post_id', $postId)
            ->with('user:id,name,username,profile_image')
            ->get();

        $userReaction = $request->user()
            ? Reaction::where('post_id', $postId)->where('user_id', $request->user()->id)->first()
            : null;

        return response()->json([
            'reactions'     => $reactions,
            'total'         => $reactions->count(),
            'user_reaction' => $userReaction?->type,
            'summary'       => $reactions->groupBy('type')->map->count(),
        ]);
    }
}
