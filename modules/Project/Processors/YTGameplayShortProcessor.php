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

/**
 * YTGameplayShortProcessor
 *
 * Converts a YouTube video or uploaded video into MULTIPLE 9:16 short-form
 * vertical videos (user-selectable count, default 4) by intelligently
 * selecting the best non-overlapping clips, editing the silence/dead air out
 * of each one, and compositing them with optional gameplay footage.
 *
 * Workflow:
 * 1. Validate input
 * 2. Download/prepare source video
 * 3. Transcribe (RapidAPI for YouTube; local Faster-Whisper fallback for uploads)
 * 4. Select the N best clips (multi-pass GPT, sentence-snapped, non-overlapping)
 * 5. Prepare gameplay video (only when the gameplay panel is enabled)
 * 6. Render each clip:
 *    - build an edit plan that skips the empty parts (silence/dead air)
 *    - cut the clip from the kept ranges (multi-segment concat)
 *    - transcribe the CUT clip's audio with Whisper word timestamps and build
 *      karaoke captions from those exact timings (they are already on the
 *      edited timeline, so captions are perfectly in sync); falls back to
 *      remapping the source transcript if per-clip transcription fails
 *    - compose (main + gameplay split, or full-frame main when disabled)
 *    - generate a per-clip thumbnail
 * 7. Cleanup temp files
 */
class YTGameplayShortProcessor extends AbstractVideoProcessor
{
    private const MIN_CLIPS = 1;
    private const MAX_CLIPS = 6;
    private const DEFAULT_CLIPS = 4;

    private YoutubeDownloaderInterface $downloadService;
    private VideoTranscriptionService $transcriptionService;
    private RapidApiTranscriptionService $rapidTranscriptionService;
    private ClipSelectionService $clipSelectionService;
    private ClipEditPlanService $editPlanService;
    private GameplayVideoService $gameplayService;
    private VideoClipCutterService $clipCutterService;
    private ShortComposerService $composerService;
    private WordLevelCaptionService $captionService;
    private R2UploadService $r2UploadService;
    private PythonAIService $pythonService;

    public function __construct(\Modules\Project\Models\Project $project)
    {
        parent::__construct($project);

        $this->downloadService = YoutubeDownloaderFactory::make();
        $this->transcriptionService = new VideoTranscriptionService();
        $this->rapidTranscriptionService = new RapidApiTranscriptionService();
        $this->clipSelectionService = new ClipSelectionService();
        $this->editPlanService = new ClipEditPlanService();
        $this->gameplayService = new GameplayVideoService();
        $this->clipCutterService = new VideoClipCutterService();
        $this->composerService = new ShortComposerService();
        $this->captionService = new WordLevelCaptionService();
        $this->r2UploadService = new R2UploadService();
        $this->pythonService = new PythonAIService();
    }

    public function process(): bool
    {
        try {
            Log::info('YTGameplayShortProcessor starting', [
                'project_id' => $this->project->id,
                'input_mode' => $this->settings['input_mode'] ?? 'unknown',
                'clip_count' => $this->clipCount(),
                'gameplay_enabled' => $this->gameplayEnabled(),
            ]);

            $this->logActivity('yt_gameplay_short_processing_started', 'YT + Gameplay Short processing started');
            $this->pusherService->sendStatus($this->project->id, 'processing', 'Starting YT + Gameplay Short processing');

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
     * Number of shorts to produce, from settings (default 4, clamped 1–6).
     */
    private function clipCount(): int
    {
        $raw = $this->settings['clip_count'] ?? self::DEFAULT_CLIPS;
        $count = is_numeric($raw) ? (int) $raw : self::DEFAULT_CLIPS;

        return max(self::MIN_CLIPS, min(self::MAX_CLIPS, $count));
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

        // Validate clip count
        $rawCount = $this->settings['clip_count'] ?? self::DEFAULT_CLIPS;
        if (!is_numeric($rawCount) || (int) $rawCount < self::MIN_CLIPS || (int) $rawCount > self::MAX_CLIPS) {
            throw new \Exception('Number of videos must be between ' . self::MIN_CLIPS . ' and ' . self::MAX_CLIPS);
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
            $mainUrl = $this->settings['main_url'];

            // Init download
            $initResult = $this->downloadService->initDownload($mainUrl);
            $progressUrl = $initResult['progress_url'];
            $videoTitle = $initResult['title'];

            // Poll until ready
            $downloadUrl = $this->downloadService->pollUntilReady($progressUrl);

            // Stream download to storage
            $this->downloadService->streamToStorage($downloadUrl, $sourceVideoPath);

            // Store in processing_state
            $state = $this->project->processing_state ?? [];
            $state['source_video_path'] = $sourceVideoPath;
            $state['video_title'] = $videoTitle;
            $this->project->update(['processing_state' => $state]);

        } else {
            // Upload mode — file already saved by controller
            $sourceVideoPath = Storage::disk('public')->path($this->project->video_path);
        }

        if (!file_exists($sourceVideoPath) || filesize($sourceVideoPath) === 0) {
            throw new \Exception('Source video is missing or empty');
        }

        Log::info('Source video ready', [
            'project_id' => $this->project->id,
            'path' => basename($sourceVideoPath),
            'size' => filesize($sourceVideoPath)
        ]);

        return $sourceVideoPath;
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
            $result = null;
            $videoId = $this->youtubeVideoId();

            if ($videoId !== null) {
                try {
                    Log::info('[YT_GAMEPLAY] Transcribing via RapidAPI', [
                        'project_id' => $this->project->id,
                        'video_id' => $videoId,
                    ]);
                    $result = $this->rapidTranscriptionService->transcribeByVideoId($videoId);
                } catch (\Exception $e) {
                    Log::warning('[YT_GAMEPLAY] RapidAPI transcription failed, falling back to local', [
                        'project_id' => $this->project->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

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

            Log::info('Transcription complete', [
                'project_id' => $this->project->id,
                'segment_count' => count($result['segments'] ?? []),
                'duration' => $result['totalDuration'] ?? 0,
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

            $requested = $this->clipCount();
            $clips = $this->clipSelectionService->findBestClips($segments, $totalDuration, $requested);

            if (empty($clips)) {
                throw new \Exception('No suitable clips found in transcript — video content may not contain a strong standalone segment');
            }

            if (count($clips) < $requested) {
                Log::warning('[YT_GAMEPLAY] Fewer strong clips than requested', [
                    'project_id' => $this->project->id,
                    'requested' => $requested,
                    'found' => count($clips),
                ]);
            }

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

        try {
            if ($gameplaySource === 'backend_library') {
                $gameplayPath = $this->gameplayService->getRandomFromLibrary();
            } else {
                // Custom upload
                $gameplayFile = $this->settings['gameplay_file'] ?? null;
                $gameplayPath = Storage::disk('public')->path($gameplayFile);
            }

            if (!file_exists($gameplayPath) || filesize($gameplayPath) === 0) {
                throw new \Exception('Gameplay video file not found or is empty');
            }

            $state = $this->project->processing_state ?? [];
            $state['gameplay_source_path'] = $gameplayPath;
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

        // Progress bookkeeping: each clip counts twice (cut + compose), the
        // shared gameplay loop once.
        $progressBase = (float) $this->project->progress;
        $progressSpan = max(0, 95 - $progressBase);
        $unitsTotal = $clipTotal * 2 + ($gameplayEnabled ? 1 : 0);
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
                $plan = $this->editPlanService->plan(
                    $segments,
                    (float) $clip['final_start_seconds'],
                    (float) $clip['final_end_seconds']
                );

                Log::info('[YT_GAMEPLAY] Cutting clip', [
                    'project_id' => $this->project->id,
                    'clip' => "{$n}/{$clipTotal}",
                    'source_range' => [round($clip['final_start_seconds'], 2), round($clip['final_end_seconds'], 2)],
                    'keep_ranges' => count($plan['ranges']),
                    'edited_duration' => $plan['edited_duration'],
                    'removed_silence' => $plan['removed_seconds'],
                ]);

                $clipPath = $tmpDir . "/main_clip_{$n}.mp4";
                $this->clipCutterService->cutSegments($sourceVideoPath, $plan['ranges'], $clipPath, $this->project->id);

                $cuts[] = ['index' => $n, 'clip' => $clip, 'plan' => $plan, 'path' => $clipPath];
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

        // ── Shared gameplay loop, sized to the longest edited clip.
        // vstack stops at the shorter input, so one loop serves every clip. ──
        $gameplayLoopPath = null;
        if ($gameplayEnabled) {
            $maxDuration = max(array_map(fn($c) => (float) $c['plan']['edited_duration'], $cuts));
            $gameplayLoopPath = $this->loopGameplay($maxDuration + 0.5);
            $tick('Gameplay loop ready');
        }

        // ── Phase B: captions + compose + thumbnail per clip ──
        $outputs = [];
        foreach ($cuts as $cut) {
            $n = $cut['index'];
            try {
                $captionsAss = $this->buildClipCaptions($segments, $cut['clip'], $cut['plan'], $n, $cut['path']);

                $outputRelative = "projects/{$this->project->id}/output_{$n}.mp4";
                $outputAbsolute = Storage::disk('public')->path($outputRelative);

                $this->composerService->compose(
                    $cut['path'],
                    $gameplayLoopPath,
                    $captionsAss,
                    $outputAbsolute,
                    $this->project->id,
                    [
                        'aspect_ratio' => $this->project->aspect_ratio ?? '9:16',
                        'caption_position' => $this->settings['caption_position'] ?? 'top_section',
                    ]
                );

                $thumbnailRelative = $this->generateClipThumbnail($outputAbsolute, $n);

                $outputs[] = [
                    'path' => $outputRelative,
                    'thumbnail' => $thumbnailRelative,
                    'duration' => round((float) $cut['plan']['edited_duration'], 2),
                    'score' => (int) ($cut['clip']['final_score'] ?? 0),
                    'subtitle' => mb_substr(trim((string) ($cut['clip']['subtitle'] ?? '')), 0, 200),
                    'source_start' => round((float) $cut['clip']['final_start_seconds'], 2),
                    'source_end' => round((float) $cut['clip']['final_end_seconds'], 2),
                    'removed_silence_seconds' => (float) $cut['plan']['removed_seconds'],
                ];
            } catch (\Exception $e) {
                Log::error('[YT_GAMEPLAY] Clip composition failed — skipping this clip', [
                    'project_id' => $this->project->id,
                    'clip' => "{$n}/{$clipTotal}",
                    'error' => $e->getMessage(),
                ]);
            }
            $tick("Composed short {$n} of {$clipTotal}");
        }

        if (empty($outputs)) {
            throw new \Exception('All clip compositions failed');
        }

        // Optional Pixabay music bed under each short (default: none).
        foreach ($outputs as $output) {
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
     * Loop gameplay video to the target duration (shared across clips).
     */
    protected function loopGameplay(float $targetDurationSeconds): string
    {
        $state = $this->project->processing_state ?? [];
        $gameplaySourcePath = $state['gameplay_source_path'] ?? null;

        if (!$gameplaySourcePath || !file_exists($gameplaySourcePath)) {
            throw new \Exception('Gameplay source not found');
        }

        $tmpDir = Storage::disk('public')->path("projects/{$this->project->id}/tmp");
        $gameplayLoopPath = $tmpDir . '/gameplay_loop.mp4';

        try {
            // Call Python /loop-video endpoint
            $payload = [
                'source_path' => $gameplaySourcePath,
                'target_duration_seconds' => $targetDurationSeconds,
                'output_width' => 1080,
                'output_height' => 768,  // 40% of 1920
                'output_path' => $gameplayLoopPath,
                'project_id' => $this->project->id,
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
     * Build karaoke captions for one clip.
     *
     * Preferred path: transcribe the CUT clip's own audio with Whisper word
     * timestamps — the timings come from the exact audio that plays in the
     * final short, so captions are perfectly in sync with no remapping.
     *
     * Fallback (per-clip transcription failed / found no speech): derive word
     * timings from the source transcript and remap them through the edit plan,
     * as before. Returns the absolute path of the generated .ass file.
     */
    protected function buildClipCaptions(array $segments, array $selectedClip, array $plan, int $clipIndex, string $clipPath): string
    {
        try {
            return $this->buildClipCaptionsFromClipAudio($clipPath, $clipIndex, (float) $plan['edited_duration']);
        } catch (\Exception $e) {
            Log::warning('[YT_GAMEPLAY] Per-clip audio captions failed — falling back to source-transcript remap', [
                'project_id' => $this->project->id,
                'clip' => $clipIndex,
                'error' => $e->getMessage(),
            ]);

            return $this->buildClipCaptionsFromSourceTranscript($segments, $selectedClip, $plan, $clipIndex);
        }
    }

    /**
     * Transcribe the edited clip's audio (Whisper, word-level timestamps) and
     * build word timings directly on the clip's timeline.
     */
    protected function buildClipCaptionsFromClipAudio(string $clipPath, int $clipIndex, float $editedDuration): string
    {
        Log::info('[YT_GAMEPLAY] Transcribing cut clip for exact caption timings', [
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
                // Exact per-word timings from Whisper.
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
                // Segment came back without word detail — spread its words
                // evenly. Still accurate: the segment bounds are measured on
                // the clip's own audio.
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

        return $this->writeCaptionFile($wordTimings, $clipIndex, 'clip_audio');
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
     */
    protected function buildClipCaptionsFromSourceTranscript(array $segments, array $selectedClip, array $plan, int $clipIndex): string
    {
        $clipStart = (float) $selectedClip['final_start_seconds'];
        $clipEnd = (float) $selectedClip['final_end_seconds'];
        $ranges = $plan['ranges'];
        $editedDuration = (float) $plan['edited_duration'];

        // Segments that overlap the clip window
        $clipSegments = array_filter($segments, function ($seg) use ($clipStart, $clipEnd) {
            $segStart = floatval($seg['start'] ?? 0);
            $segEnd = floatval($seg['end'] ?? 0);
            return $segEnd > $clipStart && $segStart < $clipEnd;
        });

        // Word-level timings, evenly spread across each segment, then mapped
        // onto the edited timeline.
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

                $sourceStart = $segStart + ($i * $timePerWord);
                $sourceEnd = $segStart + (($i + 1) * $timePerWord);

                $editedStart = $this->editPlanService->toEditedTime($sourceStart, $ranges);
                $editedEnd = $this->editPlanService->toEditedTime($sourceEnd, $ranges);

                // Word fell inside a removed gap — drop it.
                if ($editedEnd - $editedStart < 0.05) {
                    continue;
                }

                $editedStart = max(0.0, min($editedStart, $editedDuration));
                $editedEnd = max($editedStart + 0.05, min($editedEnd, $editedDuration));

                $wordTimings[] = [
                    'word' => trim($word),
                    'start' => $editedStart,
                    'end' => $editedEnd
                ];
            }
        }

        if (empty($wordTimings)) {
            throw new \Exception('No word timings extracted from transcript');
        }

        return $this->writeCaptionFile($wordTimings, $clipIndex, 'source_transcript_remap');
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

        $rawCount = $this->settings['clip_count'] ?? self::DEFAULT_CLIPS;
        if (!is_numeric($rawCount) || (int) $rawCount < self::MIN_CLIPS || (int) $rawCount > self::MAX_CLIPS) {
            $errors[] = 'Number of videos must be between ' . self::MIN_CLIPS . ' and ' . self::MAX_CLIPS;
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
            'name' => 'YouTube + Gameplay Short',
            'description' => 'Turn one YouTube video into multiple 9:16 vertical shorts — the best moments are auto-selected, dead air is edited out, and each short gets karaoke captions with optional gameplay footage at the bottom.',
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
                'clip_count' => [
                    'type' => 'select',
                    'label' => 'Number of Shorts',
                    'options' => [
                        '1' => '1 video',
                        '2' => '2 videos',
                        '3' => '3 videos',
                        '4' => '4 videos (recommended)',
                        '5' => '5 videos',
                        '6' => '6 videos'
                    ],
                    'default' => '4'
                ],
                'gameplay_enabled' => [
                    'type' => 'checkbox',
                    'label' => 'Show gameplay video at the bottom',
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
