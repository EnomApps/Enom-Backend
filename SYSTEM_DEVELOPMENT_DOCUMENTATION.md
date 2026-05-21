# ENOM - System Development Documentation

**Project:** ENOM Social Media Platform
**Document Version:** 1.0
**Date:** April 2026
**Prepared by:** Backend Development Team

---

## 1. Executive Summary

ENOM is a TikTok/Instagram-style social media platform with **AI-powered mood detection** that personalizes the user feed based on emotional state. The platform supports image/video posts, social interactions (likes, comments, follows), real-time push notifications, mood-based content recommendations, and multi-language support across 78 languages.

### Key Differentiator
Unlike traditional social platforms, ENOM uses **facial emotion recognition (FER)** to detect user mood and serve personalized content. For example, if a user is detected as "Low" mood, the feed prioritizes uplifting, motivational content.

---

## 2. System Architecture

### 2.1 High-Level Architecture

```
┌─────────────────┐
│  Flutter App    │
│  (Android/iOS)  │
└────────┬────────┘
         │ HTTPS
         ▼
┌─────────────────┐
│  Nginx Proxy    │
│  (Port 80)      │
└────────┬────────┘
         │
    ┌────┴─────────────────────────┐
    ▼                              ▼
┌──────────────┐         ┌──────────────────┐
│ Laravel API  │         │ Python FastAPI   │
│ (PHP 8.3-FPM)│         │ Mood Service     │
│ Port: 9000   │         │ Port: 8001       │
└──────┬───────┘         └────────┬─────────┘
       │                          │
       ├──────────────────────────┤
       ▼                          ▼
   ┌────────┐               ┌─────────┐
   │ MySQL  │               │ SQLite  │
   └────────┘               └─────────┘
       │                          │
   ┌───┴───┐                 ┌────┴────┐
   │ Redis │                 │ DeepFace│
   │ Cache │                 │ Model   │
   └───────┘                 └─────────┘
       │
   ┌───┴────────┐
   ▼            ▼
┌─────┐    ┌──────────┐
│ S3  │    │CloudFront│
└─────┘    │   CDN    │
           └──────────┘
```

### 2.2 Component Breakdown

| Component | Technology | Purpose |
|-----------|-----------|---------|
| Web Server | Nginx 1.24 | Reverse proxy, SSL termination |
| Backend API | Laravel 12 + PHP 8.3 | REST APIs, business logic |
| Mood AI Service | FastAPI + Python 3.12 | Facial emotion recognition |
| Database | MySQL 8 | Primary data store |
| Mood Database | SQLite | Mood history (isolated) |
| Cache | Redis 7 | Session, feed cache, rate limiting |
| Object Storage | AWS S3 | Images, videos, thumbnails |
| CDN | CloudFront | Global media delivery |
| Push Notifications | Firebase Cloud Messaging | Mobile push |
| Email | SMTP (cPanel) | OTP, password reset |
| Authentication | Laravel Sanctum | Bearer token auth |
| Mood Model | DeepFace + OpenCV | Emotion recognition (85-90% accuracy) |

### 2.3 Infrastructure

- **Hosting:** AWS EC2 (Ubuntu 22.04)
- **Instance Type:** 8 GB RAM, 2 vCPU
- **Public IP:** 52.221.249.30
- **Domain:** api.enom.ai (planned for production)
- **Deployment:** Git-based via GitHub
- **CI/CD:** Manual deployment scripts

---

## 3. Module Overview

### 3.1 Core Modules

| Module | Features |
|--------|----------|
| **Authentication** | Registration, email OTP verification, login, password reset, logout |
| **Profile** | View/update profile, interests, preferred language, share link |
| **Posts** | CRUD operations, image/video upload, location tagging, hashtags |
| **Feed** | Chronological feed, For You feed (algorithm), Mood-based feed |
| **Engagement** | Like/react (6 emotions), comment with replies, comment likes |
| **Social** | Follow/unfollow, save posts, repost, share links |
| **Discovery** | Search (users/posts/hashtags), trending hashtags |
| **Notifications** | Push (FCM) + in-app, mark read, delete |
| **Moderation** | Block/report users, content filtering, auto-hide on reports |
| **Mood AI** | Facial emotion detection, mood history, analytics, accuracy tracking |
| **Localization** | 78 languages with RTL support |

### 3.2 API Endpoints Summary

**Total Endpoints:** 70+
- Auth: 8
- Profile: 6
- Posts: 7
- Comments: 6
- Reactions: 4
- Follow: 5
- Saved Posts: 3
- Notifications: 4
- Search: 3
- Mood Detection: 1
- Mood History: 6
- Mood Analytics: 4
- Mood Feed: 4
- Block/Report: 4
- Languages: 2

---

## 4. Database Design

### 4.1 Schema Overview (MySQL)

**20 tables** organized into 5 logical groups:

#### User & Auth
- `users` - User accounts and profiles
- `personal_access_tokens` - Sanctum tokens
- `otp_verifications` - Email OTPs

#### Social Content
- `posts` - User posts (with location, moderation status)
- `post_media` - Images/videos (with thumbnails)
- `comments` - Comments with nested replies
- `comment_likes` - Comment likes
- `reactions` - Post reactions (like, love, haha, wow, sad, angry)
- `reposts` - Reposts with optional quotes
- `post_views` - View tracking

#### Discovery
- `hashtags` - Hashtag names with post counts
- `hashtag_post` - Many-to-many pivot
- `interests` - Predefined interests
- `user_interests` - User-interest pivot

#### Social Graph
- `follows` - Follow relationships
- `saved_posts` - Bookmarked posts
- `blocks` - Blocked users

#### Safety & Notifications
- `reports` - Content reports (polymorphic)
- `notifications` - In-app notifications
- `device_tokens` - FCM tokens
- `mood_content_mappings` - Mood-to-tag mappings for feed

#### Localization
- `languages` - 78 supported languages

### 4.2 Mood Database (SQLite)

Isolated from MySQL for performance and isolation:
- `mood_entries` - Detected/manual mood entries with soft delete and accuracy tracking

### 4.3 Key Relationships

- User → has many Posts, Comments, Reactions, Follows
- Post → has many Media, Comments, Reactions, belongs to User
- Comment → has many Replies (self-referential), Likes
- User ↔ Interests (many-to-many)
- Post ↔ Hashtags (many-to-many)
- User ↔ User (follows, blocks)

---

## 5. Development Process

### 5.1 Development Methodology

- **Approach:** Agile / Iterative
- **Sprint Length:** Per feature ticket
- **Tools:** Git (GitHub), Postman, Swagger UI
- **Code Reviews:** Pre-deployment review of all changes

### 5.2 Development Phases

| Phase | Features | Status |
|-------|----------|--------|
| Phase 1 | Auth, Profile, Basic Posts | Complete |
| Phase 2 | Comments, Reactions, Follow, Notifications | Complete |
| Phase 3 | S3 Media Storage, CloudFront CDN, Caching | Complete |
| Phase 4 | Search, Hashtags, For You Feed, Block/Report | Complete |
| Phase 5 | Mood Detection AI Service | Complete |
| Phase 6 | Mood History, Analytics, Mood-Based Feed | Complete |
| Phase 7 | Multi-language Support (78 languages) | Complete |
| Phase 8 | Production Hardening | In Progress |

### 5.3 Code Organization

**Laravel Backend:**
```
app/
├── Http/Controllers/Api/    # 15 controllers
├── Models/                   # 20 Eloquent models
├── Services/                 # Business logic services
│   ├── ContentModerationService.php
│   └── NotificationService.php
├── Mail/                     # Email templates
└── Console/Commands/         # CLI commands
```

**Python Mood Service:**
```
mood-service/
├── app/
│   ├── main.py              # FastAPI application
│   ├── auth.py              # Token validation
│   ├── preprocessing.py     # Image pipeline
│   ├── mood_detector.py     # DeepFace integration
│   ├── rate_limiter.py      # Redis rate limiting
│   └── database.py          # SQLite operations
└── tests/                   # Unit tests
```

---

## 6. Security Implementation

### 6.1 Authentication & Authorization

- **Mechanism:** Laravel Sanctum (Bearer tokens)
- **Token Format:** `Bearer {token}`
- **Token Storage:** `personal_access_tokens` table
- **Token Revocation:** On logout (single token deletion)
- **Multi-Device Support:** Each device gets unique token

### 6.2 Password Security

- **Hashing:** bcrypt (Laravel default)
- **Minimum Length:** 8 characters
- **Reset Flow:** 3-step OTP verification

### 6.3 API Security

| Measure | Implementation |
|---------|----------------|
| HTTPS | Configured at Nginx level |
| Rate Limiting | Redis-based, 10 req/min for mood detection |
| Input Validation | Laravel Form Requests |
| SQL Injection | Eloquent ORM (parameterized queries) |
| XSS Prevention | Output encoding, no inline HTML |
| CSRF Protection | Stateless API (Bearer token) |
| File Upload | Validated extensions, MIME types, size limits |

### 6.4 Content Moderation

**3-Layer System:**
1. **Text Filter:** Blocked words list (free)
2. **AWS Rekognition:** Image moderation (optional, paid)
3. **Community Reports:** Auto-hide after 3 reports

### 6.5 Data Privacy

- Soft deletes for sensitive data (mood entries, reports)
- User can delete account data on request
- Blocked users completely hidden from feeds
- Profile privacy settings: public, private, friends_only

---

## 7. Performance Optimization

### 7.1 Caching Strategy

| Cache | TTL | Purpose |
|-------|-----|---------|
| User profile | 60s | Reduce DB queries |
| Interests list | 1 hour | Rarely changes |
| Languages list | 1 hour | Rarely changes |
| Mood trends | 15 min | Heavy aggregation |
| Mood global stats | 15 min | Admin queries |
| Mood-to-tag mappings | 5 min | Feed algorithm |
| Feed first page | 30s | High-traffic endpoint |

### 7.2 Database Optimization

- **Cursor-based pagination** instead of offset (constant time)
- **Composite indexes** on frequently queried columns
- **Eager loading** to prevent N+1 queries
- **withCount** for counts without loading relations
- **Soft deletes** instead of hard deletes

### 7.3 Media Optimization

- **CloudFront CDN** for global delivery
- **Image dimensions** stored to avoid loading for size calculation
- **Video thumbnails** auto-generated and cached
- **Direct S3 streaming** for large file uploads

### 7.4 AI Service Performance

- **Model preloading** on service startup (no per-request load time)
- **Image resizing** before inference (max 1024px)
- **Multiple detector backends** with fallback
- **Average response time:** 300-500ms

---

## 8. Mood Detection AI System

### 8.1 Architecture

The mood AI runs as a **separate Python microservice** to:
- Isolate ML dependencies from Laravel
- Allow independent scaling
- Use Python's superior ML ecosystem
- Keep Laravel lightweight

### 8.2 Detection Pipeline

```
1. Receive base64 image
2. Validate (size, format, base64)
3. Decode and preprocess (resize to 1024px max)
4. Run face detection (OpenCV → SSD → skip fallback)
5. Run emotion classification (DeepFace)
6. Map 7 emotions to 4 moods:
   - Happy/Surprise → Happy
   - Neutral → Neutral
   - Sad/Fear → Low
   - Angry/Disgust → Angry
7. Auto-save to history
8. Return result
```

### 8.3 Mood-Based Feed Algorithm

**Scoring Formula:**
```
score = mood_match_weight(60) + reactions(0-30) +
        comments(0-20) + views(0-15) + recency(0-25)
```

**Maximum Score:** 150 points

**Diversity Rule:** No more than 3 consecutive posts from the same user

**Backfill:** If mood-matched posts < 20, fill with latest content

### 8.4 Analytics & Accuracy Tracking

- **User Trends:** 7d, 30d, 90d periods
- **Global Stats:** Platform-wide distribution (admin only)
- **Accuracy Tracking:** % of detections confirmed vs corrected by user
- **CSV Export:** For data science team

---

## 9. Deployment & Operations

### 9.1 Server Setup

**Operating System:** Ubuntu 22.04 LTS

**Required Services:**
```
nginx              # Web server
php8.3-fpm         # PHP processor
mysql-server       # Database
redis-server       # Cache + queue
mood-service       # Python systemd service
```

### 9.2 Deployment Process

```bash
# 1. SSH to server
ssh ubuntu@52.221.249.30

# 2. Pull latest code
cd /var/www/enom
sudo chown -R ubuntu:ubuntu .
git pull origin main

# 3. Run migrations
php artisan migrate

# 4. Clear caches
php artisan config:clear

# 5. Regenerate API docs
php artisan l5-swagger:generate

# 6. Restart services
sudo systemctl restart mood-service
sudo systemctl restart php8.3-fpm

# 7. Fix permissions
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### 9.3 Environment Configuration

Sensitive credentials stored in `.env` file (not in version control):

```env
APP_URL=http://52.221.249.30
DB_DATABASE=enom
REDIS_HOST=127.0.0.1
AWS_BUCKET=enom-media-storage
AWS_URL=https://d1wk74ch4zkyve.cloudfront.net
FIREBASE_CREDENTIALS=/var/www/enom/storage/firebase-credentials.json
MAIL_MAILER=smtp
```

### 9.4 Monitoring

**Logs:**
- Laravel: `storage/logs/laravel.log`
- Mood Service: `sudo journalctl -u mood-service`
- Nginx Access: `/var/log/nginx/access.log`
- Nginx Errors: `/var/log/nginx/error.log`

**Health Checks:**
- Laravel: `GET /api/auth/login` (responds 422 for empty body)
- Mood Service: `GET /api/v1/mood/health`

---

## 10. Testing & Quality Assurance

### 10.1 API Documentation

**Swagger UI:**
- Laravel APIs: `http://52.221.249.30/api/documentation`
- Mood AI APIs: `http://52.221.249.30/api/v1/mood/docs`

All endpoints documented with:
- Request schemas
- Response examples
- Error codes
- Authentication requirements

### 10.2 Testing Tools

- **Postman:** Manual API testing
- **Swagger UI:** Interactive testing
- **Pytest:** Python unit tests for mood service
- **Browser DevTools:** Frontend integration testing

### 10.3 Test Coverage Areas

| Area | Coverage |
|------|----------|
| Auth flows | Registration, login, OTP, password reset |
| File uploads | Image/video, size limits, format validation |
| Permissions | Token validation, resource ownership |
| Rate limiting | Mood detection limits |
| Content moderation | Text filter, report aggregation |
| Mood pipeline | Image preprocessing, emotion mapping |

---

## 11. Third-Party Integrations

| Service | Purpose | Cost |
|---------|---------|------|
| AWS S3 | Media storage | Pay-per-use |
| AWS CloudFront | CDN | Free tier |
| Firebase FCM | Push notifications | Free |
| cPanel SMTP | Email delivery | Included |
| AWS Rekognition | Optional image moderation | Pay-per-use |

---

## 12. Project Statistics

| Metric | Value |
|--------|-------|
| Total API Endpoints | 70+ |
| Database Tables | 20 (MySQL) + 1 (SQLite) |
| Laravel Controllers | 15 |
| Eloquent Models | 20 |
| Migrations | 35+ |
| Lines of Code (PHP) | ~8,000 |
| Lines of Code (Python) | ~1,500 |
| Languages Supported | 78 |
| Mood Detection Accuracy | 85-90% |

---

## 13. Future Roadmap

### Phase 9 (Planned)
- Direct messaging (chat)
- Live streaming
- Premium subscriptions
- Creator monetization
- Advanced analytics dashboard

### Phase 10 (Future)
- ML model fine-tuning on user feedback
- Multi-language NLP for content moderation
- Video transcoding pipeline
- Recommendation engine refinement

---

## 14. Support & Maintenance

### Documentation
- API Documentation (Swagger)
- Code comments and docstrings
- Deployment runbooks
- This system documentation

### Backup Strategy
- MySQL: Daily automated backups (recommended for production)
- SQLite: Backup with daily cron job
- S3 versioning enabled
- Code: Git repository

### Update Schedule
- **Critical patches:** Immediate
- **Security updates:** Within 24 hours
- **Feature updates:** As per sprint cycle
- **Dependency updates:** Quarterly review

---

## 15. Contact & Team

**Backend Development:** ENOM Backend Team
**Repository:** github.com/EnomApps/Enom-Backend
**Documentation:** Auto-generated via L5-Swagger and FastAPI docs

---

*This document is a living document and will be updated as the system evolves.*
*Last updated: April 13, 2026*
