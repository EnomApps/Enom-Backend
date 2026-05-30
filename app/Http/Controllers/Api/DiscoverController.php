<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\Follow;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class DiscoverController extends Controller
{
    // ─────────────────────────────────────────
    // DISCOVER USERS (Suggested to Follow)
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/users/discover',
        operationId: 'discoverUsers',
        summary: 'Get suggested users to follow',
        description: 'Returns users ranked by friends-of-friends, shared interests, same region, and popularity. Excludes blocked users and users you already follow.',
        tags: ['Discover'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20, maximum: 50))]
    #[OA\Response(response: 200, description: 'List of suggested users',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'id',                type: 'integer'),
                new OA\Property(property: 'name',              type: 'string'),
                new OA\Property(property: 'username',          type: 'string'),
                new OA\Property(property: 'bio',               type: 'string'),
                new OA\Property(property: 'profile_image_url', type: 'string'),
                new OA\Property(property: 'followers_count',   type: 'integer'),
                new OA\Property(property: 'mutual_count',      type: 'integer'),
                new OA\Property(property: 'reason',            type: 'string', example: 'Followed by 5 people you know'),
            ], type: 'object')),
        ])
    )]
    public function users(Request $request): JsonResponse
    {
        $authUserId = $request->user()->id;
        $limit = min((int) $request->input('limit', 20), 50);

        // Cache for 15 minutes (heavy aggregation query)
        $cacheKey = "discover:users:{$authUserId}:{$limit}";

        $result = Cache::remember($cacheKey, 900, function () use ($authUserId, $request, $limit) {
            return $this->buildSuggestions($authUserId, $request->user(), $limit);
        });

        return response()->json($result);
    }

    private function buildSuggestions(int $authUserId, $user, int $limit): array
    {
        // ── Exclusions ──
        $following = Follow::where('follower_id', $authUserId)
            ->pluck('following_id')->toArray();

        $blockedIds = Block::where('blocker_id', $authUserId)->pluck('blocked_id')
            ->merge(Block::where('blocked_id', $authUserId)->pluck('blocker_id'))
            ->unique()->toArray();

        $excludeIds = array_unique(array_merge($following, $blockedIds, [$authUserId]));

        // ── Signals ──

        // Signal 1: Friends-of-friends (users that the people I follow also follow)
        $fofIds = [];
        $fofCounts = [];
        if (!empty($following)) {
            $rows = Follow::whereIn('follower_id', $following)
                ->whereNotIn('following_id', $excludeIds)
                ->select('following_id', DB::raw('COUNT(*) as mutual_count'))
                ->groupBy('following_id')
                ->orderByDesc('mutual_count')
                ->limit(100)
                ->get();

            foreach ($rows as $row) {
                $fofIds[] = $row->following_id;
                $fofCounts[$row->following_id] = $row->mutual_count;
            }
        }

        // Signal 2: Users sharing my interests
        $myInterestIds = $user->interests()->pluck('interests.id')->toArray();
        $interestUserIds = [];
        if (!empty($myInterestIds)) {
            $interestUserIds = DB::table('user_interests')
                ->whereIn('interest_id', $myInterestIds)
                ->whereNotIn('user_id', $excludeIds)
                ->groupBy('user_id')
                ->select('user_id', DB::raw('COUNT(*) as shared'))
                ->orderByDesc('shared')
                ->limit(100)
                ->pluck('user_id')
                ->toArray();
        }

        // Signal 3: Users in same region/country
        $sameRegionIds = [];
        if ($user->country || $user->region) {
            $q = User::query()
                ->whereNotIn('id', $excludeIds)
                ->where('status', 'active')
                ->where('is_verified', true);

            if ($user->country) {
                $q->where('country', $user->country);
            }
            if ($user->region) {
                $q->orWhere('region', $user->region);
            }

            $sameRegionIds = $q->limit(100)->pluck('id')->toArray();
        }

        // Combine candidate IDs
        $candidateIds = array_unique(array_merge($fofIds, $interestUserIds, $sameRegionIds));
        $candidateIds = array_diff($candidateIds, $excludeIds);

        if (empty($candidateIds)) {
            // Fallback: return active verified users with most followers
            $candidateIds = User::whereNotIn('id', $excludeIds)
                ->where('status', 'active')
                ->where('is_verified', true)
                ->withCount('followers')
                ->orderByDesc('followers_count')
                ->limit($limit)
                ->pluck('id')->toArray();
        }

        // Load candidate users with follower counts
        $users = User::whereIn('id', $candidateIds)
            ->where('status', 'active')
            ->withCount('followers')
            ->get(['id', 'name', 'username', 'bio', 'profile_image', 'country', 'region']);

        // Score each user
        $scored = $users->map(function ($u) use ($fofIds, $fofCounts, $interestUserIds, $sameRegionIds) {
            $score = 0;
            $reasons = [];

            // +50 per mutual friend (capped at 100)
            if (in_array($u->id, $fofIds)) {
                $mutual = $fofCounts[$u->id] ?? 0;
                $score += min($mutual * 10, 100);
                $reasons[] = $mutual === 1
                    ? "Followed by 1 person you know"
                    : "Followed by {$mutual} people you know";
                $u->mutual_count = $mutual;
            } else {
                $u->mutual_count = 0;
            }

            // +30 for shared interests
            if (in_array($u->id, $interestUserIds)) {
                $score += 30;
                if (empty($reasons)) {
                    $reasons[] = "Shares your interests";
                }
            }

            // +20 for same region
            if (in_array($u->id, $sameRegionIds)) {
                $score += 20;
                if (empty($reasons)) {
                    $reasons[] = "Near you";
                }
            }

            // +0-15 based on follower popularity (log scale)
            $score += min($u->followers_count > 0 ? (int) log($u->followers_count, 2) : 0, 15);

            $u->score = $score;
            $u->reason = $reasons[0] ?? "Popular on ENOM";
            return $u;
        });

        // Sort by score desc and take top N
        $top = $scored->sortByDesc('score')->take($limit)->values();

        $data = $top->map(fn($u) => [
            'id'                => $u->id,
            'name'              => $u->name,
            'username'          => $u->username,
            'bio'               => $u->bio,
            'profile_image_url' => $u->profile_image_url,
            'followers_count'   => $u->followers_count,
            'mutual_count'      => $u->mutual_count,
            'reason'            => $u->reason,
        ]);

        return [
            'data'  => $data,
            'count' => $data->count(),
        ];
    }
}
