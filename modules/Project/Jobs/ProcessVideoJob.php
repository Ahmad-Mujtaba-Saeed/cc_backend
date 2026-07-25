<?php

namespace Modules\Project\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Project\Models\Project;
use Modules\Project\Processors\TemplateProcessorFactory;
use Modules\Billing\Services\CreditService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use FFMpeg\FFProbe;
use Throwable;

class ProcessVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * This must be longer than the longest expected video processing time
     * so the job is not released back to the queue while still running.
     */
    public int $retryAfter = 3600;

    /**
     * The maximum number of seconds a job should run.
     */
    public int $timeout = 3600; // 1 hour

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Project $project
    ) {
        $this->onQueue('video-processing');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info("PROCESS_VIDEO_JOB: Starting video processing", [
                'project_id' => $this->project->id,
                'template_type' => $this->project->template_type,
                'video_path' => $this->project->video_path,
                'current_status' => $this->project->status,
                'attempt' => $this->attempts(),
                'job_id' => method_exists($this->job, 'getJobId') ? $this->job->getJobId() : 'unknown',
                'queue' => method_exists($this->job, 'getQueue') ? $this->job->getQueue() : 'unknown'
            ]);

            // Update project status to processing
            $this->project->update([
                'status' => 'processing',
                'progress' => 0,
                'error_message' => null,
            ]);

            Log::info("PROCESS_VIDEO_JOB: Project status updated to processing", [
                'project_id' => $this->project->id,
                'new_status' => 'processing'
            ]);

            // Get the appropriate processor for this template
            Log::info("PROCESS_VIDEO_JOB: Creating processor", [
                'project_id' => $this->project->id,
                'template_type' => $this->project->template_type
            ]);

            $processor = TemplateProcessorFactory::create($this->project);

            Log::info("PROCESS_VIDEO_JOB: Processor created successfully", [
                'project_id' => $this->project->id,
                'processor_class' => get_class($processor)
            ]);

            // Process the video
            Log::info("PROCESS_VIDEO_JOB: Starting video processing", [
                'project_id' => $this->project->id,
                'processor_method' => 'process()'
            ]);

            $success = $processor->process();

            Log::info("PROCESS_VIDEO_JOB: Video processing completed", [
                'project_id' => $this->project->id,
                'success' => $success,
                'processor_class' => get_class($processor)
            ]);

            if (!$success) {
                // Refresh from DB to get the real error set by handleFailure() inside the processor
                $this->project->refresh();
                $actualError = $this->project->error_message ?: 'Video processing failed - processor returned false';
                throw new \RuntimeException($actualError);
            }

            Log::info("PROCESS_VIDEO_JOB: Successfully processed video for project {$this->project->id}", [
                'project_id' => $this->project->id,
                'final_status' => 'completed'
            ]);

            $this->recordFinalDuration();

        } catch (Throwable $e) {
            Log::error("PROCESS_VIDEO_JOB: Video processing failed", [
                'project_id' => $this->project->id,
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
                'class' => get_class($e)
            ]);

            // Update project with error
            $this->project->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error("PROCESS_VIDEO_JOB: Project status updated to failed", [
                'project_id' => $this->project->id,
                'error_message' => $e->getMessage()
            ]);

            // Fail the job to trigger retry if attempts remain
            $this->fail($e);
        }
    }

    /**
     * Probe the completed render's output and persist its duration. Central
     * point for every template — processors only need to leave output_path
     * populated, not each report duration individually. Never fails the job.
     */
    private function recordFinalDuration(): void
    {
        try {
            $this->project->refresh();

            if (empty($this->project->output_path)) {
                return;
            }

            $path = Storage::disk('public')->path($this->project->output_path);
            if (!is_file($path)) {
                return;
            }

            $duration = FFProbe::create()->format($path)->get('duration');
            if ($duration !== null && (float) $duration > 0) {
                $this->project->update(['duration' => (float) $duration]);
            }
        } catch (Throwable $e) {
            Log::warning('PROCESS_VIDEO_JOB: failed to probe final duration (ignored)', [
                'project_id' => $this->project->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("PROCESS_VIDEO_JOB: Job failed permanently", [
            'project_id' => $this->project->id,
            'error_message' => $exception->getMessage(),
            'error_trace' => $exception->getTraceAsString(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'attempts' => $this->tries,
            'class' => get_class($exception)
        ]);

        // Refresh to get the actual error_message already stored by handleFailure() or the catch block.
        // Preserve failed_step which was set by the processor; don't overwrite it.
        $this->project->refresh();
        $errorMessage = $this->project->error_message ?: $exception->getMessage();

        $this->project->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);

        Log::error("PROCESS_VIDEO_JOB: Final project status updated to failed", [
            'project_id' => $this->project->id,
            'final_error_message' => $errorMessage,
        ]);

        // A failed render must not consume credits — refund the charge (idempotent).
        try {
            app(CreditService::class)->refund($this->project);
        } catch (Throwable $refundError) {
            Log::error("PROCESS_VIDEO_JOB: credit refund failed", [
                'project_id' => $this->project->id,
                'error' => $refundError->getMessage(),
            ]);
        }

        // Send notification to user (optional)
        // $this->project->user->notify(new VideoProcessingFailedNotification($this->project));
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'video-processing',
            'project:' . $this->project->id,
            'template:' . $this->project->template_type,
            'user:' . $this->project->user_id,
        ];
    }
}
