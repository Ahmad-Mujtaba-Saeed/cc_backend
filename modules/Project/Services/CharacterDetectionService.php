<?php

namespace Modules\Project\Services;

use App\Services\PythonAIService;
use Illuminate\Support\Facades\Log;

class CharacterDetectionService
{
    private PythonAIService $pythonAI;

    public function __construct()
    {
        $this->pythonAI = new PythonAIService();
    }

    public function detectCharacters(array $imagePaths): ?array
    {
        try {
            if (empty($imagePaths)) {
                throw new \Exception('No image paths provided for character detection');
            }

            if (!$this->pythonAI->isAvailable()) {
                throw new \Exception('Python AI service is not available for character detection');
            }

            $result = $this->pythonAI->detectCharacters($imagePaths);

            if (!($result['success'] ?? false)) {
                throw new \Exception($result['error'] ?? 'Character detection failed');
            }

            Log::info('Character detection completed', [
                'image_count' => count($imagePaths),
                'characters_detected' => count($result['characters'] ?? []),
            ]);

            return $result['characters'] ?? [];
        } catch (\Exception $e) {
            Log::error('CharacterDetectionService failed: ' . $e->getMessage());
            return null;
        }
    }
}
