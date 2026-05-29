<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class PlaceController extends Controller
{
    private const CACHE_TTL = 3600; // 1 hour - saves Google API costs

    // ─────────────────────────────────────────
    // PLACES AUTOCOMPLETE / SEARCH
    // ─────────────────────────────────────────
    #[OA\Get(
        path: '/api/places/search',
        operationId: 'searchPlaces',
        summary: 'Search places for location tagging (Google Places API)',
        description: 'Returns location suggestions for post location tagging. Uses Google Places API server-side. Pass optional lat/lng to bias results to nearby places.',
        tags: ['Places'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'q', in: 'query', required: true, schema: new OA\Schema(type: 'string'), description: 'Search query (place name)')]
    #[OA\Parameter(name: 'lat', in: 'query', required: false, schema: new OA\Schema(type: 'number'), description: 'Optional latitude to bias results')]
    #[OA\Parameter(name: 'lng', in: 'query', required: false, schema: new OA\Schema(type: 'number'), description: 'Optional longitude to bias results')]
    #[OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10, maximum: 20))]
    #[OA\Response(response: 200, description: 'List of place suggestions',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'place_id',  type: 'string',  example: 'ChIJN1t_tDeuEmsRUsoyG83frY4'),
                new OA\Property(property: 'name',      type: 'string',  example: 'Central Park'),
                new OA\Property(property: 'address',   type: 'string',  example: 'New York, NY, USA'),
                new OA\Property(property: 'latitude',  type: 'number',  example: 40.785),
                new OA\Property(property: 'longitude', type: 'number',  example: -73.968),
            ], type: 'object')),
        ])
    )]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 422, description: 'Validation error')]
    #[OA\Response(response: 503, description: 'Places API unavailable')]
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q'     => ['required', 'string', 'min:1', 'max:200'],
            'lat'   => ['nullable', 'numeric', 'between:-90,90'],
            'lng'   => ['nullable', 'numeric', 'between:-180,180'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $query = trim($request->input('q'));
        $lat   = $request->input('lat');
        $lng   = $request->input('lng');
        $limit = min((int) $request->input('limit', 10), 20);

        // Empty query - return empty list
        if ($query === '') {
            return response()->json(['data' => []]);
        }

        // Cache key based on query + location
        $cacheKey = 'places:' . md5(strtolower($query) . ':' . round($lat ?? 0, 2) . ':' . round($lng ?? 0, 2));

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json(['data' => array_slice($cached, 0, $limit)]);
        }

        $apiKey = config('services.google_places.key');
        if (empty($apiKey)) {
            Log::error('Google Places API key not configured');
            return response()->json([
                'error'   => 'PLACES_API_NOT_CONFIGURED',
                'message' => 'Places search is temporarily unavailable.',
            ], 503);
        }

        try {
            $params = [
                'input'  => $query,
                'key'    => $apiKey,
                'types'  => 'geocode|establishment',
            ];

            // Bias results to user's location if provided
            if ($lat !== null && $lng !== null) {
                $params['location']    = "{$lat},{$lng}";
                $params['radius']      = 50000; // 50km radius
            }

            // Step 1: Autocomplete predictions
            $response = Http::timeout(5)->get(
                'https://maps.googleapis.com/maps/api/place/autocomplete/json',
                $params
            );

            if (!$response->successful()) {
                Log::error('Google Places autocomplete failed', ['status' => $response->status()]);
                return response()->json([
                    'error'   => 'PLACES_API_ERROR',
                    'message' => 'Places search failed. Please try again.',
                ], 503);
            }

            $body = $response->json();
            if (($body['status'] ?? '') !== 'OK' && ($body['status'] ?? '') !== 'ZERO_RESULTS') {
                Log::error('Google Places API error', ['status' => $body['status'] ?? 'unknown', 'message' => $body['error_message'] ?? '']);
                return response()->json(['data' => []]);
            }

            $predictions = $body['predictions'] ?? [];

            // Step 2: Get lat/lng for each prediction using Place Details
            $results = [];
            foreach (array_slice($predictions, 0, $limit) as $prediction) {
                $details = $this->getPlaceDetails($prediction['place_id'], $apiKey);
                if ($details) {
                    $results[] = [
                        'place_id'  => $prediction['place_id'],
                        'name'      => $prediction['structured_formatting']['main_text'] ?? $prediction['description'],
                        'address'   => $prediction['structured_formatting']['secondary_text']
                                       ?? $prediction['description'],
                        'latitude'  => $details['lat'],
                        'longitude' => $details['lng'],
                    ];
                }
            }

            // Cache for 1 hour to save API costs
            Cache::put($cacheKey, $results, self::CACHE_TTL);

            return response()->json(['data' => $results]);

        } catch (\Exception $e) {
            Log::error('Places search exception: ' . $e->getMessage());
            return response()->json([
                'error'   => 'PLACES_API_ERROR',
                'message' => 'Places search failed. Please try again.',
            ], 503);
        }
    }

    // ─────────────────────────────────────────
    // PLACE DETAILS (lat/lng for a place_id)
    // ─────────────────────────────────────────
    private function getPlaceDetails(string $placeId, string $apiKey): ?array
    {
        // Cache individual place details for 24 hours (rarely change)
        $cacheKey = 'place_details:' . $placeId;

        return Cache::remember($cacheKey, 86400, function () use ($placeId, $apiKey) {
            try {
                $response = Http::timeout(5)->get(
                    'https://maps.googleapis.com/maps/api/place/details/json',
                    [
                        'place_id' => $placeId,
                        'fields'   => 'geometry/location',
                        'key'      => $apiKey,
                    ]
                );

                if (!$response->successful()) {
                    return null;
                }

                $body = $response->json();
                if (($body['status'] ?? '') !== 'OK') {
                    return null;
                }

                $location = $body['result']['geometry']['location'] ?? null;
                if (!$location) {
                    return null;
                }

                return [
                    'lat' => (float) $location['lat'],
                    'lng' => (float) $location['lng'],
                ];
            } catch (\Exception $e) {
                Log::warning("Place details fetch failed for {$placeId}: {$e->getMessage()}");
                return null;
            }
        });
    }
}
