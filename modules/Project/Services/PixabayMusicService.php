<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\AccessControl\Models\ApiCredential;

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
class PixabayMusicService
{
    private const ENDPOINT = 'https://pixabay.com/api/audio/';
    private const CACHE_TTL = 21600; // 6h — search results churn slowly
    private const DOWNLOAD_DIR = 'audio/pixabay';
    private const PER_PAGE = 50;

    /** Search vocabulary exposed to template settings. */
    public const CATEGORIES = [
        'horror', 'cinematic', 'emotional', 'sad', 'happy', 'vlog',
        'technology', 'corporate', 'gaming', 'adventure', 'documentary', 'relaxing',
    ];

    /**
     * Local-library fallback (storage/app/public/audio/{dir}) for when the
     * Pixabay AUDIO API is unavailable — audio access is approved separately
     * from the regular image key and returns 403 until granted. Categories
     * whose directory name differs are aliased here.
     */
    private const LOCAL_DIR = 'audio';
    private const LOCAL_ALIASES = ['relaxing' => 'calm'];

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
                    return $this->download($category, (string) $hit['id'], (string) $hit['audio']);
                }
            }
            foreach ($this->localTracks($category) as $local) {
                if ($local['id'] === (string) $trackId) {
                    return $local['path'];
                }
            }
            Log::info('PixabayMusicService: chosen track no longer listed — using seeded pick', [
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
            $hit = $pool[abs($seed) % count($pool)];

            $audioUrl = (string) ($hit['audio'] ?? '');
            if ($audioUrl !== '') {
                return $this->download($category, (string) ($hit['id'] ?? md5($audioUrl)), $audioUrl);
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
            $tags = array_values(array_filter(array_map('trim', explode(',', (string) ($hit['tags'] ?? '')))));
            $tracks[] = [
                'id' => (string) ($hit['id'] ?? md5($url)),
                'title' => $tags !== [] ? ucwords(implode(' · ', array_slice($tags, 0, 3))) : 'Untitled track',
                'duration' => (int) ($hit['duration'] ?? 0),
                'url' => $url,
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
     * Files under storage/app/public/audio/{category} as picker tracks. Ids
     * are content-free hashes — a chosen id is only ever RESOLVED against
     * this listing, so no user input can name an arbitrary path.
     *
     * @return array<int, array{id: string, title: string, duration: int, url: string, path: string}>
     */
    private function localTracks(string $category): array
    {
        $dir = self::LOCAL_DIR . '/' . (self::LOCAL_ALIASES[$category] ?? $category);
        $tracks = [];

        try {
            foreach (Storage::disk('public')->files($dir) as $file) {
                if (!preg_match('/\.(mp3|wav|m4a)$/i', $file)) {
                    continue;
                }
                $name = pathinfo($file, PATHINFO_FILENAME);
                $title = ucwords(trim((string) preg_replace(
                    '/\s+/',
                    ' ',
                    (string) preg_replace('/\(.*?\)|[-_]+|\d{5,}/', ' ', $name)
                )));
                $tracks[] = [
                    'id' => 'l' . substr(md5($file), 0, 12),
                    'title' => $title !== '' ? $title : $name,
                    'duration' => 0,
                    'url' => Storage::disk('public')->url($file),
                    'path' => $file,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('PixabayMusicService: local library listing failed', ['error' => $e->getMessage()]);
        }

        return $tracks;
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
     * Download a track once into public storage; later picks reuse the file.
     */
    private function download(string $category, string $trackId, string $audioUrl): ?string
    {
        $relative = self::DOWNLOAD_DIR . "/{$category}/{$trackId}.mp3";

        if (Storage::disk('public')->exists($relative)) {
            return $relative;
        }

        try {
            $response = Http::timeout(120)->get($audioUrl);
            if (!$response->successful() || strlen($response->body()) === 0) {
                Log::warning('PixabayMusicService: track download failed', [
                    'url' => $audioUrl,
                    'status' => $response->status(),
                ]);
                return null;
            }

            Storage::disk('public')->put($relative, $response->body());

            Log::info('PixabayMusicService: cached track', [
                'category' => $category,
                'track_id' => $trackId,
                'bytes' => strlen($response->body()),
            ]);

            return $relative;
        } catch (\Throwable $e) {
            Log::warning('PixabayMusicService: track download threw', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
