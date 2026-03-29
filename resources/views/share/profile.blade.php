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
    <meta property="og:url" content="{{ url('/user/' . ($user->username ?? $user->id)) }}">
    <meta property="og:type" content="profile">
    <meta property="og:site_name" content="ENOM">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $image }}">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #000; color: #fff; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .container { text-align: center; padding: 40px 20px; max-width: 500px; }
        .logo { font-size: 32px; font-weight: bold; color: #D4AF37; margin-bottom: 30px; letter-spacing: 4px; }
        .avatar { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid #D4AF37; margin-bottom: 16px; }
        .name { font-size: 22px; font-weight: bold; margin-bottom: 4px; }
        .username { font-size: 16px; color: #888; margin-bottom: 16px; }
        .bio { font-size: 15px; color: #ccc; line-height: 1.5; margin-bottom: 24px; }
        .stats { display: flex; justify-content: center; gap: 30px; margin-bottom: 30px; }
        .stat { text-align: center; }
        .stat-num { font-size: 20px; font-weight: bold; color: #D4AF37; }
        .stat-label { font-size: 12px; color: #888; text-transform: uppercase; }
        .btn { display: inline-block; background: #D4AF37; color: #000; padding: 14px 40px; border-radius: 30px; text-decoration: none; font-weight: bold; font-size: 16px; }
        .btn:hover { background: #c4a030; }
        .store-links { margin-top: 20px; font-size: 14px; color: #888; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">ENOM</div>

        @if($image)
            <img src="{{ $image }}" alt="{{ $user->name }}" class="avatar">
        @endif

        <div class="name">{{ $user->name }}</div>
        <div class="username">{{ '@' . ($user->username ?? $user->id) }}</div>

        @if($user->bio)
            <p class="bio">{{ $user->bio }}</p>
        @endif

        <div class="stats">
            <div class="stat">
                <div class="stat-num">{{ $user->posts_count ?? 0 }}</div>
                <div class="stat-label">Posts</div>
            </div>
            <div class="stat">
                <div class="stat-num">{{ $user->followers_count ?? 0 }}</div>
                <div class="stat-label">Followers</div>
            </div>
            <div class="stat">
                <div class="stat-num">{{ $user->following_count ?? 0 }}</div>
                <div class="stat-label">Following</div>
            </div>
        </div>

        <a href="{{ $deepLink }}" class="btn">View on ENOM App</a>

        <div class="store-links">
            <p>Don't have the app yet?</p>
            <p>Download ENOM from App Store or Google Play</p>
        </div>
    </div>

    <script>
        setTimeout(function() {
            window.location.href = '{{ $deepLink }}';
        }, 100);
    </script>
</body>
</html>
