<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    #[OA\Post(
        path: '/api/auth/register',
        operationId: 'register',
        summary: 'Register a new user',
        description: 'Creates a new user account and returns a Sanctum auth token.',
        tags: ['Auth']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['name', 'email', 'password', 'password_confirmation', 'gender', 'dob'],
                properties: [
                    new OA\Property(property: 'name',                 type: 'string',  example: 'John Doe'),
                    new OA\Property(property: 'email',                type: 'string',  format: 'email',    example: 'john@example.com'),
                    new OA\Property(property: 'password',             type: 'string',  format: 'password', example: 'password123'),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'password123'),
                    new OA\Property(property: 'gender',               type: 'string',  enum: ['male', 'female', 'other'], example: 'male'),
                    new OA\Property(property: 'dob',                  type: 'string',  format: 'date',     example: '1995-06-15'),
                    new OA\Property(property: 'bio',                  type: 'string',  nullable: true,     example: "Hello, I'm John"),
                    new OA\Property(property: 'location',             type: 'string',  nullable: true,     example: 'New York'),
                    new OA\Property(property: 'profile_image',        type: 'string',  format: 'binary',   nullable: true),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Registration successful',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Registration successful.'),
                new OA\Property(
                    property: 'user',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'id',            type: 'integer', example: 1),
                        new OA\Property(property: 'name',          type: 'string',  example: 'John Doe'),
                        new OA\Property(property: 'email',         type: 'string',  example: 'john@example.com'),
                        new OA\Property(property: 'gender',        type: 'string',  example: 'male'),
                        new OA\Property(property: 'dob',           type: 'string',  example: '1995-06-15'),
                        new OA\Property(property: 'bio',           type: 'string',  nullable: true),
                        new OA\Property(property: 'location',      type: 'string',  nullable: true),
                        new OA\Property(property: 'profile_image', type: 'string',  nullable: true),
                        new OA\Property(property: 'is_verified',   type: 'boolean', example: false),
                        new OA\Property(property: 'status',        type: 'string',  example: 'active'),
                    ]
                ),
                new OA\Property(property: 'token', type: 'string', example: '1|abc123xyz...'),
            ]
        )
    )]
    #[OA\Response(
        response: 422,
        description: 'Validation error',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'The email has already been taken.'),
                new OA\Property(
                    property: 'errors',
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'email',
                            type: 'array',
                            items: new OA\Items(type: 'string', example: 'The email has already been taken.')
                        ),
                    ]
                ),
            ]
        )
    )]
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'email'         => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password'      => ['required', 'confirmed', Password::min(8)],
            'gender'        => ['required', 'in:male,female,other'],
            'dob'           => ['required', 'date', 'before:today'],
            'bio'           => ['nullable', 'string', 'max:500'],
            'location'      => ['nullable', 'string', 'max:255'],
            'profile_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        if ($request->hasFile('profile_image')) {
            $validated['profile_image'] = $request->file('profile_image')
                ->store('profile-images', 'public');
        }

        $validated['password'] = Hash::make($validated['password']);

        $user = User::create($validated);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful.',
            'user'    => $user,
            'token'   => $token,
        ], 201);
    }
}
