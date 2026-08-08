<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\AccessControl\Models\ApiCredential;
use Modules\Project\Contracts\MusicProviderInterface;

/**
 * Background music from the Pixabay audio API (free, commercial use, no
 * attribution required) instead of operator-uploaded files in storage.
 *
 * Templates ask for a CATEGORY (horror, cinematic, relaxing…); the service
 * searches Pixabay, picks a track deterministically per project (re-renders
 * keep the same track), downloads it ONCE into public storage and returns the
 * relative path — so render pipelines keep consuming local files exactly as
 * before and a track is never fetched twice.
 *
 * Keys live in the admin-managed api_credentials pool (provider `pixabay`,
 * multiple keys, default first, automatic failover — same machinery as the
 * YouTube downloader keys). No key / API failure returns null so every
 * caller can fall back to the legacy local library or render silent.
 */
class PixabayMusicService implements MusicProviderInterface
{
    private const ENDPOINT = 'https://pixabay.com/api/audio/';
    private const CACHE_TTL = 21600; // 6h — search results churn slowly
    /** Library namespace: files land in audio/pixabay/{category}/{id}.mp3. */
    private const LIBRARY = 'pixabay';
    private const PER_PAGE = 50;

    /*
     * CATEGORIES and DEFAULT_VOLUME now live on MusicProviderInterface — they
     * are the app's shared vocabulary, not Pixabay's, and Jamendo has to mean
     * the same thing by them. They are still readable as
     * PixabayMusicService::CATEGORIES (inherited), so existing callers are
     * unaffected.
     */

    /**
     * Local-library fallback (storage/app/public/audio/{dir}) for when the
     * Pixabay AUDIO API is unavailable — audio access is approved separately
     * from the regular image key and returns 403 until granted. Categories
     * whose directory name differs are aliased here.
     */
    private const LOCAL_DIR = 'audio';
    private const LOCAL_ALIASES = ['relaxing' => 'calm'];

    private MusicLibraryService $library;

    public function __construct(?MusicLibraryService $library = null)
    {
        $this->library = $library ?? new MusicLibraryService();
    }

    public function isConfigured(): bool
    {
        return ApiCredential::hasActive('pixabay');
    }

    /**
     * Pick and locally cache a track for a category. When the user chose a
     * SPECIFIC track in the create flow ($trackId), that track wins; otherwise
     * the pick is deterministic per project (re-renders keep the same track).
     * Returns the public-disk RELATIVE path, or null when
     * unconfigured/unavailable (callers fall back).
     */
    public function pickTrack(string $category, int $seed = 0, ?string $trackId = null): ?string
    {
        $category = strtolower(trim($category));
        if ($category === '' || $category === 'none') {
            return null;
        }

        $hits = $this->search($category);

        // A user-chosen track takes priority over the seeded pick. The create
        // UI lists the same cached search hits (or the local library), so the
        // id is normally resolvable; a stale id falls through to the seeded pick.
        if ($trackId !== null && $trackId !== '') {
            foreach ($hits as $hit) {
                if ((string) ($hit['id'] ?? '') === (string) $trackId && !empty($hit['audio'])) {
                    $path = $this->download($category, $hit);
                    if ($path !== null) {
                        return $path;
                    }
                    // Download failed (CDN hiccup, unwritable library): a copy
                    // from an earlier render still beats losing the user's pick.
                    break;
                }
            }
            foreach ($this->localTracks($category) as $local) {
                if ($local['id'] === (string) $trackId) {
                    return $local['path'];
                }
            }
            Log::info('PixabayMusicService: chosen track unavailable — using seeded pick', [
                'category' => $category,
                'track_id' => $trackId,
            ]);
        }

        if (!empty($hits)) {
            // Prefer tracks long enough to sit under a whole video but not huge.
            $preferred = array_values(array_filter(
                $hits,
                fn ($hit) => ($hit['duration'] ?? 0) >= 45 && ($hit['duration'] ?? 0) <= 300
            ));
            $pool = !empty($preferred) ? $preferred : $hits;

            usort($pool, fn ($a, $b) => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));

            // Walk on from the seeded hit when one won't download, so a single
            // dead track can't silence the whole video.
            $count = count($pool);
            for ($i = 0; $i < min($count, 3); $i++) {
                $path = $this->download($category, $pool[(abs($seed) + $i) % $count]);
                if ($path !== null) {
                    return $path;
                }
            }
        }

        // Pixabay unavailable (no audio-API access, no key, no results):
        // a seeded pick from the local library keeps music working.
        $locals = $this->localTracks($category);
        if (!empty($locals)) {
            return $locals[abs($seed) % count($locals)]['path'];
        }

        return null;
    }

    /**
     * Browsable tracks for the create UI: the same Pixabay search the render
     * pick draws from (`url` is Pixabay's own stream URL), or the local
     * library when the audio API is unavailable — so what the user auditions
     * is exactly what the render can use.
     *
     * @return array{source: string, tracks: array<int, array{id: string, title: string, duration: int, url: string}>}
     */
    public function browseTracks(string $category): array
    {
        $category = strtolower(trim($category));
        if ($category === '' || $category === 'none' || $category === 'auto') {
            return ['source' => 'none', 'tracks' => []];
        }

        $tracks = [];
        foreach ($this->search($category) as $hit) {
            $url = (string) ($hit['audio'] ?? '');
            if ($url === '') {
                continue;
            }
            $id = (string) ($hit['id'] ?? md5($url));

            // Audition the LOCAL copy when we already have one: same track,
            // but it proves the file a render will use is actually playable.
            $local = $this->library->path(self::LIBRARY, $category, $id);

            $tracks[] = [
                'id' => $id,
                'title' => $this->titleFor($hit),
                'duration' => (int) ($hit['duration'] ?? 0),
                'url' => $local !== null ? Storage::disk('public')->url($local) : $url,
            ];
        }

        if (!empty($tracks)) {
            return ['source' => 'pixabay', 'tracks' => $tracks];
        }

        $locals = array_map(
            fn ($t) => ['id' => $t['id'], 'title' => $t['title'], 'duration' => $t['duration'], 'url' => $t['url']],
            $this->localTracks($category)
        );

        return ['source' => $locals !== [] ? 'local' : 'none', 'tracks' => $locals];
    }

    /**
     * Everything playable for this category WITHOUT the API: tracks already
     * downloaded into audio/pixabay/{category} first (they keep their Pixabay
     * ids, so a user's earlier pick still resolves when the audio API is
     * unreachable), then the operator's own files in audio/{category}.
     *
     * @return array<int, array{id: string, title: string, duration: int, url: string, path: string}>
     */
    private function localTracks(string $category): array
    {
        return array_merge(
            $this->library->cached(self::LIBRARY, $category),
            $this->library->folderTracks(self::LOCAL_DIR . '/' . (self::LOCAL_ALIASES[$category] ?? $category))
        );
    }

    /** Pixabay ships no track title — only tag soup, so make a name from it. */
    private function titleFor(array $hit): string
    {
        $tags = array_values(array_filter(array_map('trim', explode(',', (string) ($hit['tags'] ?? '')))));

        return $tags !== [] ? ucwords(implode(' · ', array_slice($tags, 0, 3))) : 'Untitled track';
    }

    /**
     * Category search with a shared cache and key failover.
     *
     * @return array<int, array> raw Pixabay hits
     */
    private function search(string $category): array
    {
        $cacheKey = "pixabay:audio:{$category}";
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        foreach (ApiCredential::forProvider('pixabay') as $credential) {
            try {
                $response = Http::timeout(30)->get(self::ENDPOINT, [
                    'key' => $credential->credential,
                    'q' => $category,
                    'per_page' => self::PER_PAGE,
                    'safesearch' => 'true',
                ]);

                if (!$response->successful()) {
                    $credential->markFailure("HTTP {$response->status()}: " . substr($response->body(), 0, 200));
                    continue;
                }

                $hits = (array) ($response->json()['hits'] ?? []);
                $credential->markSuccess();

                if (empty($hits)) {
                    Log::info('PixabayMusicService: no tracks for category', ['category' => $category]);
                    // Cache the empty result too — a category without tracks
                    // shouldn't burn a request on every render.
                    Cache::put($cacheKey, [], self::CACHE_TTL);
                    return [];
                }

                Cache::put($cacheKey, $hits, self::CACHE_TTL);

                return $hits;
            } catch (\Throwable $e) {
                $credential->markFailure($e->getMessage());
            }
        }

        Log::warning('PixabayMusicService: search failed on all keys (or none configured)', [
            'category' => $category,
        ]);

        return [];
    }

    /**
     * Download a search hit into the local library once, with what metadata
     * Pixabay gives us, and return the relative path — or null when the bytes
     * could not be stored, which callers treat as "try another track".
     *
     * @param array $hit raw Pixabay hit
     */
    private function download(string $category, array $hit): ?string
    {
        $audioUrl = (string) ($hit['audio'] ?? '');
        if ($audioUrl === '') {
            return null;
        }

        return $this->library->store(
            self::LIBRARY,
            $category,
            (string) ($hit['id'] ?? md5($audioUrl)),
            $audioUrl,
            [
                'title' => $this->titleFor($hit),
                'artist' => (string) ($hit['user'] ?? ''),
                'duration' => (int) ($hit['duration'] ?? 0),
                // Pixabay's own licence — free for commercial use, no
                // attribution required.
                'license' => 'Pixabay Content License',
                'license_url' => 'https://pixabay.com/service/license-summary/',
            ]
        );
    }
}
