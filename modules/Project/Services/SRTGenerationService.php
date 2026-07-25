<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SRTGenerationService
{
    /**
     * Generate SRT file from transcription data.
     */
    public function generateSRT(array $transcription, string $outputPath, ?string $rewrittenText = null): bool
    {
        try {
            Log::info('Starting SRT generation');

            $segments = $transcription['segments'] ?? [];
            if ($rewrittenText !== null && trim($rewrittenText) !== '') {
                $segments = $this->remapSegmentsToRewrittenText($segments, $rewrittenText);
            }

            $srtContent = $this->formatTranscriptionToSRT($segments);
            
            $success = Storage::disk('public')->put($outputPath, $srtContent);

            if (!$success) {
                throw new \Exception('Failed to write SRT file');
            }

            Log::info('SRT generation completed', [
                'output_path' => $outputPath,
                'segments_count' => count($segments)
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('SRT generation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Format transcription segments to SRT format.
     */
    private function formatTranscriptionToSRT(array $segments): string
    {
        $srtLines = [];
        $index = 1;

        foreach ($segments as $segment) {
            $startTime = $this->formatTime($segment['start'] ?? 0);
            $endTime = $this->formatTime($segment['end'] ?? 0);
            $text = $segment['text'] ?? '';

            $srtLines[] = $index;
            $srtLines[] = $startTime . ' --> ' . $endTime;
            $srtLines[] = $text;
            $srtLines[] = ''; // Empty line between segments

            $index++;
        }

        return implode("\n", $srtLines);
    }

    /**
     * Map rewritten script text onto original transcription segment timings.
     */
    private function remapSegmentsToRewrittenText(array $segments, string $rewrittenText): array
    {
        if (empty($segments)) {
            return [];
        }

        $words = preg_split('/\s+/', trim($rewrittenText));
        $wordCount = count($words);
        $segmentCount = count($segments);

        if ($wordCount === 0) {
            return array_map(fn($segment) => array_merge($segment, ['text' => '']), $segments);
        }

        $wordsPerSegment = max(1, (int) ceil($wordCount / $segmentCount));
        $chunks = array_chunk($words, $wordsPerSegment);

        return array_map(function ($segment, $index) use ($chunks) {
            $text = isset($chunks[$index]) ? implode(' ', $chunks[$index]) : '';
            return array_merge($segment, ['text' => $text]);
        }, $segments, array_keys($segments));
    }

    /**
     * Format time in seconds to SRT time format (HH:MM:SS,mmm).
     */
    private function formatTime(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = floor($seconds % 60);
        $milliseconds = ($seconds - floor($seconds)) * 1000;

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $secs, $milliseconds);
    }
}
