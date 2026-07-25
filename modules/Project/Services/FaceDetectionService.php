<?php

namespace Modules\Project\Services;

use App\Services\PythonAIService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Exception;

class FaceDetectionService
{
    private PythonAIService $pythonAI;

    public function __construct()
    {
        $this->pythonAI = new PythonAIService();
    }

    /**
     * Detect faces in video using Python AI service
     */
    public function detectFaces(string $videoPath, array $settings = []): array
    {
        try {
            Log::info("Starting face detection for video: {$videoPath}");
            
            // Check if Python AI service is available
            if (!$this->pythonAI->isAvailable()) {
                throw new Exception('Python AI service is not available');
            }

            // Extract detectObjects flag from settings
            $detectObjects = $settings['detectObjects'] ?? true;

            // Call Python AI service
            $result = $this->pythonAI->detectFaces($videoPath, $detectObjects);

            if (!$result['success']) {
                Log::error("Face detection failed: {$result['error']}");
                throw new Exception($result['error']);
            }

            Log::info("Face detection completed successfully");
            return $result;

        } catch (Exception $e) {
            Log::error("Face detection service error: " . $e->getMessage());
            throw $e;
            Log::error('Face detection failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Detect objects in video using YOLO.
     */
    public function detectObjects(string $videoPath, array $settings = []): ?array
    {
        try {
            Log::info('Starting object detection');

            $model = $settings['model'] ?? 'yolov8n';
            $confidence = $settings['confidence'] ?? 0.5;
            $targetClasses = $settings['target_classes'] ?? ['person'];
            $sampleRate = $settings['sample_rate'] ?? 1;

            $process = new Process([
                $this->pythonPath,
                $this->scriptPath,
                'detect-objects',
                $videoPath,
                '--model', $model,
                '--confidence', (string)$confidence,
                '--target-classes', implode(',', $targetClasses),
                '--sample-rate', (string)$sampleRate
            ]);

            $process->setTimeout(300);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $output = $process->getOutput();
            $objectData = json_decode($output, true);

            if (!$objectData) {
                throw new \Exception('Failed to parse object detection output');
            }

            Log::info('Object detection completed', [
                'objects_detected' => count($objectData['objects'] ?? []),
                'target_classes' => $targetClasses
            ]);

            return $objectData;

        } catch (\Exception $e) {
            Log::error('Object detection failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Track faces throughout the video.
     */
    public function trackFaces(string $videoPath, array $settings = []): ?array
    {
        try {
            Log::info('Starting face tracking');

            $confidence = $settings['confidence'] ?? 0.5;
            $maxFaces = $settings['max_faces'] ?? 5;
            $trackingDistance = $settings['tracking_distance'] ?? 0.3;

            $process = new Process([
                $this->pythonPath,
                $this->scriptPath,
                'track',
                $videoPath,
                '--confidence', (string)$confidence,
                '--max-faces', (string)$maxFaces,
                '--tracking-distance', (string)$trackingDistance
            ]);

            $process->setTimeout(600); // 10 minutes
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $output = $process->getOutput();
            $trackingData = json_decode($output, true);

            if (!$trackingData) {
                throw new \Exception('Failed to parse face tracking output');
            }

            Log::info('Face tracking completed', [
                'tracks_found' => count($trackingData['tracks'] ?? []),
                'video_duration' => $trackingData['video_duration'] ?? null
            ]);

            return $trackingData;

        } catch (\Exception $e) {
            Log::error('Face tracking failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Identify main speaker based on face tracking.
     */
    public function identifyMainSpeaker(array $faceTrackingData): ?array
    {
        try {
            $tracks = $faceTrackingData['tracks'] ?? [];
            
            if (empty($tracks)) {
                return null;
            }

            // Score each track based on:
            // 1. Total screen time
            // 2. Position (center preference)
            // 3. Size (larger preference)
            // 4. Consistency (fewer gaps)

            $scores = [];
            
            foreach ($tracks as $trackId => $track) {
                $score = 0;
                
                // Screen time score
                $screenTime = count($track['detections'] ?? []);
                $score += $screenTime * 10;
                
                // Position score (prefer center)
                $avgX = array_sum(array_column($track['detections'], 'x')) / count($track['detections']);
                $centerDistance = abs($avgX - 0.5);
                $score += (1 - $centerDistance) * 50;
                
                // Size score (prefer larger faces)
                $avgSize = array_sum(array_column($track['detections'], 'size')) / count($track['detections']);
                $score += $avgSize * 100;
                
                // Consistency score
                $gaps = $this->calculateGaps($track['detections'] ?? []);
                $score -= $gaps * 5;
                
                $scores[$trackId] = $score;
            }
            
            // Find track with highest score
            $mainTrackId = array_keys($scores, max($scores))[0];
            
            return [
                'track_id' => $mainTrackId,
                'track' => $tracks[$mainTrackId],
                'confidence' => $scores[$mainTrackId] / array_sum($scores),
                'reason' => 'Highest combined score for screen time, position, size, and consistency'
            ];
            
        } catch (\Exception $e) {
            Log::error('Main speaker identification failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Calculate gaps in face detection timeline.
     */
    private function calculateGaps(array $detections): int
    {
        if (empty($detections)) {
            return 0;
        }

        $gaps = 0;
        $lastTime = null;

        foreach ($detections as $detection) {
            $currentTime = $detection['timestamp'] ?? 0;
            
            if ($lastTime !== null && ($currentTime - $lastTime) > 2.0) {
                $gaps++;
            }
            
            $lastTime = $currentTime;
        }

        return $gaps;
    }

    /**
     * Get face detection statistics.
     */
    public function getStatistics(array $faceData): array
    {
        $faces = $faceData['faces'] ?? [];
        $frames = $faceData['frames_processed'] ?? 0;
        
        $totalFaces = 0;
        $faceSizes = [];
        $positions = ['x' => [], 'y' => []];
        
        foreach ($faces as $frameFaces) {
            $totalFaces += count($frameFaces);
            
            foreach ($frameFaces as $face) {
                $faceSizes[] = $face['width'] * $face['height'];
                $positions['x'][] = $face['x'] + ($face['width'] / 2);
                $positions['y'][] = $face['y'] + ($face['height'] / 2);
            }
        }
        
        return [
            'total_faces_detected' => $totalFaces,
            'average_faces_per_frame' => $frames > 0 ? $totalFaces / $frames : 0,
            'average_face_size' => count($faceSizes) > 0 ? array_sum($faceSizes) / count($faceSizes) : 0,
            'average_position' => [
                'x' => count($positions['x']) > 0 ? array_sum($positions['x']) / count($positions['x']) : 0,
                'y' => count($positions['y']) > 0 ? array_sum($positions['y']) / count($positions['y']) : 0
            ],
            'frames_with_faces' => count(array_filter($faces, fn($f) => !empty($f))),
            'face_detection_rate' => $frames > 0 ? (count(array_filter($faces, fn($f) => !empty($f))) / $frames) * 100 : 0
        ];
    }
}
