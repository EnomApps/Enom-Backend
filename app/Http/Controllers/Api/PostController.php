<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class PostController extends Controller
{
    // ─────────────────────────────────────────
    // LIST POSTS (Feed)
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/posts',
        operationId: 'listPosts',
        summary: 'Get posts feed',
        description: 'Returns paginated public posts. Pass user_id to get a specific user\'s posts.',
        tags: ['Posts'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Response(response: 200, description: 'Paginated posts')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function index(Request $request): JsonResponse
    {
        $query = Post::with(['user:id,name,username,profile_image', 'media'])
            ->withCount(['comments', 'reactions']);

        if ($request->has('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        } else {
            $query->where('visibility', 'public');
        }

        $posts = $query->latest()->paginate(15);

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
    public function show(int $id): JsonResponse
    {
        $post = Post::with([
            'user:id,name,username,profile_image',
            'media',
            'comments' => function ($q) {
                $q->whereNull('parent_id')
                    ->with(['user:id,name,username,profile_image', 'replies.user:id,name,username,profile_image'])
                    ->latest()
                    ->limit(20);
            },
            'reactions',
        ])->withCount(['comments', 'reactions'])->findOrFail($id);

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
            'content'    => ['nullable', 'string', 'max:5000'],
            'visibility' => ['sometimes', 'in:public,private,followers'],
            'media'      => ['sometimes', 'array', 'max:10'],
            'media.*'    => ['file', 'mimes:jpg,jpeg,png,webp,mp4,mov', 'max:102400'],
        ]);

        if (!$request->input('content') && !$request->hasFile('media')) {
            return response()->json(['message' => 'Post must have content or media.'], 422);
        }

        $post = Post::create([
            'user_id'    => $request->user()->id,
            'content'    => $request->input('content'),
            'visibility' => $request->input('visibility', 'public'),
        ]);

        // Handle media uploads to S3
        if ($request->hasFile('media')) {
            foreach ($request->file('media') as $file) {
                $ext      = $file->getClientOriginalExtension();
                $type     = in_array($ext, ['mp4', 'mov']) ? 'video' : 'image';
                $path     = 'post-media/' . Str::random(40) . '.' . $ext;
                Storage::disk('s3')->put($path, file_get_contents($file));

                PostMedia::create([
                    'post_id' => $post->id,
                    'type'    => $type,
                    'url'     => $path,
                ]);
            }
        }

        return response()->json([
            'message' => 'Post created successfully.',
            'post'    => $post->load(['user:id,name,username,profile_image', 'media']),
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
            'content'    => ['nullable', 'string', 'max:5000'],
            'visibility' => ['sometimes', 'in:public,private,followers'],
        ]);

        $post->update($request->only(['content', 'visibility']));

        return response()->json([
            'message' => 'Post updated successfully.',
            'post'    => $post->load(['user:id,name,username,profile_image', 'media']),
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

        return response()->json(['message' => 'Post deleted successfully.']);
    }
}
