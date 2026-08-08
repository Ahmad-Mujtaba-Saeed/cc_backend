<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The local background-music library: every track a provider hands to a render
 * is downloaded ONCE into public storage under its category and served from
 * there, so a render never depends on a remote CDN and a re-render of the same
 * project costs nothing.
 *
 * Layout on the public disk:
 *
 *   audio/{provider}/{category}/{track_id}.mp3    the audio itself
 *   audio/{provider}/{category}/{track_id}.json   title, artist, duration, licence
 *
 * The sidecar exists so the library is browsable OFFLINE with real names: a
 * bare mp3 filename is a numeric provider id, which is useless in the picker
 * and destroys the attribution a Creative Commons track needs.
 *
 * WHY THIS CLASS EXISTS AT ALL — the bug it was written to kill:
 *
 * Both providers used to call `Storage::put()` and ignore its return value.
 * The public disk is configured `'throw' => false`, so a FAILED write returns
 * false instead of raising, and the caller happily logged "cached track" and
 * returned a path to a file that was never written. The renderer then built
 * `http://…/storage/audio/jamendo/technology/1129271.mp3`, got a 404, and
 * Remotion killed the whole render with "Could not play audio … MediaError".
 *
 * The write failed for a boring reason that will happen again: the web
 * container runs as root and the queue worker as www-data (see the comment in
 * docker-compose.yml), so whichever got to a category folder first owned it
 * 0755 and the other could not write inside it. Hence two rules here, both
 * load-bearing:
 *
 *   1. a path is only ever returned after the bytes have been verified ON DISK;
 *   2. directories are created group/other-writable and repaired on a denial,
 *      so root and www-data can share the library.
 */
class MusicLibraryService
{
    /** Root of the library on the public disk. */
    public const ROOT = 'audio';

    /**
     * Smallest plausible track. Provider CDNs answer an expired or rate-limited
     * request with a short HTML/JSON error body and HTTP 200; storing that as
     * `1129271.mp3` fails at render time instead of at download time, which is
     * far more expensive to diagnose.
     */
    private const MIN_BYTES = 16384;

    /** Audio extensions the library and the legacy folders recognise. */
    private const AUDIO_EXTENSIONS = ['mp3', 'wav', 'm4a', 'ogg'];

    /**
     * Download a track into the library once and return its public-disk
     * RELATIVE path, or null when it could not be stored — never a path whose
     * bytes are not on disk.
     *
     * @param array{title?: string, artist?: string, duration?: int, license?: string, license_url?: string} $meta
     */
    public function store(
        string $provider,
        string $category,
        string $trackId,
        string $audioUrl,
        array $meta = []
    ): ?string {
        $provider = self::slug($provider);
        $category = self::slug($category);
        $trackId = self::slug($trackId);

        if ($provider === '' || $category === '' || $trackId === '' || trim($audioUrl) === '') {
            return null;
        }

        $dir = self::ROOT . "/{$provider}/{$category}";
        $relative = "{$dir}/{$trackId}.mp3";

        // Already in the library — but only trust a file that is actually
        // playable. A truncated leftover from a killed worker must be replaced,
        // not handed to the renderer forever.
        if ($this->isHealthy($relative)) {
            $this->writeSidecar($dir, $trackId, $provider, $category, $audioUrl, $meta);

            return $relative;
        }

        try {
            $response = Http::timeout(120)->get($audioUrl);
            $body = $response->body();

            if (!$response->successful() || strlen($body) < self::MIN_BYTES) {
                Log::warning('MusicLibrary: track download failed', [
                    'provider' => $provider,
                    'category' => $category,
                    'track_id' => $trackId,
                    'status' => $response->status(),
                    'bytes' => strlen($body),
                ]);

                return null;
            }

            if (!$this->put($relative, $body)) {
                return null;
            }

            $this->writeSidecar($dir, $trackId, $provider, $category, $audioUrl, $meta);

            Log::info('MusicLibrary: cached track', [
                'provider' => $provider,
                'category' => $category,
                'track_id' => $trackId,
                'bytes' => strlen($body),
                'path' => $relative,
            ]);

            return $relative;
        } catch (\Throwable $e) {
            Log::warning('MusicLibrary: track download threw', [
                'provider' => $provider,
                'category' => $category,
                'track_id' => $trackId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The relative path of an already-downloaded track, or null. Used by a
     * provider to answer a user's explicit pick from the local library when
     * the remote catalogue is unreachable.
     */
    public function path(string $provider, string $category, string $trackId): ?string
    {
        $relative = self::ROOT . '/' . self::slug($provider) . '/' . self::slug($category)
            . '/' . self::slug($trackId) . '.mp3';

        return $this->isHealthy($relative) ? $relative : null;
    }

    /**
     * Everything already downloaded for one category, newest first, in the
     * picker's track shape plus the storage `path`.
     *
     * These are playable with no API call at all, which is what makes the
     * picker (and a render) survive an expired key or a provider outage.
     *
     * @return array<int, array{id: string, title: string, duration: int, url: string, path: string}>
     */
    public function cached(string $provider, string $category): array
    {
        $provider = self::slug($provider);
        $dir = self::ROOT . "/{$provider}/" . self::slug($category);
        $disk = Storage::disk('public');
        $tracks = [];

        try {
            foreach ($disk->files($dir) as $file) {
                if (!$this->isAudio($file) || !$this->isHealthy($file)) {
                    continue;
                }

                $id = pathinfo($file, PATHINFO_FILENAME);
                $meta = $this->readSidecar($dir, $id);

                $tracks[] = [
                    'id' => $id,
                    'title' => $this->displayTitle($meta, $id, $file),
                    'duration' => (int) ($meta['duration'] ?? 0),
                    'url' => $disk->url($file),
                    'path' => $file,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('MusicLibrary: cache listing failed', [
                'dir' => $dir,
                'error' => $e->getMessage(),
            ]);
        }

        return $tracks;
    }

    /**
     * Operator-supplied files in a plain folder (the legacy library that
     * predates any provider — storage/app/public/audio/{category}).
     *
     * Ids are content-free hashes and are only ever RESOLVED against this
     * listing, so no user input can name an arbitrary path.
     *
     * @return array<int, array{id: string, title: string, duration: int, url: string, path: string}>
     */
    public function folderTracks(string $dir): array
    {
        $disk = Storage::disk('public');
        $tracks = [];

        try {
            foreach ($disk->files($dir) as $file) {
                if (!$this->isAudio($file)) {
                    continue;
                }

                $tracks[] = [
                    'id' => 'l' . substr(md5($file), 0, 12),
                    'title' => $this->prettifyFilename($file),
                    'duration' => 0,
                    'url' => $disk->url($file),
                    'path' => $file,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('MusicLibrary: folder listing failed', [
                'dir' => $dir,
                'error' => $e->getMessage(),
            ]);
        }

        return $tracks;
    }

    /** Is this a file the renderer can actually play? */
    public function isHealthy(string $relative): bool
    {
        $disk = Storage::disk('public');

        try {
            // The worker is long-running and another container may have just
            // written this file — never answer from PHP's stat cache.
            clearstatcache(true, $disk->path($relative));

            return $disk->exists($relative) && $disk->size($relative) >= self::MIN_BYTES;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Write the bytes and prove they landed.
     *
     * `Storage::put()` on this disk returns false instead of throwing, and the
     * common failure is a permission denial on a directory created by the
     * other container's user — so a denial is repaired and retried once before
     * it is believed.
     */
    private function put(string $relative, string $body): bool
    {
        $disk = Storage::disk('public');
        $dir = dirname($relative);

        $this->ensureWritableDir($dir);

        if ($disk->put($relative, $body) === false) {
            $this->relaxPermissions($dir);

            if ($disk->put($relative, $body) === false) {
                Log::error('MusicLibrary: could not write track — check ownership of the library folders', [
                    'path' => $relative,
                    'abs' => $disk->path($relative),
                    'process_user' => function_exists('posix_geteuid') ? posix_geteuid() : null,
                ]);

                return false;
            }
        }

        @chmod($disk->path($relative), 0666);

        // The whole point of this class: a path is returned only once the
        // bytes are verifiably on disk at their full length.
        clearstatcache(true, $disk->path($relative));
        if (!$disk->exists($relative) || $disk->size($relative) !== strlen($body)) {
            Log::error('MusicLibrary: track wrote short or vanished — discarding', [
                'path' => $relative,
                'expected' => strlen($body),
                'actual' => $disk->exists($relative) ? $disk->size($relative) : null,
            ]);
            $disk->delete($relative);

            return false;
        }

        return true;
    }

    /** Create the category folder writable by BOTH container users. */
    private function ensureWritableDir(string $dir): void
    {
        $disk = Storage::disk('public');

        try {
            if (!$disk->exists($dir)) {
                $disk->makeDirectory($dir);
            }
        } catch (\Throwable $e) {
            // A denial here surfaces as a failed put() with a proper message.
        }

        $this->relaxPermissions($dir);
    }

    /**
     * Make the library folders writable by whichever user gets there second.
     * Silent by design: chmod only succeeds for the owner or root, and when it
     * fails the caller's retry reports the real problem with a real path.
     */
    private function relaxPermissions(string $dir): void
    {
        $disk = Storage::disk('public');
        $parts = explode('/', trim($dir, '/'));
        $walked = '';

        foreach ($parts as $part) {
            $walked = $walked === '' ? $part : "{$walked}/{$part}";
            try {
                @chmod($disk->path($walked), 0777);
            } catch (\Throwable $e) {
                // best effort
            }
        }
    }

    /** Track metadata beside the audio, so the library reads well offline. */
    private function writeSidecar(
        string $dir,
        string $trackId,
        string $provider,
        string $category,
        string $audioUrl,
        array $meta
    ): void {
        $relative = "{$dir}/{$trackId}.json";
        $disk = Storage::disk('public');

        try {
            if ($disk->exists($relative)) {
                return;
            }

            $payload = json_encode([
                'id' => $trackId,
                'provider' => $provider,
                'category' => $category,
                'title' => (string) ($meta['title'] ?? ''),
                'artist' => (string) ($meta['artist'] ?? ''),
                'duration' => (int) ($meta['duration'] ?? 0),
                // Jamendo's catalogue is Creative Commons: keep the licence
                // with the file so an attribution surface never needs the API.
                'license' => (string) ($meta['license'] ?? ''),
                'license_url' => (string) ($meta['license_url'] ?? ''),
                'source_url' => $audioUrl,
                'downloaded_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if ($disk->put($relative, (string) $payload) !== false) {
                @chmod($disk->path($relative), 0666);
            }
        } catch (\Throwable $e) {
            // Metadata is a nicety — never fail a usable track over it.
        }
    }

    /** @return array<string, mixed> */
    private function readSidecar(string $dir, string $trackId): array
    {
        try {
            $relative = "{$dir}/{$trackId}.json";
            if (!Storage::disk('public')->exists($relative)) {
                return [];
            }

            $decoded = json_decode((string) Storage::disk('public')->get($relative), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Sidecar title with artist, falling back to something readable. */
    private function displayTitle(array $meta, string $id, string $file): string
    {
        $title = trim((string) ($meta['title'] ?? ''));
        $artist = trim((string) ($meta['artist'] ?? ''));

        if ($title === '') {
            // Files cached before sidecars existed are named for their provider
            // id alone, which prettifies to nothing a user can read.
            $pretty = $this->prettifyFilename($file);

            return ($pretty !== '' && $pretty !== $id) ? $pretty : "Track {$id}";
        }

        return $artist !== '' ? "{$title} — {$artist}" : $title;
    }

    /** "tokyo-music-walker-day-off-chosic.com_" -> "Tokyo Music Walker Day Off". */
    private function prettifyFilename(string $file): string
    {
        $name = pathinfo($file, PATHINFO_FILENAME);
        $title = ucwords(trim((string) preg_replace(
            '/\s+/',
            ' ',
            (string) preg_replace('/\(.*?\)|[-_]+|\d{5,}/', ' ', $name)
        )));

        return $title !== '' ? $title : $name;
    }

    private function isAudio(string $file): bool
    {
        return (bool) preg_match('/\.(' . implode('|', self::AUDIO_EXTENSIONS) . ')$/i', $file);
    }

    /** Path-safe component: ids and categories never reach the filesystem raw. */
    private static function slug(string $value): string
    {
        return strtolower(trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '', trim($value))));
    }
}
