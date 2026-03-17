<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CommentController extends Controller
{
    // ─────────────────────────────────────────
    // LIST COMMENTS FOR A POST
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/posts/{postId}/comments',
        operationId: 'listComments',
        summary: 'Get comments for a post',
        tags: ['Comments'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'postId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Response(response: 200, description: 'Paginated comments')]
    public function index(int $postId): JsonResponse
    {
        Post::findOrFail($postId);

        $comments = Comment::where('post_id', $postId)
            ->whereNull('parent_id')
            ->with([
                'user:id,name,username,profile_image',
                'replies.user:id,name,username,profile_image',
            ])
            ->latest()
            ->paginate(20);

        return response()->json($comments);
    }

    // ─────────────────────────────────────────
    // ADD COMMENT
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/posts/{postId}/comments',
        operationId: 'addComment',
        summary: 'Add a comment to a post',
        tags: ['Comments'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'postId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['content'],
            properties: [
                new OA\Property(property: 'content', type: 'string', example: 'Great post!'),
                new OA\Property(property: 'parent_id', type: 'integer', nullable: true, example: null, description: 'Reply to a comment'),
            ]
        )
    )]
    #[OA\Response(response: 201, description: 'Comment added')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function store(Request $request, int $postId): JsonResponse
    {
        Post::findOrFail($postId);

        $request->validate([
            'content'   => ['required', 'string', 'max:2000'],
            'parent_id' => ['nullable', 'exists:comments,id'],
        ]);

        $comment = Comment::create([
            'user_id'   => $request->user()->id,
            'post_id'   => $postId,
            'parent_id' => $request->input('parent_id'),
            'content'   => $request->input('content'),
        ]);

        return response()->json([
            'message' => 'Comment added successfully.',
            'comment' => $comment->load('user:id,name,username,profile_image'),
        ], 201);
    }

    // ─────────────────────────────────────────
    // UPDATE COMMENT
    // ─────────────────────────────────────────
    #[OA\Put(
        path: '/api/comments/{id}',
        operationId: 'updateComment',
        summary: 'Update a comment',
        tags: ['Comments'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['content'],
            properties: [
                new OA\Property(property: 'content', type: 'string', example: 'Updated comment'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Comment updated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    public function update(Request $request, int $id): JsonResponse
    {
        $comment = Comment::findOrFail($id);

        if ($comment->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $comment->update(['content' => $request->input('content')]);

        return response()->json([
            'message' => 'Comment updated successfully.',
            'comment' => $comment->load('user:id,name,username,profile_image'),
        ]);
    }

    // ─────────────────────────────────────────
    // DELETE COMMENT
    // ─────────────────────────────────────────
    #[OA\Delete(
        path: '/api/comments/{id}',
        operationId: 'deleteComment',
        summary: 'Delete a comment',
        tags: ['Comments'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Comment deleted')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    public function destroy(Request $request, int $id): JsonResponse
    {
        $comment = Comment::findOrFail($id);

        if ($comment->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $comment->delete();

        return response()->json(['message' => 'Comment deleted successfully.']);
    }
}
