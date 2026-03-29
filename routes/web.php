<?php

use App\Models\Post;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/', function () {
    return view('welcome');
});

// Share link — opens in browser or redirects to app
Route::get('/post/{id}', function (int $id) {
    $post = Post::with(['user:id,name,username,profile_image', 'media'])->find($id);

    if (!$post) {
        abort(404);
    }

    $title = $post->user->name . ' on ENOM';
    $description = $post->content ? Str::limit($post->content, 200) : 'Check this post on ENOM';
    $image = $post->media->first()?->type === 'image'
        ? $post->media->first()->url
        : ($post->user->profile_image_url ?? '');

    // Deep link for the app
    $deepLink = 'enom://post/' . $post->id;

    return response()->view('share.post', compact('post', 'title', 'description', 'image', 'deepLink'));
});

Route::get('/profile-images/{filename}', function (string $filename) {
    if (str_contains($filename, '/') || str_contains($filename, '..')) {
        abort(404);
    }
    $path = public_path('profile-images/' . $filename);
    if (!file_exists($path) || !is_file($path)) {
        abort(404);
    }
    return response()->file($path);
});
