<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\AccessControl\Models\ApiCredential;
use Modules\Project\Contracts\MusicProviderInterface;

/**
 * Background music from the Jamendo API v3.0 — the alternative to Pixabay,
 * selected by an admin via MusicProviderFactory.
 *
 * Auth is the simplest tier Jamendo offers: read-only calls need nothing but
 * a `client_id` GET parameter (OAuth2 is only for acting on a USER's behalf —
 * favourites, playlists — which this app never does). So a Jamendo credential
 * in the api_credentials pool IS the client id, and the multi-key failover
 * works exactly as it does for Pixabay and the YouTube downloaders.
 *
 * Two things Jamendo gives us that Pixabay's audio API does not:
 *   - `vocalinstrumental=instrumental`, so a bed never fights the narration
 *     with its own lyrics;
 *   - `durationbetween`, so we ask for track lengths that can sit under a
 *     whole video instead of filtering after the fact.
 *
 * Tracks are Creative Commons licensed; `license_ccurl` is kept on the cached
 * hit so an attribution surface can read it later without another call.
 */
class JamendoMusicService implements MusicProviderInterface
{
    private const ENDPOINT = 'https://api.jamendo.com/v3.0/tracks/';
    private const CACHE_TTL = 21600; // 6h — matches the Pixabay provider
    private const DOWNLOAD_DIR = 'audio/jamendo';
    private const PER_PAGE = 50;

    /**
     * The app's categories mapped onto Jamendo's own tag vocabulary (genres,
     * instruments and themes). Sent as `fuzzytags` — an OR search that ranks
     * multi-tag matches higher — because a strict AND across three tags
     * routinely returns nothing on a CC catalogue.
     *
     * Several of our categories are not Jamendo tags at all ("vlog",
     * "documentary", "gaming"), which is exactly why this table exists: they
     * are translated into the musical qualities they actually mean.
     *
     * SPACE-separated, and that is not cosmetic. `fuzzytags` silently matches
     * NOTHING when the tags are comma-separated — it returns HTTP 200,
     * status "success" and zero results, so it looks exactly like a category
     * with no music rather than a malformed query. (`tags` tolerates commas,
     * which makes the trap easy to walk into.) Measured: comma form 0 results
     * over 5 tries, space form 20/20. tagsFor() enforces this regardless of
     * how anyone punctuates this table later.
     */
    private const TAG_MAP = [
        'horror' => 'dark horror suspense tension',
        'cinematic' => 'cinematic epic soundtrack orchestral',
        'emotional' => 'emotional touching piano melancholic',
        'sad' => 'sad melancholic slow piano',
        'happy' => 'happy upbeat positive funny',
        'vlog' => 'upbeat acoustic pop positive',
        'technology' => 'electronic futuristic techno minimal',
        'corporate' => 'corporate motivational inspiring positive',
        'gaming' => 'chiptune electronic energetic rock',
        'adventure' => 'adventure epic cinematic orchestral',
        'documentary' => 'ambient documentary piano atmospheric',
        'relaxing' => 'relaxing calm ambient chillout',
    ];

    /**
     * How long an EMPTY result is cached. Jamendo intermittently answers a
     * perfectly valid query with zero results — HTTP 200, status "success",
     * code 0, no warning, no rate-limit header — roughly one request in six.
     * An empty response is therefore NOT trustworthy evidence that a category
     * has no music, so it must never be cached for the full TTL: doing so let
     * one flaky response black out a category for six hours, which is exactly
     * how this shipped and exactly what "No tracks available" meant.
     */
    private const EMPTY_CACHE_TTL = 300; // 5 min

    /** Retries for that flake, before an empty answer is believed at all. */
    private const EMPTY_RETRIES = 2;

    /** Category -> the space-separated fuzzytags string Jamendo needs. */
    private static function tagsFor(string $category): string
    {
        $raw = self::TAG_MAP[$category] ?? $category;

        // Commas (and any other punctuation someone edits in) collapse to the
        // single separator fuzzytags actually understands.
        return trim((string) preg_replace('/[^a-z0-9]+/i', ' ', $raw));
    }

    /** Local-library fallback, identical to the Pixabay provider's. */
    private const LOCAL_DIR = 'audio';
    private const LOCAL_ALIASES = ['relaxing' => 'calm'];

    public function isConfigured(): bool
    {
        return ApiCredential::hasActive('jamendo');
    }

    public function pickTrack(string $category, int $seed = 0, ?string $trackId = null): ?string
    {
        $category = strtolower(trim($category));
        if ($category === '' || $category === 'none') {
            return null;
        }

        $hits = $this->search($category);

        // A user-chosen track wins while it still resolves; a stale id (the
        // catalogue moved on, or the admin switched provider after the pick)
        // falls through to the seeded pick rather than failing the render.
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
            Log::info('JamendoMusicService: chosen track no longer listed — using seeded pick', [
                'category' => $category,
                'track_id' => $trackId,
            ]);
        }

        if (!empty($hits)) {
            // The search already asked for usable lengths; sorting by id keeps
            // the pick stable across cache refreshes for the same project.
            usort($hits, fn ($a, $b) => ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0)));
            $hit = $hits[abs($seed) % count($hits)];

            $audioUrl = (string) ($hit['audio'] ?? '');
            if ($audioUrl !== '') {
                return $this->download($category, (string) ($hit['id'] ?? md5($audioUrl)), $audioUrl);
            }
        }

        $locals = $this->localTracks($category);
        if (!empty($locals)) {
            return $locals[abs($seed) % count($locals)]['path'];
        }

        return null;
    }

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
            // Jamendo names its tracks properly, so unlike Pixabay's tag soup
            // there is a real title to show — with the artist, which the CC
            // licence makes it good manners to credit.
            $name = trim((string) ($hit['name'] ?? ''));
            $artist = trim((string) ($hit['artist_name'] ?? ''));
            $title = $name !== '' ? $name : 'Untitled track';
            if ($artist !== '') {
                $title .= ' — ' . $artist;
            }

            $tracks[] = [
                'id' => (string) ($hit['id'] ?? md5($url)),
                'title' => $title,
                'duration' => (int) ($hit['duration'] ?? 0),
                'url' => $url,
            ];
        }

        if (!empty($tracks)) {
            return ['source' => 'jamendo', 'tracks' => $tracks];
        }

        $locals = array_map(
            fn ($t) => ['id' => $t['id'], 'title' => $t['title'], 'duration' => $t['duration'], 'url' => $t['url']],
            $this->localTracks($category)
        );

        return ['source' => $locals !== [] ? 'local' : 'none', 'tracks' => $locals];
    }

    /**
     * Files under storage/app/public/audio/{category}. Ids are content-free
     * hashes and a chosen id is only ever RESOLVED against this listing, so no
     * user input can name an arbitrary path.
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
            Log::warning('JamendoMusicService: local library listing failed', ['error' => $e->getMessage()]);
        }

        return $tracks;
    }

    /**
     * Category search with a shared cache and client-id failover.
     *
     * Jamendo answers 200 OK even for errors — the real outcome is in
     * `headers.status` / `headers.code` (a suspended application returns
     * code 11 with an HTTP 200). Treating a non-success body as a key failure
     * is what makes failover work at all here.
     *
     * @return array<int, array> raw Jamendo result rows
     */
    private function search(string $category): array
    {
        $cacheKey = "jamendo:audio:{$category}";
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $tags = self::tagsFor($category);

        foreach (ApiCredential::forProvider('jamendo') as $credential) {
            for ($attempt = 0; $attempt <= self::EMPTY_RETRIES; $attempt++) {
                try {
                    $response = Http::timeout(30)->get(self::ENDPOINT, [
                        'client_id' => $credential->credential,
                        'format' => 'json',
                        'limit' => self::PER_PAGE,
                        'fuzzytags' => $tags,
                        // A bed with lyrics fights the voice-over for the same
                        // frequencies and the same attention.
                        'vocalinstrumental' => 'instrumental',
                        // Long enough to sit under a whole video, short enough not
                        // to download 20MB for a 90-second explainer.
                        'durationbetween' => '45_300',
                        'audioformat' => 'mp32',
                        'order' => 'popularity_total_desc',
                        'include' => 'licenses',
                    ]);

                    if (!$response->successful()) {
                        $credential->markFailure("HTTP {$response->status()}: " . substr($response->body(), 0, 200));
                        break; // next credential
                    }

                $body = $response->json();
                $status = (string) ($body['headers']['status'] ?? '');
                if ($status !== 'success') {
                    $credential->markFailure(sprintf(
                        'Jamendo error %s: %s',
                        (string) ($body['headers']['code'] ?? '?'),
                        (string) ($body['headers']['error_message'] ?? 'unknown')
                    ));
                    break; // next credential
                }

                $results = (array) ($body['results'] ?? []);
                $credential->markSuccess();

                if (empty($results)) {
                    // Not necessarily a real miss — see EMPTY_CACHE_TTL. Ask
                    // again before believing it; the flake does not repeat.
                    if ($attempt < self::EMPTY_RETRIES) {
                        usleep(300000);
                        continue;
                    }

                    Log::info('JamendoMusicService: no tracks for category after retries', [
                        'category' => $category,
                        'tags' => $tags,
                    ]);
                    // Short TTL only: enough to stop a render loop hammering
                    // the API, short enough that a flaky empty heals itself.
                    Cache::put($cacheKey, [], self::EMPTY_CACHE_TTL);

                    return [];
                }

                Cache::put($cacheKey, $results, self::CACHE_TTL);

                return $results;
            } catch (\Throwable $e) {
                $credential->markFailure($e->getMessage());
                break; // next credential
            }
            }
        }

        Log::warning('JamendoMusicService: search failed on all client ids (or none configured)', [
            'category' => $category,
        ]);

        return [];
    }

    /** Download a track once into public storage; later picks reuse the file. */
    private function download(string $category, string $trackId, string $audioUrl): ?string
    {
        $relative = self::DOWNLOAD_DIR . "/{$category}/{$trackId}.mp3";

        if (Storage::disk('public')->exists($relative)) {
            return $relative;
        }

        try {
            $response = Http::timeout(120)->get($audioUrl);
            if (!$response->successful() || strlen($response->body()) === 0) {
                Log::warning('JamendoMusicService: track download failed', [
                    'url' => $audioUrl,
                    'status' => $response->status(),
                ]);

                return null;
            }

            Storage::disk('public')->put($relative, $response->body());

            Log::info('JamendoMusicService: cached track', [
                'category' => $category,
                'track_id' => $trackId,
                'bytes' => strlen($response->body()),
            ]);

            return $relative;
        } catch (\Throwable $e) {
            Log::warning('JamendoMusicService: track download threw', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
