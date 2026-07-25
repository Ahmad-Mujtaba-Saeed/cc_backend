<?php

namespace Modules\Project\Services;

use App\Services\PythonAIService;
use Illuminate\Support\Facades\Log;

class SceneAssemblyService
{
    private PythonAIService $pythonAI;

    public function __construct()
    {
        $this->pythonAI = new PythonAIService();
    }

    public function assembleScenes(array $sceneDefinitions, string $audioPath, string $outputPath, array $settings = []): ?string
    {
        try {
            if (empty($sceneDefinitions)) {
                throw new \Exception('No scene definitions provided for assembly');
            }

            if (!$this->pythonAI->isAvailable()) {
                throw new \Exception('Python AI service is not available for scene assembly');
            }

            $result = $this->pythonAI->assembleScenes($sceneDefinitions, $audioPath, $outputPath, $settings);

            if (!($result['success'] ?? false)) {
                throw new \Exception($result['error'] ?? 'Scene assembly failed');
            }

            $assembledPath = $result['output_path'] ?? $outputPath;

            Log::info('Scene assembly completed', [
                'scene_count' => count($sceneDefinitions),
                'output_path' => $assembledPath,
            ]);

            return $assembledPath;
        } catch (\Exception $e) {
            Log::error('SceneAssemblyService failed: ' . $e->getMessage());
            return null;
        }
    }
}
