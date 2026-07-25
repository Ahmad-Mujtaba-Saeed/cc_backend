<?php

namespace Modules\Project\Services;

use App\Services\PythonAIService;
use Illuminate\Support\Facades\Log;

class CharacterVibrateService
{
    private PythonAIService $pythonAI;

    public function __construct()
    {
        $this->pythonAI = new PythonAIService();
    }

    public function applyVibrateEffect(array $imagePaths, array $characterData, array $options = []): ?array
    {
        try {
            if (empty($imagePaths)) {
                throw new \Exception('No image paths provided for vibrate effect');
            }

            if (!$this->pythonAI->isAvailable()) {
                throw new \Exception('Python AI service is not available for vibrate effect');
            }

            $result = $this->pythonAI->applyVibrateEffect($imagePaths, $characterData, $options);

            if (!($result['success'] ?? false)) {
                throw new \Exception($result['error'] ?? 'Vibrate effect generation failed');
            }

            $animatedSegments = $result['animated_segments'] ?? [];

            if (empty($animatedSegments)) {
                throw new \Exception('Vibrate effect service returned no animated segments');
            }

            Log::info('Vibrate effect applied', [
                'segment_count' => count($animatedSegments),
            ]);

            return $animatedSegments;
        } catch (\Exception $e) {
            Log::error('CharacterVibrateService failed: ' . $e->getMessage());
            return null;
        }
    }
}
