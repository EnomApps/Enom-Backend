<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\Follow;
use App\Models\Hashtag;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostView;
use App\Models\Reaction;
use App\Services\ContentModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class PostController extends Controller
{
    // ─────────────────────────────────────────
    // LIST POSTS (Feed) — Cursor Pagination
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/posts',
        operationId: 'listPosts',
        summary: 'Get posts feed (cursor-based pagination)',
        description: 'Returns posts with cursor-based pagination. Use next_cursor from response to load more. Pass user_id for a specific user\'s posts.',
        tags: ['Posts'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Cursor for next page')]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15))]
    #[OA\Response(response: 200, description: 'Paginated posts with cursor')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 15), 50);
        $authUserId = $request->user()->id;
        $cursor = $request->input('cursor');
        $userId = $request->input('user_id');

        // Get blocked user IDs
        $blockedIds = Block::where('blocker_id', $authUserId)->pluck('blocked_id')
            ->merge(Block::where('blocked_id', $authUserId)->pluck('blocker_id'))
            ->unique()->toArray();

        $query = Post::with([
                'user:id,name,username,profile_image',
                'media:id,post_id,type,url,width,height',
                'hashtags:id,name',
            ])
            ->withCount(['comments', 'reactions', 'views', 'reposts'])
            ->where('moderation_status', 'approved')
            ->whereNotIn('user_id', $blockedIds);

        if ($userId) {
            $query->where('user_id', $userId);
        } else {
            $query->where('visibility', 'public');
        }

        $posts = $query->orderByDesc('id')->cursorPaginate($perPage);

        return response()->json($posts)
            ->header('Cache-Control', 'public, max-age=30');
    }

    // ─────────────────────────────────────────
    // FOR YOU FEED (Algorithm-based)
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/posts/for-you',
        operationId: 'forYouFeed',
        summary: 'Get personalized "For You" feed',
        description: 'Returns posts ranked by engagement (views, likes, comments) mixed with posts from followed users and matching interests.',
        tags: ['Posts'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15))]
    #[OA\Response(response: 200, description: 'Personalized feed')]
    public function forYou(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 15), 50);
        $authUserId = $request->user()->id;

        // Get blocked user IDs
        $blockedIds = Block::where('blocker_id', $authUserId)->pluck('blocked_id')
            ->merge(Block::where('blocked_id', $authUserId)->pluck('blocker_id'))
            ->unique()->toArray();

        // Get followed user IDs
        $followingIds = Follow::where('follower_id', $authUserId)->pluck('following_id')->toArray();

        // Get IDs of posts the user already viewed
        $viewedPostIds = PostView::where('user_id', $authUserId)->pluck('post_id')->toArray();

        // Score-based ranking:
        // - Posts from followed users get a boost
        // - Posts with more engagement rank higher
        // - Unseen posts rank higher
        $posts = Post::with([
                'user:id,name,username,profile_image',
                'media:id,post_id,type,url,width,height',
                'hashtags:id,name',
            ])
            ->withCount(['comments', 'reactions', 'views', 'reposts'])
            ->where('visibility', 'public')
            ->where('moderation_status', 'approved')
            ->where('user_id', '!=', $authUserId)
            ->whereNotIn('user_id', $blockedIds)
            ->orderByRaw('
                (CASE WHEN user_id IN (' . (count($followingIds) ? implode(',', $followingIds) : '0') . ') THEN 50 ELSE 0 END)
                + (CASE WHEN id NOT IN (' . (count($viewedPostIds) ? implode(',', $viewedPostIds) : '0') . ') THEN 30 ELSE 0 END)
                + LEAST(reactions_count * 3, 30)
                + LEAST(comments_count * 2, 20)
                + LEAST(views_count, 20)
                DESC
            ')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);

        return response()->json($posts);
    }

    // ─────────────────────────────────────────
    // SHOW SINGLE POST
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/posts/{id}',
        operationId: 'showPost',
        summary: 'Get a single post',
        tags: ['Posts'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Post details')]
    #[OA\Response(response: 404, description: 'Post not found')]
    public function show(Request $request, int $id): JsonResponse
    {
        $authUserId = $request->user()->id;

        $post = Post::with([
            'user:id,name,username,profile_image',
            'media:id,post_id,type,url,width,height',
            'hashtags:id,name',
            'comments' => function ($q) {
                $q->whereNull('parent_id')
                    ->with(['user:id,name,username,profile_image', 'replies.user:id,name,username,profile_image'])
                    ->latest()
                    ->limit(20);
            },
        ])
        ->withCount(['comments', 'reactions', 'views', 'reposts'])
        ->withExists([
            'reactions as user_reacted' => function ($q) use ($authUserId) {
                $q->where('user_id', $authUserId);
            },
        ])
        ->findOrFail($id);

        return response()->json(['post' => $post]);
    }

    // ─────────────────────────────────────────
    // CREATE POST
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/posts',
        operationId: 'createPost',
        summary: 'Create a new post',
        tags: ['Posts'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                properties: [
                    new OA\Property(property: 'content', type: 'string', example: 'Hello world!'),
                    new OA\Property(property: 'visibility', type: 'string', enum: ['public', 'private', 'followers'], example: 'public'),
                    new OA\Property(property: 'location_name', type: 'string', nullable: true, example: 'Chennai, India'),
                    new OA\Property(property: 'latitude', type: 'number', format: 'float', nullable: true, example: 13.0827),
                    new OA\Property(property: 'longitude', type: 'number', format: 'float', nullable: true, example: 80.2707),
                    new OA\Property(property: 'media[]', type: 'array', items: new OA\Items(type: 'string', format: 'binary'), description: 'Upload images/videos (max 10)'),
                ]
            )
        )
    )]
    #[OA\Response(response: 201, description: 'Post created')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'content'       => ['nullable', 'string', 'max:5000'],
            'visibility'    => ['sometimes', 'in:public,private,followers'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'latitude'      => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'     => ['nullable', 'numeric', 'between:-180,180'],
            'media'         => ['sometimes', 'array', 'max:10'],
            'media.*'       => ['file', 'mimes:jpg,jpeg,png,webp,mp4,mov', 'max:102400'],
        ]);

        if (!$request->input('content') && !$request->hasFile('media')) {
            return response()->json(['message' => 'Post must have content or media.'], 422);
        }

        // Layer 1: Check text content for inappropriate language
        $textCheck = ContentModerationService::checkText($request->input('content'));
        if (!$textCheck['safe']) {
            return response()->json([
                'message' => 'Your post was rejected.',
                'reason'  => $textCheck['reason'],
            ], 422);
        }

        // Layer 2: Check images via AWS Rekognition (if enabled)
        $moderationStatus = 'approved';
        $moderationReason = null;

        if ($request->hasFile('media')) {
            $mediaCheck = ContentModerationService::checkMedia($request->file('media'));
            if (!$mediaCheck['safe']) {
                $moderationStatus = 'rejected';
                $moderationReason = $mediaCheck['reason'];

                return response()->json([
                    'message' => 'Your post contains inappropriate content and has been rejected.',
                    'reason'  => $mediaCheck['reason'],
                ], 422);
            }
        }

        $post = Post::create([
            'user_id'           => $request->user()->id,
            'content'           => $request->input('content'),
            'visibility'        => $request->input('visibility', 'public'),
            'location_name'     => $request->input('location_name'),
            'latitude'          => $request->input('latitude'),
            'longitude'         => $request->input('longitude'),
            'moderation_status' => $moderationStatus,
            'moderation_reason' => $moderationReason,
        ]);

        // Handle media uploads to S3
        if ($request->hasFile('media')) {
            foreach ($request->file('media') as $file) {
                $ext  = strtolower($file->getClientOriginalExtension());
                $type = in_array($ext, ['mp4', 'mov']) ? 'video' : 'image';
                $name = Str::random(40);
                $path = 'post-media/' . $name . '.' . $ext;
                $size = $file->getSize();

                // Stream file directly to S3 (memory efficient)
                Storage::disk('s3')->putFileAs('post-media', $file, $name . '.' . $ext);

                $mediaData = [
                    'post_id' => $post->id,
                    'type'    => $type,
                    'url'     => $path,
                    'size'    => $size,
                ];

                // Get image dimensions
                if ($type === 'image') {
                    $dimensions = @getimagesize($file->getPathname());
                    if ($dimensions) {
                        $mediaData['width']  = $dimensions[0];
                        $mediaData['height'] = $dimensions[1];
                    }
                }

                PostMedia::create($mediaData);
            }
        }

        // Extract and sync hashtags from content
        $this->syncHashtags($post);

        // Clear feed cache
        Cache::forget('feed:public:first:15');

        return response()->json([
            'message' => 'Post created successfully.',
            'post'    => $post->load(['user:id,name,username,profile_image', 'media', 'hashtags:id,name']),
        ], 201);
    }

    // ─────────────────────────────────────────
    // UPDATE POST
    // ─────────────────────────────────────────
    #[OA\Put(
        path: '/api/posts/{id}',
        operationId: 'updatePost',
        summary: 'Update a post',
        tags: ['Posts'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'content', type: 'string', example: 'Updated content'),
                new OA\Property(property: 'visibility', type: 'string', enum: ['public', 'private', 'followers']),
                new OA\Property(property: 'location_name', type: 'string', nullable: true, example: 'Mumbai, India'),
                new OA\Property(property: 'latitude', type: 'number', format: 'float', nullable: true),
                new OA\Property(property: 'longitude', type: 'number', format: 'float', nullable: true),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Post updated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Post not found')]
    public function update(Request $request, int $id): JsonResponse
    {
        $post = Post::findOrFail($id);

        if ($post->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'content'       => ['nullable', 'string', 'max:5000'],
            'visibility'    => ['sometimes', 'in:public,private,followers'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'latitude'      => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'     => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $post->update($request->only(['content', 'visibility', 'location_name', 'latitude', 'longitude']));

        // Re-sync hashtags if content changed
        if ($request->has('content')) {
            $this->syncHashtags($post);
        }

        return response()->json([
            'message' => 'Post updated successfully.',
            'post'    => $post->load(['user:id,name,username,profile_image', 'media', 'hashtags:id,name']),
        ]);
    }

    // ─────────────────────────────────────────
    // DELETE POST
    // ─────────────────────────────────────────
    #[OA\Delete(
        path: '/api/posts/{id}',
        operationId: 'deletePost',
        summary: 'Delete a post',
        tags: ['Posts'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Post deleted')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Post not found')]
    public function destroy(Request $request, int $id): JsonResponse
    {
        $post = Post::findOrFail($id);

        if ($post->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // Delete media files from S3
        foreach ($post->media as $media) {
            Storage::disk('s3')->delete($media->getRawOriginal('url'));
        }

        $post->delete();

        // Clear feed cache
        Cache::forget('feed:public:first:15');

        return response()->json(['message' => 'Post deleted successfully.']);
    }

    // ─────────────────────────────────────────
    // EXTRACT & SYNC HASHTAGS
    // ─────────────────────────────────────────
    private function syncHashtags(Post $post): void
    {
        if (!$post->content) {
            $post->hashtags()->detach();
            return;
        }

        preg_match_all('/#(\w+)/u', $post->content, $matches);
        $tags = array_slice(array_unique(array_map('strtolower', $matches[1] ?? [])), 0, 5);

        if (empty($tags)) {
            $post->hashtags()->detach();
            return;
        }

        $hashtagIds = [];
        foreach ($tags as $tag) {
            $hashtag = Hashtag::firstOrCreate(['name' => $tag]);
            $hashtagIds[] = $hashtag->id;
        }

        $post->hashtags()->sync($hashtagIds);

        // Update posts_count for all hashtags
        Hashtag::whereIn('id', $hashtagIds)->each(function ($hashtag) {
            $hashtag->update(['posts_count' => $hashtag->posts()->count()]);
        });
    }
}
