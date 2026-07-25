<?php

namespace Modules\Project\Services;

use App\Services\PythonAIService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Project\Services\CostTracker;
use Symfony\Component\Process\Process;

/**
 * Narration via OpenAI's gpt-4o-mini-tts speech endpoint.
 *
 * Unlike Kokoro this engine takes natural-language delivery `instructions`
 * (built per template by {@see \Modules\Project\Support\TtsInstructions}),
 * but it returns no word timings — when a caller needs them (karaoke
 * captions, punchlines) the finished audio is transcribed with the existing
 * Whisper endpoint in the Python ai service and its per-word timestamps are
 * flattened into the same [{word,start,end}] shape Kokoro produces.
 *
 * The speech endpoint caps input at 4096 characters, so long scripts are
 * split at sentence boundaries and the WAV parts are joined with ffmpeg.
 */
class OpenAiTtsService
{
    private const ENDPOINT = 'https://api.openai.com/v1/audio/speech';
    private const MAX_CHUNK_CHARS = 3500;

    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = (string) config('services.openai.api_key');
        $this->model = (string) config('services.openai.tts_model', 'gpt-4o-mini-tts');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Same contract as PythonAIService::generateTTS so the router can swap
     * engines transparently: writes a WAV to $outputPath (PHP-absolute).
     *
     * @return array{success: bool, audio_path?: string, word_timings?: array, error?: string}
     */
    public function generateTTS(string $text, string $voice, string $outputPath, bool $wordTiming = false, ?string $instructions = null): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['success' => false, 'error' => 'Empty narration text'];
        }
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'OPENAI_API_KEY not configured'];
        }

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $chunks = $this->splitIntoChunks($text);

        Log::info('OpenAiTtsService: synthesizing', [
            'voice' => $voice,
            'model' => $this->model,
            'chars' => strlen($text),
            'chunks' => count($chunks),
            'instructions' => $instructions ? substr($instructions, 0, 120) : null,
            'word_timing' => $wordTiming,
        ]);

        $partPaths = [];

        try {
            foreach ($chunks as $i => $chunk) {
                $partPath = count($chunks) === 1 ? $outputPath : $outputPath . ".part{$i}.wav";

                $payload = [
                    'model' => $this->model,
                    'input' => $chunk,
                    'voice' => $voice,
                    'response_format' => 'wav',
                ];
                if ($instructions !== null && $instructions !== '') {
                    $payload['instructions'] = $instructions;
                }

                $response = Http::withToken($this->apiKey)
                    ->timeout(180)
                    ->post(self::ENDPOINT, $payload);

                if (!$response->successful()) {
                    // Error bodies are JSON even though success bodies are binary.
                    $detail = substr($response->body(), 0, 400);
                    throw new \RuntimeException("OpenAI TTS HTTP {$response->status()}: {$detail}");
                }

                file_put_contents($partPath, $response->body());
                if (!file_exists($partPath) || filesize($partPath) === 0) {
                    throw new \RuntimeException('OpenAI TTS returned an empty audio body');
                }

                $partPaths[] = $partPath;
            }

            if (count($partPaths) > 1) {
                $this->concatWavParts($partPaths, $outputPath);
            }
        } catch (\Throwable $e) {
            Log::error('OpenAiTtsService: synthesis failed', ['error' => $e->getMessage()]);
            $this->cleanupParts($partPaths, $outputPath);

            return ['success' => false, 'error' => $e->getMessage()];
        }

        $this->cleanupParts($partPaths, $outputPath);

        // Ledger: OpenAI TTS bills by produced audio. The wav is 24kHz 16-bit
        // mono (48000 bytes/s), so duration falls out of the file size — no
        // ffprobe needed on this hot path.
        $audioSeconds = max(0, (filesize($outputPath) - 44)) / 48000;
        CostTracker::recordOpenAiTts($audioSeconds, strlen($text), 'narration_tts');

        $wordTimings = [];
        if ($wordTiming) {
            $wordTimings = $this->recoverWordTimings($outputPath);
        }

        return [
            'success' => true,
            'audio_path' => $outputPath,
            'word_timings' => $wordTimings,
        ];
    }

    /**
     * Whisper the finished narration to get per-word timestamps. Best-effort:
     * an empty array is a valid result — callers already fall back to
     * synthetic/evenly-spaced timings.
     */
    private function recoverWordTimings(string $audioAbsPath): array
    {
        try {
            $result = (new PythonAIService())->transcribe($audioAbsPath, 'en', true);

            if (!($result['success'] ?? false)) {
                Log::warning('OpenAiTtsService: whisper timing recovery failed', [
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
            Log::warning('OpenAiTtsService: whisper timing recovery threw', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Sentence-boundary splitter keeping each chunk under the endpoint's
     * 4096-char input cap (with headroom). A single oversized "sentence"
     * is hard-split rather than dropped.
     *
     * @return string[]
     */
    private function splitIntoChunks(string $text): array
    {
        if (strlen($text) <= self::MAX_CHUNK_CHARS) {
            return [$text];
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [$text];
        $chunks = [];
        $current = '';

        foreach ($sentences as $sentence) {
            while (strlen($sentence) > self::MAX_CHUNK_CHARS) {
                $chunks[] = trim($current . ' ' . substr($sentence, 0, self::MAX_CHUNK_CHARS));
                $sentence = substr($sentence, self::MAX_CHUNK_CHARS);
                $current = '';
            }

            if ($current !== '' && strlen($current) + strlen($sentence) + 1 > self::MAX_CHUNK_CHARS) {
                $chunks[] = $current;
                $current = $sentence;
            } else {
                $current = $current === '' ? $sentence : $current . ' ' . $sentence;
            }
        }

        if (trim($current) !== '') {
            $chunks[] = $current;
        }

        return array_values(array_filter(array_map('trim', $chunks), fn ($c) => $c !== ''));
    }

    private function concatWavParts(array $partPaths, string $outputPath): void
    {
        $listPath = $outputPath . '.concat.txt';
        $lines = array_map(
            fn ($p) => "file '" . str_replace("'", "'\\''", $p) . "'",
            $partPaths
        );
        file_put_contents($listPath, implode("\n", $lines) . "\n");

        $process = new Process([
            'ffmpeg', '-y', '-f', 'concat', '-safe', '0',
            '-i', $listPath, '-c', 'copy', $outputPath,
        ]);
        $process->setTimeout(120);
        $process->run();

        @unlink($listPath);

        if (!$process->isSuccessful() || !file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new \RuntimeException('ffmpeg concat of TTS chunks failed: ' . substr($process->getErrorOutput(), -300));
        }
    }

    /** Remove intermediate .partN.wav files (never the final output). */
    private function cleanupParts(array $partPaths, string $outputPath): void
    {
        foreach ($partPaths as $part) {
            if ($part !== $outputPath && file_exists($part)) {
                @unlink($part);
            }
        }
    }
}
