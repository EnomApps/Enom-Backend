<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
                new OA\Property(property: 'id',            type: 'integer', example: 1),
                new OA\Property(property: 'name',          type: 'string',  example: 'John Doe'),
                new OA\Property(property: 'email',         type: 'string',  example: 'john@example.com'),
                new OA\Property(property: 'gender',        type: 'string',  nullable: true, example: 'male'),
                new OA\Property(property: 'dob',           type: 'string',  nullable: true, example: '1995-06-15'),
                new OA\Property(property: 'bio',           type: 'string',  nullable: true, example: 'Hello!'),
                new OA\Property(property: 'location',      type: 'string',  nullable: true, example: 'New York'),
                new OA\Property(property: 'profile_image', type: 'string',  nullable: true, example: 'profile-images/photo.jpg'),
                new OA\Property(property: 'is_verified',   type: 'boolean', example: true),
                new OA\Property(property: 'status',        type: 'string',  example: 'active'),
            ]),
        ])
    )]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function show(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()]);
    }

    // ─────────────────────────────────────────
    // UPDATE PROFILE
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/user/profile',
        operationId: 'updateProfile',
        summary: 'Update authenticated user profile',
        description: 'All fields are optional. Send only the fields you want to update. Use multipart/form-data when uploading a profile image.',
        tags: ['Profile'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\RequestBody(
        required: false,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                properties: [
                    new OA\Property(property: 'name',          type: 'string',  example: 'John Doe'),
                    new OA\Property(property: 'gender',        type: 'string',  enum: ['male', 'female', 'other']),
                    new OA\Property(property: 'dob',           type: 'string',  format: 'date', example: '1995-06-15'),
                    new OA\Property(property: 'bio',           type: 'string',  example: 'Hello, I am John.'),
                    new OA\Property(property: 'location',      type: 'string',  example: 'New York'),
                    new OA\Property(property: 'profile_image', type: 'string',  format: 'binary'),
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
            'name'          => ['sometimes', 'string', 'max:255'],
            'gender'        => ['sometimes', 'in:male,female,other'],
            'dob'           => ['sometimes', 'date', 'before:today'],
            'bio'           => ['sometimes', 'nullable', 'string', 'max:500'],
            'location'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile_image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();
        $data = $request->only(['name', 'gender', 'dob', 'bio', 'location']);

        if ($request->hasFile('profile_image')) {
            if ($user->profile_image) {
                Storage::disk('public')->delete($user->profile_image);
            }
            $data['profile_image'] = $request->file('profile_image')
                ->store('profile-images', 'public');
        }

        $user->update($data);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user'    => $user->fresh(),
        ]);
    }
}
