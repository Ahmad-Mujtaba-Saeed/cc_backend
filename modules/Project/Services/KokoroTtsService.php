<?php

namespace Modules\Project\Services;

use App\Services\PythonAIService;
use FFMpeg\FFProbe;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * KokoroTtsService
 *
 * Narration synthesis via the SELF-HOSTED Kokoro model that already runs inside
 * the Python `ai` service (`POST /tts`). Unlike the paid fal Chatterbox endpoint
 * ({@see ChatterboxTtsService}, ~$0.02/request), Kokoro runs on our own compute,
 * so narration costs nothing per request.
 *
 * Drop-in replacement: exposes the same isConfigured()/synthesize() contract as
 * ChatterboxTtsService, so the explainer processor swaps services in one line.
 *
 * The Python service writes the .wav into shared storage (host ./storage is
 * mounted into both the `worker` and `ai` containers), so once it returns we can
 * read the file straight from the public disk and probe its duration.
 */
class KokoroTtsService
{
    private string $voice;

    public function __construct()
    {
        // af_bella is generally the most natural-sounding Kokoro voice.
        $this->voice = (string) config('services.kokoro.voice', 'af_bella');
    }

    /**
     * Kokoro is self-hosted (no API key). Always "configured" — reachability is
     * handled gracefully by synthesize() returning a failure instead.
     */
    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * Synthesize narration text to an audio file in public storage.
     *
     * With `word_timings => true` the warm Kokoro pipeline also returns real
     * per-token timestamps ({word, start, end} in seconds) — the raw material
     * for narration-synced overlays like punchlines.
     *
     * @param  array  $options ['voice' => string, 'speed' => float, 'word_timings' => bool]
     * @return array{success: bool, audio_path?: string, duration?: float, word_timings?: array, error?: string}
     */
    public function synthesize(string $text, string $outputRelativePath, array $options = []): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['success' => false, 'error' => 'Empty narration text'];
        }

        $voice = $options['voice'] ?? $this->voice;
        $wantTimings = (bool) ($options['word_timings'] ?? false);
        $absolutePath = Storage::disk('public')->path($outputRelativePath);

        // Ensure the target directory exists before the Python service writes.
        $dir = dirname($absolutePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        try {
            $result = (new PythonAIService())->generateTTS($text, $voice, $absolutePath, $wantTimings);

            if (!($result['success'] ?? false)) {
                Log::error('KokoroTtsService: generation failed', ['error' => $result['error'] ?? 'unknown']);
                return ['success' => false, 'error' => $result['error'] ?? 'Kokoro TTS failed'];
            }

            if (!Storage::disk('public')->exists($outputRelativePath)) {
                return ['success' => false, 'error' => 'Kokoro TTS reported success but produced no file'];
            }

            $duration = $this->probeDuration($absolutePath);

            Log::info('KokoroTtsService: synthesized narration', [
                'path' => $outputRelativePath,
                'voice' => $voice,
                'duration' => $duration,
                'chars' => strlen($text),
                'word_timings' => $wantTimings ? count($result['word_timings'] ?? []) : null,
            ]);

            return [
                'success' => true,
                'audio_path' => $outputRelativePath,
                'duration' => $duration,
                'word_timings' => $wantTimings ? (array) ($result['word_timings'] ?? []) : [],
            ];
        } catch (\Throwable $e) {
            Log::error('KokoroTtsService: exception ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function probeDuration(string $absolutePath): float
    {
        try {
            return (float) FFProbe::create()->format($absolutePath)->get('duration');
        } catch (\Throwable $e) {
            Log::warning('KokoroTtsService: ffprobe failed, defaulting duration: ' . $e->getMessage());
            return 0.0;
        }
    }
}
