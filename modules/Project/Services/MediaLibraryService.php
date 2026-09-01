<?php

namespace Modules\Project\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\AccessControl\Models\ApiCredential;

/**
 * MediaLibraryService — one search box over every FREE media source.
 *
 * {@see StockFootageService} exists for the render pipeline's automatic
 * b-roll: it takes a query, picks the single best clip and caches it. This
 * service is the HUMAN-facing counterpart. It searches several providers at
 * once and hands back a normalized, ranked list of CANDIDATES so the
 * storyboard can show "here are pictures that would work for this scene" —
 * beside, never instead of, "Generate with AI" and "Upload".
 *
 * Two of the five providers need no API key at all (Openverse and Wikimedia
 * Commons), so the recommendation strip works on a fresh install; the other
 * three light up the moment an admin adds a key to the `api_credentials`
 * pool.
 *
 * THE PROVIDERS ARE QUERIED CONCURRENTLY, and that is not an optimisation —
 * it is what makes the feature usable. These are free public APIs on the far
 * side of the world: measured from the dev machine, Pexels answers in ~400ms,
 * Openverse in ~600ms but occasionally hangs for 25s, and Wikimedia Commons
 * does not resolve at all from some networks. Run in sequence that is a
 * thirty-second panel; run in a pool it is as slow as the slowest provider
 * and a dead one costs only its connect timeout. Every failure is contained:
 * a provider that errors, times out or returns junk contributes no results
 * and never breaks the panel.
 *
 * Every candidate carries its attribution, because these licences require it
 * and the user needs to know what they are agreeing to before they click.
 */
class MediaLibraryService
{
    /**
     * The provider table. `kinds` is what the provider can actually return,
     * `credential` is the api_credentials provider name (null = keyless).
     */
    public const PROVIDERS = [
        'pexels' => [
            'label' => 'Pexels',
            'kinds' => ['image', 'video'],
            'credential' => 'pexels',
            'license' => 'Pexels License — free to use, no attribution required',
            'attribution_required' => false,
        ],
        'pixabay' => [
            'label' => 'Pixabay',
            'kinds' => ['image', 'video'],
            'credential' => 'pixabay',
            'license' => 'Pixabay Content License — free to use, no attribution required',
            'attribution_required' => false,
        ],
        'unsplash' => [
            'label' => 'Unsplash',
            'kinds' => ['image'],
            'credential' => 'unsplash',
            'license' => 'Unsplash License — free to use, credit appreciated',
            'attribution_required' => false,
        ],
        'openverse' => [
            'label' => 'Openverse',
            'kinds' => ['image'],
            'credential' => null,
            'license' => 'Creative Commons — check each item, most require credit',
            'attribution_required' => true,
        ],
        'wikimedia' => [
            'label' => 'Wikimedia Commons',
            'kinds' => ['image'],
            'credential' => null,
            'license' => 'Creative Commons / public domain — credit the author',
            'attribution_required' => true,
        ],
    ];

    /** How long a search result list is reused. Queries repeat constantly
     *  (every open of the same slot), and these are free-tier APIs. */
    private const CACHE_MINUTES = 360;

    private const PER_PROVIDER = 8;

    /** A provider that has not connected by now is not going to. */
    private const CONNECT_TIMEOUT = 4;

    /** ...and one that has not answered by now is holding up the panel. */
    private const REQUEST_TIMEOUT = 12;

    /**
     * How long a provider that just failed is left out of the pool.
     *
     * Without this, an unreachable provider is re-dialled on every single
     * search and every one of them pays its full connect timeout — four
     * seconds added to each keystroke's worth of results, forever. Five
     * minutes is short enough that a provider coming back is picked up almost
     * immediately, and long enough that a user searching a slot never waits
     * on the same corpse twice.
     */
    private const DOWN_MINUTES = 5;

    /**
     * Which providers can serve this kind right now, with their state, for
     * the UI's filter row and for the admin settings page.
     *
     * @return array<int, array>
     */
    public function providers(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $name => $meta) {
            $out[] = [
                'name' => $name,
                'label' => $meta['label'],
                'kinds' => $meta['kinds'],
                'needs_key' => $meta['credential'] !== null,
                'configured' => $this->isConfigured($name),
                // A configured provider that is sitting out a cooldown is not
                // broken and not working — the UI says "unavailable right now"
                // rather than silently returning fewer results.
                'cooling_down' => $this->isDown($name),
                'license' => $meta['license'],
                'attribution_required' => $meta['attribution_required'],
            ];
        }

        return $out;
    }

    public function isConfigured(string $provider): bool
    {
        $meta = self::PROVIDERS[$provider] ?? null;
        if ($meta === null) {
            return false;
        }
        // Keyless providers are always available.
        return $meta['credential'] === null || ApiCredential::hasActive($meta['credential']);
    }

    /** True when ANY provider can answer — the UI hides the panel otherwise. */
    public function isAvailable(): bool
    {
        foreach (array_keys(self::PROVIDERS) as $name) {
            if ($this->isConfigured($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Search every capable provider concurrently and interleave the results.
     *
     * Interleaving matters: concatenating would put eight Pexels shots before
     * the first Openverse one, and the top row is the only row most people
     * look at. Round-robin means that row is one good hit from each source.
     *
     * @param  string  $kind         'image' | 'video'
     * @param  string  $orientation  'landscape' | 'portrait' | 'square'
     * @param  string[]  $only       Restrict to these providers (empty = all)
     * @return array<int, array>     Normalized candidates
     */
    public function search(
        string $query,
        string $kind = 'image',
        string $orientation = 'landscape',
        array $only = [],
        int $limit = 24
    ): array {
        $query = trim(mb_substr(preg_replace('/\s+/u', ' ', $query) ?? '', 0, 80));
        if ($query === '') {
            return [];
        }
        $kind = $kind === 'video' ? 'video' : 'image';
        $orientation = in_array($orientation, ['landscape', 'portrait', 'square'], true) ? $orientation : 'landscape';

        $wanted = [];
        foreach (self::PROVIDERS as $name => $meta) {
            if (!in_array($kind, $meta['kinds'], true)) {
                continue;
            }
            if ($only !== [] && !in_array($name, $only, true)) {
                continue;
            }
            if (!$this->isConfigured($name)) {
                continue;
            }
            $wanted[] = $name;
        }

        // Cached providers cost nothing and never enter the pool; a provider
        // that just failed is skipped entirely until its cooldown expires.
        $buckets = [];
        $toFetch = [];
        foreach ($wanted as $name) {
            $cached = Cache::get($this->cacheKey($name, $query, $kind, $orientation));
            if (is_array($cached)) {
                $buckets[$name] = $cached;
            } elseif (!$this->isDown($name)) {
                $toFetch[] = $name;
            }
        }

        foreach ($this->fetchConcurrently($toFetch, $query, $kind, $orientation) as $name => $hits) {
            $buckets[$name] = $hits;
        }

        // Restore the declared provider order so the interleave is stable
        // whatever the pool returned first.
        $ordered = [];
        foreach ($wanted as $name) {
            if (!empty($buckets[$name])) {
                $ordered[] = $buckets[$name];
            }
        }

        return $this->interleave($ordered, $limit);
    }

    /**
     * Download a chosen candidate into the public disk.
     *
     * @param  array  $candidate  A candidate from {@see search()}
     * @return string|null        public-disk relative path
     */
    public function download(array $candidate, string $relative): ?string
    {
        $url = trim((string) ($candidate['download_url'] ?? $candidate['url'] ?? ''));
        if ($url === '' || !preg_match('#^https://#i', $url)) {
            return null;
        }

        $abs = Storage::disk('public')->path($relative);
        @mkdir(dirname($abs), 0775, true);
        $tmp = $abs . '.part';

        // The sink is OUR handle, and we close it before looking at the file.
        // Handing Guzzle a path instead leaves the stream open and unflushed
        // when the call returns: the size read back was 0, and the bytes only
        // appeared on disk later — which shipped an empty file into the slot
        // and reported success.
        $handle = @fopen($tmp, 'w');
        if ($handle === false) {
            Log::warning('MediaLibraryService: cannot open a temp file', ['path' => $relative]);

            return null;
        }

        try {
            $response = Http::timeout(180)
                ->withHeaders(['User-Agent' => $this->userAgent()])
                ->withOptions(['sink' => $handle])
                ->get($url);

            fclose($handle);
            clearstatcache(true, $tmp);
            $bytes = is_file($tmp) ? (int) filesize($tmp) : 0;

            if (!$response->successful() || $bytes < 2048) {
                @unlink($tmp);
                Log::info('MediaLibraryService: download rejected', [
                    'url' => $url,
                    'status' => $response->status(),
                    'bytes' => $bytes,
                ]);

                return null;
            }

            if (!@rename($tmp, $abs)) {
                @unlink($tmp);
                Log::warning('MediaLibraryService: could not move the download into place', ['path' => $relative]);

                return null;
            }

            CostTracker::recordExternal(
                'media_library',
                ($candidate['provider'] ?? 'unknown') . '_download',
                1,
                'files',
                0.0,
                ['bytes' => $bytes, 'kind' => $candidate['kind'] ?? 'image']
            );

            return $relative;
        } catch (\Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($tmp);
            Log::warning('MediaLibraryService: download failed: ' . $e->getMessage(), ['url' => $url]);

            return null;
        }
    }

    // ---------------------------------------------------------------- search

    /**
     * One HTTP round for every provider at once.
     *
     * A keyed provider enters the pool with its DEFAULT credential only. If
     * that key is rejected the provider is retried sequentially down the rest
     * of its pool — the rare path, and the one place failover belongs.
     *
     * @param  string[]  $providers
     * @return array<string, array>  provider => candidates
     */
    private function fetchConcurrently(array $providers, string $query, string $kind, string $orientation): array
    {
        if ($providers === []) {
            return [];
        }

        $specs = [];
        foreach ($providers as $name) {
            $credential = $this->defaultCredential($name);
            $spec = $this->requestSpec($name, $query, $kind, $orientation, $credential?->credential);
            if ($spec !== null) {
                $specs[$name] = $spec + ['credential' => $credential];
            }
        }
        if ($specs === []) {
            return [];
        }

        try {
            $responses = Http::pool(function (Pool $pool) use ($specs) {
                $requests = [];
                foreach ($specs as $name => $spec) {
                    $requests[] = $pool->as($name)
                        ->connectTimeout(self::CONNECT_TIMEOUT)
                        ->timeout(self::REQUEST_TIMEOUT)
                        ->withHeaders($spec['headers'] + ['User-Agent' => $this->userAgent()])
                        ->get($spec['url'], $spec['query']);
                }

                return $requests;
            });
        } catch (\Throwable $e) {
            Log::warning('MediaLibraryService: pool failed: ' . $e->getMessage());

            return [];
        }

        $out = [];
        foreach ($specs as $name => $spec) {
            $response = $responses[$name] ?? null;
            $credential = $spec['credential'];

            if (!$response instanceof Response) {
                // A connection error arrives as the exception itself.
                $reason = $response instanceof \Throwable ? $response->getMessage() : 'no response';
                Log::info("MediaLibraryService: {$name} unreachable — " . mb_substr($reason, 0, 120));
                $credential?->markFailure(mb_substr($reason, 0, 190));
                $this->markDown($name);
                continue;
            }

            if (!$response->successful()) {
                $credential?->markFailure("HTTP {$response->status()}");
                Log::info("MediaLibraryService: {$name} HTTP {$response->status()}");
                // A rate limit or an upstream fault is worth sitting out; a
                // rejected key is handled by the failover below instead.
                if (!in_array($response->status(), [401, 403], true)) {
                    $this->markDown($name);
                }
                // A rejected key is worth another try on the next key; an
                // upstream 5xx or a rate limit is not.
                if ($credential !== null && in_array($response->status(), [401, 403], true)) {
                    $hits = $this->retryWithOtherCredentials($name, $query, $kind, $orientation, $credential->id);
                    if ($hits !== []) {
                        $out[$name] = $hits;
                    }
                }
                continue;
            }

            $credential?->markSuccess();
            $this->clearDown($name);
            CostTracker::recordExternal('media_library', $name . '_' . $kind . '_search');

            $hits = $this->rank($this->parse($name, $kind, (array) $response->json(), $orientation));
            $out[$name] = $hits;
            // Only a real answer is cached. An outage — or an empty page from
            // a provider having a bad minute — must never be frozen in for six
            // hours: an earlier build cached the empty list from a timeout and
            // the provider then stayed "broken" long after it recovered.
            if ($hits !== []) {
                Cache::put($this->cacheKey($name, $query, $kind, $orientation), $hits, now()->addMinutes(self::CACHE_MINUTES));
            }
        }

        return $out;
    }

    /** Walk the rest of a provider's key pool after the default was refused. */
    private function retryWithOtherCredentials(string $provider, string $query, string $kind, string $orientation, int $skipId): array
    {
        foreach ($this->credentials($provider) as $credential) {
            if ($credential->id === $skipId) {
                continue;
            }
            $spec = $this->requestSpec($provider, $query, $kind, $orientation, $credential->credential);
            if ($spec === null) {
                continue;
            }
            try {
                $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
                    ->timeout(self::REQUEST_TIMEOUT)
                    ->withHeaders($spec['headers'] + ['User-Agent' => $this->userAgent()])
                    ->get($spec['url'], $spec['query']);
                if (!$response->successful()) {
                    $credential->markFailure("HTTP {$response->status()}");
                    continue;
                }
                $credential->markSuccess();
                CostTracker::recordExternal('media_library', $provider . '_' . $kind . '_search');

                $hits = $this->rank($this->parse($provider, $kind, (array) $response->json(), $orientation));
                if ($hits !== []) {
                    Cache::put($this->cacheKey($provider, $query, $kind, $orientation), $hits, now()->addMinutes(self::CACHE_MINUTES));
                }

                return $hits;
            } catch (\Throwable $e) {
                $credential->markFailure(mb_substr($e->getMessage(), 0, 190));
            }
        }

        return [];
    }

    private function cacheKey(string $provider, string $query, string $kind, string $orientation): string
    {
        return 'media:' . $provider . ':' . $kind . ':' . $orientation . ':' . md5(mb_strtolower($query));
    }

    private function downKey(string $provider): string
    {
        return 'media:down:' . $provider;
    }

    /** Is this provider sitting out its cooldown? */
    public function isDown(string $provider): bool
    {
        try {
            return Cache::get($this->downKey($provider)) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function markDown(string $provider): void
    {
        try {
            Cache::put($this->downKey($provider), true, now()->addMinutes(self::DOWN_MINUTES));
        } catch (\Throwable) {
            // A cache backend problem must not escalate into a failed search.
        }
    }

    private function clearDown(string $provider): void
    {
        try {
            Cache::forget($this->downKey($provider));
        } catch (\Throwable) {
        }
    }

    /** The key a pooled request uses (null for a keyless provider). */
    private function defaultCredential(string $provider): ?ApiCredential
    {
        return $this->credentials($provider)->first();
    }

    /** @return \Illuminate\Support\Collection<int, ApiCredential> */
    private function credentials(string $provider)
    {
        $name = self::PROVIDERS[$provider]['credential'] ?? null;

        return $name === null
            ? collect()
            : ApiCredential::forProvider($name);
    }

    /**
     * The HTTP call for one provider, as data — so the same definition serves
     * the concurrent pool and the sequential failover retry.
     *
     * @return array{url: string, query: array, headers: array}|null
     */
    private function requestSpec(string $provider, string $query, string $kind, string $orientation, ?string $key): ?array
    {
        $perPage = self::PER_PROVIDER * 2;

        return match (true) {
            $provider === 'pexels' && $kind === 'video' => $key === null ? null : [
                'url' => 'https://api.pexels.com/videos/search',
                'query' => ['query' => $query, 'orientation' => $orientation, 'per_page' => $perPage, 'size' => 'medium'],
                'headers' => ['Authorization' => $key],
            ],
            $provider === 'pexels' => $key === null ? null : [
                'url' => 'https://api.pexels.com/v1/search',
                'query' => ['query' => $query, 'orientation' => $orientation, 'per_page' => $perPage],
                'headers' => ['Authorization' => $key],
            ],
            $provider === 'pixabay' && $kind === 'video' => $key === null ? null : [
                'url' => 'https://pixabay.com/api/videos/',
                'query' => ['key' => $key, 'q' => $query, 'per_page' => $perPage, 'safesearch' => 'true'],
                'headers' => [],
            ],
            $provider === 'pixabay' => $key === null ? null : [
                'url' => 'https://pixabay.com/api/',
                'query' => [
                    'key' => $key,
                    'q' => $query,
                    'image_type' => 'photo',
                    'orientation' => $orientation === 'portrait' ? 'vertical' : ($orientation === 'landscape' ? 'horizontal' : 'all'),
                    'per_page' => $perPage,
                    'safesearch' => 'true',
                ],
                'headers' => [],
            ],
            $provider === 'unsplash' => $key === null ? null : [
                'url' => 'https://api.unsplash.com/search/photos',
                'query' => [
                    'query' => $query,
                    'orientation' => $orientation === 'portrait' ? 'portrait' : ($orientation === 'square' ? 'squarish' : 'landscape'),
                    'per_page' => $perPage,
                    'content_filter' => 'high',
                ],
                'headers' => ['Authorization' => 'Client-ID ' . $key],
            ],
            $provider === 'openverse' => [
                'url' => 'https://api.openverse.org/v1/images/',
                'query' => [
                    'q' => $query,
                    'page_size' => $perPage,
                    // Commercial + modifiable keeps the results usable in a
                    // video someone may monetise, which is what this is for.
                    'license_type' => 'commercial,modification',
                    'mature' => 'false',
                ],
                'headers' => [],
            ],
            $provider === 'wikimedia' => [
                'url' => 'https://commons.wikimedia.org/w/api.php',
                'query' => [
                    'action' => 'query',
                    'format' => 'json',
                    'generator' => 'search',
                    'gsrsearch' => 'filetype:bitmap ' . $query,
                    'gsrnamespace' => 6,
                    'gsrlimit' => $perPage,
                    'prop' => 'imageinfo',
                    'iiprop' => 'url|size|extmetadata',
                    'iiurlwidth' => 480,
                ],
                'headers' => [],
            ],
            default => null,
        };
    }

    /**
     * Turn one provider's JSON into normalized candidates.
     *
     * @return array<int, array>
     */
    private function parse(string $provider, string $kind, array $json, string $orientation): array
    {
        return match (true) {
            $provider === 'pexels' && $kind === 'video' => $this->parsePexelsVideos($json, $orientation),
            $provider === 'pexels' => $this->parsePexelsPhotos($json, $orientation),
            $provider === 'pixabay' && $kind === 'video' => $this->parsePixabayVideos($json, $orientation),
            $provider === 'pixabay' => $this->parsePixabayPhotos($json, $orientation),
            $provider === 'unsplash' => $this->parseUnsplash($json, $orientation),
            $provider === 'openverse' => $this->parseOpenverse($json, $orientation),
            $provider === 'wikimedia' => $this->parseWikimedia($json, $orientation),
            default => [],
        };
    }

    /** Sort by score and keep the provider's share. */
    private function rank(array $hits): array
    {
        usort($hits, fn ($a, $b) => ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0));

        return array_slice($hits, 0, self::PER_PROVIDER);
    }

    /** Round-robin the per-provider lists into one ranked list. */
    private function interleave(array $buckets, int $limit): array
    {
        if ($buckets === []) {
            return [];
        }

        $out = [];
        $seen = [];
        $depth = 0;
        $max = max(array_map('count', $buckets));

        while ($depth < $max && count($out) < $limit) {
            foreach ($buckets as $bucket) {
                if (!isset($bucket[$depth])) {
                    continue;
                }
                $hit = $bucket[$depth];
                // The same photo is often on two providers; dedupe on the
                // download url so the grid is not half duplicates.
                $fingerprint = md5((string) ($hit['download_url'] ?? $hit['id']));
                if (isset($seen[$fingerprint])) {
                    continue;
                }
                $seen[$fingerprint] = true;
                unset($hit['_score']);
                $out[] = $hit;
                if (count($out) >= $limit) {
                    break 2;
                }
            }
            $depth++;
        }

        return $out;
    }

    // --------------------------------------------------------------- parsers

    private function parsePexelsPhotos(array $json, string $orientation): array
    {
        $out = [];
        foreach ((array) ($json['photos'] ?? []) as $photo) {
            $src = (array) ($photo['src'] ?? []);
            if (empty($src['large2x']) && empty($src['large'])) {
                continue;
            }
            $out[] = $this->candidate([
                'provider' => 'pexels',
                'id' => 'pexels:photo:' . ($photo['id'] ?? ''),
                'kind' => 'image',
                'thumb' => (string) ($src['medium'] ?? $src['small'] ?? ''),
                'download_url' => (string) ($src['large2x'] ?? $src['large']),
                'width' => (int) ($photo['width'] ?? 0),
                'height' => (int) ($photo['height'] ?? 0),
                'title' => (string) ($photo['alt'] ?? ''),
                'author' => (string) ($photo['photographer'] ?? ''),
                'author_url' => (string) ($photo['photographer_url'] ?? ''),
                'source_url' => (string) ($photo['url'] ?? ''),
            ], $orientation);
        }

        return $out;
    }

    private function parsePexelsVideos(array $json, string $orientation): array
    {
        $out = [];
        foreach ((array) ($json['videos'] ?? []) as $video) {
            $file = $this->bestVideoFile((array) ($video['video_files'] ?? []), 'link', 'file_type');
            if ($file === null) {
                continue;
            }
            $pictures = (array) ($video['video_pictures'] ?? []);
            $out[] = $this->candidate([
                'provider' => 'pexels',
                'id' => 'pexels:video:' . ($video['id'] ?? ''),
                'kind' => 'video',
                'thumb' => (string) ($video['image'] ?? ($pictures[0]['picture'] ?? '')),
                'download_url' => (string) $file['link'],
                'width' => (int) ($file['width'] ?? 0),
                'height' => (int) ($file['height'] ?? 0),
                'duration' => (float) ($video['duration'] ?? 0),
                'title' => '',
                'author' => (string) ($video['user']['name'] ?? ''),
                'author_url' => (string) ($video['user']['url'] ?? ''),
                'source_url' => (string) ($video['url'] ?? ''),
            ], $orientation);
        }

        return $out;
    }

    private function parsePixabayPhotos(array $json, string $orientation): array
    {
        $out = [];
        foreach ((array) ($json['hits'] ?? []) as $hit) {
            if (empty($hit['largeImageURL'])) {
                continue;
            }
            $out[] = $this->candidate([
                'provider' => 'pixabay',
                'id' => 'pixabay:photo:' . ($hit['id'] ?? ''),
                'kind' => 'image',
                'thumb' => (string) ($hit['webformatURL'] ?? $hit['previewURL'] ?? ''),
                'download_url' => (string) $hit['largeImageURL'],
                'width' => (int) ($hit['imageWidth'] ?? 0),
                'height' => (int) ($hit['imageHeight'] ?? 0),
                'title' => (string) ($hit['tags'] ?? ''),
                'author' => (string) ($hit['user'] ?? ''),
                'author_url' => '',
                'source_url' => (string) ($hit['pageURL'] ?? ''),
            ], $orientation);
        }

        return $out;
    }

    private function parsePixabayVideos(array $json, string $orientation): array
    {
        $out = [];
        foreach ((array) ($json['hits'] ?? []) as $hit) {
            $file = $this->bestVideoFile(array_values((array) ($hit['videos'] ?? [])), 'url', null);
            if ($file === null) {
                continue;
            }
            // Pixabay serves no still of its own; `picture_id` is the handle
            // for one on the CDN. Missing it costs a thumbnail, not the hit.
            $pictureId = trim((string) ($hit['picture_id'] ?? ''));
            $out[] = $this->candidate([
                'provider' => 'pixabay',
                'id' => 'pixabay:video:' . ($hit['id'] ?? ''),
                'kind' => 'video',
                'thumb' => $pictureId !== '' ? "https://i.vimeocdn.com/video/{$pictureId}_295x166.jpg" : '',
                'download_url' => (string) $file['url'],
                'width' => (int) ($file['width'] ?? 0),
                'height' => (int) ($file['height'] ?? 0),
                'duration' => (float) ($hit['duration'] ?? 0),
                'title' => (string) ($hit['tags'] ?? ''),
                'author' => (string) ($hit['user'] ?? ''),
                'author_url' => '',
                'source_url' => (string) ($hit['pageURL'] ?? ''),
            ], $orientation);
        }

        return $out;
    }

    private function parseUnsplash(array $json, string $orientation): array
    {
        $out = [];
        foreach ((array) ($json['results'] ?? []) as $photo) {
            $urls = (array) ($photo['urls'] ?? []);
            if (empty($urls['regular']) && empty($urls['full'])) {
                continue;
            }
            $out[] = $this->candidate([
                'provider' => 'unsplash',
                'id' => 'unsplash:' . ($photo['id'] ?? ''),
                'kind' => 'image',
                'thumb' => (string) ($urls['small'] ?? $urls['thumb'] ?? ''),
                'download_url' => (string) ($urls['regular'] ?? $urls['full']),
                'width' => (int) ($photo['width'] ?? 0),
                'height' => (int) ($photo['height'] ?? 0),
                'title' => (string) ($photo['description'] ?? $photo['alt_description'] ?? ''),
                'author' => (string) ($photo['user']['name'] ?? ''),
                'author_url' => (string) ($photo['user']['links']['html'] ?? ''),
                'source_url' => (string) ($photo['links']['html'] ?? ''),
            ], $orientation);
        }

        return $out;
    }

    private function parseOpenverse(array $json, string $orientation): array
    {
        $out = [];
        foreach ((array) ($json['results'] ?? []) as $item) {
            if (empty($item['url'])) {
                continue;
            }
            $out[] = $this->candidate([
                'provider' => 'openverse',
                'id' => 'openverse:' . ($item['id'] ?? ''),
                'kind' => 'image',
                'thumb' => (string) ($item['thumbnail'] ?? $item['url']),
                'download_url' => (string) $item['url'],
                'width' => (int) ($item['width'] ?? 0),
                'height' => (int) ($item['height'] ?? 0),
                'title' => (string) ($item['title'] ?? ''),
                'author' => (string) ($item['creator'] ?? ''),
                'author_url' => (string) ($item['creator_url'] ?? ''),
                'source_url' => (string) ($item['foreign_landing_url'] ?? ''),
                'license' => trim(strtoupper((string) ($item['license'] ?? '')) . ' ' . (string) ($item['license_version'] ?? '')),
            ], $orientation);
        }

        return $out;
    }

    /**
     * Wikimedia Commons — keyless, and the only source here with real
     * ENCYCLOPAEDIC coverage: diagrams, historical photographs, maps,
     * specimens. Exactly what an explainer wants and what stock sites lack.
     */
    private function parseWikimedia(array $json, string $orientation): array
    {
        $out = [];
        foreach ((array) ($json['query']['pages'] ?? []) as $page) {
            $info = (array) (($page['imageinfo'] ?? [])[0] ?? []);
            if (empty($info['url'])) {
                continue;
            }
            $meta = (array) ($info['extmetadata'] ?? []);
            $author = strip_tags((string) ($meta['Artist']['value'] ?? ''));
            $out[] = $this->candidate([
                'provider' => 'wikimedia',
                'id' => 'wikimedia:' . ($page['pageid'] ?? ''),
                'kind' => 'image',
                'thumb' => (string) ($info['thumburl'] ?? $info['url']),
                'download_url' => (string) $info['url'],
                'width' => (int) ($info['width'] ?? 0),
                'height' => (int) ($info['height'] ?? 0),
                'title' => trim(preg_replace('/^File:|\.\w+$/', '', (string) ($page['title'] ?? ''))),
                'author' => mb_substr(trim($author), 0, 80),
                'author_url' => '',
                'source_url' => (string) ($info['descriptionurl'] ?? ''),
                'license' => (string) ($meta['LicenseShortName']['value'] ?? 'See Commons page'),
            ], $orientation);
        }

        return $out;
    }

    // --------------------------------------------------------------- helpers

    /**
     * Best file out of a provider's ladder of renditions: at least 1080 on the
     * short side when one exists, otherwise the largest — never the 4K monster
     * when a 1080p sibling is there, because it only costs download time.
     *
     * @param  string  $urlKey   Which key holds the URL
     * @param  ?string $typeKey  Key that must read "video/mp4" (null to skip)
     */
    private function bestVideoFile(array $files, string $urlKey, ?string $typeKey): ?array
    {
        $best = null;
        $bestScore = -1;
        foreach ($files as $file) {
            $file = (array) $file;
            if (empty($file[$urlKey])) {
                continue;
            }
            if ($typeKey !== null && ($file[$typeKey] ?? '') !== 'video/mp4') {
                continue;
            }
            $short = min((int) ($file['width'] ?? 0), (int) ($file['height'] ?? 0));
            $long = max((int) ($file['width'] ?? 0), (int) ($file['height'] ?? 0));
            $score = $short >= 1080 ? 100 : ($short >= 720 ? 60 : 10);
            if ($long <= 2048) {
                $score += 5;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $file;
                $best['link'] = $file[$urlKey];
                $best['url'] = $file[$urlKey];
            }
        }

        return $best;
    }

    /** Normalize one provider hit and score it for the ranking. */
    private function candidate(array $raw, string $wanted): array
    {
        $w = (int) ($raw['width'] ?? 0);
        $h = (int) ($raw['height'] ?? 0);
        $actual = $w > $h ? 'landscape' : ($w < $h ? 'portrait' : 'square');

        $score = 0;
        if ($w > 0 && $h > 0) {
            $score += $actual === $wanted ? 400 : ($actual === 'square' ? 120 : 0);
            $short = min($w, $h);
            $score += $short >= 1400 ? 120 : ($short >= 1000 ? 90 : ($short >= 700 ? 40 : 0));
        }
        // A clip of usable length beats a 45-second one that has to be cut.
        $duration = (float) ($raw['duration'] ?? 0);
        if ($duration > 0) {
            $score += ($duration >= 4 && $duration <= 15) ? 60 : (($duration <= 30) ? 25 : 0);
        }

        $meta = self::PROVIDERS[$raw['provider']] ?? [];

        return [
            'provider' => $raw['provider'],
            'provider_label' => $meta['label'] ?? $raw['provider'],
            'id' => (string) $raw['id'],
            'kind' => $raw['kind'],
            'thumb' => (string) ($raw['thumb'] ?? ''),
            'download_url' => (string) $raw['download_url'],
            'width' => $w,
            'height' => $h,
            'orientation' => $actual,
            'duration' => $duration > 0 ? round($duration, 1) : null,
            'title' => mb_substr(trim((string) ($raw['title'] ?? '')), 0, 120),
            'credit' => [
                'author' => (string) ($raw['author'] ?? ''),
                'author_url' => (string) ($raw['author_url'] ?? ''),
                'source_url' => (string) ($raw['source_url'] ?? ''),
            ],
            'license' => (string) ($raw['license'] ?? ($meta['license'] ?? '')),
            'attribution_required' => (bool) ($meta['attribution_required'] ?? false),
            '_score' => $score,
        ];
    }

    /**
     * Wikimedia and Openverse both ask for an identifying User-Agent and
     * throttle or block generic ones.
     */
    private function userAgent(): string
    {
        return 'ViralForgeExplainer/1.0 (+' . (string) config('app.url', 'https://localhost') . ')';
    }
}
