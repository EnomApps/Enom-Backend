<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;

class NotificationService
{
    /**
     * Create a notification and send push via FCM.
     */
    public static function send(int $userId, string $type, array $data): void
    {
        // Don't notify yourself
        if (isset($data['from_user_id']) && $data['from_user_id'] === $userId) {
            return;
        }

        // Deduplication: skip if same notification was sent in the last 24 hours
        // This prevents spam when users like/unlike repeatedly
        if (self::isDuplicate($userId, $type, $data)) {
            return;
        }

        // Store in DB
        Notification::create([
            'user_id' => $userId,
            'type'    => $type,
            'data'    => $data,
        ]);

        // Send push notification
        self::sendPush($userId, $type, $data);
    }

    /**
     * Check if a similar notification was sent in the last 24 hours.
     * Prevents spam from like/unlike/like cycles.
     */
    private static function isDuplicate(int $userId, string $type, array $data): bool
    {
        $fromUserId = $data['from_user_id'] ?? null;
        if (!$fromUserId) {
            return false;
        }

        // Build a key that uniquely identifies the action source
        $query = Notification::where('user_id', $userId)
            ->where('type', $type)
            ->where('created_at', '>=', now()->subHours(24));

        // Match on from_user_id + target (post_id or comment_id)
        if (isset($data['comment_id'])) {
            $query->where('data->from_user_id', $fromUserId)
                  ->where('data->comment_id', $data['comment_id']);
        } elseif (isset($data['post_id'])) {
            $query->where('data->from_user_id', $fromUserId)
                  ->where('data->post_id', $data['post_id']);
        } else {
            $query->where('data->from_user_id', $fromUserId);
        }

        return $query->exists();
    }

    /**
     * Send push notification via Firebase Cloud Messaging (HTTP v1 API).
     */
    private static function sendPush(int $userId, string $type, array $data): void
    {
        $tokens = DeviceToken::where('user_id', $userId)->pluck('token')->toArray();

        if (empty($tokens)) {
            return;
        }

        try {
            $messaging = app('firebase.messaging');
        } catch (\Exception $e) {
            Log::warning('Firebase not configured, skipping push: ' . $e->getMessage());
            return;
        }

        $title = self::getTitle($type);
        $body  = self::getBody($type, $data);

        // Convert all data values to strings (FCM requirement)
        $fcmData = array_map('strval', array_merge($data, ['type' => $type]));

        foreach ($tokens as $token) {
            try {
                $message = CloudMessage::new()
                    ->toToken($token)
                    ->withNotification(FcmNotification::create($title, $body))
                    ->withData($fcmData);

                $messaging->send($message);
            } catch (\Kreait\Firebase\Exception\Messaging\NotFound
                   | \Kreait\Firebase\Exception\Messaging\InvalidMessage $e) {
                // Token is invalid/expired, remove it
                DeviceToken::where('token', $token)->delete();
                Log::info('Removed invalid FCM token: ' . $token);
            } catch (\Exception $e) {
                Log::error('FCM push failed: ' . $e->getMessage());
            }
        }
    }

    private static function getTitle(string $type): string
    {
        return match ($type) {
            'like'         => 'New Like',
            'comment'      => 'New Comment',
            'follow'       => 'New Follower',
            'reply'        => 'New Reply',
            'repost'       => 'Reposted',
            'comment_like' => 'Comment Liked',
            default        => 'Notification',
        };
    }

    private static function getBody(string $type, array $data): string
    {
        $name = $data['from_user_name'] ?? 'Someone';

        return match ($type) {
            'like'         => "{$name} liked your post.",
            'comment'      => "{$name} commented on your post.",
            'follow'       => "{$name} started following you.",
            'reply'        => "{$name} replied to your comment.",
            'repost'       => "{$name} reposted your post.",
            'comment_like' => "{$name} liked your comment.",
            default        => 'You have a new notification.',
        };
    }
}
