<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Follow;
use App\Models\Interest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class ProfileController extends Controller
{
    // ─────────────────────────────────────────
    // GET PROFILE
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/user/profile',
        operationId: 'getProfile',
        summary: 'Get authenticated user profile',
        tags: ['Profile'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'User profile',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'user', type: 'object', properties: [
                new OA\Property(property: 'id',                  type: 'integer', example: 1),
                new OA\Property(property: 'name',                type: 'string',  example: 'John Doe'),
                new OA\Property(property: 'username',            type: 'string',  nullable: true, example: 'johndoe'),
                new OA\Property(property: 'email',               type: 'string',  example: 'john@example.com'),
                new OA\Property(property: 'gender',              type: 'string',  nullable: true, example: 'male'),
                new OA\Property(property: 'dob',                 type: 'string',  nullable: true, example: '1995-06-15'),
                new OA\Property(property: 'bio',                 type: 'string',  nullable: true, example: 'Tech lover | Startup builder'),
                new OA\Property(property: 'location',            type: 'string',  nullable: true, example: 'New York'),
                new OA\Property(property: 'profile_image',       type: 'string',  nullable: true, example: 'profile-images/photo.jpg'),
                new OA\Property(property: 'profession',          type: 'string',  nullable: true, example: 'Developer'),
                new OA\Property(property: 'country',             type: 'string',  nullable: true, example: 'India'),
                new OA\Property(property: 'city',                type: 'string',  nullable: true, example: 'Chennai'),
                new OA\Property(property: 'region',              type: 'string',  nullable: true, example: 'South India'),
                new OA\Property(property: 'content_preferences', type: 'array',   nullable: true, items: new OA\Items(type: 'string'), example: ['Short videos', 'Articles']),
                new OA\Property(property: 'social_personality',  type: 'string',  nullable: true, example: 'Creator'),
                new OA\Property(property: 'languages',           type: 'array',   nullable: true, items: new OA\Items(type: 'string'), example: ['English', 'Tamil']),
                new OA\Property(property: 'privacy_setting',     type: 'string',  example: 'public'),
                new OA\Property(property: 'interests',           type: 'array',   items: new OA\Items(properties: [
                    new OA\Property(property: 'id',   type: 'integer', example: 1),
                    new OA\Property(property: 'name', type: 'string',  example: 'Technology'),
                ], type: 'object')),
                new OA\Property(property: 'is_verified',         type: 'boolean', example: true),
                new OA\Property(property: 'status',              type: 'string',  example: 'active'),
            ]),
        ])
    )]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function show(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $user = Cache::remember("profile:{$userId}", 60, function () use ($request) {
            return $request->user()->load('interests:id,name,category');
        });

        return response()->json(['user' => $user]);
    }

    // ─────────────────────────────────────────
    // UPDATE PROFILE
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/user/profile',
        operationId: 'updateProfile',
        summary: 'Update authenticated user profile',
        description: 'All fields are optional. Send only the fields you want to update. Use multipart/form-data when uploading a profile image. For interests, send an array of interest IDs.',
        tags: ['Profile'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\RequestBody(
        required: false,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                properties: [
                    new OA\Property(property: 'name',                   type: 'string',  example: 'John Doe'),
                    new OA\Property(property: 'username',               type: 'string',  example: 'johndoe'),
                    new OA\Property(property: 'gender',                 type: 'string',  enum: ['male', 'female', 'other']),
                    new OA\Property(property: 'dob',                    type: 'string',  format: 'date', example: '1995-06-15'),
                    new OA\Property(property: 'bio',                    type: 'string',  example: 'Tech lover | Startup builder'),
                    new OA\Property(property: 'location',               type: 'string',  example: 'New York'),
                    new OA\Property(property: 'profile_image',          type: 'string',  format: 'binary'),
                    new OA\Property(property: 'profession',             type: 'string',  example: 'Developer', enum: ['Student', 'Entrepreneur', 'Developer', 'Designer', 'Business Owner', 'Creator', 'Musician', 'Other']),
                    new OA\Property(property: 'country',                type: 'string',  example: 'India'),
                    new OA\Property(property: 'city',                   type: 'string',  example: 'Chennai'),
                    new OA\Property(property: 'region',                 type: 'string',  example: 'South India'),
                    new OA\Property(property: 'content_preferences',    type: 'string',  example: '["Short videos","Articles"]', description: 'JSON array as string'),
                    new OA\Property(property: 'social_personality',     type: 'string',  enum: ['Creator', 'Viewer', 'Influencer', 'Business', 'Community Builder']),
                    new OA\Property(property: 'languages',              type: 'string',  example: '["English","Tamil"]', description: 'JSON array as string'),
                    new OA\Property(property: 'privacy_setting',        type: 'string',  enum: ['public', 'private', 'friends_only']),
                    new OA\Property(property: 'interest_ids',           type: 'string',  example: '1,3,5,7', description: 'Comma-separated interest IDs (max 10)'),
                ]
            )
        )
    )]
    #[OA\Response(response: 200, description: 'Profile updated',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'message', type: 'string', example: 'Profile updated successfully.'),
            new OA\Property(property: 'user',    type: 'object'),
        ])
    )]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'name'                => ['sometimes', 'string', 'max:255'],
            'username'            => ['sometimes', 'string', 'max:50', 'alpha_dash', 'unique:users,username,' . $request->user()->id],
            'gender'              => ['sometimes', 'in:male,female,other'],
            'dob'                 => ['sometimes', 'date', 'before:today'],
            'bio'                 => ['sometimes', 'nullable', 'string', 'max:200'],
            'location'            => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile_image'       => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'profession'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'country'             => ['sometimes', 'nullable', 'string', 'max:100'],
            'city'                => ['sometimes', 'nullable', 'string', 'max:100'],
            'region'              => ['sometimes', 'nullable', 'string', 'max:100'],
            'content_preferences' => ['sometimes', 'nullable', 'json'],
            'social_personality'  => ['sometimes', 'nullable', 'string', 'in:Creator,Viewer,Influencer,Business,Community Builder'],
            'languages'           => ['sometimes', 'nullable', 'json'],
            'privacy_setting'     => ['sometimes', 'in:public,private,friends_only'],
            'interest_ids'        => ['sometimes', 'nullable', 'string'],
        ]);

        $user = $request->user();
        $data = $request->only([
            'name', 'username', 'gender', 'dob', 'bio', 'location',
            'profession', 'country', 'city', 'region',
            'social_personality', 'privacy_setting',
        ]);

        // Handle JSON fields
        if ($request->has('content_preferences')) {
            $data['content_preferences'] = json_decode($request->input('content_preferences'), true);
        }
        if ($request->has('languages')) {
            $data['languages'] = json_decode($request->input('languages'), true);
        }

        // Handle profile image upload to S3
        if ($request->hasFile('profile_image')) {
            if ($user->profile_image) {
                Storage::disk('s3')->delete($user->profile_image);
            }
            $file     = $request->file('profile_image');
            $filename = 'profile-images/' . Str::random(40) . '.' . $file->getClientOriginalExtension();
            Storage::disk('s3')->put($filename, file_get_contents($file));
            $data['profile_image'] = $filename;
        }

        $user->update($data);

        // Handle interests (sync)
        if ($request->has('interest_ids')) {
            $ids = $request->input('interest_ids')
                ? array_map('intval', explode(',', $request->input('interest_ids')))
                : [];
            $ids = array_slice($ids, 0, 10); // max 10 interests
            $validIds = Interest::whereIn('id', $ids)->pluck('id')->toArray();
            $user->interests()->sync($validIds);
        }

        // Clear profile cache
        Cache::forget("profile:{$user->id}");

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user'    => $user->fresh()->load('interests:id,name,category'),
        ]);
    }

    // ─────────────────────────────────────────
    // GET ALL INTERESTS
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/interests',
        operationId: 'getInterests',
        summary: 'Get all available interests',
        description: 'Returns all interests grouped by category. Use the IDs when updating user profile.',
        tags: ['Profile']
    )]
    #[OA\Response(response: 200, description: 'List of interests',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'interests', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'id',       type: 'integer', example: 1),
                new OA\Property(property: 'name',     type: 'string',  example: 'Technology'),
                new OA\Property(property: 'category', type: 'string',  example: 'Tech'),
            ], type: 'object')),
        ])
    )]
    public function interests(): JsonResponse
    {
        $interests = Cache::remember('interests:all', 3600, function () {
            return Interest::select('id', 'name', 'category')->orderBy('category')->get();
        });

        return response()->json(['interests' => $interests]);
    }

    // ─────────────────────────────────────────
    // VIEW OTHER USER'S PROFILE
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/users/{userId}/profile',
        operationId: 'viewUserProfile',
        summary: 'View another user\'s public profile',
        tags: ['Profile'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'User profile')]
    #[OA\Response(response: 404, description: 'User not found')]
    public function viewProfile(Request $request, int $userId): JsonResponse
    {
        $user = User::select('id', 'name', 'username', 'bio', 'profile_image', 'profession', 'country', 'city')
            ->withCount(['posts', 'followers', 'following'])
            ->findOrFail($userId);

        $isFollowing = Follow::where('follower_id', $request->user()->id)
            ->where('following_id', $userId)
            ->exists();

        return response()->json([
            'user' => $user,
            'is_following' => $isFollowing,
        ]);
    }

    // ─────────────────────────────────────────
    // SHARE PROFILE LINK
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/users/{userId}/share-link',
        operationId: 'shareProfileLink',
        summary: 'Get shareable link for a user profile',
        tags: ['Profile'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Profile share link')]
    #[OA\Response(response: 404, description: 'User not found')]
    public function shareLink(int $userId): JsonResponse
    {
        $user = User::select('id', 'name', 'username', 'bio', 'profile_image')->findOrFail($userId);

        $shareUrl = config('app.url') . '/user/' . ($user->username ?? $user->id);
        $message = $user->bio ? Str::limit($user->bio, 100) : 'Check out ' . $user->name . ' on ENOM';

        return response()->json([
            'share_url' => $shareUrl,
            'message'   => $message . ' — shared via ENOM',
            'user_id'   => $user->id,
            'user_name' => $user->name,
        ]);
    }
}
