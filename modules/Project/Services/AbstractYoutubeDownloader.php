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

    /**
     * Stream a remote video file to storage.
     *
     * Uses streaming (not buffering into memory) to handle large files.
     * Opens output in write-binary mode to handle video data correctly.
     */
    public function streamToStorage(string $downloadUrl, string $outputAbsolutePath): void
    {
        Log::info(static::class . ': Starting stream download', [
            'download_url' => $downloadUrl,
            'output_path' => $outputAbsolutePath,
        ]);

        try {
            $dir = dirname($outputAbsolutePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $response = Http::withOptions([
                'stream' => true,
                'timeout' => 300, // 5 minutes for large files
            ])->get($downloadUrl);

            if (!$response->successful()) {
                throw new \Exception('Download failed with status ' . $response->status());
            }

            $handle = fopen($outputAbsolutePath, 'wb');
            if (!$handle) {
                throw new \Exception("Cannot open output file for writing: {$outputAbsolutePath}");
            }

            $body = $response->toPsrResponse()->getBody();
            $totalBytes = 0;
            $chunkSize = 8192; // 8KB chunks

            while (!$body->eof()) {
                $chunk = $body->read($chunkSize);
                if ($chunk === '') {
                    break;
                }

                $written = fwrite($handle, $chunk);
                if ($written === false) {
                    fclose($handle);
                    throw new \Exception('Error writing to output file');
                }

                $totalBytes += $written;
            }

            fclose($handle);

            $fileSize = filesize($outputAbsolutePath);
            if ($fileSize === 0 || $fileSize === false) {
                @unlink($outputAbsolutePath);
                throw new \Exception('Downloaded file is empty — download may have failed');
            }

            Log::info(static::class . ': Stream download complete', [
                'output_path' => $outputAbsolutePath,
                'file_size_bytes' => $fileSize,
                'chunks_written' => ceil($totalBytes / $chunkSize),
            ]);
        } catch (\Exception $e) {
            @unlink($outputAbsolutePath); // Clean up partial file
            Log::error(static::class . ': Stream download failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
