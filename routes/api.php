<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;

// ─── Public Auth Routes ────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/register',        [AuthController::class, 'register']);
    Route::post('/verify-otp',      [AuthController::class, 'verifyOtp']);
    Route::post('/resend-otp',      [AuthController::class, 'resendOtp']);
    Route::post('/login',           [AuthController::class, 'login']);
    Route::post('/forgot-password',  [AuthController::class, 'forgotPassword']);
    Route::post('/verify-reset-otp', [AuthController::class, 'verifyResetOtp']);
    Route::post('/reset-password',   [AuthController::class, 'resetPassword']);
});

// ─── Public Data Routes ─────────────────────────────────────────────────────
Route::get('/interests', [ProfileController::class, 'interests']);

// ─── Protected Routes (require Bearer token) ───────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout',  [AuthController::class,  'logout']);
    Route::get('/user/profile',  [ProfileController::class, 'show']);
    Route::post('/user/profile', [ProfileController::class, 'update']);
});
