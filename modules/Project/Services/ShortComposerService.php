<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Log;

use App\Services\PythonAIService;
/**
 * ShortComposerService
 * 
 * Composes final 9:16 short from:
 * - Main clip (60% top panel: 1080x1152)
 * - Gameplay clip (40% bottom panel: 1080x768)
 * - Karaoke captions (ASS file)
 * 
 * Final output: 1080x1920
 * Calls Python endpoint /compose-short
 */
class ShortComposerService
{
    private PythonAIService $pythonService;

    public function __construct()
    {
        $this->pythonService = new PythonAIService();
    }

    /**
     * Compose final short video.
     *
     * Pass null for $gameplayClipPath to render the main clip full-frame
     * (1080x1920) with no gameplay panel.
     *
     * $settings['focus_x'] (0..1) is the subject's horizontal centre in the
     * main clip; 0.5 is a plain centre crop.
     *
     * Returns: ['path' => string, 'duration' => float|null] — the duration is
     * MEASURED from the rendered file, not predicted.
     * Throws: on failure
     */
    public function compose(
        string $mainClipPath,
        ?string $gameplayClipPath,
        string $captionsAssPath,
        string $outputPath,
        int $projectId,
        array $settings = []
    ): array
    {
        $aspectRatio = $settings['aspect_ratio'] ?? '9:16';
        $captionPosition = $settings['caption_position'] ?? 'top_section';
        $focusX = min(1.0, max(0.0, (float) ($settings['focus_x'] ?? 0.5)));
        $withGameplay = !empty($gameplayClipPath);

        Log::info('ShortComposerService: Starting compose', [
            'main_clip' => basename($mainClipPath),
            'focus_x' => $focusX,
            'gameplay_clip' => $withGameplay ? basename($gameplayClipPath) : null,
            'mode' => $withGameplay ? 'split (main + gameplay)' : 'fullscreen (no gameplay)',
            'captions_ass' => basename($captionsAssPath),
            'aspect_ratio' => $aspectRatio,
            'caption_position' => $captionPosition,
            'output' => basename($outputPath)
        ]);

        try {
            // Build request for Python endpoint
            $payload = [
                'main_clip_path' => $mainClipPath,
                'captions_ass_path' => $captionsAssPath,
                'output_path' => $outputPath,
                'project_id' => $projectId,
                'aspect_ratio' => $aspectRatio,
                'caption_position' => $captionPosition,
                'focus_x' => $focusX,
            ];

            if ($withGameplay) {
                $payload['gameplay_clip_path'] = $gameplayClipPath;
            }

            $response = $this->pythonService->makeRequest('POST', '/compose-short', $payload);

            if (!$response['success']) {
                throw new \Exception($response['detail'] ?? 'Compose failed');
            }

            $fileSize = filesize($outputPath);
            if (!$fileSize || $fileSize === 0) {
                throw new \Exception('Output video file is empty');
            }

            $duration = isset($response['duration']) ? (float) $response['duration'] : null;

            Log::info('ShortComposerService: Compose complete', [
                'output_path' => $outputPath,
                'file_size' => $fileSize,
                'duration' => $duration,
                'focus_x' => $focusX,
                'final_dimensions' => '1080x1920',
                'panels' => $withGameplay
                    ? ['top' => '1080x1152 (main clip)', 'bottom' => '1080x768 (gameplay)']
                    : ['full' => '1080x1920 (main clip)']
            ]);

            return ['path' => $outputPath, 'duration' => $duration];

        } catch (\Exception $e) {
            Log::error('ShortComposerService: Compose failed', [
                'error' => $e->getMessage(),
                'main_clip' => basename($mainClipPath),
                'gameplay_clip' => $withGameplay ? basename($gameplayClipPath) : null
            ]);
            throw $e;
        }
    }
}
