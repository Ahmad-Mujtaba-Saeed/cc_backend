<?php

namespace Modules\Project\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Project\Models\UserVoice;
use Modules\Project\Services\VoiceCloneService;
use Throwable;

/**
 * Clone one uploaded recording into a usable voice (runs on the `voice`
 * queue connection, served by the `voice-worker` container).
 *
 * Carries the id, not the model: the user may delete the voice while it waits.
 */
class PrepareUserVoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** First boot downloads the model weights before the clone can start. */
    public int $timeout = 1800;

    public function __construct(public int $voiceId)
    {
        $this->onConnection(VoiceCloneService::queueConnection());
        $this->onQueue(VoiceCloneService::QUEUE);
    }

    public function handle(): void
    {
        $voice = UserVoice::find($this->voiceId);

        // Deleted meanwhile, or already handled by an earlier delivery.
        if (!$voice || $voice->status !== UserVoice::STATUS_QUEUED) {
            return;
        }

        (new VoiceCloneService())->prepare($voice);
    }

    public function failed(?Throwable $exception): void
    {
        UserVoice::where('id', $this->voiceId)
            ->whereIn('status', [UserVoice::STATUS_QUEUED, UserVoice::STATUS_PROCESSING])
            ->update([
                'status' => UserVoice::STATUS_FAILED,
                'error' => 'Cloning did not finish. Please delete this voice and try again.',
            ]);
    }
}
