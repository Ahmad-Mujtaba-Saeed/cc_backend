<?php

/**
 * QUICK REFERENCE: Using PusherService in Your Processors
 * 
 * This file shows example implementations of PusherService 
 * in different video processor templates.
 */

// ============================================
// EXAMPLE 1: Simple Progress Update
// ============================================

namespace Modules\Project\Processors;

use App\Services\PusherService;

class SimpleVideoProcessor extends AbstractVideoProcessor
{
    private PusherService $pusherService;

    public function __construct(\Modules\Project\Models\Project $project)
    {
        parent::__construct($project);
        $this->pusherService = new PusherService();
    }

    public function process(): bool
    {
        try {
            // Send status update
            $this->pusherService->sendStatus($this->project->id, 'processing', 'Starting video processing');

            // Do some processing
            $this->processStep1();
            $this->pusherService->sendProgress($this->project->id, 25, 'Step 1 complete');

            $this->processStep2();
            $this->pusherService->sendProgress($this->project->id, 50, 'Step 2 complete');

            $this->processStep3();
            $this->pusherService->sendProgress($this->project->id, 75, 'Step 3 complete');

            // Completion
            $this->pusherService->sendCompletion($this->project->id, [
                'output_path' => $this->outputPath,
                'file_size' => filesize($this->outputPath)
            ]);

            return true;
        } catch (\Exception $e) {
            $this->pusherService->sendError($this->project->id, 'Processing failed: ' . $e->getMessage());
            return false;
        }
    }

    private function processStep1() { /* ... */ }
    private function processStep2() { /* ... */ }
    private function processStep3() { /* ... */ }
}


// ============================================
// EXAMPLE 2: Multi-Step Progress with Tracking
// ============================================

class ComplexVideoProcessor extends AbstractVideoProcessor
{
    private PusherService $pusherService;
    private const TOTAL_STEPS = 5;

    public function __construct(\Modules\Project\Models\Project $project)
    {
        parent::__construct($project);
        $this->pusherService = new PusherService();
    }

    public function process(): bool
    {
        try {
            $this->pusherService->sendStatus($this->project->id, 'processing');

            // Step 1: Extract audio
            if (!$this->extractAudio()) {
                throw new \Exception('Failed to extract audio');
            }
            $this->sendStepProgress(1, 'Extracting audio', 100);

            // Step 2: Analyze audio
            if (!$this->analyzeAudio()) {
                throw new \Exception('Failed to analyze audio');
            }
            $this->sendStepProgress(2, 'Analyzing audio', 100);

            // Step 3: Generate subtitles
            if (!$this->generateSubtitles()) {
                throw new \Exception('Failed to generate subtitles');
            }
            $this->sendStepProgress(3, 'Generating subtitles', 100);

            // Step 4: Apply effects
            if (!$this->applyEffects()) {
                throw new \Exception('Failed to apply effects');
            }
            $this->sendStepProgress(4, 'Applying effects', 100);

            // Step 5: Encode final video
            if (!$this->encodeVideo()) {
                throw new \Exception('Failed to encode video');
            }
            $this->sendStepProgress(5, 'Encoding video', 100);

            $this->pusherService->sendCompletion($this->project->id);
            return true;

        } catch (\Exception $e) {
            $this->pusherService->sendError($this->project->id, $e->getMessage());
            return false;
        }
    }

    private function sendStepProgress(int $stepNumber, string $stepName, int $stepProgress = 0)
    {
        $this->pusherService->sendStepProgress(
            $this->project->id,
            $stepName,
            $stepNumber,
            self::TOTAL_STEPS,
            $stepProgress
        );
    }

    private function extractAudio() { /* ... */ return true; }
    private function analyzeAudio() { /* ... */ return true; }
    private function generateSubtitles() { /* ... */ return true; }
    private function applyEffects() { /* ... */ return true; }
    private function encodeVideo() { /* ... */ return true; }
}


// ============================================
// EXAMPLE 3: Long-Running Step with Sub-Progress
// ============================================

class AdvancedVideoProcessor extends AbstractVideoProcessor
{
    private PusherService $pusherService;

    public function __construct(\Modules\Project\Models\Project $project)
    {
        parent::__construct($project);
        $this->pusherService = new PusherService();
    }

    public function process(): bool
    {
        try {
            $this->pusherService->sendStatus($this->project->id, 'processing');

            // Long running step with sub-progress updates
            for ($i = 1; $i <= 10; $i++) {
                $this->processFrame($i);
                
                // Send step progress with sub-progress indicator
                $this->pusherService->sendStepProgress(
                    $this->project->id,
                    'Processing frames',
                    1,
                    2,
                    ($i / 10) * 100  // 0-100% of current step
                );
            }

            // Step 2: Finalize
            $this->pusherService->sendStepProgress(
                $this->project->id,
                'Finalizing output',
                2,
                2,
                50
            );

            $this->finalizeOutput();

            $this->pusherService->sendStepProgress(
                $this->project->id,
                'Finalizing output',
                2,
                2,
                100
            );

            $this->pusherService->sendCompletion($this->project->id);
            return true;

        } catch (\Exception $e) {
            $this->pusherService->sendError($this->project->id, $e->getMessage());
            return false;
        }
    }

    private function processFrame($frameNumber) 
    { 
        // Simulate frame processing
        sleep(1);
    }

    private function finalizeOutput() 
    { 
        // Finalize the output
    }
}


// ============================================
// EXAMPLE 4: Custom Event Broadcasting
// ============================================

class CustomEventProcessor extends AbstractVideoProcessor
{
    private PusherService $pusherService;

    public function __construct(\Modules\Project\Models\Project $project)
    {
        parent::__construct($project);
        $this->pusherService = new PusherService();
    }

    public function process(): bool
    {
        try {
            $this->pusherService->sendStatus($this->project->id, 'processing');

            // Send custom event for specific actions
            $this->pusherService->sendEvent($this->project->id, 'video.conversion_started', [
                'format' => 'mp4',
                'bitrate' => '5000k',
                'resolution' => '1920x1080'
            ]);

            $this->convertVideo();

            $this->pusherService->sendEvent($this->project->id, 'video.conversion_complete', [
                'format' => 'mp4',
                'file_size' => '512MB',
                'duration' => '3600'
            ]);

            $this->pusherService->sendEvent($this->project->id, 'video.optimization_started', [
                'optimization_type' => 'compression'
            ]);

            $this->optimizeVideo();

            $this->pusherService->sendCompletion($this->project->id);
            return true;

        } catch (\Exception $e) {
            $this->pusherService->sendError($this->project->id, $e->getMessage());
            return false;
        }
    }

    private function convertVideo() { /* ... */ }
    private function optimizeVideo() { /* ... */ }
}


// ============================================
// PUSHER SERVICE METHOD REFERENCE
// ============================================

/**
 * Send progress percentage (0-100)
 * 
 * $pusherService->sendProgress(
 *     int $projectId,
 *     int $progress,           // 0-100
 *     ?string $message,        // Optional status message
 *     array $metadata = []     // Optional additional data
 * )
 */

/**
 * Send status update
 * 
 * $pusherService->sendStatus(
 *     int $projectId,
 *     string $status,          // 'pending', 'processing', 'completed', 'failed'
 *     ?string $message,        // Optional message
 *     array $metadata = []     // Optional additional data
 * )
 */

/**
 * Send step-based progress
 * 
 * $pusherService->sendStepProgress(
 *     int $projectId,
 *     string $stepName,        // e.g., "Transcribing video"
 *     int $stepNumber,         // Current step (1-based)
 *     int $totalSteps,         // Total number of steps
 *     int $stepProgress = 0,   // 0-100 progress within step
 *     array $metadata = []     // Optional additional data
 * )
 */

/**
 * Send error notification
 * 
 * $pusherService->sendError(
 *     int $projectId,
 *     string $errorMessage,
 *     array $metadata = []
 * )
 */

/**
 * Send completion notification
 * 
 * $pusherService->sendCompletion(
 *     int $projectId,
 *     array $resultData = [],  // Result data (output_path, etc.)
 *     array $metadata = []
 * )
 */

/**
 * Send generic event
 * 
 * $pusherService->sendEvent(
 *     int $projectId,
 *     string $eventName,       // e.g., "video.processing_step"
 *     array $eventData = []    // Event payload
 * )
 */
