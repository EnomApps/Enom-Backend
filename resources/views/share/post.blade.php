<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>

    <!-- Open Graph (WhatsApp, Facebook, Telegram) -->
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:image" content="{{ $image }}">
    <meta property="og:url" content="{{ url('/post/' . $post->id) }}">
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="ENOM">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $image }}">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #000; color: #fff; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .container { text-align: center; padding: 40px 20px; max-width: 500px; }
        .logo { font-size: 32px; font-weight: bold; color: #D4AF37; margin-bottom: 20px; letter-spacing: 4px; }
        .user { display: flex; align-items: center; justify-content: center; gap: 12px; margin-bottom: 20px; }
        .avatar { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; border: 2px solid #D4AF37; }
        .username { font-size: 16px; color: #ccc; }
        .content { font-size: 18px; line-height: 1.6; margin-bottom: 30px; color: #eee; }
        .media { max-width: 100%; border-radius: 12px; margin-bottom: 30px; }
        .btn { display: inline-block; background: #D4AF37; color: #000; padding: 14px 40px; border-radius: 30px; text-decoration: none; font-weight: bold; font-size: 16px; }
        .btn:hover { background: #c4a030; }
        .store-links { margin-top: 20px; font-size: 14px; color: #888; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">ENOM</div>

        <div class="user">
            @if($post->user->profile_image_url)
                <img src="{{ $post->user->profile_image_url }}" alt="{{ $post->user->name }}" class="avatar">
            @endif
            <span class="username">{{ '@' . ($post->user->username ?? $post->user->name) }}</span>
        </div>

        @if($post->content)
            <p class="content">{{ Str::limit($post->content, 300) }}</p>
        @endif

        @if($post->media->first())
            @if($post->media->first()->type === 'image')
                <img src="{{ $post->media->first()->url }}" alt="Post media" class="media">
            @endif
        @endif

        <a href="{{ $deepLink }}" class="btn">Open in ENOM App</a>

        <div class="store-links">
            <p>Don't have the app yet?</p>
            <p>Download ENOM from App Store or Google Play</p>
        </div>
    </div>

    <script>
        // Try to open the app, fallback to store
        setTimeout(function() {
            window.location.href = '{{ $deepLink }}';
        }, 100);
    </script>
</body>
</html>
