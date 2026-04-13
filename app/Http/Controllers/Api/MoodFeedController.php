<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\MoodContentMapping;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class MoodFeedController extends Controller
{
    // ─────────────────────────────────────────
    // MOOD-BASED FEED
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/v1/feed',
        operationId: 'moodFeed',
        summary: 'Get mood-customized content feed',
        description: 'Returns posts ranked by mood relevance, engagement, and recency. Pass mood parameter to customize. Supports A/B testing with ab_test flag.',
        tags: ['Mood Feed'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'mood', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['Happy', 'Neutral', 'Low', 'Angry']), description: 'Current mood for personalization')]
    #[OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Parameter(name: 'ab_test', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['mood', 'chronological']), description: 'A/B test flag: mood-ranked or chronological')]
    #[OA\Response(response: 200, description: 'Mood-customized feed')]
    public function feed(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 20), 1), 50);
        $mood = $request->input('mood');
        $abTest = $request->input('ab_test', 'mood');
        $authUserId = $request->user()->id;

        // Validate mood
        $validMoods = ['Happy', 'Neutral', 'Low', 'Angry'];
        if ($mood && !in_array($mood, $validMoods)) {
            return response()->json(['message' => 'Invalid mood. Use: Happy, Neutral, Low, or Angry.'], 422);
        }

        // Get blocked users
        $blockedIds = Block::where('blocker_id', $authUserId)->pluck('blocked_id')
            ->merge(Block::where('blocked_id', $authUserId)->pluck('blocker_id'))
            ->unique()->toArray();

        // A/B Test: chronological mode (no mood ranking)
        if ($abTest === 'chronological' || !$mood) {
            return $this->chronologicalFeed($request, $perPage, $blockedIds, $authUserId, $mood);
        }

        // Get mood-to-tag mappings
        $moodTags = Cache::remember("mood_tags:{$mood}", 300, function () use ($mood) {
            return MoodContentMapping::where('mood', $mood)
                ->where('is_active', true)
                ->pluck('weight', 'tag')
                ->toArray();
        });

        // Get post IDs matching mood tags via hashtags
        $tagNames = array_keys($moodTags);
        $moodPostIds = [];
        $moodPostWeights = [];

        if (!empty($tagNames)) {
            $matches = DB::table('hashtag_post')
                ->join('hashtags', 'hashtags.id', '=', 'hashtag_post.hashtag_id')
                ->whereIn('hashtags.name', $tagNames)
                ->select('hashtag_post.post_id', 'hashtags.name')
                ->get();

            foreach ($matches as $match) {
                $postId = $match->post_id;
                $weight = $moodTags[$match->name] ?? 0.5;

                if (!isset($moodPostWeights[$postId])) {
                    $moodPostWeights[$postId] = 0;
                }
                $moodPostWeights[$postId] = max($moodPostWeights[$postId], $weight);
                $moodPostIds[] = $postId;
            }
            $moodPostIds = array_unique($moodPostIds);
        }

        // Build query with mood scoring
        $query = Post::with([
                'user:id,name,username,profile_image',
                'media:id,post_id,type,url,thumbnail_url,width,height',
                'hashtags:id,name',
            ])
            ->withCount(['comments', 'reactions', 'views', 'reposts'])
            ->where('visibility', 'public')
            ->where('moderation_status', 'approved')
            ->where('user_id', '!=', $authUserId)
            ->whereNotIn('user_id', $blockedIds);

        // Scoring: mood_weight * engagement * recency
        $moodPostIdStr = count($moodPostIds) ? implode(',', $moodPostIds) : '0';

        $query->orderByRaw("
            (
                CASE WHEN id IN ({$moodPostIdStr}) THEN 60 ELSE 0 END
                + LEAST(reactions_count * 3, 30)
                + LEAST(comments_count * 2, 20)
                + LEAST(views_count, 15)
                + GREATEST(0, 25 - DATEDIFF(NOW(), created_at) * 2)
            ) DESC
        ");

        $posts = $query->cursorPaginate($perPage);

        // Apply content diversity (no more than 3 consecutive from same user)
        $diversified = $this->applyDiversity($posts->getCollection());

        // Backfill if < minimum items
        $minItems = 20;
        if ($diversified->count() < $minItems && !$request->input('cursor')) {
            $existingIds = $diversified->pluck('id')->toArray();
            $backfill = Post::with([
                    'user:id,name,username,profile_image',
                    'media:id,post_id,type,url,thumbnail_url,width,height',
                    'hashtags:id,name',
                ])
                ->withCount(['comments', 'reactions', 'views', 'reposts'])
                ->where('visibility', 'public')
                ->where('moderation_status', 'approved')
                ->where('user_id', '!=', $authUserId)
                ->whereNotIn('user_id', $blockedIds)
                ->whereNotIn('id', $existingIds)
                ->latest()
                ->limit($minItems - $diversified->count())
                ->get();

            $diversified = $diversified->concat($backfill);
        }

        // Add user_reaction
        $diversified->transform(function ($post) use ($authUserId) {
            $reaction = $post->reactions()->where('user_id', $authUserId)->first();
            $post->user_reaction = $reaction?->type;
            return $post;
        });

        $appliedFilters = [];
        if ($mood) $appliedFilters[] = "mood:{$mood}";
        if (!empty($tagNames)) $appliedFilters[] = "tags:" . implode(',', array_slice($tagNames, 0, 5));

        return response()->json([
            'data'           => $diversified->values(),
            'mood'           => $mood,
            'appliedFilters' => $appliedFilters,
            'next_cursor'    => $posts->nextCursor()?->encode(),
            'has_more'       => $posts->hasMorePages(),
            'per_page'       => $perPage,
            'ab_test'        => $abTest,
        ]);
    }

    // ─────────────────────────────────────────
    // CHRONOLOGICAL FEED (A/B test fallback)
    // ─────────────────────────────────────────
    private function chronologicalFeed(Request $request, int $perPage, array $blockedIds, int $authUserId, ?string $mood): JsonResponse
    {
        $posts = Post::with([
                'user:id,name,username,profile_image',
                'media:id,post_id,type,url,thumbnail_url,width,height',
                'hashtags:id,name',
            ])
            ->withCount(['comments', 'reactions', 'views', 'reposts'])
            ->where('visibility', 'public')
            ->where('moderation_status', 'approved')
            ->where('user_id', '!=', $authUserId)
            ->whereNotIn('user_id', $blockedIds)
            ->orderByDesc('id')
            ->cursorPaginate($perPage);

        $posts->getCollection()->transform(function ($post) use ($authUserId) {
            $reaction = $post->reactions()->where('user_id', $authUserId)->first();
            $post->user_reaction = $reaction?->type;
            return $post;
        });

        return response()->json([
            'data'           => $posts->items(),
            'mood'           => $mood,
            'appliedFilters' => $mood ? ["mood:{$mood}", "mode:chronological"] : [],
            'next_cursor'    => $posts->nextCursor()?->encode(),
            'has_more'       => $posts->hasMorePages(),
            'per_page'       => $perPage,
            'ab_test'        => 'chronological',
        ]);
    }

    // ─────────────────────────────────────────
    // CONTENT DIVERSITY SHUFFLER
    // ─────────────────────────────────────────
    private function applyDiversity($posts)
    {
        $maxConsecutive = 3;
        $result = collect();
        $lastUserId = null;
        $consecutiveCount = 0;
        $deferred = collect();

        foreach ($posts as $post) {
            if ($post->user_id === $lastUserId) {
                $consecutiveCount++;
                if ($consecutiveCount >= $maxConsecutive) {
                    $deferred->push($post);
                    continue;
                }
            } else {
                $consecutiveCount = 1;
                $lastUserId = $post->user_id;
            }
            $result->push($post);
        }

        // Append deferred posts at the end
        return $result->concat($deferred);
    }

    // ─────────────────────────────────────────
    // ADMIN: GET MOOD MAPPINGS
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/v1/mood-mappings',
        operationId: 'getMoodMappings',
        summary: 'Get mood-to-content-tag mappings',
        tags: ['Mood Feed'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'mood', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Mood mappings')]
    public function getMappings(Request $request): JsonResponse
    {
        $mood = $request->input('mood');

        $query = MoodContentMapping::where('is_active', true);
        if ($mood) {
            $query->where('mood', $mood);
        }

        $mappings = $query->orderBy('mood')->orderByDesc('weight')->get();

        return response()->json(['mappings' => $mappings]);
    }

    // ─────────────────────────────────────────
    // ADMIN: UPDATE MOOD MAPPING
    // ─────────────────────────────────────────
    #[OA\Post(
        path: '/api/v1/mood-mappings',
        operationId: 'upsertMoodMapping',
        summary: 'Create or update a mood-to-tag mapping',
        tags: ['Mood Feed'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['mood', 'tag', 'weight'],
            properties: [
                new OA\Property(property: 'mood', type: 'string', enum: ['Happy', 'Neutral', 'Low', 'Angry']),
                new OA\Property(property: 'tag', type: 'string', example: 'funny'),
                new OA\Property(property: 'weight', type: 'number', example: 0.8),
                new OA\Property(property: 'is_active', type: 'boolean', example: true),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Mapping saved')]
    public function upsertMapping(Request $request): JsonResponse
    {
        $request->validate([
            'mood'      => ['required', 'in:Happy,Neutral,Low,Angry'],
            'tag'       => ['required', 'string', 'max:50'],
            'weight'    => ['required', 'numeric', 'between:0,1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $mapping = MoodContentMapping::updateOrCreate(
            ['mood' => $request->mood, 'tag' => strtolower($request->tag)],
            [
                'weight'    => $request->weight,
                'is_active' => $request->input('is_active', true),
            ]
        );

        // Clear cache
        Cache::forget("mood_tags:{$request->mood}");

        return response()->json(['message' => 'Mapping saved.', 'mapping' => $mapping]);
    }

    // ─────────────────────────────────────────
    // ADMIN: DELETE MOOD MAPPING
    // ─────────────────────────────────────────
    #[OA\Delete(
        path: '/api/v1/mood-mappings/{id}',
        operationId: 'deleteMoodMapping',
        summary: 'Delete a mood-to-tag mapping',
        tags: ['Mood Feed'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Mapping deleted')]
    public function deleteMapping(int $id): JsonResponse
    {
        $mapping = MoodContentMapping::findOrFail($id);
        Cache::forget("mood_tags:{$mapping->mood}");
        $mapping->delete();

        return response()->json(['message' => 'Mapping deleted.']);
    }
}
