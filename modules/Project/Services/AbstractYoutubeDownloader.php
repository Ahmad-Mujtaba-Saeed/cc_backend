<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Project\Contracts\YoutubeDownloaderInterface;

/**
 * Shared behaviour for YouTube downloaders: video-id extraction and the
 * streaming download-to-disk that is identical across providers. Concrete
 * providers implement only initDownload()/pollUntilReady().
 */
abstract class AbstractYoutubeDownloader implements YoutubeDownloaderInterface
{
    /**
     * Extract video ID from a YouTube URL.
     *
     * Handles:
     * - https://www.youtube.com/watch?v=VIDEO_ID
     * - https://youtu.be/VIDEO_ID
     * - https://www.youtube.com/shorts/VIDEO_ID
     */
    public function extractVideoId(string $url): string
    {
        $pattern = '/(?:v=|youtu\.be\/|shorts\/)([\w-]{11})/';
        if (!preg_match($pattern, $url, $matches)) {
            throw new \Exception('Invalid YouTube URL — could not extract video ID');
        }
        return $matches[1];
    }

    /** How many times to re-attempt a download that arrived incomplete. */
    private const DOWNLOAD_ATTEMPTS = 3;

    /**
     * Stream a remote video file to storage.
     *
     * Uses streaming (not buffering into memory) to handle large files.
     * Opens output in write-binary mode to handle video data correctly.
     *
     * A short read is treated as a FAILURE, not a download. The provider's
     * CDN drops long transfers, and a partial MP4 whose moov atom never
     * arrived is unreadable: it used to sail past the old "is the file
     * non-empty?" check and surface hundreds of seconds later as
     * "Failed to cut any clips from the source video". Compare against
     * Content-Length and retry instead.
     */
    public function streamToStorage(string $downloadUrl, string $outputAbsolutePath): void
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= self::DOWNLOAD_ATTEMPTS; $attempt++) {
            try {
                $this->streamOnce($downloadUrl, $outputAbsolutePath, $attempt);
                return;
            } catch (\Exception $e) {
                $lastError = $e;
                @unlink($outputAbsolutePath); // never leave a partial file behind

                Log::warning(static::class . ': Stream download attempt failed', [
                    'attempt' => $attempt,
                    'of' => self::DOWNLOAD_ATTEMPTS,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < self::DOWNLOAD_ATTEMPTS) {
                    sleep($attempt * 3);
                }
            }
        }

        Log::error(static::class . ': Stream download failed', [
            'attempts' => self::DOWNLOAD_ATTEMPTS,
            'error' => $lastError?->getMessage(),
        ]);

        throw $lastError ?? new \Exception('Download failed');
    }

    /**
     * One download attempt. Throws when the transfer is incomplete.
     */
    private function streamOnce(string $downloadUrl, string $outputAbsolutePath, int $attempt): void
    {
        Log::info(static::class . ': Starting stream download', [
            'download_url' => $downloadUrl,
            'output_path' => $outputAbsolutePath,
            'attempt' => $attempt,
        ]);

        $dir = dirname($outputAbsolutePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $response = Http::withOptions([
            'stream' => true,
            // No overall cap: a two-hour source can legitimately take longer
            // than any fixed budget. A stalled connection is caught by the
            // read timeout below instead of killing a healthy slow transfer.
            'timeout' => 0,
            'read_timeout' => 120,
            'connect_timeout' => 30,
        ])->get($downloadUrl);

        if (!$response->successful()) {
            throw new \Exception('Download failed with status ' . $response->status());
        }

        $expectedBytes = (int) ($response->header('Content-Length') ?: 0);

        $handle = fopen($outputAbsolutePath, 'wb');
        if (!$handle) {
            throw new \Exception("Cannot open output file for writing: {$outputAbsolutePath}");
        }

        try {
            $body = $response->toPsrResponse()->getBody();
            $totalBytes = 0;
            $chunkSize = 262144; // 256KB chunks

            while (!$body->eof()) {
                $chunk = $body->read($chunkSize);
                if ($chunk === '') {
                    break;
                }

                $written = fwrite($handle, $chunk);
                if ($written === false) {
                    throw new \Exception('Error writing to output file');
                }

                $totalBytes += $written;
            }
        } finally {
            fclose($handle);
        }

        $fileSize = filesize($outputAbsolutePath);
        if ($fileSize === 0 || $fileSize === false) {
            throw new \Exception('Downloaded file is empty — download may have failed');
        }

        if ($expectedBytes > 0 && $fileSize < $expectedBytes) {
            throw new \Exception(sprintf(
                'Download truncated: got %d of %d bytes (%.1f%%)',
                $fileSize,
                $expectedBytes,
                100 * $fileSize / $expectedBytes
            ));
        }

        Log::info(static::class . ': Stream download complete', [
            'output_path' => $outputAbsolutePath,
            'file_size_bytes' => $fileSize,
            'expected_bytes' => $expectedBytes ?: 'not advertised',
            'attempt' => $attempt,
        ]);
    }
}
