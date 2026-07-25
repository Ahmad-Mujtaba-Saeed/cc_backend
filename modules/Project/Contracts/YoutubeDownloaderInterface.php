<?php

namespace Modules\Project\Contracts;

/**
 * Common contract for YouTube video downloaders so the RapidAPI and Apify
 * implementations are fully interchangeable. The processors depend on this
 * interface and resolve a concrete provider via YoutubeDownloaderFactory,
 * which honours the admin-configured provider setting.
 *
 * Flow used by callers:
 *   $r   = $svc->initDownload($youtubeUrl);   // ['progress_url' => ..., 'title' => ...]
 *   $url = $svc->pollUntilReady($r['progress_url']);
 *   $svc->streamToStorage($url, $absolutePath);
 *
 * For synchronous providers (Apify) initDownload already resolves the final
 * media URL and pollUntilReady simply returns it, so the call sequence is
 * identical regardless of provider.
 */
interface YoutubeDownloaderInterface
{
    public function extractVideoId(string $url): string;

    /**
     * @return array{progress_url: string, title: string}
     */
    public function initDownload(string $ytUrl): array;

    public function pollUntilReady(string $progressUrl, int $maxAttempts = 20, int $pollIntervalSeconds = 10): string;

    public function streamToStorage(string $downloadUrl, string $outputAbsolutePath): void;
}
