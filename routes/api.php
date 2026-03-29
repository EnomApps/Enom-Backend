<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\ReactionController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\FollowController;
use App\Http\Controllers\Api\SavedPostController;
use App\Http\Controllers\Api\PostViewController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\RepostController;
use App\Http\Controllers\Api\BlockReportController;

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
    // Auth
    Route::post('/auth/logout',  [AuthController::class,  'logout']);

    // Profile
    Route::get('/user/profile',  [ProfileController::class, 'show']);
    Route::post('/user/profile', [ProfileController::class, 'update']);

    // Posts
    Route::get('/posts/for-you',  [PostController::class, 'forYou']);
    Route::get('/posts',          [PostController::class, 'index']);
    Route::post('/posts',         [PostController::class, 'store']);
    Route::get('/posts/{id}',     [PostController::class, 'show']);
    Route::put('/posts/{id}',     [PostController::class, 'update']);
    Route::delete('/posts/{id}',  [PostController::class, 'destroy']);
    Route::get('/posts/{id}/share-link', [PostController::class, 'shareLink']);

    // Comments
    Route::get('/posts/{postId}/comments',  [CommentController::class, 'index']);
    Route::post('/posts/{postId}/comments', [CommentController::class, 'store']);
    Route::put('/comments/{id}',            [CommentController::class, 'update']);
    Route::delete('/comments/{id}',         [CommentController::class, 'destroy']);
    Route::post('/comments/{id}/like',      [CommentController::class, 'toggleLike']);
    Route::get('/comments/{id}/likes',      [CommentController::class, 'likes']);

    // Reactions
    Route::get('/posts/{postId}/reactions',  [ReactionController::class, 'index']);
    Route::post('/posts/{postId}/reactions', [ReactionController::class, 'toggle']);

    // Notifications
    Route::get('/notifications',              [NotificationController::class, 'index']);
    Route::post('/notifications/read-all',    [NotificationController::class, 'markAllAsRead']);
    Route::post('/notifications/{id}/read',   [NotificationController::class, 'markAsRead']);
    Route::delete('/notifications/{id}',      [NotificationController::class, 'destroy']);

    // Device Tokens
    Route::post('/device-tokens',   [DeviceTokenController::class, 'store']);
    Route::delete('/device-tokens', [DeviceTokenController::class, 'destroy']);

    // Post Views
    Route::post('/posts/{postId}/view',  [PostViewController::class, 'store']);
    Route::get('/posts/{postId}/views',  [PostViewController::class, 'count']);

    // Saved Posts
    Route::post('/posts/{postId}/save',        [SavedPostController::class, 'toggle']);
    Route::get('/posts/{postId}/save-status',   [SavedPostController::class, 'status']);
    Route::get('/saved-posts',                  [SavedPostController::class, 'index']);

    // Repost
    Route::post('/posts/{postId}/repost',  [RepostController::class, 'toggle']);
    Route::get('/posts/{postId}/reposts',  [RepostController::class, 'index']);

    // Search
    Route::get('/search',              [SearchController::class, 'search']);
    Route::get('/hashtags/{name}/posts', [SearchController::class, 'hashtagPosts']);
    Route::get('/trending/hashtags',   [SearchController::class, 'trendingHashtags']);

    // Block & Report
    Route::post('/users/{userId}/block',       [BlockReportController::class, 'toggleBlock']);
    Route::get('/users/{userId}/block-status', [BlockReportController::class, 'blockStatus']);
    Route::get('/blocked-users',               [BlockReportController::class, 'blockedUsers']);
    Route::post('/report',                     [BlockReportController::class, 'report']);

    // Follow
    Route::post('/users/{userId}/follow',        [FollowController::class, 'toggle']);
    Route::get('/users/{userId}/follow-status',   [FollowController::class, 'status']);
    Route::get('/users/{userId}/followers',       [FollowController::class, 'followers']);
    Route::get('/users/{userId}/following',       [FollowController::class, 'following']);
    Route::get('/users/{userId}/follow-counts',   [FollowController::class, 'counts']);
});
