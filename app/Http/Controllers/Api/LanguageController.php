<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Language;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

class LanguageController extends Controller
{
    #[OA\Get(
        path: '/api/languages',
        operationId: 'listLanguages',
        summary: 'Get all supported languages',
        tags: ['Languages']
    )]
    #[OA\Parameter(name: 'region', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'List of languages')]
    public function index(Request $request): JsonResponse
    {
        $region = $request->input('region');
        $cacheKey = 'languages:all' . ($region ? ':' . $region : '');

        $languages = Cache::remember($cacheKey, 3600, function () use ($region) {
            $query = Language::where('is_active', true)->orderBy('sort_order');
            if ($region) {
                $query->where('region', $region);
            }
            return $query->get()->map(fn($lang) => [
                'code'       => $lang->code,
                'name'       => $lang->name,
                'nativeName' => $lang->native_name,
                'flag'       => $lang->flag,
                'region'     => $lang->region,
                'isRTL'      => $lang->is_rtl,
            ]);
        });

        return response()->json(['languages' => $languages]);
    }

    #[OA\Get(
        path: '/api/languages/regions',
        operationId: 'listLanguageRegions',
        summary: 'Get all language regions',
        tags: ['Languages']
    )]
    #[OA\Response(response: 200, description: 'List of regions')]
    public function regions(): JsonResponse
    {
        $regions = Cache::remember('languages:regions', 3600, function () {
            return Language::where('is_active', true)
                ->whereNotNull('region')
                ->distinct()
                ->pluck('region')
                ->sort()
                ->values();
        });

        return response()->json(['regions' => $regions]);
    }
}
