<?php

namespace Modules\Project\Services;

use FFMpeg\FFProbe;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * ChatterboxTtsService
 *
 * Narration synthesis via fal.ai Chatterbox TTS (same fal account/key already
 * used for image generation). Uses the synchronous fal endpoint
 * (https://fal.run/fal-ai/chatterbox/text-to-speech) which blocks until the
 * clip is ready, then downloads it to public storage.
 *
 * Chatterbox returns only an audio URL — no duration and no word-level
 * timestamps — so the caller probes the downloaded file with ffprobe and uses
 * that real duration to pace the scene.
 *
 * Request:  { text, audio_url?, exaggeration, temperature, cfg, seed? }
 * Response: { audio: { url } }
 */
class ChatterboxTtsService
{
    private string $endpoint;
    private ?string $authToken;
    private ?string $referenceAudio;
    private float $exaggeration;
    private float $temperature;
    private float $cfg;

    public function __construct()
    {
        $this->endpoint = (string) config('services.fal_ai.tts_endpoint', 'https://fal.run/fal-ai/chatterbox/text-to-speech');
        $this->authToken = config('services.fal_ai.auth_token') ?: env('FAL_AI_AUTH_TOKEN');
        $this->referenceAudio = config('services.fal_ai.tts_reference_audio') ?: null;
        $this->exaggeration = (float) config('services.fal_ai.tts_exaggeration', 0.25);
        $this->temperature = (float) config('services.fal_ai.tts_temperature', 0.7);
        $this->cfg = (float) config('services.fal_ai.tts_cfg', 0.5);
    }

    public function isConfigured(): bool
    {
        return !empty($this->authToken);
    }

    /**
     * Synthesize narration text to an audio file in public storage.
     *
     * @param  array  $options ['reference_audio_url' => string, 'seed' => int]
     * @return array{success: bool, audio_path?: string, duration?: float, error?: string}
     */
    public function synthesize(string $text, string $outputRelativePath, array $options = []): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['success' => false, 'error' => 'Empty narration text'];
        }
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'FAL_AI_AUTH_TOKEN is not set'];
        }

        $payload = [
            'text' => mb_substr($text, 0, 5000),
            'exaggeration' => $this->exaggeration,
            'temperature' => $this->temperature,
            'cfg' => $this->cfg,
        ];

        $reference = $options['reference_audio_url'] ?? $this->referenceAudio;
        if (!empty($reference)) {
            $payload['audio_url'] = $reference;
        }
        if (isset($options['seed'])) {
            $payload['seed'] = (int) $options['seed'];
        }

        try {
            $response = Http::withHeaders(['Authorization' => 'Key ' . $this->authToken])
                ->timeout(180)
                ->post($this->endpoint, $payload);

            if (!$response->successful()) {
                Log::error('ChatterboxTtsService: HTTP error', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 400),
                ]);
                return ['success' => false, 'error' => "TTS HTTP {$response->status()}"];
            }

            $audioUrl = $response->json('audio.url');
            if (empty($audioUrl)) {
                Log::error('ChatterboxTtsService: no audio.url in response', ['body' => substr($response->body(), 0, 400)]);
                return ['success' => false, 'error' => 'TTS returned no audio URL'];
            }

            $audioBytes = Http::timeout(120)->get($audioUrl)->body();
            if (empty($audioBytes)) {
                return ['success' => false, 'error' => 'Failed to download TTS audio'];
            }

            Storage::disk('public')->put($outputRelativePath, $audioBytes);
            $duration = $this->probeDuration(Storage::disk('public')->path($outputRelativePath));

            Log::info('ChatterboxTtsService: synthesized narration', [
                'path' => $outputRelativePath,
                'duration' => $duration,
                'chars' => strlen($text),
            ]);

            return ['success' => true, 'audio_path' => $outputRelativePath, 'duration' => $duration];
        } catch (\Throwable $e) {
            Log::error('ChatterboxTtsService: exception ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function probeDuration(string $absolutePath): float
    {
        try {
            return (float) FFProbe::create()->format($absolutePath)->get('duration');
        } catch (\Throwable $e) {
            Log::warning('ChatterboxTtsService: ffprobe failed, defaulting duration: ' . $e->getMessage());
            return 0.0;
        }
    }
}
