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
     * Returns: path to output video
     * Throws: on failure
     */
    public function compose(
        string $mainClipPath,
        ?string $gameplayClipPath,
        string $captionsAssPath,
        string $outputPath,
        int $projectId,
        array $settings = []
    ): string
    {
        $aspectRatio = $settings['aspect_ratio'] ?? '9:16';
        $captionPosition = $settings['caption_position'] ?? 'top_section';
        $withGameplay = !empty($gameplayClipPath);

        Log::info('ShortComposerService: Starting compose', [
            'main_clip' => basename($mainClipPath),
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

            Log::info('ShortComposerService: Compose complete', [
                'output_path' => $outputPath,
                'file_size' => $fileSize,
                'final_dimensions' => '1080x1920',
                'panels' => $withGameplay
                    ? ['top' => '1080x1152 (main clip)', 'bottom' => '1080x768 (gameplay)']
                    : ['full' => '1080x1920 (main clip)']
            ]);

            return $outputPath;

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
