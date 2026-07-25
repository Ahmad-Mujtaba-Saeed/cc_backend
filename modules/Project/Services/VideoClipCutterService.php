<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Log;

use App\Services\PythonAIService;

/**
 * VideoClipCutterService
 * 
 * Cuts a segment from a video using FFmpeg (fast seek + stream copy).
 * Calls Python endpoint /cut-video-clip.
 */
class VideoClipCutterService
{
    private PythonAIService $pythonService;

    public function __construct()
    {
        $this->pythonService = new PythonAIService();
    }

    /**
     * Cut a clip from source video
     * 
     * Uses FFmpeg fast seeking (-ss before -i) and stream copy (-c copy).
     * This is 10-100x faster than re-encoding and preserves quality.
     * 
     * Returns: output path
     * Throws: on failure
     */
    public function cut(
        string $sourcePath,
        float $startSeconds,
        float $endSeconds,
        string $outputPath,
        int $projectId
    ): string
    {
        $duration = $endSeconds - $startSeconds;

        Log::info('VideoClipCutterService: Starting cut', [
            'source' => basename($sourcePath),
            'start' => $startSeconds,
            'end' => $endSeconds,
            'duration' => $duration,
            'output' => basename($outputPath)
        ]);

        try {
            // Build request for Python endpoint
            $payload = [
                'source_path' => $sourcePath,
                'start_seconds' => $startSeconds,
                'end_seconds' => $endSeconds,
                'output_path' => $outputPath,
                'project_id' => $projectId,
            ];

            $response = $this->pythonService->makeRequest('POST', '/cut-video-clip', $payload);

            if (!$response['success']) {
                throw new \Exception($response['detail'] ?? 'FFmpeg cut failed');
            }

            $fileSize = filesize($outputPath);
            if (!$fileSize || $fileSize === 0) {
                throw new \Exception('Output clip file is empty');
            }

            Log::info('VideoClipCutterService: Cut complete', [
                'output_path' => $outputPath,
                'file_size' => $fileSize,
                'duration' => $response['duration'] ?? $duration
            ]);

            return $outputPath;

        } catch (\Exception $e) {
            Log::error('VideoClipCutterService: Cut failed', [
                'error' => $e->getMessage(),
                'start' => $startSeconds,
                'end' => $endSeconds
            ]);
            throw $e;
        }
    }

    /**
     * Cut multiple keep-ranges from the source and concatenate them into a
     * single clip (skips the silent/empty parts between ranges).
     *
     * A single range degrades to the fast contiguous cut() path; multiple
     * ranges call the Python /cut-video-segments endpoint (re-encode).
     *
     * @param array<int, array{start: float, end: float}> $ranges source-time keep ranges, ordered
     */
    public function cutSegments(
        string $sourcePath,
        array $ranges,
        string $outputPath,
        int $projectId
    ): string
    {
        $ranges = array_values($ranges);

        if (empty($ranges)) {
            throw new \Exception('No keep-ranges provided for segment cut');
        }

        if (count($ranges) === 1) {
            return $this->cut($sourcePath, $ranges[0]['start'], $ranges[0]['end'], $outputPath, $projectId);
        }

        $keptDuration = array_sum(array_map(fn ($r) => $r['end'] - $r['start'], $ranges));

        Log::info('VideoClipCutterService: Starting segment cut', [
            'source' => basename($sourcePath),
            'ranges' => count($ranges),
            'kept_duration' => round($keptDuration, 2),
            'output' => basename($outputPath)
        ]);

        try {
            $payload = [
                'source_path' => $sourcePath,
                'segments' => array_map(fn ($r) => [
                    'start_seconds' => (float) $r['start'],
                    'end_seconds' => (float) $r['end'],
                ], $ranges),
                'output_path' => $outputPath,
                'project_id' => $projectId,
            ];

            $response = $this->pythonService->makeRequest('POST', '/cut-video-segments', $payload);

            if (!$response['success']) {
                throw new \Exception($response['detail'] ?? 'FFmpeg segment cut failed');
            }

            $fileSize = filesize($outputPath);
            if (!$fileSize || $fileSize === 0) {
                throw new \Exception('Output clip file is empty');
            }

            Log::info('VideoClipCutterService: Segment cut complete', [
                'output_path' => $outputPath,
                'file_size' => $fileSize,
                'duration' => $response['duration'] ?? $keptDuration
            ]);

            return $outputPath;

        } catch (\Exception $e) {
            Log::error('VideoClipCutterService: Segment cut failed', [
                'error' => $e->getMessage(),
                'ranges' => count($ranges)
            ]);
            throw $e;
        }
    }
}
