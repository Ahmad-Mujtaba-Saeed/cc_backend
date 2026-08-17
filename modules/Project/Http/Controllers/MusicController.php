<?php

namespace Modules\Project\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Project\Contracts\MusicProviderInterface;
use Modules\Project\Services\MusicProviderFactory;
use Modules\Project\Services\UserMusicLibrary;

/**
 * Background-music browsing for the create flows and the storyboard's music
 * panel: the selectable categories and, per category, the same tracks the
 * render pick draws from — so what the user auditions is exactly what can end
 * up under the video.
 *
 * Provider-agnostic: whichever source an admin selected (Pixabay or Jamendo)
 * answers here, so the picker and the renderer can never disagree.
 *
 * The reserved category `custom` is the user's OWN uploads. It is served
 * through the same two endpoints in the same row shape, so the picker needs no
 * second code path — it is simply one more category, whose contents happen to
 * be private to whoever is asking.
 */
class MusicController extends Controller
{
    /** Categories, the active provider, and whether it has a usable key. */
    public function options(): JsonResponse
    {
        $provider = MusicProviderFactory::provider();
        $userId = (int) auth()->id();

        return response()->json([
            'configured' => MusicProviderFactory::make($provider)->isConfigured(),
            'categories' => MusicProviderInterface::CATEGORIES,
            'provider' => $provider,
            'provider_label' => MusicProviderFactory::labels()[$provider] ?? $provider,
            // The private shelf, described so the UI can render its tab and
            // its limits without hardcoding them.
            'custom' => [
                'category' => UserMusicLibrary::CATEGORY,
                'label' => 'My music',
                'count' => UserMusicLibrary::countFor($userId),
                'max' => UserMusicLibrary::MAX_PER_USER,
                'max_kilobytes' => UserMusicLibrary::MAX_KILOBYTES,
                'accept' => '.mp3,.wav,.m4a,.aac,.ogg',
            ],
        ]);
    }

    /** Auditionable tracks for one category, from the active provider. */
    public function tracks(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => [
                'required',
                'string',
                'in:' . implode(',', array_merge(MusicProviderInterface::CATEGORIES, [UserMusicLibrary::CATEGORY])),
            ],
        ]);

        if (UserMusicLibrary::isCustom($data['category'])) {
            return response()->json([
                'success' => true,
                'configured' => true, // your own files need nobody's API key
                'provider' => UserMusicLibrary::CATEGORY,
                'provider_label' => 'My music',
                'category' => UserMusicLibrary::CATEGORY,
                'source' => 'user',
                'tracks' => UserMusicLibrary::listFor((int) auth()->id()),
            ]);
        }

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

    /**
     * Add a track to the signed-in user's own library. Available to every
     * template's music picker from then on, and to nobody else's.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimetypes:' . implode(',', UserMusicLibrary::MIME_TYPES),
                'max:' . UserMusicLibrary::MAX_KILOBYTES,
            ],
            'title' => 'sometimes|nullable|string|max:120',
        ]);

        try {
            $track = UserMusicLibrary::store(
                (int) auth()->id(),
                $request->file('file'),
                $request->input('title')
            );
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => ['track' => $track->toTrack()],
            'message' => 'Added to your music library.',
        ], 201);
    }

    /** Remove one of the signed-in user's own tracks. */
    public function destroyTrack(int $trackId): JsonResponse
    {
        $removed = UserMusicLibrary::remove((int) auth()->id(), $trackId);

        return $removed
            ? response()->json(['success' => true, 'message' => 'Removed from your library.'])
            : response()->json(['success' => false, 'message' => 'Track not found'], 404);
    }
}
