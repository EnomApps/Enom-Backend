<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\Comment;
use App\Models\Follow;
use App\Models\Post;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class BlockReportController extends Controller
{
    // ─────────────────────────────────────────
    // TOGGLE BLOCK USER
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/users/{userId}/block',
        operationId: 'toggleBlockUser',
        summary: 'Block or unblock a user',
        tags: ['Block & Report'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Block toggled')]
    public function toggleBlock(Request $request, int $userId): JsonResponse
    {
        $authUserId = $request->user()->id;

        if ($authUserId === $userId) {
            return response()->json(['message' => 'You cannot block yourself.'], 422);
        }

        $existing = Block::where('blocker_id', $authUserId)->where('blocked_id', $userId)->first();

        if ($existing) {
            $existing->delete();
            return response()->json(['message' => 'User unblocked.', 'blocked' => false]);
        }

        Block::create(['blocker_id' => $authUserId, 'blocked_id' => $userId]);

        // Remove follow relationships both ways
        Follow::where('follower_id', $authUserId)->where('following_id', $userId)->delete();
        Follow::where('follower_id', $userId)->where('following_id', $authUserId)->delete();

        return response()->json(['message' => 'User blocked.', 'blocked' => true]);
    }

    // ─────────────────────────────────────────
    // CHECK BLOCK STATUS
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/users/{userId}/block-status',
        operationId: 'blockStatus',
        summary: 'Check if user is blocked',
        tags: ['Block & Report'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Block status')]
    public function blockStatus(Request $request, int $userId): JsonResponse
    {
        $authUserId = $request->user()->id;

        return response()->json([
            'blocked_by_you' => Block::where('blocker_id', $authUserId)->where('blocked_id', $userId)->exists(),
            'blocked_you'    => Block::where('blocker_id', $userId)->where('blocked_id', $authUserId)->exists(),
        ]);
    }

    // ─────────────────────────────────────────
    // LIST BLOCKED USERS
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/blocked-users',
        operationId: 'listBlockedUsers',
        summary: 'Get list of blocked users',
        tags: ['Block & Report'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'List of blocked users')]
    public function blockedUsers(Request $request): JsonResponse
    {
        $blocks = Block::where('blocker_id', $request->user()->id)
            ->with('blocked:id,name,username,profile_image')
            ->latest()
            ->paginate(20);

        return response()->json($blocks);
    }

    // ─────────────────────────────────────────
    // REPORT CONTENT
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/report',
        operationId: 'reportContent',
        summary: 'Report a post, comment, or user',
        tags: ['Block & Report'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['type', 'id', 'reason'],
            properties: [
                new OA\Property(property: 'type', type: 'string', enum: ['post', 'comment', 'user'], example: 'post'),
                new OA\Property(property: 'id', type: 'integer', example: 1, description: 'ID of the post/comment/user'),
                new OA\Property(property: 'reason', type: 'string', enum: ['spam', 'harassment', 'nudity', 'violence', 'misinformation', 'other'], example: 'spam'),
                new OA\Property(property: 'description', type: 'string', nullable: true, example: 'This is spam content'),
            ]
        )
    )]
    #[OA\Response(response: 201, description: 'Report submitted')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function report(Request $request): JsonResponse
    {
        $request->validate([
            'type'        => ['required', 'in:post,comment,user'],
            'id'          => ['required', 'integer'],
            'reason'      => ['required', 'in:spam,harassment,nudity,violence,misinformation,other'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $typeMap = [
            'post'    => 'App\\Models\\Post',
            'comment' => 'App\\Models\\Comment',
            'user'    => 'App\\Models\\User',
        ];

        $reportableType = $typeMap[$request->input('type')];
        $reportableId = $request->input('id');

        // Check if already reported by this user
        $exists = Report::where('user_id', $request->user()->id)
            ->where('reportable_type', $reportableType)
            ->where('reportable_id', $reportableId)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'You have already reported this.'], 422);
        }

        Report::create([
            'user_id'         => $request->user()->id,
            'reportable_type' => $reportableType,
            'reportable_id'   => $reportableId,
            'reason'          => $request->input('reason'),
            'description'     => $request->input('description'),
        ]);

        // Auto-hide post/comment if 3+ unique reports
        $reportCount = Report::where('reportable_type', $reportableType)
            ->where('reportable_id', $reportableId)
            ->count();

        if ($reportCount >= 3) {
            if ($request->input('type') === 'post') {
                Post::where('id', $reportableId)->update([
                    'moderation_status' => 'pending_review',
                    'moderation_reason' => "Auto-flagged: {$reportCount} reports received",
                ]);
            } elseif ($request->input('type') === 'comment') {
                Comment::where('id', $reportableId)->delete();
            }
        }

        return response()->json(['message' => 'Report submitted. We will review it shortly.'], 201);
    }
}
