<?php

namespace Modules\Project\Processors;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Project\Services\ClipEditPlanService;
use Modules\Project\Services\ClipSelectionService;
use Modules\Project\Services\GameplayVideoService;
use App\Services\PythonAIService;
use Modules\Project\Services\R2UploadService;
use Modules\Project\Services\ShortComposerService;
use Modules\Project\Services\VideoClipCutterService;
use Modules\Project\Services\VideoTranscriptionService;
use Modules\Project\Services\RapidApiTranscriptionService;
use Modules\Project\Services\WordLevelCaptionService;
use Modules\Project\Contracts\YoutubeDownloaderInterface;
use Modules\Project\Services\YoutubeDownloaderFactory;
use Modules\Project\Services\Shorts\ClipSceneAnalyzer;
use Modules\Project\Services\Shorts\ClipTightenService;
use Modules\Project\Services\Shorts\ShortEditDirector;
use Modules\Project\Services\Shorts\ShortLayoutPlanner;
use Modules\Project\Services\Shorts\ShortMusicPlanner;
use Modules\Project\Services\Shorts\ShortStylePlanner;
use Modules\Project\Services\Shorts\ViralShortRenderService;

/**
 * YTGameplayShortProcessor
 *
 * Converts a YouTube video or uploaded video into MULTIPLE 9:16 short-form
 * vertical videos by intelligently selecting the best non-overlapping clips,
 * editing the silence/dead air out of each one, and compositing them with
 * optional gameplay footage.
 *
 * HOW MANY SHORTS: not a user setting. The source length sets a ceiling of
 * 2-12 and the clips' own viral scores decide how many of those actually ship
 * (ranked best-first, cut where the scores fall off). Every short also gets
 * its OWN gameplay segment, from its own random point in the footage.
 *
 * Workflow:
 * 1. Validate input
 * 2. Download/prepare source video
 * 3. Transcribe (RapidAPI for YouTube; local Faster-Whisper fallback for uploads)
 * 4. Select the best clips (multi-pass GPT, sentence-snapped, non-overlapping,
 *    head-to-head ranked, then gated on score)
 * 5. Prepare the gameplay pool (only when the gameplay panel is enabled)
 * 6. Render each clip:
 *    - build an edit plan that skips the empty parts (silence/dead air)
 *    - cut the clip from the kept ranges (multi-segment concat)
 *    - transcribe the CUT clip's audio with Whisper word timestamps and build
 *      karaoke captions from those exact timings (they are already on the
 *      edited timeline, so captions are perfectly in sync); falls back to
 *      remapping the source transcript if per-clip transcription fails
 *    - loop this short's OWN gameplay segment from its own random offset
 *    - compose (main + gameplay split, or full-frame main when disabled)
 *    - generate a per-clip thumbnail
 * 7. Cleanup temp files
 */
class YTGameplayShortProcessor extends AbstractVideoProcessor
{
    /**
     * How many shorts a run produces. The user no longer picks this: the
     * source length sets a ceiling, and the clips' own scores decide how many
     * of them are actually worth shipping (best first, dropping down until the
     * quality floor is hit). Always at least MIN_CLIPS when the material
     * allows it, never more than MAX_CLIPS.
     */
    private const MIN_CLIPS = 2;
    private const MAX_CLIPS = 20;

    /** One short per this many seconds of source, before the score gate. */
    private const SECONDS_PER_CLIP = 120.0;

    /** A short must score at least this to ship at all. */
    private const SCORE_FLOOR = 50;

    /** ...and at least this fraction of the best short's score. */
    private const SCORE_RELATIVE_FLOOR = 0.65;

    private YoutubeDownloaderInterface $downloadService;
    private VideoTranscriptionService $transcriptionService;
    private RapidApiTranscriptionService $rapidTranscriptionService;
    private ClipSelectionService $clipSelectionService;
    private ClipEditPlanService $editPlanService;
    private ClipTightenService $tightenService;
    private ShortMusicPlanner $musicPlanner;
    private GameplayVideoService $gameplayService;
    private VideoClipCutterService $clipCutterService;
    private ShortComposerService $composerService;
    private WordLevelCaptionService $captionService;
    private R2UploadService $r2UploadService;
    private PythonAIService $pythonService;
    private ClipSceneAnalyzer $sceneAnalyzer;
    private ShortStylePlanner $stylePlanner;
    private ShortLayoutPlanner $layoutPlanner;
    private ShortEditDirector $editDirector;
    private ViralShortRenderService $viralRenderer;
    private ?bool $viralAvailable = null;

    /**
     * The YouTube transcript for this run, fetched once. [] means "tried and
     * unavailable"; null means "not fetched yet".
     */
    private ?array $youtubeTranscript = null;

    public function __construct(\Modules\Project\Models\Project $project)
    {
        parent::__construct($project);

        $this->downloadService = YoutubeDownloaderFactory::make();
        $this->transcriptionService = new VideoTranscriptionService();
        $this->rapidTranscriptionService = new RapidApiTranscriptionService();
        $this->clipSelectionService = new ClipSelectionService();
        $this->editPlanService = new ClipEditPlanService();
        $this->tightenService = new ClipTightenService();
        $this->musicPlanner = new ShortMusicPlanner();
        $this->gameplayService = new GameplayVideoService();
        $this->clipCutterService = new VideoClipCutterService();
        $this->composerService = new ShortComposerService();
        $this->captionService = new WordLevelCaptionService();
        $this->r2UploadService = new R2UploadService();
        $this->pythonService = new PythonAIService();
        $this->sceneAnalyzer = new ClipSceneAnalyzer($this->pythonService);
        $this->stylePlanner = new ShortStylePlanner();
        $this->layoutPlanner = new ShortLayoutPlanner();
        $this->editDirector = new ShortEditDirector();
        $this->viralRenderer = new ViralShortRenderService();
    }

    public function process(): bool
    {
        try {
            Log::info('YTGameplayShortProcessor starting', [
                'project_id' => $this->project->id,
                'input_mode' => $this->settings['input_mode'] ?? 'unknown',
                'clip_count' => 'auto (' . self::MIN_CLIPS . '-' . self::MAX_CLIPS . ')',
                'gameplay_enabled' => $this->gameplayEnabled(),
            ]);

            $this->logActivity('yt_gameplay_short_processing_started', 'YT + Gameplay Short processing started');
            $this->pusherService->sendStatus($this->project->id, 'processing', 'Starting Long Video to Shorts processing');

            // STEP 1: Validate
            $validation = $this->runProcessingStep(
                'validate_input',
                fn() => $this->validateInput(),
                5,
                'Validating input',
                'Input validation completed',
                'Validation failed'
            );

            if ($validation === false) {
                return false;
            }

            // STEP 2: Prepare source video
            $sourceVideoPath = $this->runProcessingStep(
                'download_video',
                fn() => $this->prepareSourceVideo(),
                20,
                'Preparing source video',
                'Source video ready',
                'Failed to prepare source video'
            );

            if (!$sourceVideoPath) {
                return false;
            }

            // STEP 3: Transcribe
            $transcription = $this->runProcessingStep(
                'transcribe_video',
                fn() => $this->transcribeVideo($sourceVideoPath),
                40,
                'Transcribing video',
                'Video transcription completed',
                'Failed to transcribe video',
                persistOutput: false // full transcript would bloat processing_state (MySQL 1038)
            );

            if ($transcription === false) {
                return false;
            }

            // The transcript is intentionally not persisted, so a resumed run
            // gets null back from the skipped step — re-transcribe in-memory.
            if (!is_array($transcription) || empty($transcription['segments'])) {
                $transcription = $this->transcribeVideo($sourceVideoPath);
                if (!$transcription) {
                    $this->handleFailure('Failed to transcribe video', 'transcribe_video');
                    return false;
                }
            }

            // STEP 4: Select the N best clips
            $selectedClips = $this->runProcessingStep(
                'select_best_clips',
                fn() => $this->selectBestClips($transcription),
                55,
                'Selecting best clips',
                'Clip selection completed',
                'Failed to select clips',
                persistOutput: false // clips carry full subtitles; keep the row slim
            );

            if ($selectedClips === false) {
                return false;
            }

            if (!is_array($selectedClips) || empty($selectedClips)) {
                // Resumed run — selection isn't persisted either, redo it.
                $selectedClips = $this->selectBestClips($transcription);
                if (empty($selectedClips)) {
                    $this->handleFailure('Failed to select clips', 'select_best_clips');
                    return false;
                }
            }

            // STEP 5: Prepare gameplay (skipped entirely when the panel is off)
            if ($this->gameplayEnabled()) {
                $gameplayReady = $this->runProcessingStep(
                    'prepare_gameplay',
                    fn() => $this->prepareGameplay(),
                    58,
                    'Preparing gameplay video',
                    'Gameplay video ready',
                    'Failed to prepare gameplay'
                );

                if ($gameplayReady === false) {
                    return false;
                }
            }

            // STEP 6: Render every clip (edit → cut → captions → compose → thumbnail)
            $outputs = $this->runProcessingStep(
                'render_clips',
                fn() => $this->renderClips($sourceVideoPath, $transcription, $selectedClips),
                95,
                'Rendering shorts',
                'All shorts rendered',
                'Failed to render shorts'
            );

            if (!$outputs) {
                return false;
            }

            // STEP 7: Cleanup
            $this->runProcessingStep(
                'cleanup',
                fn() => $this->cleanupTempFiles(),
                98,
                'Cleaning up temporary files',
                'Cleanup completed',
                'Cleanup failed (non-fatal)'
            );

            // Mark complete
            $this->project->update([
                'status' => 'completed',
                'progress' => 100,
                'completed_at' => now(),
                'failed_step' => null,
            ]);
            $this->pusherService->sendStatus($this->project->id, 'completed', 'Video processing completed successfully');

            Log::info('YTGameplayShortProcessor completed successfully', [
                'project_id' => $this->project->id,
                'videos_rendered' => count($outputs),
            ]);
            return true;

        } catch (\Exception $e) {
            Log::error('YTGameplayShortProcessor failed', [
                'project_id' => $this->project->id,
                'error' => $e->getMessage()
            ]);

            $this->cleanupTempFiles();  // Best effort cleanup on error
            $this->handleFailure('AI Video processing failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * How many clips to ASK the selector for, from the source length alone.
     *
     * This is a ceiling, not a promise: a 90-minute video is allowed 12 shorts
     * but only gets them if 12 candidates clear the score gate in
     * shipworthyClips(). A short source simply cannot hide 12 distinct
     * moments, so asking for them would only force weak, overlapping picks.
     */
    private function targetClipCount(float $sourceDurationSeconds): int
    {
        if ($sourceDurationSeconds <= 0) {
            return self::MIN_CLIPS;
        }

        $count = (int) ceil($sourceDurationSeconds / self::SECONDS_PER_CLIP);

        return max(self::MIN_CLIPS, min(self::MAX_CLIPS, $count));
    }

    /**
     * Keep the clips worth shipping, best score first.
     *
     * The list arrives ranked. We walk down it and stop at the first clip that
     * is either weak in absolute terms or far behind the leader — the tail of
     * a ranked list is where filler shorts come from. MIN_CLIPS survives the
     * gate regardless, so a run never returns a single lonely short when a
     * second one exists.
     *
     * @param array<int, array> $clips ranked best-first
     * @return array<int, array>
     */
    private function shipworthyClips(array $clips): array
    {
        $clips = array_values($clips);
        if (count($clips) <= self::MIN_CLIPS) {
            return $clips;
        }

        usort($clips, fn ($a, $b) => ($b['final_score'] ?? 0) <=> ($a['final_score'] ?? 0));

        $best = (float) ($clips[0]['final_score'] ?? 0);
        $floor = max(self::SCORE_FLOOR, $best * self::SCORE_RELATIVE_FLOOR);

        $kept = [];
        foreach ($clips as $i => $clip) {
            if ($i >= self::MIN_CLIPS && (float) ($clip['final_score'] ?? 0) < $floor) {
                break;
            }
            $kept[] = $clip;
        }

        if (count($kept) < count($clips)) {
            Log::info('[YT_GAMEPLAY] Dropped low-ranked clips below the quality gate', [
                'project_id' => $this->project->id,
                'kept' => count($kept),
                'dropped' => count($clips) - count($kept),
                'best_score' => $best,
                'floor' => round($floor, 1),
            ]);
        }

        return $kept;
    }

    /**
     * Whether the gameplay bottom panel is enabled (default: yes).
     */
    private function gameplayEnabled(): bool
    {
        return filter_var($this->settings['gameplay_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * STEP 1: Validate input
     */
    protected function validateInput(): bool
    {
        $inputMode = $this->settings['input_mode'] ?? null;

        if ($inputMode === 'youtube_url') {
            $mainUrl = $this->settings['main_url'] ?? null;
            if (!$mainUrl) {
                throw new \Exception('YouTube URL is required');
            }

            // Test URL validity
            try {
                $this->downloadService->extractVideoId($mainUrl);
            } catch (\Exception $e) {
                throw new \Exception('Invalid YouTube URL: ' . $e->getMessage());
            }
        } elseif ($inputMode === 'upload') {
            $videoPath = $this->project->video_path;
            if (!$videoPath || !Storage::disk('public')->exists($videoPath)) {
                throw new \Exception('Uploaded video file not found');
            }
        } else {
            throw new \Exception('Invalid input mode: ' . $inputMode);
        }

        // Validate gameplay settings (only relevant when the panel is on)
        if ($this->gameplayEnabled()) {
            $gameplaySource = $this->settings['gameplay_source'] ?? 'backend_library';
            if ($gameplaySource === 'custom_upload') {
                $gameplayFile = $this->settings['gameplay_file'] ?? null;
                if (!$gameplayFile) {
                    throw new \Exception('Custom gameplay file is required');
                }
            }
        }

        // Validate caption settings
        $captionTemplate = $this->settings['caption_template'] ?? 'modern_karaoke';
        $validTemplates = ['modern_karaoke', 'classic_block', 'minimal_clean'];
        if (!in_array($captionTemplate, $validTemplates)) {
            throw new \Exception('Invalid caption template');
        }

        return true;
    }

    /**
     * STEP 2: Download or prepare source video
     */
    protected function prepareSourceVideo(): ?string
    {
        $inputMode = $this->settings['input_mode'];
        $sourceVideoPath = Storage::disk('public')->path("projects/{$this->project->id}/source_video.mp4");

        if ($inputMode === 'youtube_url') {
            $sourceDuration = $this->downloadYoutubeSource($sourceVideoPath);
        } else {
            // Upload mode — file already saved by controller
            $sourceVideoPath = Storage::disk('public')->path($this->project->video_path);

            if (!file_exists($sourceVideoPath) || filesize($sourceVideoPath) === 0) {
                throw new \Exception('Source video is missing or empty');
            }

            $sourceDuration = $this->probeDuration($sourceVideoPath);
            if ($sourceDuration === null) {
                throw new \Exception('Uploaded video is unreadable or not a valid video file');
            }
        }

        $state = $this->project->processing_state ?? [];
        $state['source_duration'] = round($sourceDuration, 2);
        $this->project->update(['processing_state' => $state]);

        Log::info('Source video ready', [
            'project_id' => $this->project->id,
            'path' => basename($sourceVideoPath),
            'size' => filesize($sourceVideoPath),
            'duration' => round($sourceDuration, 2),
        ]);

        return $sourceVideoPath;
    }

    /**
     * Download the YouTube source, retrying until the file on disk is a
     * complete, readable video.
     *
     * The provider transcodes on the fly and answers with a chunked stream:
     * no Content-Length to verify against, and a dropped connection yields a
     * headerless MP4 that ffprobe cannot open at all. A non-empty file is
     * therefore no evidence of a successful download — that check used to pass
     * and the job died four minutes later inside the cut step with
     * "Failed to cut any clips from the source video".
     *
     * So every attempt is verified against the transcript's length (the
     * transcript covers the whole video and is fetched cheaply by video id),
     * and a short or unreadable file earns a fresh download URL rather than a
     * confusing failure downstream.
     *
     * @return float the verified duration of the downloaded file
     */
    protected function downloadYoutubeSource(string $sourceVideoPath): float
    {
        $mainUrl = $this->settings['main_url'];
        $expected = $this->expectedSourceDuration();
        $attempts = 3;
        $shortest = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $initResult = $this->downloadService->initDownload($mainUrl);
            $downloadUrl = $this->downloadService->pollUntilReady($initResult['progress_url']);
            $this->downloadService->streamToStorage($downloadUrl, $sourceVideoPath);

            $state = $this->project->processing_state ?? [];
            $state['source_video_path'] = $sourceVideoPath;
            $state['video_title'] = $initResult['title'];
            $this->project->update(['processing_state' => $state]);

            $duration = $this->probeDuration($sourceVideoPath);

            if ($duration !== null && ($expected === null || $duration >= $expected * 0.9)) {
                return $duration;
            }

            $shortest = $duration;

            Log::warning('[YT_GAMEPLAY] Incomplete source download, retrying', [
                'project_id' => $this->project->id,
                'attempt' => $attempt,
                'of' => $attempts,
                'got_duration' => $duration,
                'expected_duration' => $expected,
                'size' => file_exists($sourceVideoPath) ? filesize($sourceVideoPath) : 0,
            ]);

            @unlink($sourceVideoPath);

            if ($attempt < $attempts) {
                sleep($attempt * 5);
            }
        }

        if ($shortest === null) {
            throw new \Exception(
                'Source video download failed — the file arrived corrupt every time. Please try again.'
            );
        }

        throw new \Exception(sprintf(
            'Source video download kept arriving incomplete (%.0f min of %.0f min). Please try again.',
            $shortest / 60,
            ($expected ?? 0) / 60
        ));
    }

    /**
     * How long the source SHOULD be, from the YouTube transcript (which is
     * keyed on the video id and needs no downloaded file). The transcript is
     * cached on the instance so the later transcription step reuses this call
     * instead of paying for a second one. Null when unavailable — the download
     * is then only checked for readability.
     */
    protected function expectedSourceDuration(): ?float
    {
        $transcript = $this->youtubeTranscript();
        $duration = (float) ($transcript['totalDuration'] ?? 0);

        return $duration > 0 ? $duration : null;
    }

    /**
     * The RapidAPI transcript for this project's YouTube video, fetched once.
     * Null for upload mode or when the API is unavailable.
     */
    protected function youtubeTranscript(): ?array
    {
        if ($this->youtubeTranscript !== null) {
            return $this->youtubeTranscript ?: null;
        }

        $videoId = $this->youtubeVideoId();
        if ($videoId === null) {
            $this->youtubeTranscript = [];
            return null;
        }

        try {
            $result = $this->rapidTranscriptionService->transcribeByVideoId($videoId);
            $this->youtubeTranscript = (is_array($result) && !empty($result['segments'])) ? $result : [];
        } catch (\Exception $e) {
            Log::warning('[YT_GAMEPLAY] RapidAPI transcript unavailable', [
                'project_id' => $this->project->id,
                'error' => $e->getMessage(),
            ]);
            $this->youtubeTranscript = [];
        }

        return $this->youtubeTranscript ?: null;
    }

    /**
     * The YouTube video id for the current project, or null for upload mode /
     * an unparseable URL (in which case transcription falls back to local).
     */
    protected function youtubeVideoId(): ?string
    {
        if (($this->settings['input_mode'] ?? null) !== 'youtube_url') {
            return null;
        }

        $url = $this->settings['main_url'] ?? null;
        if (!$url) {
            return null;
        }

        try {
            return $this->downloadService->extractVideoId($url);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * STEP 3: Transcribe the video.
     *
     * For YouTube sources we use the RapidAPI transcriber (fast, no local
     * inference, uses YouTube's own caption timings). For uploaded files —
     * which have no video id — and whenever the API fails, we fall back to
     * local Faster-Whisper on the downloaded file so the job still completes.
     */
    protected function transcribeVideo(string $sourceVideoPath): ?array
    {
        try {
            // Already fetched while verifying the download's length — reuse it
            // rather than paying the API a second time for the same answer.
            $result = $this->youtubeTranscript();

            // Fallback: local Faster-Whisper on the downloaded/uploaded file.
            if (!$result || empty($result['segments'])) {
                Log::info('[YT_GAMEPLAY] Transcribing locally (Faster-Whisper)', [
                    'project_id' => $this->project->id,
                    'source_path' => $sourceVideoPath,
                    'file_exists' => file_exists($sourceVideoPath),
                ]);
                $result = $this->transcriptionService->transcribe($sourceVideoPath);
            }

            if (!$result || empty($result['segments'])) {
                Log::error('[YT_GAMEPLAY] Transcription returned no segments');
                return null;
            }

            // RapidAPI reports `totalDuration`, local Whisper reports `duration`.
            // Reading only the first left it 0 on every fallback run, and the
            // selector then rejected EVERY clip as "ends after video ends"
            // (project 194: six 88-scoring candidates, zero shorts).
            $result['totalDuration'] = (float) ($result['totalDuration'] ?? 0)
                ?: (float) ($result['duration'] ?? 0)
                ?: (float) (end($result['segments'])['end'] ?? 0);

            // Persist only lightweight metadata. The full transcript can be
            // megabytes (3000+ segments on long videos); storing it in the
            // processing_state JSON bloats the row and breaks ORDER BY queries
            // (MySQL 1038 out-of-sort-memory). It's only needed in-memory for
            // this run — clip selection/captions receive $result directly, and
            // a retry re-transcribes from scratch.
            $state = $this->project->processing_state ?? [];
            $state['transcript_segment_count'] = count($result['segments'] ?? []);
            $state['total_duration'] = $result['totalDuration'] ?? 0;
            $this->project->update(['processing_state' => $state]);

            // The transcript covers the WHOLE video; the downloaded file may
            // not. If the file is materially shorter, every clip the selector
            // picks past that point cuts to nothing - fail here with a reason
            // instead of producing zero or garbage shorts.
            $sourceDuration = (float) ($state['source_duration'] ?? 0);
            $transcriptDuration = (float) ($result['totalDuration'] ?? 0);
            if ($sourceDuration > 0 && $transcriptDuration > 0
                && $sourceDuration < $transcriptDuration * 0.9) {
                throw new \Exception(sprintf(
                    'Downloaded video is only %.0f min of the %.0f min source — the download was incomplete, try again',
                    $sourceDuration / 60,
                    $transcriptDuration / 60
                ));
            }

            Log::info('Transcription complete', [
                'project_id' => $this->project->id,
                'segment_count' => count($result['segments'] ?? []),
                'duration' => $transcriptDuration,
                'source_duration' => $sourceDuration,
                'has_text' => !empty($result['text'])
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('[YT_GAMEPLAY] Transcription failed with exception', [
                'project_id' => $this->project->id,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * STEP 4: Select the N best non-overlapping clips (multi-pass GPT).
     *
     * Returns fewer clips than requested when the video simply doesn't contain
     * enough strong standalone moments — never pads with weak ones.
     */
    protected function selectBestClips(array $transcription): ?array
    {
        try {
            $segments = $transcription['segments'] ?? [];
            $totalDuration = $transcription['totalDuration'] ?? 0;

            if (empty($segments)) {
                throw new \Exception('No transcript segments available for clip selection');
            }

            $requested = $this->targetClipCount((float) $totalDuration);
            $clips = $this->clipSelectionService->findBestClips($segments, $totalDuration, $requested);

            if (empty($clips)) {
                throw new \Exception('No suitable clips found in transcript — video content may not contain a strong standalone segment');
            }

            if (count($clips) < $requested) {
                Log::info('[YT_GAMEPLAY] Fewer strong clips than the source-length ceiling allowed', [
                    'project_id' => $this->project->id,
                    'ceiling' => $requested,
                    'found' => count($clips),
                ]);
            }

            // Rank gate: ship the top scorers, drop the tail that would only
            // dilute the batch. Ordering is score-descending from here on, so
            // output_1 is always the strongest short.
            $clips = $this->shipworthyClips($clips);

            Log::info('[YT_GAMEPLAY] Shorts to build', [
                'project_id' => $this->project->id,
                'source_minutes' => round($totalDuration / 60, 1),
                'ceiling' => $requested,
                'shipping' => count($clips),
                'scores' => array_map(fn ($c) => (int) ($c['final_score'] ?? 0), $clips),
            ]);

            // Persist a lightweight summary only (subtitles can be long).
            $state = $this->project->processing_state ?? [];
            $state['selected_clips'] = array_map(fn($c) => [
                'start' => round($c['final_start_seconds'], 2),
                'end' => round($c['final_end_seconds'], 2),
                'score' => $c['final_score'] ?? 0,
            ], $clips);
            $this->project->update(['processing_state' => $state]);

            return $clips;

        } catch (\Exception $e) {
            Log::error('Clip selection failed', [
                'project_id' => $this->project->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * STEP 5: Prepare gameplay video path
     */
    protected function prepareGameplay(): bool
    {
        $gameplaySource = $this->settings['gameplay_source'] ?? 'backend_library';
        $state = $this->project->processing_state ?? [];
        $clipCount = max(1, count($state['selected_clips'] ?? []));

        try {
            if ($gameplaySource === 'backend_library') {
                // One entry per short, so a batch spreads across the library
                // instead of replaying one file from 0:00 every time.
                $pool = $this->gameplayService->getPoolFromLibrary($clipCount);
            } else {
                // Custom upload: a single file, varied by start offset later.
                $gameplayFile = $this->settings['gameplay_file'] ?? null;
                $pool = [Storage::disk('public')->path($gameplayFile)];
            }

            $pool = array_values(array_unique(array_filter(
                $pool,
                fn ($p) => $p && file_exists($p) && filesize($p) > 0
            )));

            if (empty($pool)) {
                throw new \Exception('Gameplay video file not found or is empty');
            }

            $state['gameplay_source_pool'] = $pool;
            // Kept for anything that still reads the single-path key.
            $state['gameplay_source_path'] = $pool[0];
            $this->project->update(['processing_state' => $state]);

            return true;

        } catch (\Exception $e) {
            Log::error('Gameplay preparation failed', [
                'project_id' => $this->project->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * STEP 6: Render every selected clip into a finished short.
     *
     * Phase A cuts all clips from the (large) source file — with the silent
     * parts edited out — so the source can be deleted as early as possible.
     * Phase B builds captions, composes, and generates a thumbnail per clip.
     *
     * A single failing clip is skipped, not fatal; the step only fails when
     * no clip could be rendered at all.
     *
     * Returns the outputs manifest that is also persisted to
     * processing_state['output_videos'].
     */
    protected function renderClips(string $sourceVideoPath, array $transcription, array $selectedClips): array
    {
        $segments = $transcription['segments'] ?? [];
        $gameplayEnabled = $this->gameplayEnabled();
        $clipTotal = count($selectedClips);

        $tmpDir = Storage::disk('public')->path("projects/{$this->project->id}/tmp");
        @mkdir($tmpDir, 0755, true);

        // Progress bookkeeping: each clip counts twice (cut + edit/render).
        $progressBase = (float) $this->project->progress;
        $progressSpan = max(0, 95 - $progressBase);
        $unitsTotal = $clipTotal * 2;
        $unitsDone = 0;
        $tick = function (string $message) use (&$unitsDone, $unitsTotal, $progressBase, $progressSpan) {
            $unitsDone++;
            $progress = (int) round($progressBase + $progressSpan * ($unitsDone / max(1, $unitsTotal)));
            $this->updateProgress(min(95, $progress));
            $this->pusherService->sendProgress($this->project->id, min(95, $progress), $message);
        };

        // ── Phase A: edit-plan + cut every clip while the source exists ──
        $cuts = [];
        foreach (array_values($selectedClips) as $i => $clip) {
            $n = $i + 1;
            try {
                // Which lines are dead weight. Asked BEFORE the cut, because
                // this is the last point at which the source video still
                // exists — after Phase A it is deleted to free the disk.
                $drops = $this->viralEditingEnabled()
                    ? $this->tightenService->dropSpans(
                        $segments,
                        (float) $clip['final_start_seconds'],
                        (float) $clip['final_end_seconds'],
                        (string) ($clip['hook'] ?? $clip['reason'] ?? ''),
                        (string) (($this->project->processing_state['video_title'] ?? null) ?: $this->project->title),
                        $n
                    )
                    : [];

                $plan = $this->editPlanService->plan(
                    $segments,
                    (float) $clip['final_start_seconds'],
                    (float) $clip['final_end_seconds'],
                    $this->measureSilence(
                        $sourceVideoPath,
                        (float) $clip['final_start_seconds'],
                        (float) $clip['final_end_seconds']
                    ),
                    $drops
                );

                Log::info('[YT_GAMEPLAY] Cutting clip', [
                    'project_id' => $this->project->id,
                    'clip' => "{$n}/{$clipTotal}",
                    'source_range' => [round($clip['final_start_seconds'], 2), round($clip['final_end_seconds'], 2)],
                    'keep_ranges' => count($plan['ranges']),
                    'edited_duration' => $plan['edited_duration'],
                    'removed_silence' => $plan['removed_seconds'],
                    'content_cuts' => count($drops),
                ]);

                $clipPath = $tmpDir . "/main_clip_{$n}.mp4";
                $cutResult = $this->clipCutterService->cutSegments($sourceVideoPath, $plan['ranges'], $clipPath, $this->project->id);

                $cuts[] = [
                    'index' => $n,
                    'clip' => $clip,
                    'plan' => $plan,
                    'path' => $clipPath,
                    // Measured, not planned: the gameplay loop is sized off
                    // this, and a loop shorter than its clip would truncate
                    // the short now that vstack terminates on the shortest.
                    'duration' => (float) $cutResult['duration'],
                    'content_cuts' => count($drops),
                ];
            } catch (\Exception $e) {
                Log::error('[YT_GAMEPLAY] Clip cut failed — skipping this clip', [
                    'project_id' => $this->project->id,
                    'clip' => "{$n}/{$clipTotal}",
                    'error' => $e->getMessage(),
                ]);
            }
            $tick("Cut clip {$n} of {$clipTotal}");
        }

        if (empty($cuts)) {
            throw new \Exception('Failed to cut any clips from the source video');
        }

        // Source video no longer needed — it can be 200-800MB, free it now.
        $this->deleteSourceVideoEarly($sourceVideoPath);

        // ── Phase B0: what each short SAYS (exact word timings from its own
        // audio) and what it SHOWS (faces, cuts, peaks, and the vision
        // model's read of the scene). The styles are planned across the whole
        // batch from these, so every short gets a different edit. ──
        $viral = $this->viralEditingEnabled();
        $titleForContext = (string) (($this->project->processing_state['video_title'] ?? null) ?: $this->project->title);
        foreach ($cuts as $cutIndex => $cut) {
            $n = $cut['index'];
            $this->pusherService->sendProgress(
                $this->project->id,
                (int) $this->project->progress,
                "Watching short {$n} of {$clipTotal}"
            );
            $cuts[$cutIndex]['words'] = $this->clipWordTimings($segments, $cut);
            $cuts[$cutIndex]['analysis'] = $viral
                ? $this->analyzeClip($cut, $cuts[$cutIndex]['words'])
                : $this->minimalAnalysis($cut);
        }

        // One webcam for the whole stream: clips that could not find it
        // borrow the box the others agreed on.
        if ($viral) {
            $shared = ClipSceneAnalyzer::shareWebcam(array_map(fn ($c) => $c['analysis'], $cuts));
            foreach ($cuts as $cutIndex => $cut) {
                $cuts[$cutIndex]['analysis'] = $shared[$cutIndex];
            }
        }

        $styles = $this->stylePlanner->assign(
            array_map(fn ($c) => $c['analysis'], $cuts),
            $this->editStyleMode(),
            (int) $this->project->id * 7919 + count($cuts)
        );

        // Library gameplay under a talking head is one flavour among several,
        // not the default look: alternate it across the shorts that suit it.
        $gameplayPlan = [];
        if ($gameplayEnabled) {
            try {
                $gameplayPlan = $this->planGameplaySegments($cuts);
            } catch (\Exception $e) {
                Log::warning('[YT_GAMEPLAY] Could not plan gameplay segments — no gameplay panels', [
                    'project_id' => $this->project->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $stockTurn = 0;

        // ── Phase B: layout → edit → render (+ thumbnail) per short ──
        $outputs = [];
        foreach ($cuts as $cutIndex => $cut) {
            $n = $cut['index'];
            try {
                $analysis = $cut['analysis'];
                $style = $styles[$cutIndex] ?? $styles[0];

                // Decide the layout; only build a gameplay loop if it is used.
                $singleSpeaker = $this->isSingleSpeaker($analysis);
                $stockAllowed = $gameplayEnabled && isset($gameplayPlan[$cutIndex])
                    && ($singleSpeaker || !$viral)
                    && ($stockTurn++ % 2 === 0 || !$viral);
                $layout = $this->layoutPlanner->plan(
                    $analysis,
                    $stockAllowed,
                    $this->layoutMode(),
                    (float) ($style['framing']['tightness'] ?? 1.0),
                    (int) $this->project->id + $cutIndex
                );

                $gameplayLoopPath = null;
                if ($layout['kind'] === 'gameplay_split_stock' || (!$viral && $stockAllowed)) {
                    try {
                        $gameplayLoopPath = $this->loopGameplay(
                            $gameplayPlan[$cutIndex]['source'],
                            (float) $gameplayPlan[$cutIndex]['start'],
                            (float) $cut['duration'] + 6.0,
                            $n
                        );
                    } catch (\Exception $e) {
                        Log::warning('[YT_GAMEPLAY] Gameplay panel failed — re-planning this short without it', [
                            'project_id' => $this->project->id,
                            'clip' => "{$n}/{$clipTotal}",
                            'error' => $e->getMessage(),
                        ]);
                        $layout = $this->layoutPlanner->plan(
                            $analysis,
                            false,
                            $this->layoutMode(),
                            (float) ($style['framing']['tightness'] ?? 1.0),
                            (int) $this->project->id + $cutIndex
                        );
                    }
                }

                $outputRelative = "projects/{$this->project->id}/output_{$n}.mp4";
                $outputAbsolute = Storage::disk('public')->path($outputRelative);

                $edit = null;
                $engine = 'basic';
                $duration = null;
                $music = null;
                if ($viral) {
                    $edit = $this->editDirector->direct(
                        $cut['words'],
                        $analysis,
                        $style,
                        (float) $cut['duration'],
                        $titleForContext,
                        $n
                    );
                    $music = $this->musicPlanner->plan(
                        $analysis,
                        $edit,
                        (int) $this->project->id,
                        $n,
                        (string) ($this->settings['music_category'] ?? 'auto')
                    );
                    $rendered = $this->renderViralShort($cut, $analysis, $layout, $style, $edit, $gameplayLoopPath, $outputAbsolute, $music);
                    if ($rendered) {
                        $engine = 'viral';
                        $duration = $this->probeDuration($outputAbsolute) ?? (float) $edit['duration'];
                    }
                }

                if ($engine === 'basic') {
                    // The plain composer: crop + optional gameplay + karaoke.
                    $captionsAss = $this->writeCaptionFile($cut['words'], $n, 'clip_audio');
                    $composed = $this->composerService->compose(
                        $cut['path'],
                        $gameplayLoopPath,
                        $captionsAss,
                        $outputAbsolute,
                        $this->project->id,
                        [
                            'aspect_ratio' => $this->project->aspect_ratio ?? '9:16',
                            'caption_position' => $this->settings['caption_position'] ?? 'top_section',
                            'focus_x' => $this->focusFromAnalysis($analysis) ?? $this->detectFocusX($cut['path'], $n),
                        ]
                    );
                    $duration = $composed['duration'] ?? (float) $cut['plan']['edited_duration'];
                }

                $thumbnailRelative = $this->generateClipThumbnail($outputAbsolute, $n);

                $outputs[] = [
                    'path' => $outputRelative,
                    'thumbnail' => $thumbnailRelative,
                    'duration' => round((float) $duration, 2),
                    'score' => (int) ($cut['clip']['final_score'] ?? 0),
                    'subtitle' => mb_substr(trim((string) ($cut['clip']['subtitle'] ?? '')), 0, 200),
                    'source_start' => round((float) $cut['clip']['final_start_seconds'], 2),
                    'source_end' => round((float) $cut['clip']['final_end_seconds'], 2),
                    'removed_silence_seconds' => (float) $cut['plan']['removed_seconds'],
                'content_cuts' => (int) ($cut['content_cuts'] ?? 0),
                    'gameplay' => $gameplayLoopPath !== null && ($engine === 'basic' || $layout['kind'] === 'gameplay_split_stock'),
                    'gameplay_start' => $gameplayLoopPath !== null
                        ? (float) ($gameplayPlan[$cutIndex]['start'] ?? 0.0)
                        : null,
                    'engine' => $engine,
                    'edit_style' => $engine === 'viral' ? (string) $style['name'] : 'Basic',
                    'edit_family' => $engine === 'viral' ? (string) $style['family'] : null,
                    'layout' => $engine === 'viral' ? (string) $layout['kind'] : null,
                    'framing' => $engine === 'viral'
                        ? sprintf('%.2fx %s', $style['framing']['tightness'] ?? 1.0, $style['framing']['drift_to'] ?? 'in')
                        : null,
                    'scene_type' => (string) ($analysis['scene_type'] ?? ''),
                    'music' => $engine === 'viral' && $music ? $music['category'] : null,
                    'title' => $edit['title'] ?? null,
                    'hook' => $edit['hook']['text'] ?? null,
                    'hashtags' => $edit['hashtags'] ?? [],
                    'beats' => $edit['beat_count'] ?? 0,
                ];
            } catch (\Exception $e) {
                Log::error('[YT_GAMEPLAY] Clip composition failed — skipping this clip', [
                    'project_id' => $this->project->id,
                    'clip' => "{$n}/{$clipTotal}",
                    'error' => $e->getMessage(),
                ]);
            }
            $tick("Edited short {$n} of {$clipTotal}");
        }

        if (empty($outputs)) {
            throw new \Exception('All clip compositions failed');
        }

        // The ffmpeg music pass, for shorts the composition did NOT bed itself.
        // A short rendered with `music` in its props already has the track
        // mixed on the edit's own clock; running this over it would lay a
        // second, unsynced copy on top.
        foreach ($outputs as $output) {
            if (!empty($output['music'])) {
                continue;
            }
            $this->applyBackgroundMusic(Storage::disk('public')->path($output['path']));
        }

        // Persist the manifest; first video doubles as the project's primary
        // output/thumbnail for anything that still expects a single file.
        $state = $this->project->processing_state ?? [];
        $state['output_videos'] = $outputs;

        $update = [
            'processing_state' => $state,
            'output_path' => $outputs[0]['path'],
            'duration' => $outputs[0]['duration'],
        ];
        if (!empty($outputs[0]['thumbnail'])) {
            $update['thumbnail_path'] = $outputs[0]['thumbnail'];
        }
        $this->project->update($update);

        Log::info('[YT_GAMEPLAY] Render complete', [
            'project_id' => $this->project->id,
            'requested' => $clipTotal,
            'rendered' => count($outputs),
            'gameplay_enabled' => $gameplayEnabled,
        ]);

        return $outputs;
    }

    /**
     * Container duration of a media file, or null when it cannot be read
     * (which is itself the useful answer: the file is not usable video).
     */
    protected function probeDuration(string $path): ?float
    {
        try {
            $response = $this->pythonService->makeRequest('POST', '/probe-duration', [
                'path' => $path,
                'project_id' => $this->project->id,
            ]);

            if (empty($response['success']) || empty($response['duration'])) {
                return null;
            }

            return (float) $response['duration'];

        } catch (\Exception $e) {
            Log::warning('[YT_GAMEPLAY] Duration probe failed', [
                'project_id' => $this->project->id,
                'path' => basename($path),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Dead air inside one clip window, measured from the audio.
     *
     * The transcript-gap plan alone finds nothing on the YouTube path (auto
     * captions are timed back-to-back, so a ten-second pause shows up as a
     * zero-length gap), which is why every rendered short reported
     * removed_silence_seconds = 0. Non-fatal: on any failure we return no
     * silences and the plan falls back to transcript gaps only.
     *
     * @return array<int, array{start: float, end: float}> source-time silences
     */
    protected function measureSilence(string $sourceVideoPath, float $clipStart, float $clipEnd): array
    {
        if (!file_exists($sourceVideoPath) || $clipEnd <= $clipStart) {
            return [];
        }

        try {
            $response = $this->pythonService->makeRequest('POST', '/detect-silence', [
                'source_path' => $sourceVideoPath,
                'start_seconds' => $clipStart,
                'end_seconds' => $clipEnd,
                'project_id' => $this->project->id,
            ]);

            if (empty($response['success']) || empty($response['silences'])) {
                return [];
            }

            $silences = [];
            foreach ($response['silences'] as $row) {
                $start = (float) ($row['start_seconds'] ?? 0);
                $end = (float) ($row['end_seconds'] ?? 0);
                if ($end > $start) {
                    $silences[] = ['start' => $start, 'end' => $end];
                }
            }

            Log::info('[YT_GAMEPLAY] Measured silence in clip window', [
                'project_id' => $this->project->id,
                'window' => [round($clipStart, 2), round($clipEnd, 2)],
                'silences' => count($silences),
                'silent_seconds' => $response['silent_seconds'] ?? 0,
            ]);

            return $silences;

        } catch (\Exception $e) {
            Log::warning('[YT_GAMEPLAY] Silence detection failed (non-fatal)', [
                'project_id' => $this->project->id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Where the speaker sits horizontally in this clip, so the 9:16 crop
     * follows them instead of blindly taking the middle of a 16:9 frame.
     * Non-fatal: 0.5 reproduces the old centre crop.
     */
    protected function detectFocusX(string $clipPath, int $clipIndex): float
    {
        try {
            $response = $this->pythonService->makeRequest('POST', '/video-focus', [
                'video_path' => $clipPath,
                'project_id' => $this->project->id,
            ]);

            if (empty($response['success'])) {
                return 0.5;
            }

            $focus = (float) ($response['focus_x'] ?? 0.5);

            Log::info('[YT_GAMEPLAY] Framing focus detected', [
                'project_id' => $this->project->id,
                'clip' => $clipIndex,
                'focus_x' => $focus,
                'frames_with_faces' => $response['frames_with_faces'] ?? 0,
                'frames_sampled' => $response['frames_sampled'] ?? 0,
            ]);

            return min(1.0, max(0.0, $focus));

        } catch (\Exception $e) {
            Log::warning('[YT_GAMEPLAY] Focus detection failed (non-fatal)', [
                'project_id' => $this->project->id,
                'clip' => $clipIndex,
                'error' => $e->getMessage(),
            ]);
            return 0.5;
        }
    }

    /**
     * Delete source video early (before final cleanup) — source downloads can
     * be 200-800MB. Only for YouTube mode: in upload mode the "source" is the
     * user's own uploaded file, which must survive for retries.
     */
    protected function deleteSourceVideoEarly(string $sourceVideoPath): void
    {
        if (($this->settings['input_mode'] ?? null) !== 'youtube_url') {
            return;
        }

        try {
            if (file_exists($sourceVideoPath)) {
                @unlink($sourceVideoPath);
                Log::info('Deleted large source video early', [
                    'project_id' => $this->project->id,
                    'path' => basename($sourceVideoPath)
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to delete source video early', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Decide which gameplay file each short uses and where inside it to start.
     *
     * Sources are dealt round-robin from the prepared pool. For every source,
     * the usable span (its duration minus the longest short that will play
     * over it) is cut into as many bands as there are shorts on that file, the
     * bands are shuffled, and each short gets a random point inside its own
     * band. That guarantees two shorts sharing one gameplay file never open on
     * the same footage — a plain rand() can hand out near-identical offsets.
     *
     * @param array<int, array> $cuts
     * @return array<int, array{source: string, start: float}> keyed by cut position
     */
    protected function planGameplaySegments(array $cuts): array
    {
        $state = $this->project->processing_state ?? [];
        $pool = $state['gameplay_source_pool'] ?? array_filter([$state['gameplay_source_path'] ?? null]);
        $pool = array_values(array_filter($pool, fn ($p) => $p && file_exists($p)));

        if (empty($pool)) {
            throw new \Exception('Gameplay source not found');
        }

        // Deal sources to shorts, then invert into "which shorts use file X".
        $positionsBySource = [];
        foreach (array_keys(array_values($cuts)) as $i) {
            $positionsBySource[$pool[$i % count($pool)]][] = $i;
        }

        $plan = [];
        foreach ($positionsBySource as $source => $positions) {
            $sourceDuration = (float) ($this->probeDuration($source) ?? 0.0);
            $slots = count($positions);

            // Every short on this file must fit from its offset onwards, so
            // the band grid is sized by the longest of them.
            $longestClip = 0.0;
            foreach ($positions as $i) {
                $longestClip = max($longestClip, (float) $cuts[$i]['duration']);
            }

            $usable = $sourceDuration - $longestClip - 0.5;

            if ($sourceDuration <= 0 || $usable <= 1.0) {
                // Short (or unreadable) gameplay file: it has to loop anyway,
                // so every offset shows the same thing. Start at 0.
                foreach ($positions as $i) {
                    $plan[$i] = ['source' => $source, 'start' => 0.0];
                }
                continue;
            }

            $bands = range(0, $slots - 1);
            shuffle($bands);
            $bandWidth = $usable / $slots;

            foreach (array_values($positions) as $k => $i) {
                // Inset inside the band (middle 80%), so two shorts landing in
                // ADJACENT bands still keep a fifth of a band between them
                // rather than meeting at the shared edge.
                $withinBand = (0.1 + 0.8 * (mt_rand(0, 1000) / 1000)) * $bandWidth;
                $offset = $bands[$k] * $bandWidth + $withinBand;
                $plan[$i] = [
                    'source' => $source,
                    'start' => round(min($usable, max(0.0, $offset)), 2),
                ];
            }
        }

        Log::info('[YT_GAMEPLAY] Gameplay segments planned', [
            'project_id' => $this->project->id,
            'sources' => count($positionsBySource),
            'starts' => array_map(fn ($p) => $p['start'], $plan),
        ]);

        return $plan;
    }

    /**
     * Build one short's gameplay panel: $sourcePath from $startSeconds,
     * looped/trimmed to $targetDurationSeconds and scaled to the panel size.
     */
    protected function loopGameplay(string $sourcePath, float $startSeconds, float $targetDurationSeconds, int $clipIndex): string
    {
        if (!file_exists($sourcePath)) {
            throw new \Exception('Gameplay source not found');
        }

        $tmpDir = Storage::disk('public')->path("projects/{$this->project->id}/tmp");
        $gameplayLoopPath = $tmpDir . "/gameplay_loop_{$clipIndex}.mp4";

        try {
            // Call Python /loop-video endpoint
            $payload = [
                'source_path' => $sourcePath,
                'target_duration_seconds' => $targetDurationSeconds,
                'output_width' => 1080,
                'output_height' => 768,  // 40% of 1920
                'output_path' => $gameplayLoopPath,
                'project_id' => $this->project->id,
                'start_seconds' => max(0.0, $startSeconds),
            ];

            $response = $this->pythonService->makeRequest('POST', '/loop-video', $payload);

            if (!$response['success']) {
                throw new \Exception($response['detail'] ?? 'Loop video failed');
            }

            if (!file_exists($gameplayLoopPath) || filesize($gameplayLoopPath) === 0) {
                throw new \Exception('Gameplay loop output is empty');
            }

            return $gameplayLoopPath;

        } catch (\Exception $e) {
            Log::error('Gameplay looping failed', [
                'project_id' => $this->project->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Exact word timings for one cut clip, on the clip's own clock.
     *
     * Preferred: Whisper with word timestamps on the CUT clip's audio — the
     * timings come from the exact audio that plays in the short. Fallback:
     * the source transcript spread across its segments and remapped through
     * the edit plan. Never throws; an empty list means a silent clip.
     *
     * @return array<int, array{word: string, start: float, end: float}>
     */
    protected function clipWordTimings(array $segments, array $cut): array
    {
        $duration = (float) ($cut['duration'] ?: $cut['plan']['edited_duration']);
        try {
            return $this->wordTimingsFromClipAudio($cut['path'], $cut['index'], $duration);
        } catch (\Exception $e) {
            Log::warning('[YT_GAMEPLAY] Per-clip audio timings failed — falling back to source-transcript remap', [
                'project_id' => $this->project->id,
                'clip' => $cut['index'],
                'error' => $e->getMessage(),
            ]);
            try {
                return $this->wordTimingsFromSourceTranscript($segments, $cut['clip'], $cut['plan'], $duration);
            } catch (\Exception $inner) {
                return [];
            }
        }
    }

    /**
     * Transcribe the edited clip's audio (Whisper, word-level timestamps).
     *
     * @return array<int, array{word: string, start: float, end: float}>
     */
    protected function wordTimingsFromClipAudio(string $clipPath, int $clipIndex, float $editedDuration): array
    {
        Log::info('[YT_GAMEPLAY] Transcribing cut clip for exact word timings', [
            'project_id' => $this->project->id,
            'clip' => $clipIndex,
            'path' => basename($clipPath),
        ]);

        $result = $this->transcriptionService->transcribe($clipPath, 'en', true);

        if (empty($result['segments'])) {
            throw new \Exception('Clip transcription returned no segments');
        }

        $wordTimings = [];
        foreach ($result['segments'] as $segment) {
            $words = $segment['words'] ?? [];

            if (!empty($words)) {
                foreach ($words as $w) {
                    $word = trim((string) ($w['word'] ?? ''));
                    if ($word === '') {
                        continue;
                    }
                    $start = max(0.0, min((float) ($w['start'] ?? 0), $editedDuration));
                    $end = max($start + 0.05, min((float) ($w['end'] ?? 0), $editedDuration));
                    $wordTimings[] = ['word' => $word, 'start' => $start, 'end' => $end];
                }
            } else {
                foreach ($this->spreadWordsAcrossWindow(
                    (string) ($segment['text'] ?? ''),
                    (float) ($segment['start'] ?? 0),
                    (float) ($segment['end'] ?? 0),
                    $editedDuration
                ) as $timing) {
                    $wordTimings[] = $timing;
                }
            }
        }

        if (empty($wordTimings)) {
            throw new \Exception('Clip transcription produced no word timings');
        }

        return $wordTimings;
    }

    /**
     * Evenly distribute a text's words across a time window, clamped to the
     * clip duration. Used only when Whisper returns a segment without
     * word-level detail.
     *
     * @return array<int, array{word: string, start: float, end: float}>
     */
    private function spreadWordsAcrossWindow(string $text, float $windowStart, float $windowEnd, float $maxDuration): array
    {
        $words = array_values(array_filter(explode(' ', trim($text)), fn ($w) => trim($w) !== ''));
        if (empty($words)) {
            return [];
        }

        $timePerWord = ($windowEnd - $windowStart) / count($words);
        $timings = [];

        foreach ($words as $i => $word) {
            $start = max(0.0, min($windowStart + $i * $timePerWord, $maxDuration));
            $end = max($start + 0.05, min($windowStart + ($i + 1) * $timePerWord, $maxDuration));

            $timings[] = ['word' => trim($word), 'start' => $start, 'end' => $end];
        }

        return $timings;
    }

    /**
     * FALLBACK: word timings approximated from the source transcript segments
     * inside the clip window, mapped through the edit plan's keep-ranges onto
     * the edited timeline.
     *
     * @return array<int, array{word: string, start: float, end: float}>
     */
    protected function wordTimingsFromSourceTranscript(array $segments, array $selectedClip, array $plan, float $editedDuration): array
    {
        $clipStart = (float) $selectedClip['final_start_seconds'];
        $clipEnd = (float) $selectedClip['final_end_seconds'];
        $ranges = $plan['ranges'];

        $clipSegments = array_filter($segments, function ($seg) use ($clipStart, $clipEnd) {
            $segStart = floatval($seg['start'] ?? 0);
            $segEnd = floatval($seg['end'] ?? 0);
            return $segEnd > $clipStart && $segStart < $clipEnd;
        });

        $wordTimings = [];
        foreach ($clipSegments as $segment) {
            $words = explode(' ', trim($segment['text'] ?? ''));
            $segStart = floatval($segment['start']);
            $segEnd = floatval($segment['end']);
            $timePerWord = (count($words) > 0) ? ($segEnd - $segStart) / count($words) : 0;

            foreach ($words as $i => $word) {
                if (empty(trim($word))) {
                    continue;
                }

                $editedStart = $this->editPlanService->toEditedTime($segStart + ($i * $timePerWord), $ranges);
                $editedEnd = $this->editPlanService->toEditedTime($segStart + (($i + 1) * $timePerWord), $ranges);

                if ($editedEnd - $editedStart < 0.05) {
                    continue;
                }

                $editedStart = max(0.0, min($editedStart, $editedDuration));
                $editedEnd = max($editedStart + 0.05, min($editedEnd, $editedDuration));

                $wordTimings[] = ['word' => trim($word), 'start' => $editedStart, 'end' => $editedEnd];
            }
        }

        if (empty($wordTimings)) {
            throw new \Exception('No word timings extracted from transcript');
        }

        return $wordTimings;
    }

    // ------------------------------------------------------------------
    // The viral editor
    // ------------------------------------------------------------------

    /** Full edits need the Remotion render service; otherwise the plain composer runs. */
    private function viralEditingEnabled(): bool
    {
        if (($this->settings['edit_style'] ?? 'auto_mix') === 'basic') {
            return false;
        }
        if ($this->viralAvailable === null) {
            $this->viralAvailable = $this->viralRenderer->isAvailable();
            if (!$this->viralAvailable) {
                Log::warning('[YT_GAMEPLAY] Render service unreachable — using the basic composer', [
                    'project_id' => $this->project->id,
                ]);
            }
        }

        return $this->viralAvailable;
    }

    private function editStyleMode(): string
    {
        $mode = (string) ($this->settings['edit_style'] ?? 'auto_mix');

        return array_key_exists($mode, ShortStylePlanner::FAMILY_LABELS) ? $mode : 'auto_mix';
    }

    private function layoutMode(): string
    {
        $mode = (string) ($this->settings['layout_mode'] ?? 'auto');

        return in_array($mode, ['auto', 'fill_follow', 'stack_two', 'blur_fit'], true) ? $mode : 'auto';
    }

    private function captionsEnabled(): bool
    {
        return filter_var($this->settings['captions_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    private function analyzeClip(array $cut, array $words): array
    {
        $text = implode(' ', array_map(fn ($w) => $w['word'], $words));
        $framesDir = Storage::disk('public')->path("projects/{$this->project->id}/tmp/frames_{$cut['index']}");

        try {
            return $this->sceneAnalyzer->analyze($cut['path'], $framesDir, $text, (int) $this->project->id, (int) $cut['index']);
        } catch (\Throwable $e) {
            Log::warning('[YT_GAMEPLAY] Scene analysis failed — plain layout', [
                'project_id' => $this->project->id,
                'clip' => $cut['index'],
                'error' => $e->getMessage(),
            ]);
            return $this->minimalAnalysis($cut);
        }
    }

    private function minimalAnalysis(array $cut): array
    {
        return [
            'source_width' => 1920, 'source_height' => 1080, 'duration' => (float) $cut['duration'],
            'scene_type' => 'other', 'people_on_screen' => 1, 'regions' => [], 'recommended_layout' => '',
            'visual_summary' => '', 'mood' => '', 'reaction_moments' => [], 'face_tracks' => [],
            'scene_cuts' => [], 'audio_peaks' => [], 'energy' => [], 'motion_mean' => 0.0,
            'faces_per_frame' => 0.0, 'faces_typical' => 0, 'camera_track' => null,
            'vision_used' => false,
        ];
    }

    private function isSingleSpeaker(array $analysis): bool
    {
        $tracks = array_filter($analysis['face_tracks'] ?? [], fn ($t) => $t['presence'] >= 0.35);
        $type = (string) ($analysis['scene_type'] ?? '');

        return count($tracks) === 1
            && !in_array($type, ['gameplay', 'gameplay_facecam', 'screen_recording', 'sports'], true);
    }

    /** The basic composer's horizontal focus, from the tracker when we have it. */
    private function focusFromAnalysis(array $analysis): ?float
    {
        $tracks = $analysis['face_tracks'] ?? [];
        if (!$tracks) {
            return null;
        }
        usort($tracks, fn ($a, $b) => ($b['presence'] * $b['size']) <=> ($a['presence'] * $a['size']));

        return min(0.95, max(0.05, 0.5 + ((float) $tracks[0]['cx'] - 0.5) * 0.85));
    }

    private function renderViralShort(
        array $cut,
        array $analysis,
        array $layout,
        array $style,
        array $edit,
        ?string $gameplayLoopPath,
        string $outputAbsolute,
        ?array $music = null
    ): bool {
        $publicRoot = rtrim(str_replace('\\', '/', Storage::disk('public')->path('')), '/') . '/';
        $toRelative = fn (string $abs) => ltrim(str_replace($publicRoot, '', str_replace('\\', '/', $abs)), '/');

        $gameplay = null;
        if ($gameplayLoopPath && $layout['kind'] === 'gameplay_split_stock') {
            $gameplay = [
                'url' => ViralShortRenderService::assetUrl($toRelative($gameplayLoopPath)),
                'duration' => (float) ($this->probeDuration($gameplayLoopPath) ?? ($cut['duration'] + 6)),
            ];
        }

        $props = $this->viralRenderer->buildProps(
            $toRelative($cut['path']),
            (float) $cut['duration'],
            $analysis,
            $layout,
            $style,
            $edit,
            $gameplay,
            $this->captionsEnabled() && !empty($edit['words']),
            $music
        );

        // Kept beside the temp files so a failed render can be replayed by
        // hand (scripts/short-render-check.ts <out> <props.json>).
        @file_put_contents(
            Storage::disk('public')->path("projects/{$this->project->id}/tmp/short_props_{$cut['index']}.json"),
            json_encode($props, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $result = $this->viralRenderer->render($props, $outputAbsolute, (int) $this->project->id, (int) $cut['index']);
        $ok = $result['success'] && is_file($outputAbsolute) && filesize($outputAbsolute) > 0;

        Log::log($ok ? 'info' : 'warning', $ok ? '[YT_GAMEPLAY] Short edited' : '[YT_GAMEPLAY] Edited render failed — falling back to the basic composer', [
            'project_id' => $this->project->id,
            'clip' => $cut['index'],
            'style' => $style['name'],
            'layout' => $layout['kind'],
            'layout_reason' => $layout['reason'] ?? '',
            'events' => count($edit['events']),
            'seconds' => $result['render_seconds'] ?? null,
            'error' => $result['error'] ?? null,
        ]);

        return $ok;
    }

    /**
     * Render word timings into the clip's .ass caption file and return its
     * absolute path. $method is logged so we can tell exact clip-audio
     * timings apart from the remap fallback.
     */
    private function writeCaptionFile(array $wordTimings, int $clipIndex, string $method): string
    {
        $tmpDir = "projects/{$this->project->id}/tmp";
        $captionsRelativePath = $tmpDir . "/captions_{$clipIndex}.ass";
        $captionTemplate = $this->settings['caption_template'] ?? 'modern_karaoke';

        $this->captionService->generateKaraokeCaptions(
            $wordTimings,
            $captionsRelativePath,
            $captionTemplate,
            $this->captionStyleOverrides()
        );

        $captionsAbsPath = Storage::disk('public')->path($captionsRelativePath);
        if (!file_exists($captionsAbsPath) || filesize($captionsAbsPath) === 0) {
            throw new \Exception('Caption file was not generated');
        }

        Log::info('Captions generated', [
            'project_id' => $this->project->id,
            'clip' => $clipIndex,
            'word_count' => count($wordTimings),
            'template' => $captionTemplate,
            'timing_method' => $method,
            'path' => basename($captionsAbsPath)
        ]);

        return $captionsAbsPath;
    }

    /**
     * Caption placement for the current layout (1080×1920 canvas; with
     * gameplay the split sits at y=1152). margin_v is measured up from the
     * bottom edge (alignment 2 = bottom-center anchor).
     */
    private function captionStyleOverrides(): array
    {
        $position = $this->settings['caption_position'] ?? 'top_section';

        if ($this->gameplayEnabled()) {
            return $position === 'bottom_section'
                ? ['margin_v' => 290]   // centred on the gameplay panel
                : ['margin_v' => 820];  // on the main clip, just above the split
        }

        return $position === 'bottom_section'
            ? ['margin_v' => 520]       // classic lower third
            : ['margin_v' => 880];      // upper-middle, just below centre
    }

    /**
     * Per-clip thumbnail via the Python service. Non-fatal: returns the
     * relative path on success, null on failure.
     */
    private function generateClipThumbnail(string $videoAbsolutePath, int $clipIndex): ?string
    {
        try {
            $relative = "projects/{$this->project->id}/thumbnail_{$clipIndex}.jpg";

            $payload = [
                'video_path' => $videoAbsolutePath,
                'time_seconds' => 1.0,
                'output_path' => Storage::disk('public')->path($relative),
                'width' => 1080,
                'height' => 1920,
                'project_id' => $this->project->id,
            ];

            $response = $this->pythonService->makeRequest('POST', '/generate-thumbnail', $payload);

            return !empty($response['success']) ? $relative : null;

        } catch (\Exception $e) {
            Log::warning('Thumbnail generation failed (non-fatal)', [
                'clip' => $clipIndex,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Upload final video to R2 (currently unused — kept for parity with the
     * other processors).
     */
    protected function uploadToR2(string $videoPath): bool
    {
        try {
            $key = "shorts/yt_gameplay_{$this->project->id}_" . time() . '.mp4';

            $result = $this->r2UploadService->upload($videoPath, $key, 'video/mp4');

            if ($result['success']) {
                $this->project->update([
                    'r2_url' => $result['url'],
                    'r2_key' => $key
                ]);
                return true;
            }

            throw new \Exception('R2 upload returned success=false');

        } catch (\Exception $e) {
            Log::error('R2 upload failed', [
                'project_id' => $this->project->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * STEP 7: Cleanup temporary files
     */
    protected function cleanupTempFiles(): bool
    {
        try {
            $tmpDir = Storage::disk('public')->path("projects/{$this->project->id}/tmp");

            if (is_dir($tmpDir)) {
                $files = glob($tmpDir . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
                @rmdir($tmpDir);
            }

            Log::info('Temp files cleaned up', ['project_id' => $this->project->id]);
            return true;

        } catch (\Exception $e) {
            Log::warning('Temp file cleanup failed (non-fatal)', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Validate that project settings meet template requirements
     */
    public function validate(): array
    {
        $errors = [];

        $inputMode = $this->settings['input_mode'] ?? null;
        if (!$inputMode || !in_array($inputMode, ['youtube_url', 'upload'])) {
            $errors[] = 'Input mode must be either youtube_url or upload';
        }

        if ($inputMode === 'youtube_url') {
            $url = $this->settings['main_url'] ?? null;
            if (!$url) {
                $errors[] = 'YouTube URL is required when using youtube_url mode';
            }
        } elseif ($inputMode === 'upload') {
            if (!$this->inputPath) {
                $errors[] = 'Video file must be uploaded for upload mode';
            }
        }

        if ($this->gameplayEnabled()) {
            $gameplaySource = $this->settings['gameplay_source'] ?? 'backend_library';
            if (!in_array($gameplaySource, ['backend_library', 'custom_upload'])) {
                $errors[] = 'Gameplay source must be either backend_library or custom_upload';
            }
        }

        $captionTemplate = $this->settings['caption_template'] ?? 'modern_karaoke';
        if (!in_array($captionTemplate, ['modern_karaoke', 'classic_block', 'minimal_clean'])) {
            $errors[] = 'Caption template must be one of: modern_karaoke, classic_block, minimal_clean';
        }

        $captionPosition = $this->settings['caption_position'] ?? 'top_section';
        if (!in_array($captionPosition, ['top_section', 'bottom_section'])) {
            $errors[] = 'Caption position must be either top_section or bottom_section';
        }

        return $errors;
    }

    /**
     * Get template-specific configuration
     */
    protected function getTemplateConfig(): array
    {
        return self::getStaticTemplateConfig();
    }

    /**
     * Get processing steps for this template
     */
    protected function getProcessingSteps(): array
    {
        return [
            'validate_input' => 5,
            'download_video' => 15,
            'transcribe_video' => 20,
            'select_best_clips' => 15,
            'prepare_gameplay' => 3,
            'render_clips' => 37,
            'cleanup' => 5,
        ];
    }

    /**
     * Get static template configuration
     */
    public static function getStaticTemplateConfig(): array
    {
        return [
            'name' => 'Long Video to Shorts',
            'description' => 'Turn one long video into up to 20 fully edited 9:16 shorts. The AI finds the moments worth posting, watches each one to understand the scene (podcast, streamer with facecam, talking head, screen), lays it out for a phone (speaker-following crop, stacked speakers, facecam over gameplay), and edits every short in its own style: animated captions, punch-in zooms, meme pops and sound effects.',
            'requires_upload' => false,
            'min_duration' => 15,
            'max_duration' => 600,
            'max_file_size_mb' => 500,
            'supported_formats' => ['mp4', 'mov', 'webm', 'avi', 'mkv'],
            'output_formats' => ['mp4'],
            'settings_schema' => [
                'input_mode' => [
                    'type' => 'radio',
                    'label' => 'Source Video',
                    'options' => [
                        'youtube_url' => 'YouTube URL',
                        'upload' => 'Upload Video File'
                    ],
                    'default' => 'youtube_url'
                ],
                'main_url' => [
                    'type' => 'text',
                    'label' => 'YouTube URL',
                    'placeholder' => 'https://www.youtube.com/watch?v=...',
                    'required' => true,
                    'visible_when' => ['input_mode' => 'youtube_url']
                ],
                'video_file' => [
                    'type' => 'file',
                    'label' => 'Upload Video',
                    'accept' => 'video/*',
                    'max_size' => 536870912,
                    'required' => true,
                    'visible_when' => ['input_mode' => 'upload']
                ],
                // NOTE: there is deliberately no "number of shorts" field. The
                // count is decided per run: the source length sets a ceiling
                // (2-12) and the clips' own viral scores decide how many of
                // them clear the quality gate. Adding the field back lets a
                // user demand 12 shorts from a 6-minute video, which can only
                // be satisfied with weak, repetitive picks.
                'edit_style' => [
                    'type' => 'select',
                    'label' => 'Edit style',
                    'options' => [
                        'auto_mix' => 'AI mix — every short edited differently',
                        'meme_chaos' => 'Meme chaos (zooms, sound effects, freeze frames)',
                        'hormozi' => 'Bold captions',
                        'podcast_clean' => 'Clean podcast',
                        'neon_gamer' => 'Neon gamer',
                        'storyteller' => 'Storyteller',
                        'comic_pop' => 'Comic pop',
                        'news_flash' => 'News flash',
                        'minimal' => 'Minimal',
                        'basic' => 'Basic (captions only)',
                    ],
                    'default' => 'auto_mix'
                ],
                'layout_mode' => [
                    'type' => 'select',
                    'label' => 'Framing',
                    'options' => [
                        'auto' => 'AI decides per short',
                        'fill_follow' => 'Follow the speaker (full screen)',
                        'stack_two' => 'Two speakers stacked',
                        'blur_fit' => 'Whole frame on blurred background',
                    ],
                    'default' => 'auto'
                ],
                'captions_enabled' => [
                    'type' => 'checkbox',
                    'label' => 'Animated captions',
                    'default' => true
                ],
                'gameplay_enabled' => [
                    'type' => 'checkbox',
                    'label' => 'Add gameplay under some single-speaker shorts',
                    'default' => true
                ],
                'gameplay_source' => [
                    'type' => 'radio',
                    'label' => 'Gameplay Video',
                    'options' => [
                        'backend_library' => 'Use from Library',
                        'custom_upload' => 'Upload Custom'
                    ],
                    'default' => 'backend_library',
                    'visible_when' => ['gameplay_enabled' => true]
                ],
                'gameplay_file' => [
                    'type' => 'file',
                    'label' => 'Upload Gameplay',
                    'accept' => 'video/*',
                    'max_size' => 536870912,
                    'visible_when' => ['gameplay_enabled' => true, 'gameplay_source' => 'custom_upload']
                ],
                'caption_template' => [
                    'type' => 'select',
                    'label' => 'Caption Style',
                    'options' => [
                        'modern_karaoke' => 'Modern Karaoke',
                        'classic_block' => 'Classic Block',
                        'minimal_clean' => 'Minimal Clean'
                    ],
                    'default' => 'modern_karaoke'
                ],
                'caption_position' => [
                    'type' => 'select',
                    'label' => 'Caption Position',
                    'options' => [
                        'top_section' => 'Upper Area',
                        'bottom_section' => 'Lower Area'
                    ],
                    'default' => 'top_section'
                ]
            ],
            'processing_steps' => [
                'validate_input' => 5,
                'download_video' => 15,
                'transcribe_video' => 20,
                'select_best_clips' => 15,
                'prepare_gameplay' => 3,
                'render_clips' => 37,
                'cleanup' => 5,
            ]
        ];
    }
}
