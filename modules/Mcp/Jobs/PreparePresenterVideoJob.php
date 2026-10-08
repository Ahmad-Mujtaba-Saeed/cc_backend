<?php

namespace Modules\Mcp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Mcp\Models\McpUpload;
use Modules\Mcp\Services\PresenterService;
use Modules\Project\Models\Project;

/**
 * Normalize + transcribe an uploaded presenter recording. Runs on the render
 * queue (the only queue with a worker): a 15-minute recording takes minutes
 * of ffmpeg and Whisper time, never something a web request should hold.
 */
class PreparePresenterVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 5400;

    public function __construct(public Project $project, public McpUpload $upload)
    {
        $this->onQueue('video-processing');
    }

    public function handle(): void
    {
        $upload = $this->upload->fresh();
        // The redis queue re-delivers a job that outlives retry_after (90 s);
        // a second delivery must not redo minutes of ffmpeg and Whisper — or
        // fail on the original file the first run already cleaned up.
        if (!$upload || $upload->status !== 'processing') {
            return;
        }
        PresenterService::prepare($this->project->fresh(), $upload);
    }
}
