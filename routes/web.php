<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
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
