<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DeviceTokenController extends Controller
{
    // ─────────────────────────────────────────
    // REGISTER DEVICE TOKEN
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/device-tokens',
        operationId: 'registerDeviceToken',
        summary: 'Register a device token for push notifications',
        tags: ['Device Tokens'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['token'],
            properties: [
                new OA\Property(property: 'token', type: 'string', example: 'fcm_token_here'),
                new OA\Property(property: 'platform', type: 'string', enum: ['ios', 'android'], example: 'android'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Device token registered')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => ['required', 'string'],
            'platform' => ['nullable', 'in:ios,android'],
        ]);

        DeviceToken::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'token'   => $request->input('token'),
            ],
            [
                'platform' => $request->input('platform'),
            ]
        );

        return response()->json(['message' => 'Device token registered successfully.']);
    }

    // ─────────────────────────────────────────
    // REMOVE DEVICE TOKEN
    // ─────────────────────────────────────────
    #[OA\Delete(
        path: '/api/device-tokens',
        operationId: 'removeDeviceToken',
        summary: 'Remove a device token',
        tags: ['Device Tokens'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['token'],
            properties: [
                new OA\Property(property: 'token', type: 'string', example: 'fcm_token_here'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Device token removed')]
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
        ]);

        DeviceToken::where('user_id', $request->user()->id)
            ->where('token', $request->input('token'))
            ->delete();

        return response()->json(['message' => 'Device token removed successfully.']);
    }
}
