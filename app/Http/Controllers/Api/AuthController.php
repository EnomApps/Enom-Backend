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
                required: ['name', 'email', 'password'],
                properties: [
                    new OA\Property(property: 'name',     type: 'string', example: 'John Doe'),
                    new OA\Property(property: 'email',    type: 'string', format: 'email',    example: 'john@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password123'),
                ]
            )
        )
    )]
    #[OA\Response(response: 201, description: 'OTP sent to email',
        content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'OTP sent to your email. Please verify to continue.')])
    )]
    #[OA\Response(response: 409, description: 'Email already registered and verified')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', Password::min(8)],
        ]);

        $existing = User::where('email', $request->email)->first();

        if ($existing) {
            if ($existing->is_verified) {
                return response()->json(['message' => 'Email is already registered. Please log in.'], 409);
            }
            $existing->delete();
        }

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $this->sendOtp($user->email, 'email_verification');

        return response()->json(['message' => 'OTP sent to your email. Please verify to continue.'], 201);
    }

    // ─────────────────────────────────────────
    // VERIFY OTP
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/auth/verify-otp',
        operationId: 'verifyOtp',
        summary: 'Verify email with OTP',
        description: 'Submit the 6-digit OTP received after registration. On success, returns the Sanctum auth token.',
        tags: ['Auth']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/json',
            schema: new OA\Schema(
                required: ['otp'],
                properties: [new OA\Property(property: 'otp', type: 'string', example: '483921')]
            )
        )
    )]
    #[OA\Response(response: 200, description: 'Email verified — token returned',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'message', type: 'string', example: 'Email verified successfully.'),
            new OA\Property(property: 'user', type: 'object', properties: [
                new OA\Property(property: 'id',          type: 'integer', example: 1),
                new OA\Property(property: 'name',        type: 'string',  example: 'John Doe'),
                new OA\Property(property: 'email',       type: 'string',  example: 'john@example.com'),
                new OA\Property(property: 'is_verified', type: 'boolean', example: true),
                new OA\Property(property: 'status',      type: 'string',  example: 'active'),
            ]),
            new OA\Property(property: 'token', type: 'string', example: '1|abc123xyz...'),
        ])
    )]
    #[OA\Response(response: 422, description: 'Invalid or expired OTP')]
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate(['otp' => ['required', 'digits:6']]);

        $record = OtpVerification::where('otp', $request->otp)
            ->where('type', 'email_verification')
            ->first();

        if (! $record) {
            return response()->json(['message' => 'Invalid OTP.'], 422);
        }

        if ($record->expires_at->isPast()) {
            return response()->json(['message' => 'OTP has expired. Please request a new one.'], 422);
        }

        $user = User::where('email', $record->email)->firstOrFail();
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
        summary: 'Resend email verification OTP',
        description: 'Resends a fresh OTP to the given email if the account is not yet verified.',
        tags: ['Auth']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/json',
            schema: new OA\Schema(
                required: ['email'],
                properties: [new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com')]
            )
        )
    )]
    #[OA\Response(response: 200, description: 'OTP resent',
        content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'A new OTP has been sent to your email.')])
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

        $this->sendOtp($user->email, 'email_verification');

        return response()->json(['message' => 'A new OTP has been sent to your email.']);
    }

    // ─────────────────────────────────────────
    // LOGIN
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/auth/login',
        operationId: 'login',
        summary: 'Login',
        description: 'Authenticate with email and password. Returns a Sanctum token on success.',
        tags: ['Auth']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/json',
            schema: new OA\Schema(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email',    type: 'string', format: 'email',    example: 'john@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password123'),
                ]
            )
        )
    )]
    #[OA\Response(response: 200, description: 'Login successful',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'message', type: 'string', example: 'Login successful.'),
            new OA\Property(property: 'user', type: 'object', properties: [
                new OA\Property(property: 'id',             type: 'integer', example: 1),
                new OA\Property(property: 'name',           type: 'string',  example: 'John Doe'),
                new OA\Property(property: 'email',          type: 'string',  example: 'john@example.com'),
                new OA\Property(property: 'is_verified',    type: 'boolean', example: true),
                new OA\Property(property: 'status',         type: 'string',  example: 'active'),
                new OA\Property(property: 'last_login_at',  type: 'string',  example: '2026-03-04T10:00:00Z'),
            ]),
            new OA\Property(property: 'token', type: 'string', example: '1|abc123xyz...'),
        ])
    )]
    #[OA\Response(response: 401, description: 'Invalid password')]
    #[OA\Response(response: 403, description: 'Email not verified or account blocked')]
    #[OA\Response(response: 404, description: 'Account not found')]
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return response()->json(['message' => 'No account found with this email.'], 404);
        }

        if (! $user->is_verified) {
            return response()->json(['message' => 'Please verify your email before logging in.'], 403);
        }

        if ($user->status === 'blocked') {
            return response()->json(['message' => 'Your account has been blocked. Please contact support.'], 403);
        }

        if (! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid password.'], 401);
        }

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'user'    => $user,
            'token'   => $token,
        ]);
    }

    // ─────────────────────────────────────────
    // FORGOT PASSWORD
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/auth/forgot-password',
        operationId: 'forgotPassword',
        summary: 'Forgot password — send OTP',
        description: 'Sends a password reset OTP to the email if a verified account exists. Always returns 200 for security.',
        tags: ['Auth']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/json',
            schema: new OA\Schema(
                required: ['email'],
                properties: [new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com')]
            )
        )
    )]
    #[OA\Response(response: 200, description: 'OTP sent (if account exists)',
        content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'If an account with that email exists, an OTP has been sent.')])
    )]
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $request->email)->where('is_verified', true)->first();

        if ($user) {
            $this->sendOtp($user->email, 'password_reset');
        }

        return response()->json(['message' => 'If an account with that email exists, an OTP has been sent.']);
    }

    // ─────────────────────────────────────────
    // RESET PASSWORD
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/auth/reset-password',
        operationId: 'resetPassword',
        summary: 'Reset password with OTP',
        description: 'Submit the OTP received via forgot-password along with a new password. Returns a fresh token after reset.',
        tags: ['Auth']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/json',
            schema: new OA\Schema(
                required: ['otp', 'password'],
                properties: [
                    new OA\Property(property: 'otp',      type: 'string', example: '192837'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'newpassword123'),
                ]
            )
        )
    )]
    #[OA\Response(response: 200, description: 'Password reset successful',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'message', type: 'string', example: 'Password reset successfully.'),
            new OA\Property(property: 'token',   type: 'string', example: '2|xyz789abc...'),
        ])
    )]
    #[OA\Response(response: 422, description: 'Invalid or expired OTP')]
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'otp'      => ['required', 'digits:6'],
            'password' => ['required', Password::min(8)],
        ]);

        $record = OtpVerification::where('otp', $request->otp)
            ->where('type', 'password_reset')
            ->first();

        if (! $record) {
            return response()->json(['message' => 'Invalid OTP.'], 422);
        }

        if ($record->expires_at->isPast()) {
            return response()->json(['message' => 'OTP has expired. Please request a new one.'], 422);
        }

        $user = User::where('email', $record->email)->firstOrFail();
        $user->password = Hash::make($request->password);
        $user->save();

        $record->delete();

        // Revoke all old tokens and issue a fresh one
        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Password reset successfully.',
            'token'   => $token,
        ]);
    }

    // ─────────────────────────────────────────
    // LOGOUT
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/auth/logout',
        operationId: 'logout',
        summary: 'Logout',
        description: 'Revokes the current access token.',
        tags: ['Auth'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Logged out',
        content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Logged out successfully.')])
    )]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    // ─────────────────────────────────────────
    // HELPER
    // ─────────────────────────────────────────
    private function sendOtp(string $email, string $type): void
    {
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        OtpVerification::where('email', $email)->where('type', $type)->delete();

        OtpVerification::create([
            'email'      => $email,
            'type'       => $type,
            'otp'        => $otp,
            'expires_at' => now()->addMinutes(10),
        ]);

        Mail::to($email)->send(new OtpMail($otp, $type));
    }
}
