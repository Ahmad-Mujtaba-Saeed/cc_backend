<?php

namespace Modules\Project\Services;

use Modules\Project\Support\LlmModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * VlmFrameReviewService (copilot.md §12.4) — the last quality gate.
 *
 * Samples 6 frames evenly across a rendered MP4, sends them in ONE cheap
 * vision call, and returns per-frame flags in strict JSON:
 *   { gibberish_text, empty_layout, cropped_subject, style_mismatch }
 *
 * The caller (ExplainerVideoProcessor) maps a flagged frame's timestamp back
 * to its scene, regenerates that scene's AI asset once, and re-renders — the
 * review itself only ever LOOKS. Tracked in CostTracker as 'vlm_review'.
 */
class VlmFrameReviewService
{
    public const FRAME_COUNT = 6;

    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: (string) env('OPENAI_API_KEY');
        $this->model = LlmModels::for('vlm');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @param  string  $videoAbsPath  the rendered MP4
     * @param  float  $durationSeconds  its real duration (ffprobe'd by caller)
     * @return array<int, array{time: float, gibberish_text: bool, empty_layout: bool,
     *               cropped_subject: bool, style_mismatch: bool}>|null
     *         null when the review could not run (never blocks the render)
     */
    public function review(string $videoAbsPath, float $durationSeconds): ?array
    {
        if (!$this->isConfigured() || !is_file($videoAbsPath) || $durationSeconds <= 0) {
            return null;
        }

        $samples = $this->sampleFrames($videoAbsPath, $durationSeconds);
        if (count($samples) < 2) {
            return null;
        }

        $content = [[
            'type' => 'text',
            'text' => 'You are a strict QA reviewer for an automated flat-design explainer video. '
                . 'For EACH numbered frame, report exactly these boolean flags: '
                . 'gibberish_text (an AI-generated image contains garbled/nonsense letterforms or pseudo-text — real UI screenshots and the video\'s own crisp typography do NOT count), '
                . 'empty_layout (more than ~60% of the frame is empty background with no meaningful content), '
                . 'cropped_subject (the main subject of a photo/illustration is badly cut off by the frame or a mask), '
                . 'style_mismatch (an image clashes hard with the flat vector look — e.g. a photorealistic render where every other visual is flat). '
                . 'Be conservative: flag only clear failures. '
                . 'Return ONLY JSON: {"frames":[{"index":1,"gibberish_text":false,"empty_layout":false,"cropped_subject":false,"style_mismatch":false}, ...]} '
                . 'with one entry per frame, in order.',
        ]];
        foreach ($samples as $i => $sample) {
            $content[] = ['type' => 'text', 'text' => 'Frame ' . ($i + 1) . ':'];
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $sample['data_uri'], 'detail' => 'low'],
            ];
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(90)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $this->model,
                    'messages' => [['role' => 'user', 'content' => $content]],
                    'temperature' => 0,
                    'max_tokens' => 600,
                    'response_format' => ['type' => 'json_object'],
                ]);
        } catch (\Throwable $e) {
            Log::warning('VlmFrameReviewService: request threw (non-fatal): ' . $e->getMessage());

            return null;
        }

        if (!$response->successful()) {
            Log::warning('VlmFrameReviewService: request failed (non-fatal)', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 300),
            ]);

            return null;
        }

        CostTracker::recordChat($this->model, $response->json('usage'), 'vlm_review');

        $parsed = json_decode((string) $response->json('choices.0.message.content'), true);
        $frames = is_array($parsed) ? (array) ($parsed['frames'] ?? []) : [];

        $out = [];
        foreach ($samples as $i => $sample) {
            $f = is_array($frames[$i] ?? null) ? $frames[$i] : [];
            $out[] = [
                'time' => $sample['time'],
                'gibberish_text' => (bool) ($f['gibberish_text'] ?? false),
                'empty_layout' => (bool) ($f['empty_layout'] ?? false),
                'cropped_subject' => (bool) ($f['cropped_subject'] ?? false),
                'style_mismatch' => (bool) ($f['style_mismatch'] ?? false),
            ];
        }

        return $out;
    }

    /** Any flag on any frame? */
    public static function flagged(array $review): array
    {
        return array_values(array_filter($review, fn ($f) => $f['gibberish_text']
            || $f['empty_layout'] || $f['cropped_subject'] || $f['style_mismatch']));
    }

    /**
     * Extract FRAME_COUNT stills spread across the video (skipping the very
     * first/last seconds — hooks and outros are typography, not assets),
     * downscaled to 640px and base64'd for the vision call.
     *
     * @return array<int, array{time: float, data_uri: string}>
     */
    private function sampleFrames(string $videoAbsPath, float $durationSeconds): array
    {
        $tmpDir = sys_get_temp_dir() . '/vlm_review_' . substr(md5($videoAbsPath . microtime()), 0, 8);
        @mkdir($tmpDir, 0775, true);

        $samples = [];
        try {
            for ($i = 0; $i < self::FRAME_COUNT; $i++) {
                // Even spread through the middle 90% of the runtime.
                $t = $durationSeconds * (0.05 + 0.9 * ($i + 0.5) / self::FRAME_COUNT);
                $out = $tmpDir . "/frame_{$i}.jpg";
                $ffmpeg = new Process([
                    'ffmpeg', '-v', 'error', '-y',
                    '-ss', number_format($t, 2, '.', ''), '-i', $videoAbsPath,
                    '-frames:v', '1', '-vf', 'scale=640:-2', '-q:v', '5', $out,
                ]);
                $ffmpeg->setTimeout(60);
                $ffmpeg->run();
                if (is_file($out) && filesize($out) > 0) {
                    $samples[] = [
                        'time' => round($t, 2),
                        'data_uri' => 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($out)),
                    ];
                }
            }
        } finally {
            foreach (glob($tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }

        return $samples;
    }
}
