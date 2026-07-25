<?php

namespace Modules\Project\Services;

use App\Services\PythonAIService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Exception;

class VideoEditingService
{
    private PythonAIService $pythonAI;

    public function __construct()
    {
        $this->pythonAI = new PythonAIService();
    }

    /**
     * Crop video to focus on faces using Python AI service
     */
    public function cropToFaces(string $videoPath, array $faceData, array $settings = []): array
    {
        try {
            Log::info("Starting face cropping for video: {$videoPath}");
            
            // Check if Python AI service is available
            if (!$this->pythonAI->isAvailable()) {
                throw new Exception('Python AI service is not available');
            }

            // Prepare output path
            $outputPath = $settings['output_path'] ?? 'cropped_video.mp4';
            
            // Call Python AI service
            $result = $this->pythonAI->editVideo(
                $videoPath,
                $outputPath,
                'face_crop',
                $settings,
                null, // no audio path for face cropping
                $faceData
            );

            if (!$result['success']) {
                Log::error("Face cropping failed: {$result['error']}");
                throw new Exception($result['error']);
            }

            Log::info("Face cropping completed successfully");
            return $result;

        } catch (Exception $e) {
            Log::error("Video editing service error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Automatically edit video based on content analysis using Python AI service
     */
    public function autoEdit(string $videoPath, string $audioPath, array $settings = []): ?string
    {
        try {
            Log::info('Starting automatic video editing');
            
            // Check if Python AI service is available
            if (!$this->pythonAI->isAvailable()) {
                throw new Exception('Python AI service is not available');
            }

            // Prepare output path
            $outputPath = $settings['output_path'] ?? 'auto_edited_video.mp4';
            
            // Call Python AI service
            $result = $this->pythonAI->editVideo(
                $videoPath,
                $outputPath,
                'auto_edit',
                $settings,
                $audioPath
            );

            if (!$result['success']) {
                Log::error("Auto edit failed: {$result['error']}");
                throw new Exception($result['error']);
            }

            Log::info("Auto edit completed successfully");
            return $result['output_path'] ?? $outputPath;
            
        } catch (Exception $e) {
            Log::error("Auto edit service error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Add smooth transitions between scenes.
     */
    public function addTransitions(string $videoPath, array $sceneData, array $settings = []): ?string
    {
        try {
            Log::info('Adding transitions to video');

            $outputPath = $settings['output_path'] ?? $this->generateTempPath();
            $transitionType = $settings['transition_type'] ?? 'fade';
            $transitionDuration = $settings['transition_duration'] ?? 1.0;

            $process = new Process([
                $this->pythonPath,
                $this->scriptPath,
                'add-transitions',
                $videoPath,
                '--output', $outputPath,
                '--transition-type', $transitionType,
                '--transition-duration', (string)$transitionDuration
            ]);

            $process->setInput(json_encode($sceneData));
            $process->setTimeout(300);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            Log::info('Video transitions added', [
                'output_path' => $outputPath,
                'transition_type' => $transitionType
            ]);

            return $outputPath;

        } catch (\Exception $e) {
            Log::error('Video transitions failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Stabilize video to reduce camera shake.
     */
    public function stabilizeVideo(string $videoPath, array $settings = []): ?string
    {
        try {
            Log::info('Starting video stabilization');

            $outputPath = $settings['output_path'] ?? $this->generateTempPath();
            $smoothing = $settings['smoothing'] ?? 10;
            $crop = $settings['crop'] ?? 'auto';

            $process = new Process([
                $this->pythonPath,
                $this->scriptPath,
                'stabilize',
                $videoPath,
                '--output', $outputPath,
                '--smoothing', (string)$smoothing,
                '--crop', $crop
            ]);

            $process->setTimeout(600);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            Log::info('Video stabilization completed', [
                'output_path' => $outputPath,
                'smoothing' => $smoothing
            ]);

            return $outputPath;

        } catch (\Exception $e) {
            Log::error('Video stabilization failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Enhance video quality.
     */
    public function enhanceVideo(string $videoPath, array $settings = []): ?string
    {
        try {
            Log::info('Starting video enhancement');

            $outputPath = $settings['output_path'] ?? $this->generateTempPath();
            $brightness = $settings['brightness'] ?? 0;
            $contrast = $settings['contrast'] ?? 0;
            $saturation = $settings['saturation'] ?? 0;
            $sharpen = $settings['sharpen'] ?? false;
            $denoise = $settings['denoise'] ?? false;

            $process = new Process([
                $this->pythonPath,
                $this->scriptPath,
                'enhance',
                $videoPath,
                '--output', $outputPath,
                '--brightness', (string)$brightness,
                '--contrast', (string)$contrast,
                '--saturation', (string)$saturation
            ]);

            if ($sharpen) {
                $process->add('--sharpen');
            }

            if ($denoise) {
                $process->add('--denoise');
            }

            $process->setTimeout(300);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            Log::info('Video enhancement completed', [
                'output_path' => $outputPath,
                'filters_applied' => ['brightness', 'contrast', 'saturation', 'sharpen', 'denoise']
            ]);

            return $outputPath;

        } catch (\Exception $e) {
            Log::error('Video enhancement failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Combine video with new audio track using Python AI service
     */
    public function combineWithAudio(string $videoPath, string $audioPath, array $settings = []): ?string
    {
        try {
            Log::info('Combining video with audio');
            
            // Check if Python AI service is available
            if (!$this->pythonAI->isAvailable()) {
                throw new Exception('Python AI service is not available');
            }

            // Prepare output path
            $outputPath = $settings['output_path'] ?? 'final_video.mp4';
            
            // Call Python AI service
            $result = $this->pythonAI->editVideo(
                $videoPath,
                $outputPath,
                'combine_audio',
                $settings,
                $audioPath
            );

            if (!$result['success']) {
                Log::error("Audio combination failed: {$result['error']}");
                throw new Exception($result['error']);
            }

            Log::info("Audio combination completed successfully");
            return $result['output_path'] ?? $outputPath;
            
        } catch (Exception $e) {
            Log::error("Audio combination service error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate video preview.
     */
    public function generatePreview(string $videoPath, array $settings = []): ?string
    {
        try {
            Log::info('Generating video preview');

            $outputPath = $settings['output_path'] ?? $this->generateTempPath();
            $duration = $settings['duration'] ?? 10;
            $resolution = $settings['resolution'] ?? '720p';
            $quality = $settings['quality'] ?? 'medium';

            $process = new Process([
                $this->pythonPath,
                $this->scriptPath,
                'preview',
                $videoPath,
                '--output', $outputPath,
                '--duration', (string)$duration,
                '--resolution', $resolution,
                '--quality', $quality
            ]);

            $process->setTimeout(120);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            Log::info('Video preview generated', [
                'output_path' => $outputPath,
                'duration' => $duration,
                'resolution' => $resolution
            ]);

            return $outputPath;

        } catch (\Exception $e) {
            Log::error('Video preview generation failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate temporary file path.
     */
    private function generateTempPath(string $extension = '.mp4'): string
    {
        $tempDir = sys_get_temp_dir();
        $fileName = 'video_edit_' . uniqid() . $extension;
        return $tempDir . '/' . $fileName;
    }

    /**
     * Clean up temporary files.
     */
    public function cleanup(string $filePath): void
    {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    /**
     * Get video information.
     */
    public function getVideoInfo(string $videoPath): ?array
    {
        try {
            $process = new Process([
                'ffprobe',
                '-v', 'quiet',
                '-print_format', 'json',
                '-show_format',
                '-show_streams',
                $videoPath
            ]);

            $process->setTimeout(30);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $output = $process->getOutput();
            $info = json_decode($output, true);

            return $info;

        } catch (\Exception $e) {
            Log::error('Failed to get video info: ' . $e->getMessage());
            return null;
        }
    }
}
