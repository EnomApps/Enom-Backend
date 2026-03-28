<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\CommentLike;
use App\Models\Post;
use App\Services\NotificationService;
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
            ->withCount('likes')
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

        $post = Post::find($postId);
        $userId = $request->user()->id;
        $userName = $request->user()->name;

        if ($request->input('parent_id')) {
            // Reply notification to parent comment owner
            $parentComment = Comment::find($request->input('parent_id'));
            NotificationService::send($parentComment->user_id, 'reply', [
                'from_user_id'   => $userId,
                'from_user_name' => $userName,
                'post_id'        => $postId,
                'comment_id'     => $comment->id,
            ]);
        }

        // Comment notification to post owner
        NotificationService::send($post->user_id, 'comment', [
            'from_user_id'   => $userId,
            'from_user_name' => $userName,
            'post_id'        => $postId,
            'comment_id'     => $comment->id,
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

    // ─────────────────────────────────────────
    // TOGGLE LIKE ON COMMENT
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/comments/{id}/like',
        operationId: 'toggleCommentLike',
        summary: 'Like or unlike a comment',
        tags: ['Comments'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Like toggled')]
    #[OA\Response(response: 404, description: 'Comment not found')]
    public function toggleLike(Request $request, int $id): JsonResponse
    {
        $comment = Comment::findOrFail($id);
        $userId = $request->user()->id;

        $existing = CommentLike::where('user_id', $userId)->where('comment_id', $id)->first();

        if ($existing) {
            $existing->delete();
            return response()->json([
                'message'     => 'Comment unliked.',
                'liked'       => false,
                'likes_count' => CommentLike::where('comment_id', $id)->count(),
            ]);
        }

        CommentLike::create(['user_id' => $userId, 'comment_id' => $id]);

        // Notify comment owner
        if ($comment->user_id !== $userId) {
            NotificationService::send($comment->user_id, 'comment_like', [
                'from_user_id'   => $userId,
                'from_user_name' => $request->user()->name,
                'post_id'        => $comment->post_id,
                'comment_id'     => $id,
            ]);
        }

        return response()->json([
            'message'     => 'Comment liked.',
            'liked'       => true,
            'likes_count' => CommentLike::where('comment_id', $id)->count(),
        ]);
    }

    // ─────────────────────────────────────────
    // LIST WHO LIKED A COMMENT
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/comments/{id}/likes',
        operationId: 'listCommentLikes',
        summary: 'Get users who liked a comment',
        tags: ['Comments'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'List of likes')]
    public function likes(int $id): JsonResponse
    {
        Comment::findOrFail($id);

        $likes = CommentLike::where('comment_id', $id)
            ->with('user:id,name,username,profile_image')
            ->latest()
            ->paginate(20);

        return response()->json($likes);
    }
}
