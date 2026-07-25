<?php

namespace App\Services;

use Pusher\Pusher;
use Illuminate\Support\Facades\Log;
use Modules\Project\Models\Project;

class PusherService
{
    private Pusher $pusher;

    public function __construct()
    {
        $this->pusher = new Pusher(
            env('PUSHER_APP_KEY'),
            env('PUSHER_APP_SECRET'),
            env('PUSHER_APP_ID'),
            [
                'cluster' => env('PUSHER_APP_CLUSTER', 'ap2'),
                'useTLS' => true,
                'encrypted' => true,
            ]
        );
    }

    /**
     * Send progress update for a project.
     * 
     * @param int $projectId
     * @param int $progress Percentage 0-100
     * @param string|null $message Optional progress message
     * @param array $metadata Optional additional metadata
     * @return bool
     */
    public function sendProgress(int $projectId, int $progress, ?string $message = null, array $metadata = []): bool
    {
        try {
            $channel = "project.{$projectId}";
            // Persist progress to DB so refresh reads updated value
            try {
                $project = Project::find($projectId);
                if ($project) {
                    $project->update(['progress' => $progress]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to persist project progress: ' . $e->getMessage(), ['project_id' => $projectId]);
            }

            $data = array_merge([
                'progress' => $progress,
                'message' => $message,
                'timestamp' => now()->toIso8601String(),
            ], $metadata);

            $this->pusher->trigger($channel, 'project.progress', $data);

            Log::info('Pusher progress sent', [
                'project_id' => $projectId,
                'progress' => $progress,
                'channel' => $channel,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Pusher progress failed: ' . $e->getMessage(), [
                'project_id' => $projectId,
                'progress' => $progress,
            ]);
            return false;
        }
    }

    /**
     * Send status update for a project.
     * 
     * @param int $projectId
     * @param string $status Status: pending, processing, completed, failed
     * @param string|null $message Optional status message
     * @param array $metadata Optional additional metadata
     * @return bool
     */
    public function sendStatus(int $projectId, string $status, ?string $message = null, array $metadata = []): bool
    {
        try {
            $channel = "project.{$projectId}";
            // Persist status in DB
            try {
                $project = Project::find($projectId);
                if ($project) {
                    $update = ['status' => $status];
                    if ($status === 'completed') {
                        $update['completed_at'] = now();
                        $update['progress'] = 100;
                    }
                    $project->update($update);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to persist project status: ' . $e->getMessage(), ['project_id' => $projectId]);
            }

            $data = array_merge([
                'status' => $status,
                'message' => $message,
                'timestamp' => now()->toIso8601String(),
            ], $metadata);

            $this->pusher->trigger($channel, 'project.status', $data);

            Log::info('Pusher status sent', [
                'project_id' => $projectId,
                'status' => $status,
                'channel' => $channel,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Pusher status failed: ' . $e->getMessage(), [
                'project_id' => $projectId,
                'status' => $status,
            ]);
            return false;
        }
    }

    /**
     * Send step-based progress update (for multi-step processing).
     * 
     * @param int $projectId
     * @param string $stepName Name of the current step
     * @param int $stepNumber Current step number
     * @param int $totalSteps Total number of steps
     * @param int $stepProgress Progress within current step (0-100)
     * @param array $metadata Optional additional metadata
     * @return bool
     */
    public function sendStepProgress(
        int $projectId,
        string $stepName,
        int $stepNumber,
        int $totalSteps,
        int $stepProgress = 0,
        array $metadata = []
    ): bool
    {
        try {
            $channel = "project.{$projectId}";
            
            // Calculate overall progress
            $overallProgress = (($stepNumber - 1) / $totalSteps) * 100 + ($stepProgress / $totalSteps);

            // Persist overall progress to DB
            try {
                $project = Project::find($projectId);
                if ($project) {
                    $project->update(['progress' => (int) round($overallProgress)]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to persist step progress: ' . $e->getMessage(), ['project_id' => $projectId]);
            }

            $data = array_merge([
                'step' => $stepName,
                'step_number' => $stepNumber,
                'total_steps' => $totalSteps,
                'step_progress' => $stepProgress,
                'overall_progress' => round($overallProgress, 2),
                'timestamp' => now()->toIso8601String(),
            ], $metadata);

            $this->pusher->trigger($channel, 'project.step_progress', $data);

            Log::info('Pusher step progress sent', [
                'project_id' => $projectId,
                'step' => $stepName,
                'step_number' => $stepNumber,
                'total_steps' => $totalSteps,
                'overall_progress' => round($overallProgress, 2),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Pusher step progress failed: ' . $e->getMessage(), [
                'project_id' => $projectId,
                'step' => $stepName,
            ]);
            return false;
        }
    }

    /**
     * Send error notification.
     * 
     * @param int $projectId
     * @param string $errorMessage
     * @param array $metadata Optional additional metadata
     * @return bool
     */
    public function sendError(int $projectId, string $errorMessage, array $metadata = []): bool
    {
        try {
            $channel = "project.{$projectId}";
            
            // Persist error to DB
            try {
                $project = Project::find($projectId);
                if ($project) {
                    $project->update(['status' => 'failed', 'error_message' => $errorMessage]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to persist project error: ' . $e->getMessage(), ['project_id' => $projectId]);
            }

            $data = array_merge([
                'error' => $errorMessage,
                'timestamp' => now()->toIso8601String(),
            ], $metadata);

            $this->pusher->trigger($channel, 'project.error', $data);

            Log::error('Pusher error sent', [
                'project_id' => $projectId,
                'error' => $errorMessage,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Pusher error notification failed: ' . $e->getMessage(), [
                'project_id' => $projectId,
            ]);
            return false;
        }
    }

    /**
     * Send completion notification.
     * 
     * @param int $projectId
     * @param array $resultData Data about the completed processing
     * @param array $metadata Optional additional metadata
     * @return bool
     */
    public function sendCompletion(int $projectId, array $resultData = [], array $metadata = []): bool
    {
        try {
            $channel = "project.{$projectId}";
            
            // Persist completion details
            try {
                $project = Project::find($projectId);
                if ($project) {
                    $update = ['status' => 'completed', 'progress' => 100, 'completed_at' => now()];
                    if (isset($resultData['output_path'])) {
                        $update['output_path'] = $resultData['output_path'];
                    }
                    $project->update($update);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to persist project completion: ' . $e->getMessage(), ['project_id' => $projectId]);
            }

            $data = array_merge([
                'status' => 'completed',
                'result' => $resultData,
                'timestamp' => now()->toIso8601String(),
            ], $metadata);

            $this->pusher->trigger($channel, 'project.completed', $data);

            Log::info('Pusher completion sent', [
                'project_id' => $projectId,
                'channel' => $channel,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Pusher completion notification failed: ' . $e->getMessage(), [
                'project_id' => $projectId,
            ]);
            return false;
        }
    }

    /**
     * Send generic event.
     * 
     * @param int $projectId
     * @param string $eventName
     * @param array $eventData
     * @return bool
     */
    public function sendEvent(int $projectId, string $eventName, array $eventData = []): bool
    {
        try {
            $channel = "project.{$projectId}";
            
            $data = array_merge([
                'timestamp' => now()->toIso8601String(),
            ], $eventData);

            $this->pusher->trigger($channel, $eventName, $data);

            Log::info('Pusher event sent', [
                'project_id' => $projectId,
                'event' => $eventName,
                'channel' => $channel,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Pusher event failed: ' . $e->getMessage(), [
                'project_id' => $projectId,
                'event' => $eventName,
            ]);
            return false;
        }
    }
}
