<?php

namespace Modules\Project\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Project\Contracts\MusicProviderInterface;
use Modules\Project\Services\MusicProviderFactory;

/**
 * Background-music browsing for the create flows and the storyboard's music
 * panel: the selectable categories and, per category, the same tracks the
 * render pick draws from — so what the user auditions is exactly what can end
 * up under the video.
 *
 * Provider-agnostic: whichever source an admin selected (Pixabay or Jamendo)
 * answers here, so the picker and the renderer can never disagree.
 */
class MusicController extends Controller
{
    /** Categories, the active provider, and whether it has a usable key. */
    public function options(): JsonResponse
    {
        $provider = MusicProviderFactory::provider();

        return response()->json([
            'configured' => MusicProviderFactory::make($provider)->isConfigured(),
            'categories' => MusicProviderInterface::CATEGORIES,
            'provider' => $provider,
            'provider_label' => MusicProviderFactory::labels()[$provider] ?? $provider,
        ]);
    }

    /** Auditionable tracks for one category, from the active provider. */
    public function tracks(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'in:' . implode(',', MusicProviderInterface::CATEGORIES)],
        ]);

        $providerName = MusicProviderFactory::provider();
        $service = MusicProviderFactory::make($providerName);
        $result = $service->browseTracks($data['category']);

        return response()->json([
            'success' => true,
            'configured' => $service->isConfigured(),
            'provider' => $providerName,
            'provider_label' => MusicProviderFactory::labels()[$providerName] ?? $providerName,
            'category' => $data['category'],
            'source' => $result['source'],
            'tracks' => $result['tracks'],
        ]);
    }
}
