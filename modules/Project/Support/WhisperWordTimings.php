<?php

namespace Modules\Project\Support;

use App\Services\PythonAIService;
use Illuminate\Support\Facades\Log;

/**
 * Per-word timestamps for narration from an engine that does not report them
 * (OpenAI gpt-4o-mini-tts, cloned voices): the finished audio is transcribed
 * by the Whisper endpoint in the Python ai service and flattened into the same
 * [{word,start,end}] shape Kokoro emits.
 *
 * Best-effort: an empty array is a valid result — callers already fall back
 * to synthetic, evenly spaced timings.
 */
final class WhisperWordTimings
{
    /** @return array<int, array{word: string, start: float, end: float}> */
    public static function recover(string $audioAbsPath, string $logTag = 'TTS'): array
    {
        try {
            $result = (new PythonAIService())->transcribe($audioAbsPath, 'en', true);

            if (!($result['success'] ?? false)) {
                Log::warning("{$logTag}: whisper timing recovery failed", [
                    'error' => $result['error'] ?? 'unknown',
                ]);
                return [];
            }

            $timings = [];
            foreach ($result['segments'] ?? [] as $segment) {
                foreach ($segment['words'] ?? [] as $word) {
                    $token = trim((string) ($word['word'] ?? ''));
                    if ($token === '') {
                        continue;
                    }
                    $timings[] = [
                        'word' => $token,
                        'start' => round((float) ($word['start'] ?? 0), 4),
                        'end' => round((float) ($word['end'] ?? 0), 4),
                    ];
                }
            }

            return $timings;
        } catch (\Throwable $e) {
            Log::warning("{$logTag}: whisper timing recovery threw", ['error' => $e->getMessage()]);
            return [];
        }
    }
}
