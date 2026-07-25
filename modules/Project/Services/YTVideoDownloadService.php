<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * YTVideoDownloadService
 *
 * Downloads YouTube videos via RapidAPI (download only — no transcript API).
 * After download, transcription is done locally via VideoTranscriptionService.
 *
 * One of the interchangeable providers behind YoutubeDownloaderInterface;
 * the Apify-backed alternative is ApifyVideoDownloadService. Resolve via
 * YoutubeDownloaderFactory rather than constructing directly — the factory
 * returns a FailoverYoutubeDownloader that injects an admin-managed key
 * (api_credentials pool) and retries with the next key on failure. Keys are
 * NOT read from .env.
 */
class YTVideoDownloadService extends AbstractYoutubeDownloader
{
    private string $rapidApiKey;
    private string $rapidApiHost;

    public function __construct(string $rapidApiKey)
    {
        $this->rapidApiKey = trim($rapidApiKey);
        $this->rapidApiHost = config('services.rapidapi.download_host', 'youtube-info-download-api.p.rapidapi.com');
        if (empty($this->rapidApiKey)) {
            throw new \Exception('RapidAPI key is empty. Add a key in Settings → Integrations.');
        }
    }

    /**
     * Initiate video download via RapidAPI
     * 
     * Returns: { progress_url, title }
     * Throws if RapidAPI doesn't return progress_url
     */
    public function initDownload(string $ytUrl): array
    {
        $videoId = $this->extractVideoId($ytUrl);
        
        Log::info('YTVideoDownloadService: Initiating RapidAPI download', [
            'video_id' => $videoId,
            'url' => $ytUrl
        ]);

        $response = Http::withHeaders([
            'x-rapidapi-host' => $this->rapidApiHost,
            'x-rapidapi-key' => $this->rapidApiKey,
        ])->get('https://' . $this->rapidApiHost . '/ajax/download.php', [
            'format' => '480',
            'url' => $ytUrl,
            'audio_quality' => '128',
            'no_merge' => 'false',
            'allow_extended_duration' => 0,
        ]);

        if (!$response->successful()) {
            Log::error('RapidAPI download init failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            throw new \Exception('RapidAPI download initialization failed: ' . $response->status());
        }

        $data = $response->json();
        
        if (empty($data['progress_url'])) {
            Log::error('RapidAPI returned no progress_url', ['response' => $data]);
            throw new \Exception('RapidAPI did not return a progress URL — the video may be unavailable');
        }

        Log::info('YTVideoDownloadService: Download initiated', [
            'video_id' => $videoId,
            'progress_url' => $data['progress_url'],
            'title' => $data['title'] ?? 'Unknown'
        ]);

        return [
            'progress_url' => $data['progress_url'],
            'title' => $data['title'] ?? 'YouTube Video'
        ];
    }

    /**
     * Poll progress URL until download is ready
     * 
     * Polls every $pollIntervalSeconds (up to $maxAttempts times)
     * Returns download_url when progress >= 1000
     * Throws if timeout exceeded
     */
    public function pollUntilReady(
        string $progressUrl,
        int $maxAttempts = 20,
        int $pollIntervalSeconds = 10
    ): string
    {
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;
            
            Log::debug('YTVideoDownloadService: Polling download progress', [
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts
            ]);

            $response = Http::timeout(30)->get($progressUrl);

            if (!$response->successful()) {
                sleep($pollIntervalSeconds);
                continue;
            }

            $data = $response->json();
            $progress = intval($data['progress'] ?? 0);

            Log::debug('YTVideoDownloadService: Poll response', [
                'progress' => $progress,
                'download_url_present' => !empty($data['download_url'])
            ]);

            // Ready when progress >= 1000 AND download_url is present
            if ($progress >= 1000 && !empty($data['download_url'])) {
                Log::info('YTVideoDownloadService: Download ready', [
                    'download_url' => $data['download_url'],
                    'attempts_taken' => $attempt
                ]);
                return $data['download_url'];
            }

            if ($attempt < $maxAttempts) {
                sleep($pollIntervalSeconds);
            }
        }

        $totalSeconds = $maxAttempts * $pollIntervalSeconds;
        throw new \Exception(
            "Video download timed out after {$totalSeconds}s — the download may have failed. "
            . "Please try again or use the upload option."
        );
    }
}
