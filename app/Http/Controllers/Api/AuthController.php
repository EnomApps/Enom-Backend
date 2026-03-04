<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\OtpMail;
use App\Models\OtpVerification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    // ─────────────────────────────────────────
    // REGISTER
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/auth/register',
        operationId: 'register',
        summary: 'Register a new user',
        description: 'Accepts name, email and password. Sends a 6-digit OTP to the email for verification. Token is NOT returned here — call /verify-otp after this.',
        tags: ['Auth']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/json',
            schema: new OA\Schema(
                required: ['name', 'email', 'password', 'password_confirmation'],
                properties: [
                    new OA\Property(property: 'name',                  type: 'string', example: 'John Doe'),
                    new OA\Property(property: 'email',                 type: 'string', format: 'email',    example: 'john@example.com'),
                    new OA\Property(property: 'password',              type: 'string', format: 'password', example: 'password123'),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'password123'),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'OTP sent to email',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'OTP sent to your email. Please verify to continue.'),
            ]
        )
    )]
    #[OA\Response(response: 409, description: 'Email already registered and verified')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $existing = User::where('email', $request->email)->first();

        if ($existing) {
            if ($existing->is_verified) {
                return response()->json(['message' => 'Email is already registered. Please log in.'], 409);
            }
            // Unverified account — delete and allow re-registration
            $existing->delete();
        }

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $this->sendOtp($user->email);

        return response()->json([
            'message' => 'OTP sent to your email. Please verify to continue.',
        ], 201);
    }

    // ─────────────────────────────────────────
    // VERIFY OTP
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/auth/verify-otp',
        operationId: 'verifyOtp',
        summary: 'Verify email with OTP',
        description: 'Submit the 6-digit OTP received by email. On success, returns the Sanctum auth token.',
        tags: ['Auth']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/json',
            schema: new OA\Schema(
                required: ['email', 'otp'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                    new OA\Property(property: 'otp',   type: 'string', example: '483921'),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Email verified — token returned',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Email verified successfully.'),
                new OA\Property(
                    property: 'user',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'id',          type: 'integer', example: 1),
                        new OA\Property(property: 'name',        type: 'string',  example: 'John Doe'),
                        new OA\Property(property: 'email',       type: 'string',  example: 'john@example.com'),
                        new OA\Property(property: 'is_verified', type: 'boolean', example: true),
                        new OA\Property(property: 'status',      type: 'string',  example: 'active'),
                    ]
                ),
                new OA\Property(property: 'token', type: 'string', example: '1|abc123xyz...'),
            ]
        )
    )]
    #[OA\Response(response: 404, description: 'No pending verification found for this email')]
    #[OA\Response(response: 422, description: 'Invalid or expired OTP')]
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'otp'   => ['required', 'digits:6'],
        ]);

        $record = OtpVerification::where('email', $request->email)->first();

        if (! $record) {
            return response()->json(['message' => 'No pending verification found for this email.'], 404);
        }

        if ($record->otp !== $request->otp) {
            return response()->json(['message' => 'Invalid OTP.'], 422);
        }

        if ($record->expires_at->isPast()) {
            return response()->json(['message' => 'OTP has expired. Please request a new one.'], 422);
        }

        $user = User::where('email', $request->email)->firstOrFail();
        $user->is_verified = true;
        $user->save();

        $record->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Email verified successfully.',
            'user'    => $user,
            'token'   => $token,
        ]);
    }

    // ─────────────────────────────────────────
    // RESEND OTP
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/auth/resend-otp',
        operationId: 'resendOtp',
        summary: 'Resend OTP',
        description: 'Resends a fresh OTP to the given email if the account is not yet verified.',
        tags: ['Auth']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/json',
            schema: new OA\Schema(
                required: ['email'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'OTP resent',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'A new OTP has been sent to your email.'),
            ]
        )
    )]
    #[OA\Response(response: 404, description: 'Email not found')]
    #[OA\Response(response: 409, description: 'Email already verified')]
    public function resendOtp(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return response()->json(['message' => 'No account found with this email.'], 404);
        }

        if ($user->is_verified) {
            return response()->json(['message' => 'Email is already verified.'], 409);
        }

        $this->sendOtp($user->email);

        return response()->json(['message' => 'A new OTP has been sent to your email.']);
    }

    // ─────────────────────────────────────────
    // HELPER
    // ─────────────────────────────────────────
    private function sendOtp(string $email): void
    {
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        OtpVerification::updateOrCreate(
            ['email' => $email],
            ['otp' => $otp, 'expires_at' => now()->addMinutes(10)]
        );

        Mail::to($email)->send(new OtpMail($otp));
    }
}
