<?php

namespace Modules\Project\Services;

use App\Services\PythonAIService;
use Illuminate\Support\Facades\Log;

class ImageGenerationService
{
    private PythonAIService $pythonAI;

    public function __construct()
    {
        $this->pythonAI = new PythonAIService();
    }

    public function generateImages($script, int $sceneCount = 5, string $visualStyle = 'cinematic', array $options = []): ?array
    {
        try {
            if (!$this->pythonAI->isAvailable()) {
                throw new \Exception('Python AI service is not available for image generation');
            }

            $result = $this->pythonAI->generateImages($script, $sceneCount, $visualStyle, $options);

            if (!($result['success'] ?? false)) {
                throw new \Exception($result['error'] ?? 'Image generation failed');
            }

            $images = array_values($result['images'] ?? []);
            if (empty($images)) {
                throw new \Exception('Image generation returned no image paths');
            }

            // Ensure we have at least $sceneCount images. If not, pad by repeating the last image and log a warning.
            if (count($images) < $sceneCount) {
                Log::warning('ImageGenerationService: fewer images returned than requested, padding with last image', [
                    'requested' => $sceneCount,
                    'returned' => count($images),
                    'project_id' => $options['project_id'] ?? null,
                ]);
                $last = end($images);
                while (count($images) < $sceneCount) {
                    $images[] = $last;
                }
            }

            Log::info('Image generation completed', [
                'image_count' => count($images),
                'visual_style' => $visualStyle,
                'sample_paths' => array_slice($images, 0, 5),
            ]);

            return $images;
        } catch (\Exception $e) {
            Log::error('ImageGenerationService failed: ' . $e->getMessage());
            return null;
        }
    }
}
