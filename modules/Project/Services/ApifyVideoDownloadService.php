<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ApifyVideoDownloadService
 *
 * Downloads YouTube videos via the Apify actor `truefetch/youtube-video-downloader`.
 * Interchangeable alternative to YTVideoDownloadService (RapidAPI) — both honour
 * YoutubeDownloaderInterface so callers behave identically regardless of provider.
 *
 * Apify runs synchronously (run-sync-get-dataset-items): initDownload() runs the
 * actor and already resolves the final signed MP4 URL, so pollUntilReady() simply
 * passes that URL through. Streaming-to-disk is shared via AbstractYoutubeDownloader.
 */
class ApifyVideoDownloadService extends AbstractYoutubeDownloader
{
    private string $token;
    private string $actor;
    private string $videoQuality;

    public function __construct(string $token)
    {
        $this->token = trim($token);
        $this->actor = (string) config('services.apify.youtube_actor', 'truefetch/youtube-video-downloader');
        $this->videoQuality = (string) config('services.apify.video_quality', 'medium');

        if (empty($this->token)) {
            throw new \Exception('Apify token is empty. Add a token in Settings → Integrations.');
        }
    }

    /**
     * Run the Apify actor synchronously and resolve the downloadable MP4 URL.
     *
     * Returns { progress_url, title } to match YoutubeDownloaderInterface — for
     * Apify the "progress_url" is already the final media URL (no polling needed).
     */
    public function initDownload(string $ytUrl): array
    {
        $videoId = $this->extractVideoId($ytUrl);

        Log::info('ApifyVideoDownloadService: Running Apify actor', [
            'video_id' => $videoId,
            'url' => $ytUrl,
            'actor' => $this->actor,
        ]);

        // Actor ids use a tilde in API paths (owner~name).
        $actorPath = str_replace('/', '~', $this->actor);

        $response = Http::timeout(300)
            ->acceptJson()
            ->post("https://api.apify.com/v2/acts/{$actorPath}/run-sync-get-dataset-items?token={$this->token}", [
                'video_url' => $ytUrl,
                'video_quality' => $this->videoQuality,
                'max_comments' => 0,
            ]);

        if (!$response->successful()) {
            Log::error('Apify actor run failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Apify download failed: HTTP ' . $response->status());
        }

        $items = $response->json();
        if (!is_array($items) || empty($items)) {
            Log::error('Apify returned no dataset items', ['response' => $items]);
            throw new \Exception('Apify did not return any video — the video may be unavailable');
        }

        // run-sync-get-dataset-items returns the dataset rows (array of objects).
        $item = $items[0];
        if (!is_array($item)) {
            throw new \Exception('Apify returned an unexpected dataset shape');
        }

        $downloadUrl = $this->resolveDownloadUrl($item);
        if (empty($downloadUrl)) {
            Log::error('Apify item had no resolvable download URL', ['item_keys' => array_keys($item)]);
            throw new \Exception('Apify did not return a download URL for the video');
        }

        $title = $item['title'] ?? 'YouTube Video';

        Log::info('ApifyVideoDownloadService: Download resolved', [
            'video_id' => $videoId,
            'title' => $title,
            'download_url' => $downloadUrl,
        ]);

        return [
            'progress_url' => $downloadUrl,
            'title' => $title,
        ];
    }

    /**
     * Apify is synchronous — the URL from initDownload() is already downloadable.
     * Parameters are accepted only to satisfy the interface.
     */
    public function pollUntilReady(string $progressUrl, int $maxAttempts = 20, int $pollIntervalSeconds = 10): string
    {
        return $progressUrl;
    }

    /**
     * Find the downloadable MP4 URL in an Apify dataset item.
     *
     * Prefers well-known keys, then falls back to scanning every string value
     * for an Apify key-value-store record URL so we are resilient to the actor
     * renaming its output field.
     */
    private function resolveDownloadUrl(array $item): ?string
    {
        foreach (['download_url', 'downloadUrl', 'video', 'video_file', 'file', 'url', 'mp4'] as $key) {
            if (!empty($item[$key]) && is_string($item[$key]) && $this->looksLikeMediaUrl($item[$key])) {
                return $item[$key];
            }
        }

        // Fallback: any string value that points at a stored media file.
        foreach ($item as $value) {
            if (is_string($value) && $this->looksLikeMediaUrl($value)) {
                return $value;
            }
        }

        return null;
    }

    private function looksLikeMediaUrl(string $value): bool
    {
        if (!str_starts_with($value, 'http')) {
            return false;
        }

        // Apify stores the rendered file in a key-value store; the signed record
        // URL is the actual MP4 download. Also accept any direct .mp4 link.
        return str_contains($value, '/key-value-stores/')
            || str_contains($value, '.mp4');
    }
}
