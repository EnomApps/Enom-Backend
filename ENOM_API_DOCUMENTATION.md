# ENOM API - Complete Documentation

## Overview
ENOM is a TikTok/Instagram-style social media platform built with **Laravel 12**, **PHP 8.3**, **MySQL**, **Redis**, **AWS S3**, **CloudFront CDN**, and **Firebase Cloud Messaging**.

**Base URLs:**
- Production (AWS): `http://47.129.5.68`
- Swagger Docs: `http://47.129.5.68/api/documentation`

---

## Architecture

```
Flutter App (Android/iOS)
        |
        v
    Nginx (Reverse Proxy)
        |
        v
    Laravel 12 (PHP 8.3-FPM)
        |
        +---> MySQL (Database)
        +---> Redis (Cache + Sessions)
        +---> AWS S3 (Media Storage)
        +---> CloudFront CDN (Media Delivery)
        +---> Firebase FCM (Push Notifications)
        +---> SMTP (Email OTP)
```

### Tech Stack
| Component | Technology |
|-----------|-----------|
| Framework | Laravel 12 |
| Auth | Laravel Sanctum (Bearer Token) |
| Database | MySQL |
| Cache | Redis |
| Media Storage | AWS S3 |
| CDN | CloudFront (`d1wk74ch4zkyve.cloudfront.net`) |
| Push Notifications | Firebase Cloud Messaging (HTTP v1) |
| Email | SMTP (cPanel mail) |
| API Docs | L5-Swagger (OpenAPI 3.0) |
| Server | AWS EC2 (Ubuntu + Nginx) |

---

## Authentication Flow

### How It Works
1. User registers with **name, email, password**
2. System sends **6-digit OTP** to email
3. User verifies OTP -> receives **Bearer token**
4. All protected APIs require `Authorization: Bearer {token}` header

### Registration Flow
```
POST /api/auth/register     -> Sends OTP email
POST /api/auth/verify-otp   -> Returns Bearer token
POST /api/auth/resend-otp   -> Resends if expired
```

### Login Flow
```
POST /api/auth/login        -> Returns Bearer token
  Body: { email, password, device_token?, platform? }
```
- `device_token` (optional): FCM token for push notifications
- `platform` (optional): "android" | "ios" | "web"

### Password Reset Flow (3 steps)
```
POST /api/auth/forgot-password   -> Sends reset OTP
POST /api/auth/verify-reset-otp  -> Returns reset_token
POST /api/auth/reset-password    -> Uses reset_token + new password
```

### Logout
```
POST /api/auth/logout
  Body: { device_token? }  -> Removes FCM token on logout
```

---

## User Profile

### Fields
| Field | Type | Description |
|-------|------|-------------|
| name | string | Display name |
| username | string | Unique handle (alphanumeric + dash/underscore) |
| email | string | Email address |
| gender | enum | male, female, other |
| dob | date | Date of birth |
| bio | string | Max 200 characters |
| profile_image | string | S3 path |
| profession | string | Student, Developer, Creator, etc. |
| country | string | Country name |
| city | string | City name |
| region | string | Region name |
| content_preferences | array | ["Short videos", "Articles"] |
| social_personality | string | Creator, Viewer, Influencer, Business, Community Builder |
| languages | array | ["English", "Tamil"] |
| privacy_setting | enum | public, private, friends_only |
| interests | relation | Max 10 interests from predefined list |

### Endpoints
```
GET  /api/user/profile              -> Own profile (with posts_count, followers_count, following_count)
POST /api/user/profile              -> Update profile (multipart/form-data for image upload)
GET  /api/users/{userId}/profile    -> View other user's profile
GET  /api/users/{userId}/share-link -> Get shareable profile URL
GET  /api/interests                 -> List all available interests (20 pre-seeded)
```

### Profile Image
- Uploaded to S3 under `profile-images/` folder
- Served via CloudFront CDN
- `profile_image_url` is auto-appended to all user responses
- Max size: 2MB, formats: jpg, jpeg, png, webp

---

## Posts

### Create Post
```
POST /api/posts (multipart/form-data)
  Fields:
    content        -> Text content (supports #hashtags)
    visibility     -> public | private | followers
    location_name  -> "Chennai, India" (optional)
    latitude       -> 13.0827 (optional)
    longitude      -> 80.2707 (optional)
    media[]        -> Image/video files (max 10, max 100MB each)
    thumbnails[]   -> Thumbnail images for videos (optional, max 2MB each)
```

### How Hashtags Work
- Hashtags are auto-extracted from post content (e.g., `#travel #food`)
- Max **5 hashtags** per post are stored
- Hashtags are searchable and have trending rankings
- Example: `"Enjoying sunset #travel #nature"` -> Stores hashtag "travel" and "nature"

### Feed APIs
```
GET /api/posts                -> Chronological feed (cursor pagination)
GET /api/posts/for-you        -> Algorithm-ranked personalized feed
GET /api/posts/{id}           -> Single post with comments
PUT /api/posts/{id}           -> Update post
DELETE /api/posts/{id}        -> Delete post (removes S3 media)
GET /api/posts/{id}/share-link -> Shareable URL
```

### Pagination (Cursor-based)
```
First page:  GET /api/posts?per_page=15
Next page:   GET /api/posts?cursor=eyJpZCI6MTB9&per_page=15
```
Response includes `next_cursor` (null = no more pages).

### For You Algorithm (Scoring System)
| Signal | Points | Description |
|--------|--------|-------------|
| Following | +50 | Post from someone you follow |
| Interest match | +40 | Post hashtags match your selected interests |
| Unseen | +30 | You haven't viewed this post |
| Similar to liked | +25 | Post shares hashtags with posts you liked |
| Reactions | up to +30 | 3 pts per like (capped) |
| Comments | up to +20 | 2 pts per comment (capped) |
| Views | up to +20 | 1 pt per view (capped) |

**Max possible score: 215 points**

### Feed Response
```json
{
  "data": [
    {
      "id": 42,
      "user_id": 1,
      "content": "Hello world! #travel",
      "visibility": "public",
      "location_name": "Chennai, India",
      "moderation_status": "approved",
      "comments_count": 5,
      "reactions_count": 42,
      "views_count": 100,
      "reposts_count": 3,
      "user_reaction": "like",
      "user": {
        "id": 1,
        "name": "veeraiyan",
        "username": "veera",
        "profile_image_url": "https://d1wk74ch4zkyve.cloudfront.net/..."
      },
      "media": [
        {
          "id": 1,
          "type": "video",
          "url": "https://d1wk74ch4zkyve.cloudfront.net/post-media/video.mp4",
          "thumbnail_url": "https://d1wk74ch4zkyve.cloudfront.net/thumbnails/thumb.jpg",
          "width": 1080,
          "height": 1920
        }
      ],
      "hashtags": [
        { "id": 1, "name": "travel" }
      ]
    }
  ],
  "next_cursor": "eyJpZCI6MTB9",
  "prev_cursor": null,
  "per_page": 15
}
```

---

## Reactions (Like System)

### Two Ways to React

**1. Quick Like (Double-tap / Heart button)**
```
POST /api/posts/{postId}/like
  -> No body needed
  -> Returns: { liked: true/false, likes_count: 42 }
```

**2. Facebook-style Reactions (Long-press)**
```
POST /api/posts/{postId}/react
  Body: { "type": "love" }
  Types: like, love, haha, wow, sad, angry
  -> Same type again = removes reaction
  -> Different type = changes reaction
```

### View Reactions
```
GET /api/posts/{postId}/like-status  -> Check if you reacted + summary
GET /api/posts/{postId}/likes        -> List who reacted (?type=love to filter)
```

Response includes `reactions_summary`:
```json
{
  "reactions_summary": {
    "like": 20,
    "love": 15,
    "haha": 5
  }
}
```

---

## Comments

### Endpoints
```
GET  /api/posts/{postId}/comments  -> List comments (top-level with nested replies)
POST /api/posts/{postId}/comments  -> Add comment
PUT  /api/comments/{id}            -> Update comment
DELETE /api/comments/{id}          -> Delete comment
POST /api/comments/{id}/like       -> Like/unlike comment
GET  /api/comments/{id}/likes      -> List who liked comment
```

### Nested Replies
```json
POST /api/posts/42/comments
{
  "content": "This is a reply!",
  "parent_id": 5              // ID of comment being replied to
}
```

### Response Structure
```json
{
  "data": [
    {
      "id": 5,
      "content": "Great post!",
      "parent_id": null,
      "likes_count": 3,
      "user": { "id": 1, "name": "veera" },
      "replies": [
        {
          "id": 8,
          "content": "Thanks!",
          "parent_id": 5,
          "user": { "id": 2, "name": "john" }
        }
      ]
    }
  ]
}
```

---

## Follow System

```
POST /api/users/{userId}/follow        -> Toggle follow/unfollow
GET  /api/users/{userId}/follow-status -> Check if following
GET  /api/users/{userId}/followers     -> List followers
GET  /api/users/{userId}/following     -> List following
GET  /api/users/{userId}/follow-counts -> Get counts
```

- Same endpoint for follow AND unfollow (toggle)
- Blocking a user auto-removes follow both ways

---

## Search & Discovery

### Search
```
GET /api/search?q=keyword               -> Search everything
GET /api/search?q=keyword&type=users     -> Search users only
GET /api/search?q=keyword&type=posts     -> Search posts only
GET /api/search?q=keyword&type=hashtags  -> Search hashtags only
```

### Hashtags
```
GET /api/hashtags/{name}/posts   -> Posts with this hashtag
GET /api/trending/hashtags       -> Top trending hashtags
```

---

## Save Posts

```
POST /api/posts/{postId}/save        -> Toggle save/unsave
GET  /api/posts/{postId}/save-status -> Check if saved
GET  /api/saved-posts                -> List all saved posts
```

---

## Post Views

```
POST /api/posts/{postId}/view  -> Record a view (one per user, idempotent)
GET  /api/posts/{postId}/views -> Get view count + whether you viewed it
```

Call `POST /view` when a video starts playing. Multiple calls for same post won't create duplicate views.

---

## Repost / Share

### Repost (Internal - like retweet)
```
POST /api/posts/{postId}/repost   -> Toggle repost (optional "quote" field)
GET  /api/posts/{postId}/reposts  -> List who reposted
```

### Share Link (External - WhatsApp, Telegram)
```
GET /api/posts/{id}/share-link        -> Returns shareable post URL
GET /api/users/{userId}/share-link    -> Returns shareable profile URL
```

Share URLs open a rich preview page with Open Graph meta tags for WhatsApp/Telegram previews and a "Open in ENOM App" button with deep link.

---

## Block & Report

### Block
```
POST /api/users/{userId}/block        -> Toggle block/unblock
GET  /api/users/{userId}/block-status -> Check block status
GET  /api/blocked-users               -> List blocked users
```
- Blocking removes follow relationships both ways
- Blocked users' posts are hidden from all feeds and search

### Report
```
POST /api/report
{
  "type": "post",              // post | comment | user
  "id": 42,                   // ID of the reported item
  "reason": "spam",           // spam | harassment | nudity | violence | misinformation | other
  "description": "This is spam"  // optional details
}
```
- **Auto-moderation**: 3+ reports on a post -> auto-hidden (pending_review)
- **Auto-moderation**: 3+ reports on a comment -> auto-deleted

---

## Notifications

### Types
| Type | Trigger | Message |
|------|---------|---------|
| like | Someone likes your post | "John liked your post." |
| comment | Someone comments on your post | "John commented on your post." |
| reply | Someone replies to your comment | "John replied to your comment." |
| follow | Someone follows you | "John started following you." |
| repost | Someone reposts your post | "John reposted your post." |
| comment_like | Someone likes your comment | "John liked your comment." |

### Endpoints
```
GET    /api/notifications             -> List notifications (with unread_count)
POST   /api/notifications/{id}/read   -> Mark as read
POST   /api/notifications/read-all    -> Mark all as read
DELETE /api/notifications/{id}        -> Delete notification
```

### Push Notifications (Firebase)
- Push sent via Firebase Cloud Messaging (HTTP v1 API)
- Device token registered during login (`device_token` field)
- Invalid/expired tokens are auto-cleaned
- You DON'T get notified for your own actions

---

## Content Moderation

### 3-Layer System
| Layer | What | Cost | Status |
|-------|------|------|--------|
| Text Filter | Blocks profanity, hate speech, violence keywords | Free | Active |
| AWS Rekognition | Scans images for nudity, violence | ~$0.001/image | Off (enable via env) |
| Community Reports | Users report content, auto-hide after 3 reports | Free | Active |

### How It Works
- Post with bad words -> **Rejected immediately** (422 error)
- Image flagged by Rekognition -> **Rejected immediately** (422 error)
- 3+ user reports -> Post auto-hidden (`pending_review`)
- Rejected/hidden posts never appear in any feed

---

## Media Storage

### Upload Flow
```
User uploads -> Laravel validates -> Uploads to S3 -> CloudFront serves
```

### Paths
| Type | S3 Path | CDN URL |
|------|---------|---------|
| Profile Image | `profile-images/{random}.jpg` | `https://d1wk74ch4zkyve.cloudfront.net/profile-images/...` |
| Post Media | `post-media/{random}.mp4` | `https://d1wk74ch4zkyve.cloudfront.net/post-media/...` |
| Thumbnails | `thumbnails/{random}.jpg` | `https://d1wk74ch4zkyve.cloudfront.net/thumbnails/...` |

### Limits
| Type | Max Size | Formats |
|------|----------|---------|
| Profile Image | 2MB | jpg, jpeg, png, webp |
| Post Media | 100MB | jpg, jpeg, png, webp, mp4, mov |
| Thumbnail | 2MB | jpg, jpeg, png, webp |

---

## Redis Caching

| Cache Key | TTL | What |
|-----------|-----|------|
| `profile:{userId}` | 60s | User profile data |
| `interests:all` | 1 hour | All interests list |

Cache is invalidated when:
- User updates their profile
- New post is created (clears feed cache)
- Post is deleted

---

## Database Schema (17 Tables)

### Core
- `users` - User accounts and profiles
- `personal_access_tokens` - Sanctum auth tokens
- `otp_verifications` - Email/reset OTPs

### Social
- `posts` - User posts with content, visibility, location, moderation
- `post_media` - Images/videos attached to posts
- `comments` - Comments with nested replies (parent_id)
- `comment_likes` - Likes on comments
- `reactions` - Post reactions (like, love, haha, wow, sad, angry)
- `follows` - User follow relationships
- `saved_posts` - Saved/bookmarked posts
- `post_views` - Post view tracking
- `reposts` - Reposted posts with optional quotes

### Discovery
- `hashtags` - Hashtag names with post counts
- `hashtag_post` - Many-to-many pivot (hashtag <-> post)
- `interests` - Predefined interest categories
- `user_interests` - Many-to-many pivot (user <-> interest)

### Safety
- `blocks` - Blocked user relationships
- `reports` - Content reports (polymorphic: post/comment/user)
- `notifications` - In-app notification store
- `device_tokens` - FCM device tokens for push

---

## Environment Variables (.env)

### Required on Server
```env
APP_URL=http://47.129.5.68
APP_NAME=ENOM

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=enom
DB_USERNAME=root
DB_PASSWORD=your_password

# Redis
CACHE_STORE=redis
REDIS_HOST=127.0.0.1

# AWS S3
AWS_ACCESS_KEY_ID=your_key
AWS_SECRET_ACCESS_KEY=your_secret
AWS_DEFAULT_REGION=ap-southeast-1
AWS_BUCKET=enom-media-storage
AWS_URL=https://d1wk74ch4zkyve.cloudfront.net

# Email
MAIL_MAILER=smtp
MAIL_HOST=mail.enom.ai
MAIL_PORT=465
MAIL_USERNAME=_mainaccount@enom.ai
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS=ceo@enom.ai
MAIL_FROM_NAME=ENOM

# Firebase
FIREBASE_CREDENTIALS=/var/www/enom/storage/firebase-credentials.json

# Content Moderation (optional)
AWS_REKOGNITION_ENABLED=false
```

---

## Deployment

### Server Setup
- AWS EC2 (Ubuntu) with Nginx + PHP 8.3-FPM
- Application at `/var/www/enom`
- Firebase credentials at `/var/www/enom/storage/firebase-credentials.json`

### Deploy Commands
```bash
cd /var/www/enom
sudo chown -R ubuntu:ubuntu .
git pull origin main
php artisan migrate
php artisan config:clear
php artisan l5-swagger:generate
sudo chown -R www-data:www-data storage bootstrap/cache
```

### File Permissions
```bash
# After git pull, fix permissions
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# Firebase credentials
sudo chmod 644 /var/www/enom/storage/firebase-credentials.json
sudo chown www-data:www-data /var/www/enom/storage/firebase-credentials.json
```

---

## Artisan Commands

| Command | Description |
|---------|-------------|
| `php artisan migrate` | Run database migrations |
| `php artisan db:seed --class=InterestSeeder` | Seed 20 default interests |
| `php artisan l5-swagger:generate` | Regenerate Swagger docs |
| `php artisan config:clear` | Clear config cache |
| `php artisan cache:clear` | Clear Redis cache |
| `php artisan media:generate-thumbnails` | Generate thumbnails for old videos (FFmpeg) |

---

## Error Handling

All errors return structured JSON:
```json
{
  "message": "Error description",
  "errors": {
    "field": ["Validation error"]
  }
}
```

### HTTP Status Codes
| Code | Meaning |
|------|---------|
| 200 | Success |
| 201 | Created |
| 401 | Unauthenticated (missing/invalid token) |
| 403 | Forbidden (not your resource) |
| 404 | Not found |
| 409 | Conflict (e.g., email already registered) |
| 422 | Validation error / Content rejected |
| 429 | Rate limited |
| 500 | Server error |

---

## Complete API Endpoint List (58 endpoints)

### Auth (7 endpoints - Public)
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/auth/register` | Register with name, email, password |
| POST | `/api/auth/verify-otp` | Verify email OTP, get token |
| POST | `/api/auth/resend-otp` | Resend OTP |
| POST | `/api/auth/login` | Login (optional device_token) |
| POST | `/api/auth/forgot-password` | Send reset OTP |
| POST | `/api/auth/verify-reset-otp` | Verify reset OTP |
| POST | `/api/auth/reset-password` | Set new password |

### Auth (1 endpoint - Protected)
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/auth/logout` | Logout, remove token |

### Profile (5 endpoints)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/user/profile` | Own profile |
| POST | `/api/user/profile` | Update profile |
| GET | `/api/users/{id}/profile` | View other profile |
| GET | `/api/users/{id}/share-link` | Profile share URL |
| GET | `/api/interests` | List interests (public) |

### Posts (7 endpoints)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/posts` | Feed (cursor pagination) |
| GET | `/api/posts/for-you` | For You algorithm feed |
| POST | `/api/posts` | Create post |
| GET | `/api/posts/{id}` | Single post |
| PUT | `/api/posts/{id}` | Update post |
| DELETE | `/api/posts/{id}` | Delete post |
| GET | `/api/posts/{id}/share-link` | Post share URL |

### Comments (6 endpoints)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/posts/{id}/comments` | List comments |
| POST | `/api/posts/{id}/comments` | Add comment/reply |
| PUT | `/api/comments/{id}` | Update comment |
| DELETE | `/api/comments/{id}` | Delete comment |
| POST | `/api/comments/{id}/like` | Like/unlike comment |
| GET | `/api/comments/{id}/likes` | Who liked comment |

### Reactions (4 endpoints)
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/posts/{id}/like` | Quick like toggle |
| POST | `/api/posts/{id}/react` | React with emoji |
| GET | `/api/posts/{id}/like-status` | Check reaction |
| GET | `/api/posts/{id}/likes` | Who reacted |

### Follow (5 endpoints)
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/users/{id}/follow` | Follow/unfollow |
| GET | `/api/users/{id}/follow-status` | Check status |
| GET | `/api/users/{id}/followers` | List followers |
| GET | `/api/users/{id}/following` | List following |
| GET | `/api/users/{id}/follow-counts` | Get counts |

### Saved Posts (3 endpoints)
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/posts/{id}/save` | Save/unsave |
| GET | `/api/posts/{id}/save-status` | Check saved |
| GET | `/api/saved-posts` | List saved |

### Post Views (2 endpoints)
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/posts/{id}/view` | Record view |
| GET | `/api/posts/{id}/views` | View count |

### Repost (2 endpoints)
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/posts/{id}/repost` | Repost/unrepost |
| GET | `/api/posts/{id}/reposts` | Who reposted |

### Search (3 endpoints)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/search?q=keyword` | Search all |
| GET | `/api/hashtags/{name}/posts` | Posts by hashtag |
| GET | `/api/trending/hashtags` | Trending hashtags |

### Block & Report (4 endpoints)
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/users/{id}/block` | Block/unblock |
| GET | `/api/users/{id}/block-status` | Check block |
| GET | `/api/blocked-users` | List blocked |
| POST | `/api/report` | Report content |

### Notifications (4 endpoints)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/notifications` | List notifications |
| POST | `/api/notifications/{id}/read` | Mark read |
| POST | `/api/notifications/read-all` | Mark all read |
| DELETE | `/api/notifications/{id}` | Delete |

### Device Tokens (2 endpoints)
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/device-tokens` | Register token |
| DELETE | `/api/device-tokens` | Remove token |

---

## Project File Structure

```
enom/
├── app/
│   ├── Console/Commands/
│   │   └── GenerateVideoThumbnails.php
│   ├── Http/Controllers/Api/
│   │   ├── AuthController.php
│   │   ├── BlockReportController.php
│   │   ├── CommentController.php
│   │   ├── DeviceTokenController.php
│   │   ├── FollowController.php
│   │   ├── NotificationController.php
│   │   ├── PostController.php
│   │   ├── PostViewController.php
│   │   ├── ProfileController.php
│   │   ├── ReactionController.php
│   │   ├── RepostController.php
│   │   ├── SavedPostController.php
│   │   └── SearchController.php
│   ├── Mail/
│   │   └── OtpMail.php
│   ├── Models/
│   │   ├── Block.php
│   │   ├── Comment.php
│   │   ├── CommentLike.php
│   │   ├── DeviceToken.php
│   │   ├── Follow.php
│   │   ├── Hashtag.php
│   │   ├── Interest.php
│   │   ├── Notification.php
│   │   ├── OtpVerification.php
│   │   ├── Post.php
│   │   ├── PostMedia.php
│   │   ├── PostView.php
│   │   ├── Reaction.php
│   │   ├── Repost.php
│   │   ├── Report.php
│   │   ├── SavedPost.php
│   │   └── User.php
│   └── Services/
│       ├── ContentModerationService.php
│       └── NotificationService.php
├── config/
│   ├── filesystems.php (S3 config)
│   └── services.php (Rekognition config)
├── database/
│   ├── migrations/ (30 migration files)
│   └── seeders/
│       └── InterestSeeder.php
├── resources/views/
│   ├── emails/otp.blade.php
│   └── share/
│       ├── post.blade.php
│       └── profile.blade.php
├── routes/
│   ├── api.php
│   └── web.php
└── storage/
    ├── api-docs/api-docs.json
    └── firebase-credentials.json (server only)
```

---

*Last updated: April 2026*
*Total API Endpoints: 58*
*Total Database Tables: 20*
*Total Models: 17*
*Total Controllers: 13*
