<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\AccessControl\Models\ApiCredential;
use Symfony\Component\Process\Process;

/**
 * StockFootageService (copilot.md §8 b-roll): free stock video from the
 * Pexels and Pixabay video APIs. The planner marks a slot
 * `content_type: "stock_video"` with a short search query; the validator
 * normalizes it to a video slot carrying `stock_query`, and the processor
 * calls fetch() before assembly.
 *
 * Candidates are scored orientation match > resolution ≥1080 > duration
 * 4–12s, downloaded ONCE into the shared `stock/` cache (keyed by query +
 * orientation, so re-renders and other projects reuse the file) and remuxed
 * +faststart; the render pipeline's existing CFR normalization covers the
 * rest. Keys live in the admin api_credentials pool (providers `pexels` and
 * `pixabay` — the same pixabay pool the music picker uses). Every failure
 * returns null; callers degrade to an ordinary upload request.
 */
class StockFootageService
{
    private const PEXELS_ENDPOINT = 'https://api.pexels.com/videos/search';
    private const PIXABAY_ENDPOINT = 'https://pixabay.com/api/videos/';
    private const CACHE_DIR = 'stock';
    private const PER_PAGE = 15;

    public function isConfigured(): bool
    {
        return ApiCredential::hasActive('pexels') || ApiCredential::hasActive('pixabay');
    }

    /**
     * Fetch (or reuse) a stock clip for a query.
     *
     * @param  string  $orientation  landscape | portrait | square
     * @return string|null  public-disk relative path
     */
    public function fetch(string $query, string $orientation = 'landscape'): ?string
    {
        $query = trim(mb_substr($query, 0, 60));
        if ($query === '') {
            return null;
        }
        $orientation = in_array($orientation, ['landscape', 'portrait', 'square'], true) ? $orientation : 'landscape';

        $relative = self::CACHE_DIR . '/' . $orientation . '/' . md5(mb_strtolower($query)) . '.mp4';
        if (Storage::disk('public')->exists($relative)) {
            return $relative;
        }

        $candidate = $this->searchPexels($query, $orientation)
            ?? $this->searchPixabay($query, $orientation);
        if ($candidate === null) {
            Log::info('StockFootageService: no candidates', ['query' => $query]);

            return null;
        }

        return $this->download($candidate['url'], $relative) ? $relative : null;
    }

    /**
     * Best Pexels hit, already scored. @return array{url: string}|null
     */
    private function searchPexels(string $query, string $orientation): ?array
    {
        foreach (ApiCredential::forProvider('pexels') as $credential) {
            try {
                $response = Http::timeout(30)
                    ->withHeaders(['Authorization' => $credential->credential])
                    ->get(self::PEXELS_ENDPOINT, [
                        'query' => $query,
                        'orientation' => $orientation,
                        'per_page' => self::PER_PAGE,
                        'size' => 'medium',
                    ]);
                if (!$response->successful()) {
                    $credential->markFailure("HTTP {$response->status()}");
                    continue;
                }
                $credential->markSuccess();
                CostTracker::recordExternal('stock_footage', 'pexels_search');

                $best = null;
                $bestScore = -1;
                foreach ((array) ($response->json()['videos'] ?? []) as $video) {
                    $duration = (float) ($video['duration'] ?? 0);
                    foreach ((array) ($video['video_files'] ?? []) as $file) {
                        if (($file['file_type'] ?? '') !== 'video/mp4' || empty($file['link'])) {
                            continue;
                        }
                        $score = $this->score(
                            (int) ($file['width'] ?? 0),
                            (int) ($file['height'] ?? 0),
                            $duration,
                            $orientation
                        );
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $best = ['url' => (string) $file['link']];
                        }
                    }
                }

                return $best;
            } catch (\Throwable $e) {
                $credential->markFailure($e->getMessage());
            }
        }

        return null;
    }

    /** Best Pixabay hit. @return array{url: string}|null */
    private function searchPixabay(string $query, string $orientation): ?array
    {
        foreach (ApiCredential::forProvider('pixabay') as $credential) {
            try {
                $response = Http::timeout(30)->get(self::PIXABAY_ENDPOINT, [
                    'key' => $credential->credential,
                    'q' => $query,
                    'per_page' => self::PER_PAGE,
                    'safesearch' => 'true',
                ]);
                if (!$response->successful()) {
                    $credential->markFailure("HTTP {$response->status()}");
                    continue;
                }
                $credential->markSuccess();
                CostTracker::recordExternal('stock_footage', 'pixabay_search');

                $best = null;
                $bestScore = -1;
                foreach ((array) ($response->json()['hits'] ?? []) as $hit) {
                    $duration = (float) ($hit['duration'] ?? 0);
                    foreach ((array) ($hit['videos'] ?? []) as $file) {
                        if (empty($file['url'])) {
                            continue;
                        }
                        $score = $this->score(
                            (int) ($file['width'] ?? 0),
                            (int) ($file['height'] ?? 0),
                            $duration,
                            $orientation
                        );
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $best = ['url' => (string) $file['url']];
                        }
                    }
                }

                return $best;
            } catch (\Throwable $e) {
                $credential->markFailure($e->getMessage());
            }
        }

        return null;
    }

    /**
     * Contract scoring: orientation match dominates, then ≥1080 on the short
     * side, then a 4–12s duration; mild tie-breaks for resolution closeness
     * (giant 4K files waste download time for a 1080p render).
     */
    private function score(int $width, int $height, float $duration, string $orientation): int
    {
        if ($width < 1 || $height < 1) {
            return -1;
        }
        $actual = $width > $height ? 'landscape' : ($width < $height ? 'portrait' : 'square');
        $score = 0;
        if ($actual === $orientation) {
            $score += 1000;
        }
        if (min($width, $height) >= 1080) {
            $score += 100;
        } elseif (min($width, $height) >= 720) {
            $score += 40;
        }
        if ($duration >= 4 && $duration <= 12) {
            $score += 10;
        } elseif ($duration > 12 && $duration <= 30) {
            $score += 5;
        }
        // Prefer files near the render target over 4K monsters.
        if (max($width, $height) <= 2048) {
            $score += 3;
        }

        return $score;
    }

    /** Stream the file down and remux +faststart; false on any failure. */
    private function download(string $url, string $relative): bool
    {
        $abs = Storage::disk('public')->path($relative);
        @mkdir(dirname($abs), 0775, true);
        $tmp = $abs . '.part';

        try {
            $response = Http::timeout(300)->withOptions(['sink' => $tmp])->get($url);
            if (!$response->successful() || !is_file($tmp) || filesize($tmp) < 10240) {
                @unlink($tmp);

                return false;
            }

            // moov up front so the renderer can stream it; CFR normalization
            // happens later in the render pipeline like any slot video.
            $remuxed = $abs . '.remux.mp4';
            $ffmpeg = new Process([
                'ffmpeg', '-v', 'error', '-y', '-i', $tmp,
                '-c', 'copy', '-movflags', '+faststart', $remuxed,
            ]);
            $ffmpeg->setTimeout(180);
            $ffmpeg->run();

            if ($ffmpeg->isSuccessful() && is_file($remuxed) && filesize($remuxed) > 0) {
                @unlink($tmp);
                rename($remuxed, $abs);
            } else {
                @unlink($remuxed);
                rename($tmp, $abs);
            }

            CostTracker::recordExternal('stock_footage', 'clip_download', 1, 'clips', 0.0, ['bytes' => filesize($abs)]);
            Log::info('StockFootageService: cached clip', ['path' => $relative, 'bytes' => filesize($abs)]);

            return true;
        } catch (\Throwable $e) {
            @unlink($tmp);
            Log::warning('StockFootageService: download failed: ' . $e->getMessage());

            return false;
        }
    }
}
