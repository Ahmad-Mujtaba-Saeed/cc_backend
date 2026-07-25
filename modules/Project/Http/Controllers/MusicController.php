<?php

namespace Modules\Project\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Project\Services\PixabayMusicService;

/**
 * Background-music browsing for the create flows: the selectable categories
 * and, per category, the same Pixabay tracks the render pick draws from —
 * so what the user auditions is exactly what can end up under the video.
 */
class MusicController extends Controller
{
    /** Categories + whether a Pixabay key is configured at all. */
    public function options(): JsonResponse
    {
        return response()->json([
            'configured' => (new PixabayMusicService())->isConfigured(),
            'categories' => PixabayMusicService::CATEGORIES,
        ]);
    }

    /** Auditionable tracks for one category (Pixabay stream URLs). */
    public function tracks(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'in:' . implode(',', PixabayMusicService::CATEGORIES)],
        ]);

        $service = new PixabayMusicService();
        $result = $service->browseTracks($data['category']);

        return response()->json([
            'success' => true,
            'configured' => $service->isConfigured(),
            'category' => $data['category'],
            'source' => $result['source'],
            'tracks' => $result['tracks'],
        ]);
    }
}
