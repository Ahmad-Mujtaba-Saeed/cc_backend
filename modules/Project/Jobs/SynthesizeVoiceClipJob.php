<?php

namespace Modules\Project\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Project\Models\UserVoiceClip;
use Modules\Project\Services\VoiceCloneService;
use Throwable;

/** Read one stand-alone text-to-speech clip aloud in the user's cloned voice. */
class SynthesizeVoiceClipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** 5000 characters is several minutes of speech — slow on a CPU. */
    public int $timeout = 3000;

    public function __construct(public int $clipId)
    {
        $this->onConnection(VoiceCloneService::queueConnection());
        $this->onQueue(VoiceCloneService::QUEUE);
    }

    public function handle(): void
    {
        $clip = UserVoiceClip::find($this->clipId);

        if (!$clip || $clip->status !== UserVoiceClip::STATUS_QUEUED) {
            return;
        }

        (new VoiceCloneService())->renderClip($clip);
    }

    public function failed(?Throwable $exception): void
    {
        UserVoiceClip::where('id', $this->clipId)
            ->whereIn('status', [UserVoiceClip::STATUS_QUEUED, UserVoiceClip::STATUS_PROCESSING])
            ->update([
                'status' => UserVoiceClip::STATUS_FAILED,
                'error' => 'Generating this audio did not finish. Please try again.',
            ]);
    }
}
