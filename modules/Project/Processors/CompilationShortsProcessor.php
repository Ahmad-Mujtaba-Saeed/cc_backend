<?php

namespace Modules\Project\Processors;

use App\Services\PythonAIService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Project\Models\Project;
use Modules\Project\Services\MomentRankingService;
use Modules\Project\Services\R2UploadService;
use Modules\Project\Services\SceneDetectionService;
use Modules\Project\Services\TTSGenerationService;
use Modules\Project\Services\VideoTranscriptionService;
use Modules\Project\Services\WordLevelCaptionService;
use Modules\Project\Contracts\YoutubeDownloaderInterface;
use Modules\Project\Services\YoutubeDownloaderFactory;
use Symfony\Component\Process\Process;

/**
 * CompilationShortsProcessor
 *
 * Turns 2-3 user-provided YouTube URLs into a single themed compilation
 * video (e.g. "30 Disaster Moments") with AI commentary voiceover,
 * countdown overlays and karaoke captions.
 *
 * Cost profile: everything is free/local (FFmpeg scene detection,
 * Faster-Whisper transcription, Kokoro TTS) except two small batched
 * gpt-4o-mini text calls, both with full local fallbacks.
 *
 * Copyright-risk mitigation (reduction, NOT immunity):
 * - every clip window capped at max_clip_seconds (4-8s), and a clip may only
 *   run past it by MAX_CLIP_STRETCH_SECONDS so its commentary can finish
 * - total footage per source capped at min(90s, 15% of duration)
 * - clips interleaved across sources and shuffled
 * - commentary voiceover mixed on top, original audio ducked to 25%
 * - 5% zoom treatment so no frame is reproduced verbatim
 *
 * Workflow:
 *  1. Validate input          8. Generate TTS (Kokoro)
 *  2. Download sources        9. Render treated clips
 *  3. Detect scenes          10. Assemble + burn overlays
 *  4. Transcribe sources     11. Generate thumbnail
 *  5. Rank moments           12. Upload to R2
 *  6. Select clips           13. Cleanup
 *  7. Generate commentary
 */
class CompilationShortsProcessor extends AbstractVideoProcessor
{
    private const TITLE_CARD_SECONDS = 1.6;

    /**
     * Most a clip may run past its selected window so the commentary fits.
     * The clip selector is told about this so it can space windows far enough
     * apart that a stretched clip never eats into the next pick.
     */
    private const MAX_CLIP_STRETCH_SECONDS = 3.0;

    /** Beat of silence before the commentary starts, so it clears the cut. */
    private const VOICE_LEAD_IN_SECONDS = 0.15;

    /** Beat of silence after the commentary before the clip cuts away. */
    private const VOICE_TAIL_SECONDS = 0.30;

    /** How much the voiceover may be sped up to land inside its clip. */
    private const MAX_VOICE_TEMPO = 1.25;

    private YoutubeDownloaderInterface $downloadService;
    private SceneDetectionService $sceneService;
    private VideoTranscriptionService $transcriptionService;
    private MomentRankingService $rankingService;
    private TTSGenerationService $ttsService;
    private WordLevelCaptionService $captionService;
    private R2UploadService $r2UploadService;
    private PythonAIService $pythonService;

    public function __construct(Project $project)
    {
        parent::__construct($project);

        $this->downloadService = YoutubeDownloaderFactory::make();
        $this->sceneService = new SceneDetectionService();
        $this->transcriptionService = new VideoTranscriptionService();
        $this->rankingService = new MomentRankingService();
        $this->ttsService = new TTSGenerationService();
        $this->captionService = new WordLevelCaptionService();
        $this->r2UploadService = new R2UploadService();
        $this->pythonService = new PythonAIService();
    }

    public function process(): bool
    {
        try {
            Log::info('CompilationShortsProcessor starting', [
                'project_id' => $this->project->id,
                'theme' => $this->settings['theme'] ?? null,
            ]);

            $this->logActivity('compilation_processing_started', 'Moments Compilation processing started');
            $this->pusherService->sendStatus($this->project->id, 'processing', 'Starting Moments Compilation processing');

            $validation = $this->runProcessingStep(
                'validate_input',
                fn () => $this->validateInput(),
                4,
                'Validating input',
                'Input validation completed',
                'Validation failed'
            );
            if ($validation === false) {
                return false;
            }

            $sources = $this->runProcessingStep(
                'download_videos',
                fn () => $this->downloadSources(),
                22,
                'Downloading source videos',
                'Source videos downloaded',
                'Failed to download source videos'
            );
            if (!$sources) {
                return false;
            }

            $analysis = $this->runProcessingStep(
                'analyze_scenes',
                fn () => $this->analyzeScenes($sources),
                32,
                'Detecting scenes and audio peaks',
                'Scene analysis completed',
                'Failed to analyze scenes'
            );
            if (!$analysis) {
                return false;
            }

            $transcripts = $this->runProcessingStep(
                'transcribe_sources',
                fn () => $this->transcribeSources($sources),
                45,
                'Transcribing source videos',
                'Transcription completed',
                'Failed to transcribe sources'
            );
            if ($transcripts === false || $transcripts === null) {
                return false;
            }

            $ranked = $this->runProcessingStep(
                'rank_moments',
                fn () => $this->rankMoments($analysis, $transcripts),
                52,
                'Ranking the best moments',
                'Moment ranking completed',
                'Failed to rank moments'
            );
            if (!$ranked) {
                return false;
            }

            $clips = $this->runProcessingStep(
                'select_clips',
                fn () => $this->selectClips($ranked, $analysis),
                55,
                'Selecting clips',
                'Clip selection completed',
                'Failed to select clips'
            );
            if (!$clips) {
                return false;
            }

            $commentary = [];
            $ttsResults = [];

            if ($this->commentaryEnabled()) {
                $commentary = $this->runProcessingStep(
                    'generate_commentary',
                    fn () => $this->generateCommentary($clips),
                    60,
                    'Writing commentary',
                    'Commentary generated',
                    'Failed to generate commentary'
                );
                if (!$commentary) {
                    return false;
                }

                $ttsResults = $this->runProcessingStep(
                    'generate_tts',
                    fn () => $this->generateTTS($commentary),
                    68,
                    'Generating commentary voiceover',
                    'Voiceover generated',
                    'Failed to generate voiceover'
                );
                if (!$ttsResults) {
                    return false;
                }
            } else {
                Log::info('Compilation: commentary disabled, skipping commentary + voiceover', [
                    'project_id' => $this->project->id,
                ]);
                $this->pusherService->sendStatus($this->project->id, 'processing', 'Commentary disabled, using original audio');
            }

            $rendered = $this->runProcessingStep(
                'render_clips',
                fn () => $this->renderClips($sources, $clips, $ttsResults),
                80,
                'Rendering clips',
                'Clips rendered',
                'Failed to render clips'
            );
            if (!$rendered) {
                return false;
            }

            $outputVideo = $this->runProcessingStep(
                'assemble_video',
                fn () => $this->assembleVideo($rendered, $clips, $ttsResults, $commentary),
                88,
                'Assembling final compilation',
                'Compilation assembled',
                'Failed to assemble compilation'
            );
            if (!$outputVideo) {
                return false;
            }

            // $this->runProcessingStep(
            //     'generate_thumbnail',
            //     fn () => $this->generateThumbnail('00:00:02', $outputVideo),
            //     90,
            //     'Generating thumbnail',
            //     'Thumbnail generated',
            //     'Failed to generate thumbnail'
            // );

            // $uploaded = $this->runProcessingStep(
            //     'upload_to_r2',
            //     fn () => $this->uploadToR2($outputVideo),
            //     96,
            //     'Uploading to R2',
            //     'R2 upload completed',
            //     'Failed to upload to R2'
            // );
            // if (!$uploaded) {
            //     return false;
            // }

            $this->runProcessingStep(
                'cleanup',
                fn () => $this->cleanupTempFiles(),
                98,
                'Cleaning up temporary files',
                'Cleanup completed',
                'Cleanup failed (non-fatal)'
            );

            $this->handleSuccess();
            $this->pusherService->sendStatus($this->project->id, 'completed', 'Compilation completed successfully');

            Log::info('CompilationShortsProcessor completed successfully', ['project_id' => $this->project->id]);

            return true;
        } catch (\Exception $e) {
            Log::error('CompilationShortsProcessor failed', [
                'project_id' => $this->project->id,
                'error' => $e->getMessage(),
            ]);

            // Keep the downloaded sources: a retry resumes with the download
            // step already complete and cannot fetch them again by itself.
            $this->cleanupTempFiles(true);
            $this->handleFailure('Compilation processing failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * STEP 1: Validate input settings.
     */
    protected function validateInput(): bool
    {
        $errors = $this->validate();

        if (!empty($errors)) {
            throw new \Exception('Validation failed: ' . implode(' | ', $errors));
        }

        return true;
    }

    /**
     * STEP 2: Download all YouTube sources (resume-safe per file).
     */
    protected function downloadSources(): array
    {
        $urls = $this->collectYoutubeUrls();
        $sources = [];

        $projectDir = Storage::disk('public')->path("projects/{$this->project->id}");
        if (!is_dir($projectDir)) {
            mkdir($projectDir, 0755, true);
        }

        foreach ($urls as $index => $url) {
            $destination = $projectDir . "/source_{$index}.mp4";

            if (file_exists($destination) && filesize($destination) > 0) {
                Log::info('Compilation: source already downloaded, skipping', [
                    'project_id' => $this->project->id,
                    'index' => $index,
                ]);
                $sources[] = ['index' => $index, 'path' => $destination, 'title' => 'YouTube Video', 'url' => $url];
                continue;
            }

            $initResult = $this->downloadService->initDownload($url);
            $downloadUrl = $this->downloadService->pollUntilReady($initResult['progress_url']);
            $this->downloadService->streamToStorage($downloadUrl, $destination);

            if (!file_exists($destination) || filesize($destination) === 0) {
                throw new \Exception("Downloaded source video {$index} is missing or empty");
            }

            Log::info('Compilation: source downloaded', [
                'project_id' => $this->project->id,
                'index' => $index,
                'title' => $initResult['title'],
                'size' => filesize($destination),
            ]);

            $sources[] = ['index' => $index, 'path' => $destination, 'title' => $initResult['title'], 'url' => $url];
        }

        return $this->dropDuplicateSources($sources);
    }

    /**
     * Drop sources that turned out to be the same video - a re-upload, or the
     * same video behind two URL forms that survived the URL de-duplication.
     *
     * Two copies of one video are two independent candidate pools, and the
     * "never replay the same footage" spacing rule only ever compares windows
     * within a single source, so the compilation would show the same moments
     * twice without noticing.
     */
    private function dropDuplicateSources(array $sources): array
    {
        $unique = [];
        $fingerprints = [];

        foreach ($sources as $source) {
            $size = (int) @filesize($source['path']);

            $duration = 0.0;
            try {
                $duration = $this->sceneService->getDuration($source['path']);
            } catch (\Exception $e) {
                // No readable duration just means this one cannot be fingerprinted.
            }

            $isDuplicate = false;
            foreach ($fingerprints as $seen) {
                $sizeClose = $size > 0 && $seen['size'] > 0
                    && abs($size - $seen['size']) <= max(4096, (int) ($seen['size'] * 0.005));
                $durationClose = $duration > 0.0 && $seen['duration'] > 0.0
                    && abs($duration - $seen['duration']) <= 0.5;

                if ($sizeClose && $durationClose) {
                    $isDuplicate = true;
                    break;
                }
            }

            if ($isDuplicate) {
                Log::warning('Compilation: two sources are the same video, dropping the duplicate', [
                    'project_id' => $this->project->id,
                    'index' => $source['index'],
                    'url' => $source['url'] ?? null,
                ]);
                @unlink($source['path']);
                continue;
            }

            $fingerprints[] = ['size' => $size, 'duration' => $duration];
            $unique[] = $source;
        }

        if (count($unique) !== count($sources)) {
            $this->logActivity('compilation_duplicate_sources', 'Duplicate source videos were ignored', [
                'given' => count($sources),
                'used' => count($unique),
            ]);
        }

        return $unique;
    }

    /**
     * STEP 3: Scene detection + audio energy analysis per source (free, local).
     */
    protected function analyzeScenes(array $sources): array
    {
        $maxClipSeconds = $this->maxClipSeconds();

        // Give the selector room to work with. It discards every candidate that
        // sits too close to a window it already took, so a thin candidate list
        // is the usual reason a "30 moments" video ships with a dozen.
        $maxCandidates = max(80, $this->targetClipCount() * 5);

        $durations = [];
        $candidates = [];

        foreach ($sources as $source) {
            $index = (int) $source['index'];
            $durations[$index] = $this->sceneService->getDuration($source['path']);
            $candidates[$index] = $this->sceneService->buildCandidateMoments(
                $source['path'],
                $maxClipSeconds,
                2.5,
                $maxCandidates
            );

            if (empty($candidates[$index])) {
                throw new \Exception("No candidate moments found in source video {$index}");
            }
        }

        return ['durations' => $durations, 'candidates' => $candidates];
    }

    /**
     * STEP 4: Transcribe each source locally; tolerate per-source failures.
     */
    protected function transcribeSources(array $sources): array
    {
        $transcripts = [];

        foreach ($sources as $source) {
            $index = (int) $source['index'];

            try {
                $result = $this->transcriptionService->transcribe($source['path']);
                $segments = $result['segments'] ?? [];

                // Persist only what ranking needs to keep processing_state lean
                $transcripts[$index] = array_map(fn ($segment) => [
                    'start' => (float) ($segment['start'] ?? 0),
                    'end' => (float) ($segment['end'] ?? 0),
                    'text' => mb_substr((string) ($segment['text'] ?? ''), 0, 300),
                ], array_values($segments));
            } catch (\Exception $e) {
                Log::warning('Compilation: transcription failed for source, continuing without transcript', [
                    'project_id' => $this->project->id,
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);
                $transcripts[$index] = [];
            }
        }

        return $transcripts;
    }

    /**
     * STEP 5: Rank candidate moments (heuristics + one batched OpenAI call).
     */
    protected function rankMoments(array $analysis, array $transcripts): array
    {
        return $this->rankingService->rankMoments(
            $analysis['candidates'],
            $this->theme(),
            $transcripts,
            $this->targetClipCount()
        );
    }

    /**
     * STEP 6: Select the final clip plan with copyright-mitigation rules.
     */
    protected function selectClips(array $ranked, array $analysis): array
    {
        return $this->rankingService->selectClips(
            $ranked,
            $this->targetClipCount(),
            $this->maxClipSeconds(),
            $analysis['durations'],
            self::MAX_CLIP_STRETCH_SECONDS
        );
    }

    /**
     * STEP 7: Generate one commentary line per clip (one batched OpenAI call).
     */
    protected function generateCommentary(array $clips): array
    {
        $lines = $this->rankingService->generateCommentary(
            $clips,
            $this->theme(),
            (string) ($this->settings['commentary_style'] ?? 'energetic')
        );

        // A short response is not worth failing a whole compilation over - top
        // the list up so every clip still has something to say.
        $orderedClips = array_values($clips);
        $lines = array_slice(array_values($lines), 0, count($orderedClips));

        while (count($lines) < count($orderedClips)) {
            $counter = (int) ($orderedClips[count($lines)]['counter'] ?? (count($lines) + 1));
            $lines[] = "Number {$counter}. Watch this one.";
        }

        return $lines;
    }

    /**
     * STEP 8: One TTS take per commentary line, cached by the LINE plus voice -
     * never by position.
     *
     * A retry reshuffles the clip order, so the old position-keyed cache
     * (tts_7.wav) happily handed clip 7 a voiceover recorded for a completely
     * different moment. The word timings are cached beside the wav as well, so
     * a resumed run still gets real karaoke instead of an even-spread guess.
     */
    protected function generateTTS(array $commentary): array
    {
        $tmpDir = $this->tmpDir();
        $voice = (string) ($this->settings['tts_voice'] ?? 'am_michael');
        $results = [];
        $expected = [];

        foreach (array_values($commentary) as $index => $line) {
            $key = substr(sha1($voice . '|' . $line), 0, 16);
            $audioPath = $tmpDir . "/tts_{$key}.wav";
            $timingPath = $tmpDir . "/tts_{$key}.json";

            $expected[basename($audioPath)] = true;
            $expected[basename($timingPath)] = true;

            if (file_exists($audioPath) && filesize($audioPath) > 0 && file_exists($timingPath)) {
                $decoded = json_decode((string) file_get_contents($timingPath), true);
                $wordTimings = is_array($decoded) ? $decoded : [];
            } else {
                $result = $this->ttsService->generateTTS($line, $voice, $audioPath, true, $this->ttsContext());

                if (empty($result['success']) || !file_exists($audioPath) || filesize($audioPath) === 0) {
                    throw new \Exception("TTS generation failed for clip {$index}: " . ($result['error'] ?? 'no audio produced'));
                }

                $wordTimings = is_array($result['word_timings'] ?? null) ? $result['word_timings'] : [];
                file_put_contents($timingPath, json_encode($wordTimings));
            }

            $results[] = [
                'audio_path' => $audioPath,
                'duration' => $this->sceneService->getDuration($audioPath),
                'word_timings' => $wordTimings,
                'line' => $line,
            ];
        }

        $this->sweepStaleArtifacts($tmpDir, ['tts_*.wav', 'tts_*.json'], $expected);

        return $results;
    }

    /**
     * STEP 9: Render each clip: cut the planned window, scale/crop with the 5%
     * zoom treatment, duck the original audio and mix the commentary on top.
     * Uniform encode so concat is safe.
     *
     * Every artifact is named after its CONTENT, so a resumed or retried run
     * can only ever reuse a file that belongs to the moment it is rendering,
     * and anything left over from a previous attempt is swept away.
     */
    protected function renderClips(array $sources, array $clips, array $ttsResults): array
    {
        [$width, $height] = $this->targetDimensions();
        $tmpDir = $this->tmpDir();
        $audioMode = $this->originalAudioMode();

        $sourcePaths = [];
        $sourceDurations = [];
        foreach ($sources as $source) {
            $index = (int) $source['index'];
            $path = $this->ensureSourceAvailable($sources, $index);
            $sourcePaths[$index] = $path;
            $sourceDurations[$index] = $this->sceneService->getDuration($path);
        }

        $orderedClips = array_values($clips);
        $plan = $this->planClipTimeline($orderedClips, $ttsResults, $sourceDurations);

        $rendered = [];
        $expected = [];

        foreach ($plan as $index => $step) {
            $clipPath = $tmpDir . '/clip_' . $step['key'] . '.mp4';
            $expected[basename($clipPath)] = true;

            if (!file_exists($clipPath) || filesize($clipPath) === 0) {
                $sourcePath = $sourcePaths[$step['source_index']] ?? null;

                if (!$sourcePath) {
                    throw new \Exception("Source video for clip {$index} not found");
                }

                $bedPath = $this->prepareClipBed(
                    $sourcePath,
                    (float) $step['start'],
                    (float) $step['source_seconds'],
                    $audioMode,
                    (string) $step['key']
                );

                $this->renderSingleClip($sourcePath, $clipPath, $step, $width, $height, $bedPath, $audioMode);

                if ($bedPath !== null && file_exists($bedPath)) {
                    @unlink($bedPath);
                }
            }

            $rendered[] = [
                'path' => $clipPath,
                'duration' => $this->sceneService->getDuration($clipPath),
                'planned_duration' => (float) $step['duration'],
                'counter' => (int) ($orderedClips[$index]['counter'] ?? ($index + 1)),
                'voice_offset' => (float) $step['voice_offset'],
                'voice_tempo' => (float) $step['voice_tempo'],
                'voice_seconds' => (float) $step['voice_seconds'],
            ];
        }

        $this->sweepStaleArtifacts($tmpDir, ['clip_*.mp4', 'bed_raw_*.wav', 'bed_bg_*.wav'], $expected);

        // Sources can be large - delete them as soon as every clip is rendered
        foreach ($sourcePaths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        Log::info('Compilation: source videos deleted after clip rendering', ['project_id' => $this->project->id]);

        return $rendered;
    }

    /**
     * Return a usable local path for a source, downloading it again if an
     * earlier failed attempt cleaned it away.
     *
     * A retry resumes with download_videos already marked complete, so its
     * cached paths are handed straight to the renderer - and if the files are
     * gone, every retry used to die on the first clip.
     */
    private function ensureSourceAvailable(array $sources, int $sourceIndex): string
    {
        foreach ($sources as $source) {
            if ((int) $source['index'] !== $sourceIndex) {
                continue;
            }

            $path = (string) $source['path'];

            if (file_exists($path) && filesize($path) > 0) {
                return $path;
            }

            $url = trim((string) ($source['url'] ?? ''));
            if ($url === '') {
                break;
            }

            Log::warning('Compilation: source missing on resume, downloading it again', [
                'project_id' => $this->project->id,
                'index' => $sourceIndex,
            ]);

            $initResult = $this->downloadService->initDownload($url);
            $downloadUrl = $this->downloadService->pollUntilReady($initResult['progress_url']);
            $this->downloadService->streamToStorage($downloadUrl, $path);

            if (file_exists($path) && filesize($path) > 0) {
                return $path;
            }

            break;
        }

        throw new \Exception("Source video {$sourceIndex} is unavailable and could not be downloaded again");
    }

    /**
     * Work out, for every clip, how long it stays on screen, how much source
     * footage backs it, and how its commentary is fitted inside it.
     *
     * The old code stretched a clip by "up to 3 seconds" and then mixed with
     * amix duration=first, so a longer commentary line - or a clip whose
     * original audio ran short - was cut off mid-word as the video jumped to
     * the next moment. Here a clip is never shorter than the line it has to
     * speak: the voice is nudged faster up to MAX_VOICE_TEMPO, and if even that
     * is not enough the clip simply runs on (holding its last frame when the
     * source ran out) rather than cutting the sentence in half.
     */
    private function planClipTimeline(array $clips, array $ttsResults, array $sourceDurations): array
    {
        [$width, $height] = $this->targetDimensions();
        $audioMode = $this->originalAudioMode();
        $plan = [];

        foreach (array_values($clips) as $index => $clip) {
            $sourceIndex = (int) $clip['source_index'];
            $start = max(0.0, (float) $clip['start']);
            $windowSeconds = max(0.5, (float) $clip['end'] - $start);

            $voicePath = $ttsResults[$index]['audio_path'] ?? null;
            $voiceRaw = (float) ($ttsResults[$index]['duration'] ?? 0.0);
            $hasVoice = is_string($voicePath) && $voicePath !== '' && file_exists($voicePath) && $voiceRaw > 0.01;

            $tempo = 1.0;
            $duration = $windowSeconds;

            if ($hasVoice) {
                $ceiling = $windowSeconds + self::MAX_CLIP_STRETCH_SECONDS;
                $needed = self::VOICE_LEAD_IN_SECONDS + $voiceRaw + self::VOICE_TAIL_SECONDS;

                if ($needed > $ceiling) {
                    $speakRoom = max(0.5, $ceiling - self::VOICE_LEAD_IN_SECONDS - self::VOICE_TAIL_SECONDS);
                    $tempo = min(self::MAX_VOICE_TEMPO, max(1.0, $voiceRaw / $speakRoom));
                    $needed = self::VOICE_LEAD_IN_SECONDS + ($voiceRaw / $tempo) + self::VOICE_TAIL_SECONDS;
                }

                $duration = max($windowSeconds, $needed);
            }

            $duration = round($duration, 3);

            // Only ever extend FORWARD from the window start: that is the
            // direction the selector's spacing rule budgeted for, so a
            // stretched clip can never run into the next pick from the same
            // source and replay footage the viewer has already seen.
            $sourceDuration = (float) ($sourceDurations[$sourceIndex] ?? 0.0);
            $sourceSeconds = $sourceDuration > 0.0
                ? max(0.5, min($duration, $sourceDuration - $start))
                : $duration;
            $sourceSeconds = round($sourceSeconds, 3);

            $plan[] = [
                'source_index' => $sourceIndex,
                'start' => round($start, 3),
                'duration' => $duration,
                'source_seconds' => $sourceSeconds,
                'freeze_seconds' => round(max(0.0, $duration - $sourceSeconds), 3),
                'voice_path' => $hasVoice ? $voicePath : null,
                'voice_tempo' => round($tempo, 4),
                'voice_offset' => $hasVoice ? self::VOICE_LEAD_IN_SECONDS : 0.0,
                'voice_seconds' => $hasVoice ? round($voiceRaw / $tempo, 3) : 0.0,
                'key' => substr(sha1(implode('|', [
                    $sourceIndex,
                    round($start, 2),
                    $duration,
                    $sourceSeconds,
                    $hasVoice ? basename((string) $voicePath) : 'novoice',
                    round($tempo, 3),
                    $audioMode,
                    $width . 'x' . $height,
                ])), 0, 16),
            ];
        }

        Log::info('Compilation: clip timeline planned', [
            'project_id' => $this->project->id,
            'clips' => count($plan),
            'stretched_for_voiceover' => count(array_filter($plan, fn ($step) => $step['voice_tempo'] > 1.001)),
            'total_seconds' => round(array_sum(array_column($plan, 'duration')), 2),
        ]);

        return $plan;
    }

    /**
     * Build the per-clip background "bed" audio derived from the original clip,
     * according to the chosen original_audio mode.
     *
     *  - mute:            returns null (no original audio at all)
     *  - keep:            the original clip audio, untouched (ducked at mix time)
     *  - background_only: the original audio with the SPOKEN COMMENTARY stripped
     *                     out (Demucs) so it never fights the AI voiceover —
     *                     music / crowd / SFX / ambience are kept. Falls back to
     *                     an FFmpeg center-channel vocal reducer if Demucs is
     *                     unavailable.
     *
     * Returns the absolute wav path, or null when there is no usable audio.
     */
    private function prepareClipBed(string $sourcePath, float $start, float $duration, string $mode, string $index): ?string
    {
        if ($mode === 'mute') {
            return null;
        }

        $tmpDir = $this->tmpDir();
        $rawPath = $tmpDir . "/bed_raw_{$index}.wav";

        // Extract the clip's original audio (stereo, 44.1k). No audio stream → no bed.
        $extract = new Process([
            'ffmpeg', '-y',
            '-ss', $this->ffNumber($start), '-t', $this->ffNumber($duration), '-i', $sourcePath,
            '-vn', '-ac', '2', '-ar', '44100',
            $rawPath,
        ]);
        $extract->setTimeout(600);
        $extract->run();

        if (!$extract->isSuccessful() || !file_exists($rawPath) || filesize($rawPath) === 0) {
            Log::info('Compilation: clip has no usable original audio, bed skipped', [
                'project_id' => $this->project->id,
                'clip' => $index,
            ]);
            @unlink($rawPath);
            return null;
        }

        if ($mode === 'keep') {
            return $rawPath;
        }

        // background_only — strip the original voice, keep music/SFX/ambience.
        $bgPath = $tmpDir . "/bed_bg_{$index}.wav";

        $separation = $this->pythonService->separateVocals($rawPath, $bgPath);
        if (!empty($separation['success']) && file_exists($bgPath) && filesize($bgPath) > 0) {
            @unlink($rawPath);
            return $bgPath;
        }

        Log::warning('Compilation: Demucs separation unavailable, using FFmpeg vocal reducer', [
            'project_id' => $this->project->id,
            'clip' => $index,
            'error' => $separation['error'] ?? 'unknown',
        ]);

        // Fallback: center-channel cancellation removes center-panned vocals on
        // stereo sources; on mono sources it degrades (little background left).
        $reduced = new Process([
            'ffmpeg', '-y',
            '-i', $rawPath,
            '-af', 'pan=stereo|c0=c0-c1|c1=c1-c0',
            $bgPath,
        ]);
        $reduced->setTimeout(300);
        $reduced->run();

        @unlink($rawPath);

        if ($reduced->isSuccessful() && file_exists($bgPath) && filesize($bgPath) > 0) {
            return $bgPath;
        }

        @unlink($bgPath);
        return null;
    }

    /**
     * Render one clip with ffmpeg: cut the planned window, scale/crop with a 5%
     * zoom treatment, hold the last frame when the source ran out, and build
     * the audio from an optional background bed and optional commentary.
     *
     * Both streams are pinned to exactly the planned duration, so a clip can
     * never end while its commentary is still talking, the concat boundary
     * never lands on a missing video frame, and the caption/counter offsets
     * computed from these files stay true across the whole compilation.
     */
    private function renderSingleClip(
        string $sourcePath,
        string $clipPath,
        array $step,
        int $width,
        int $height,
        ?string $bedPath,
        string $audioMode
    ): void {
        $start = (float) $step['start'];
        $duration = (float) $step['duration'];
        $sourceSeconds = (float) $step['source_seconds'];
        $freeze = (float) $step['freeze_seconds'];
        $voicePath = $step['voice_path'] ?? null;
        $tempo = (float) ($step['voice_tempo'] ?? 1.0);

        $videoChain = "[0:v]scale={$width}:{$height}:force_original_aspect_ratio=increase,"
            . "crop={$width}:{$height},scale=trunc(iw*1.05/2)*2:trunc(ih*1.05/2)*2,"
            . "crop={$width}:{$height},setsar=1,fps=30";

        if ($freeze > 0.01) {
            // The clip runs past the end of the source - hold the last frame
            // instead of ending early on top of the commentary.
            $videoChain .= ',tpad=stop_mode=clone:stop_duration=' . $this->ffNumber($freeze + 0.5);
        }

        $videoChain .= ',trim=duration=' . $this->ffNumber($duration) . ',setpts=PTS-STARTPTS[v]';

        $hasBed = is_string($bedPath) && $bedPath !== '' && file_exists($bedPath);
        $hasVoice = is_string($voicePath) && $voicePath !== '' && file_exists($voicePath);

        // Input 0 is always the source video.
        $inputs = ['-ss', $this->ffNumber($start), '-t', $this->ffNumber($sourceSeconds), '-i', $sourcePath];
        $nextIdx = 1;

        $bedIdx = null;
        if ($hasBed) {
            $inputs[] = '-i';
            $inputs[] = $bedPath;
            $bedIdx = $nextIdx++;
        }

        $voiceIdx = null;
        if ($hasVoice) {
            $inputs[] = '-i';
            $inputs[] = $voicePath;
            $voiceIdx = $nextIdx++;
        }

        // When commentary plays, the bed sits underneath: keep-mode ducks the
        // full original hard (it still has voices); background_only can sit
        // louder since voices are gone. With no commentary the bed plays full.
        $bedVol = $hasVoice ? ($audioMode === 'keep' ? 0.25 : 0.55) : 1.0;

        $filters = [$videoChain];

        // Every audio branch is padded and trimmed to the exact clip length, so
        // a bed that runs out (or a voice that does not) can no longer decide
        // when the mix stops - that is what used to cut commentary off.
        if ($bedIdx !== null) {
            $filters[] = "[{$bedIdx}:a]aresample=44100,volume={$bedVol},apad,atrim=duration="
                . $this->ffNumber($duration) . ',asetpts=PTS-STARTPTS[bed]';
        }

        if ($voiceIdx !== null) {
            $voiceChain = "[{$voiceIdx}:a]aresample=44100";

            if ($tempo > 1.001) {
                $voiceChain .= ',atempo=' . $this->ffNumber($tempo);
            }

            $delayMs = (int) round(((float) ($step['voice_offset'] ?? 0.0)) * 1000);
            if ($delayMs > 0) {
                $voiceChain .= ",adelay={$delayMs}|{$delayMs}";
            }

            $voiceChain .= ',apad,atrim=duration=' . $this->ffNumber($duration) . ',asetpts=PTS-STARTPTS[vo]';
            $filters[] = $voiceChain;
        }

        if ($bedIdx !== null && $voiceIdx !== null) {
            $filters[] = '[bed][vo]amix=inputs=2:duration=longest:dropout_transition=0:normalize=0[mixed]';
            $mixLabel = '[mixed]';
        } elseif ($bedIdx !== null) {
            $mixLabel = '[bed]';
        } elseif ($voiceIdx !== null) {
            $mixLabel = '[vo]';
        } else {
            // No audio at all - synthesize silence so concat stays uniform.
            $inputs[] = '-f';
            $inputs[] = 'lavfi';
            $inputs[] = '-t';
            $inputs[] = $this->ffNumber($duration);
            $inputs[] = '-i';
            $inputs[] = 'anullsrc=channel_layout=stereo:sample_rate=44100';
            $mixLabel = "[{$nextIdx}:a]";
            $nextIdx++;
        }

        // Tiny fades: without them every hard cut clicks.
        $filters[] = $mixLabel . 'afade=t=in:st=0:d=0.04,afade=t=out:st='
            . $this->ffNumber(max(0.0, $duration - 0.12)) . ':d=0.12[a]';

        $cmd = array_merge(
            ['ffmpeg', '-y'],
            $inputs,
            [
                '-filter_complex', implode(';', $filters),
                '-map', '[v]', '-map', '[a]',
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '21',
                '-c:a', 'aac', '-b:a', '128k', '-ar', '44100', '-ac', '2',
                '-pix_fmt', 'yuv420p', '-movflags', '+faststart',
                '-t', $this->ffNumber($duration),
                $clipPath,
            ]
        );

        $process = new Process($cmd);
        $process->setTimeout(1800);
        $process->run();

        if ($process->isSuccessful() && file_exists($clipPath) && filesize($clipPath) > 0) {
            return;
        }

        Log::warning('Compilation: clip render failed, retrying with silent audio', [
            'project_id' => $this->project->id,
            'clip' => basename($clipPath),
            'stderr_tail' => substr($process->getErrorOutput(), -500),
        ]);

        $fallbackCmd = [
            'ffmpeg', '-y',
            '-ss', $this->ffNumber($start), '-t', $this->ffNumber($sourceSeconds), '-i', $sourcePath,
            '-f', 'lavfi', '-t', $this->ffNumber($duration), '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100',
            '-filter_complex', $videoChain,
            '-map', '[v]', '-map', '1:a',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '21',
            '-c:a', 'aac', '-b:a', '128k', '-ar', '44100', '-ac', '2',
            '-pix_fmt', 'yuv420p', '-movflags', '+faststart',
            '-t', $this->ffNumber($duration),
            $clipPath,
        ];

        $fallback = new Process($fallbackCmd);
        $fallback->setTimeout(1800);
        $fallback->run();

        if (!$fallback->isSuccessful() || !file_exists($clipPath) || filesize($clipPath) === 0) {
            throw new \Exception('Clip rendering failed: ' . substr($fallback->getErrorOutput() ?: $fallback->getOutput(), -800));
        }
    }

    /**
     * Format a number for an ffmpeg argument: fixed notation, a dot separator
     * whatever the locale, and no trailing zeroes.
     */
    private function ffNumber(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return ($formatted === '' || $formatted === '-') ? '0' : $formatted;
    }

    /**
     * Delete files in $dir matching $patterns that the current plan does not
     * expect.
     *
     * A failed run leaves rendered clips and voiceovers behind. They used to be
     * named by position (clip_7.mp4), and a retry reshuffles the order, so
     * clip 7's stale file was reused for a completely different moment - which
     * is how the same footage ended up in the compilation twice.
     *
     * @param array $patterns glob patterns relative to $dir
     * @param array $keep     [basename => true] of files the plan still needs
     */
    private function sweepStaleArtifacts(string $dir, array $patterns, array $keep): void
    {
        $removed = 0;

        foreach ($patterns as $pattern) {
            foreach (glob($dir . '/' . $pattern) ?: [] as $file) {
                if (!is_file($file) || isset($keep[basename($file)])) {
                    continue;
                }

                @unlink($file);
                $removed++;
            }
        }

        if ($removed > 0) {
            Log::info('Compilation: swept artifacts left by an earlier attempt', [
                'project_id' => $this->project->id,
                'removed' => $removed,
            ]);
        }
    }

    /**
     * STEP 10: Title card + concat + karaoke captions + countdown overlay.
     */
    protected function assembleVideo(array $rendered, array $clips, array $ttsResults, array $commentary): string
    {
        [$width, $height] = $this->targetDimensions();
        $tmpDir = $this->tmpDir();

        // 1) Title card (dark background; the title text is drawn by the overlay ASS,
        //    libass is proven in this codebase while drawtext fonts are not)
        $titlePath = $tmpDir . '/title.mp4';
        $this->renderTitleCard($titlePath, $width, $height);

        // 2) Concat: title card + clips, re-encoded uniformly
        $concatList = $tmpDir . '/concat.txt';
        $listContent = "file '" . str_replace("'", "'\\''", $titlePath) . "'\n";
        foreach ($rendered as $clip) {
            $listContent .= "file '" . str_replace("'", "'\\''", $clip['path']) . "'\n";
        }
        file_put_contents($concatList, $listContent);

        $assembledPath = $tmpDir . '/assembled.mp4';
        $concat = new Process([
            'ffmpeg', '-y',
            '-f', 'concat', '-safe', '0', '-i', $concatList,
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
            '-c:a', 'aac', '-b:a', '128k', '-ar', '44100', '-ac', '2',
            '-pix_fmt', 'yuv420p', '-movflags', '+faststart',
            $assembledPath,
        ]);
        $concat->setTimeout(3600);
        $concat->run();

        if (!$concat->isSuccessful() || !file_exists($assembledPath) || filesize($assembledPath) === 0) {
            throw new \Exception('Concat failed: ' . substr($concat->getErrorOutput(), -800));
        }

        // 3) Per-clip offsets in the final timeline. The concat demuxer shifts
        //    each file by its container duration, so reading the rendered files
        //    back is what makes the counter badges and captions land on the
        //    clip they belong to.
        $offsets = [];
        $cursor = self::TITLE_CARD_SECONDS;
        $worstDrift = 0.0;

        foreach (array_values($rendered) as $index => $clip) {
            $offsets[$index] = $cursor;
            $actual = (float) $clip['duration'];
            $worstDrift = max($worstDrift, abs($actual - (float) ($clip['planned_duration'] ?? $actual)));
            $cursor += $actual;
        }

        Log::info('Compilation: timeline assembled', [
            'project_id' => $this->project->id,
            'clips' => count($rendered),
            'total_seconds' => round($cursor, 2),
            'worst_clip_drift_seconds' => round($worstDrift, 3),
        ]);

        // 4) Karaoke captions from the commentary word timings, offset into the
        //    timeline. Only when the user wants captions AND there is
        //    commentary to caption.
        $captionFilters = [];
        $wordTimings = ($this->showCaptions() && !empty($ttsResults))
            ? $this->buildGlobalWordTimings($rendered, $ttsResults, $commentary, $offsets)
            : [];

        if (!empty($wordTimings)) {
            $captionsRelative = "projects/{$this->project->id}/tmp/captions.ass";
            $this->captionService->generateKaraokeCaptions(
                $wordTimings,
                $captionsRelative,
                (string) ($this->settings['caption_template'] ?? 'modern_karaoke')
            );
            $captionsAbs = Storage::disk('public')->path($captionsRelative);

            if (!file_exists($captionsAbs) || filesize($captionsAbs) === 0) {
                throw new \Exception('Caption file was not generated');
            }

            $escapedCaptions = str_replace(["\\", "'"], ['/', "\\'"], $captionsAbs);
            $captionFilters[] = "ass='{$escapedCaptions}'";
        } else {
            Log::info('Compilation: captions skipped', [
                'project_id' => $this->project->id,
                'show_captions' => $this->showCaptions(),
                'has_voiceover' => !empty($ttsResults),
            ]);
        }

        // 5) Self-generated overlay ASS: big title text + countdown badges
        $overlayAbs = $this->buildOverlayAss($rendered, $offsets, $width, $height);
        $escapedOverlay = str_replace(["\\", "'"], ['/', "\\'"], $overlayAbs);
        $captionFilters[] = "ass='{$escapedOverlay}'";

        // 6) Burn the subtitle track(s) in one pass
        $outputRelative = "projects/{$this->project->id}/output.mp4";
        $outputAbs = Storage::disk('public')->path($outputRelative);

        $burn = new Process([
            'ffmpeg', '-y',
            '-i', $assembledPath,
            '-vf', implode(',', $captionFilters),
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '21',
            '-c:a', 'copy',
            $outputAbs,
        ]);
        $burn->setTimeout(3600);
        $burn->run();

        if (!$burn->isSuccessful() || !file_exists($outputAbs) || filesize($outputAbs) === 0) {
            throw new \Exception('Overlay burn failed: ' . substr($burn->getErrorOutput(), -800));
        }

        // Optional Pixabay music bed under clips + commentary (default: none).
        $this->applyBackgroundMusic($outputAbs);

        $this->project->update(['output_path' => $outputRelative]);

        $this->logActivity('compilation_assembled', 'Compilation assembled successfully', [
            'clip_count' => count($rendered),
            'total_duration' => $cursor,
            'output_path' => $outputRelative,
        ]);

        return $outputAbs;
    }

    /**
     * Render the dark title card with silent audio, uniform encode.
     */
    private function renderTitleCard(string $titlePath, int $width, int $height): void
    {
        $duration = (string) self::TITLE_CARD_SECONDS;

        $process = new Process([
            'ffmpeg', '-y',
            '-f', 'lavfi', '-i', "color=c=0x101014:s={$width}x{$height}:d={$duration}:r=30",
            '-f', 'lavfi', '-t', $duration, '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100',
            '-map', '0:v', '-map', '1:a',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
            '-c:a', 'aac', '-b:a', '128k', '-ar', '44100', '-ac', '2',
            '-pix_fmt', 'yuv420p', '-movflags', '+faststart',
            $titlePath,
        ]);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful() || !file_exists($titlePath) || filesize($titlePath) === 0) {
            throw new \Exception('Title card rendering failed: ' . substr($process->getErrorOutput(), -500));
        }
    }

    /**
     * Build word timings for the whole timeline from per-clip TTS timings.
     * Falls back to even word distribution when Kokoro timings are missing.
     */
    private function buildGlobalWordTimings(array $rendered, array $ttsResults, array $commentary, array $offsets): array
    {
        $global = [];

        foreach (array_values($rendered) as $index => $clip) {
            $offset = (float) ($offsets[$index] ?? 0.0);
            $clipDuration = (float) $clip['duration'];
            $lead = (float) ($clip['voice_offset'] ?? 0.0);
            $tempo = max(0.1, (float) ($clip['voice_tempo'] ?? 1.0));
            $speakSeconds = (float) ($clip['voice_seconds'] ?? 0.0);

            // Kokoro times the line as recorded. The renderer may have delayed
            // it past the cut and sped it up to make it fit, so undo both here
            // or the highlight drifts further behind with every clip.
            $words = [];
            foreach (($ttsResults[$index]['word_timings'] ?? []) as $timing) {
                $word = trim((string) ($timing['word'] ?? ''));
                if ($word === '') {
                    continue;
                }

                $words[] = [
                    'word' => $word,
                    'start' => $lead + ((float) ($timing['start'] ?? 0)) / $tempo,
                    'end' => $lead + ((float) ($timing['end'] ?? 0)) / $tempo,
                ];
            }

            if (empty($words)) {
                $tokens = preg_split('/\s+/', trim((string) ($commentary[$index] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $span = $speakSeconds > 0.0 ? $speakSeconds : max(0.0, $clipDuration - $lead);
                $perWord = count($tokens) > 0 ? $span / count($tokens) : 0.0;

                foreach ($tokens as $tokenIndex => $token) {
                    $words[] = [
                        'word' => $token,
                        'start' => $lead + ($tokenIndex * $perWord),
                        'end' => $lead + (($tokenIndex + 1) * $perWord),
                    ];
                }
            }

            $clipEnd = $offset + $clipDuration;

            foreach ($words as $word) {
                $start = $offset + $word['start'];

                // A word that would only appear after the cut belongs to
                // nothing on screen - drop it rather than stamping a
                // zero-length caption onto the next clip.
                if ($start >= $clipEnd - 0.02) {
                    continue;
                }

                $end = min($offset + $word['end'], $clipEnd);
                if ($end - $start < 0.08) {
                    $end = min($start + 0.08, $clipEnd);
                }

                $global[] = [
                    'word' => $word['word'],
                    'start' => round($start, 3),
                    'end' => round($end, 3),
                    // Keeps a caption line from spanning two clips.
                    'group' => $index,
                ];
            }
        }

        return $global;
    }

    /**
     * Build the overlay ASS file: theme title during the title card and a
     * gold countdown badge (#N) in the top-right corner of every clip.
     */
    private function buildOverlayAss(array $rendered, array $offsets, int $width, int $height): string
    {
        $showCounter = ($this->settings['show_counter'] ?? 'yes') !== 'no';
        $titleText = $this->sanitizeAssText(
            mb_strtoupper(count($rendered) . ' ' . $this->theme())
        );

        $ass = "[Script Info]\n"
            . "ScriptType: v4.00+\n"
            . "PlayResX: {$width}\n"
            . "PlayResY: {$height}\n"
            . "WrapStyle: 0\n"
            . "ScaledBorderAndShadow: yes\n\n"
            . "[V4+ Styles]\n"
            . "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n"
            . "Style: Title,Arial," . (int) round($height * 0.07) . ",&H00FFFFFF,&H00FFFFFF,&H00000000,&H80000000,-1,0,0,0,100,100,0,0,1,4,2,5,40,40,40,1\n"
            . "Style: Counter,Arial," . (int) round($height * 0.045) . ",&H0000D7FF,&H0000D7FF,&H00000000,&H80000000,-1,0,0,0,100,100,0,0,1,3,1,9,30,30,30,1\n\n"
            . "[Events]\n"
            . "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";

        $ass .= 'Dialogue: 0,' . $this->assTime(0.0) . ',' . $this->assTime(self::TITLE_CARD_SECONDS)
            . ',Title,,0,0,0,,{\\fad(200,200)}' . $titleText . "\n";

        if ($showCounter) {
            foreach (array_values($rendered) as $index => $clip) {
                $start = $offsets[$index];
                $end = $start + (float) $clip['duration'];

                $ass .= 'Dialogue: 0,' . $this->assTime($start) . ',' . $this->assTime($end)
                    . ',Counter,,0,0,0,,{\\fad(120,0)}#' . (int) $clip['counter'] . "\n";
            }
        }

        $overlayAbs = $this->tmpDir() . '/overlay.ass';
        file_put_contents($overlayAbs, $ass);

        return $overlayAbs;
    }

    private function sanitizeAssText(string $text): string
    {
        return trim(str_replace(['{', '}', '\\', "\n", "\r"], ' ', $text));
    }

    private function assTime(float $seconds): string
    {
        $seconds = max(0.0, $seconds);
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(fmod($seconds, 3600) / 60);
        $secs = (int) floor(fmod($seconds, 60));
        $centis = (int) floor(($seconds - floor($seconds)) * 100);

        return sprintf('%d:%02d:%02d.%02d', $hours, $minutes, $secs, $centis);
    }

    /**
     * STEP 11: Thumbnail via the Python sidecar (with parent fallback).
     */
    protected function generateThumbnail(string $time = '00:00:01', ?string $videoPath = null): bool
    {
        try {
            $video = $videoPath ?? Storage::disk('public')->path("projects/{$this->project->id}/output.mp4");

            if (!file_exists($video)) {
                Log::warning('Compilation: thumbnail skipped - video not found', ['video_path' => $video]);
                return false;
            }

            $seconds = 2.0;
            if ($time && preg_match('/(\d+):(\d+):(\d+)/', $time, $matches)) {
                $seconds = (int) $matches[1] * 3600 + (int) $matches[2] * 60 + (int) $matches[3];
            }

            [$width, $height] = $this->targetDimensions();
            $thumbnailPath = Storage::disk('public')->path("projects/{$this->project->id}/thumbnail.jpg");

            $response = $this->pythonService->makeRequest('POST', '/generate-thumbnail', [
                'video_path' => $video,
                'time_seconds' => $seconds,
                'output_path' => $thumbnailPath,
                'width' => $width,
                'height' => $height,
                'project_id' => $this->project->id,
            ]);

            if (!empty($response['success'])) {
                $this->project->update(['thumbnail_path' => "projects/{$this->project->id}/thumbnail.jpg"]);
                return true;
            }

            // Fall back to the FFMpeg-based generator from the abstract class
            return parent::generateThumbnail($time, $video);
        } catch (\Exception $e) {
            Log::warning('Compilation: thumbnail generation failed (non-fatal)', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * STEP 12: Upload the final compilation to R2.
     */
    protected function uploadToR2(string $videoPath): bool
    {
        $key = "shorts/yt_compilation_{$this->project->id}_" . time() . '.mp4';

        $result = $this->r2UploadService->upload($videoPath, $key, 'video/mp4');

        if (!empty($result['success'])) {
            $this->project->update([
                'r2_url' => $result['url'],
                'r2_key' => $key,
            ]);

            return true;
        }

        throw new \Exception('R2 upload returned success=false');
    }

    /**
     * STEP 13: Aggressive cleanup of tmp clips, audio and leftover sources.
     *
     * @param bool $keepSources leave the downloaded source videos in place
     *                          (used on failure, so a retry can resume)
     */
    protected function cleanupTempFiles(bool $keepSources = false): bool
    {
        try {
            $projectDir = Storage::disk('public')->path("projects/{$this->project->id}");
            $tmpDir = $projectDir . '/tmp';

            if (is_dir($tmpDir)) {
                foreach (glob($tmpDir . '/*') ?: [] as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
                @rmdir($tmpDir);
            }

            if (!$keepSources) {
                foreach (glob($projectDir . '/source_*.mp4') ?: [] as $file) {
                    @unlink($file);
                }
            }

            Log::info('Compilation: temp files cleaned up', ['project_id' => $this->project->id]);

            return true;
        } catch (\Exception $e) {
            Log::warning('Compilation: temp cleanup failed (non-fatal)', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // -------------------------------------------------------------------
    // Settings helpers
    // -------------------------------------------------------------------

    private function collectYoutubeUrls(): array
    {
        $urls = [];
        $seen = [];

        foreach (['youtube_url_1', 'youtube_url_2', 'youtube_url_3'] as $field) {
            $url = trim((string) ($this->settings[$field] ?? ''));
            if ($url === '') {
                continue;
            }

            // The same video pasted twice (or in two URL forms) used to be
            // downloaded twice and treated as two independent sources, so every
            // moment it contributed could appear twice in the compilation.
            $key = preg_match('/(?:v=|youtu\.be\/|shorts\/|embed\/)([\w-]{11})/', $url, $match)
                ? $match[1]
                : mb_strtolower($url);

            if (isset($seen[$key])) {
                Log::info('Compilation: duplicate source URL ignored', [
                    'project_id' => $this->project->id,
                    'field' => $field,
                ]);
                continue;
            }

            $seen[$key] = true;
            $urls[] = $url;
        }

        return array_values($urls);
    }

    private function theme(): string
    {
        return trim((string) ($this->settings['theme'] ?? 'viral moments'));
    }

    private function targetClipCount(): int
    {
        $count = (int) ($this->settings['target_clip_count'] ?? 30);

        return max(10, min(50, $count));
    }

    private function maxClipSeconds(): float
    {
        $seconds = (float) ($this->settings['max_clip_seconds'] ?? 6);

        return max(3.0, min(8.0, $seconds));
    }

    private function targetDimensions(): array
    {
        $aspect = (string) ($this->settings['aspect_ratio'] ?? '16:9');

        return $aspect === '9:16' ? [1080, 1920] : [1920, 1080];
    }

    private function tmpDir(): string
    {
        $tmpDir = Storage::disk('public')->path("projects/{$this->project->id}/tmp");
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        return $tmpDir;
    }

    /**
     * Whether the AI commentary voiceover (and its captions) should be produced.
     */
    private function commentaryEnabled(): bool
    {
        return (string) ($this->settings['commentary'] ?? 'on') !== 'off';
    }

    /**
     * How the original source audio is treated under the commentary:
     *  - background_only: strip the original voices, keep music/SFX (default)
     *  - keep:            keep the original audio (ducked under commentary)
     *  - mute:            drop the original audio entirely
     */
    private function originalAudioMode(): string
    {
        $mode = (string) ($this->settings['original_audio'] ?? 'background_only');

        return in_array($mode, ['background_only', 'keep', 'mute'], true) ? $mode : 'background_only';
    }

    /**
     * Whether karaoke captions are burned in. Captions need commentary timings,
     * so they are implicitly off when commentary is disabled.
     */
    private function showCaptions(): bool
    {
        if (!$this->commentaryEnabled()) {
            return false;
        }

        return (string) ($this->settings['show_captions'] ?? 'yes') !== 'no';
    }

    // -------------------------------------------------------------------
    // Template contract
    // -------------------------------------------------------------------

    public function validate(): array
    {
        $errors = [];

        $urls = $this->collectYoutubeUrls();
        if (empty($urls)) {
            $errors[] = 'At least one YouTube URL is required';
        }

        foreach ($urls as $url) {
            if (!preg_match('/(?:v=|youtu\.be\/|shorts\/)([\w-]{11})/', $url)) {
                $errors[] = "Invalid YouTube URL: {$url}";
            }
        }

        $theme = trim((string) ($this->settings['theme'] ?? ''));
        if ($theme === '') {
            $errors[] = 'Compilation theme is required';
        } elseif (mb_strlen($theme) > 100) {
            $errors[] = 'Compilation theme may not exceed 100 characters';
        }

        if (isset($this->settings['target_clip_count']) && !in_array((string) $this->settings['target_clip_count'], ['20', '30', '40', '50'], true)) {
            $errors[] = 'Invalid number of moments selected';
        }

        if (isset($this->settings['max_clip_seconds']) && !in_array((string) $this->settings['max_clip_seconds'], ['4', '5', '6', '8'], true)) {
            $errors[] = 'Invalid max clip length selected';
        }

        if (isset($this->settings['aspect_ratio']) && !in_array($this->settings['aspect_ratio'], ['16:9', '9:16'], true)) {
            $errors[] = 'Invalid aspect ratio selected';
        }

        if (isset($this->settings['tts_voice']) && !in_array($this->settings['tts_voice'], \Modules\Project\Support\TtsVoices::allIds(), true)) {
            $errors[] = 'Invalid TTS voice selected';
        }

        if (isset($this->settings['caption_template']) && !in_array($this->settings['caption_template'], ['single_word_pop', 'modern_karaoke', 'classic_block', 'minimal_clean'], true)) {
            $errors[] = 'Invalid caption template selected';
        }

        if (isset($this->settings['commentary_style']) && !in_array($this->settings['commentary_style'], ['energetic', 'sarcastic', 'dramatic', 'minimal'], true)) {
            $errors[] = 'Invalid commentary style selected';
        }

        if (isset($this->settings['show_counter']) && !in_array($this->settings['show_counter'], ['yes', 'no'], true)) {
            $errors[] = 'Invalid countdown counter option';
        }

        if (isset($this->settings['commentary']) && !in_array($this->settings['commentary'], ['on', 'off'], true)) {
            $errors[] = 'Invalid commentary voiceover option';
        }

        if (isset($this->settings['original_audio']) && !in_array($this->settings['original_audio'], ['background_only', 'keep', 'mute'], true)) {
            $errors[] = 'Invalid original audio option';
        }

        if (isset($this->settings['show_captions']) && !in_array($this->settings['show_captions'], ['yes', 'no'], true)) {
            $errors[] = 'Invalid caption visibility option';
        }

        return $errors;
    }

    protected function getTemplateConfig(): array
    {
        return self::getStaticTemplateConfig();
    }

    protected function getProcessingSteps(): array
    {
        return [
            'validate_input' => 4,
            'download_videos' => 18,
            'analyze_scenes' => 10,
            'transcribe_sources' => 13,
            'rank_moments' => 7,
            'select_clips' => 3,
            'generate_commentary' => 5,
            'generate_tts' => 8,
            'render_clips' => 12,
            'assemble_video' => 8,
            'generate_thumbnail' => 2,
            'upload_to_r2' => 6,
            'cleanup' => 2,
        ];
    }

    public static function getStaticTemplateConfig(): array
    {
        return [
            'name' => 'Moments Compilation',
            'description' => 'Turn 2-3 YouTube videos into one themed compilation with AI commentary, countdown overlays and captions.',
            'requires_upload' => false,
            'min_duration' => 60,
            'max_duration' => 7200,
            'max_file_size_mb' => 1024,
            'supported_formats' => ['mp4'],
            'output_formats' => ['mp4'],
            'settings_schema' => [
                'youtube_url_1' => [
                    'type' => 'text',
                    'label' => 'YouTube URL #1',
                    'placeholder' => 'https://www.youtube.com/watch?v=...',
                    'required' => true,
                ],
                'youtube_url_2' => [
                    'type' => 'text',
                    'label' => 'YouTube URL #2 (optional)',
                    'placeholder' => 'https://www.youtube.com/watch?v=...',
                    'required' => false,
                ],
                'youtube_url_3' => [
                    'type' => 'text',
                    'label' => 'YouTube URL #3 (optional)',
                    'placeholder' => 'https://www.youtube.com/watch?v=...',
                    'required' => false,
                ],
                'theme' => [
                    'type' => 'text',
                    'label' => 'Compilation Theme',
                    'placeholder' => 'disaster moments, funny fails, satisfying saves...',
                    'required' => true,
                ],
                'target_clip_count' => [
                    'type' => 'select',
                    'label' => 'Number of Moments',
                    'options' => [
                        '20' => '20 moments',
                        '30' => '30 moments',
                        '40' => '40 moments',
                        '50' => '50 moments',
                    ],
                    'default' => '30',
                ],
                'max_clip_seconds' => [
                    'type' => 'select',
                    'label' => 'Max Clip Length',
                    'options' => [
                        '4' => '4 seconds',
                        '5' => '5 seconds',
                        '6' => '6 seconds',
                        '8' => '8 seconds',
                    ],
                    'default' => '6',
                ],
                'aspect_ratio' => [
                    'type' => 'select',
                    'label' => 'Aspect Ratio',
                    'options' => [
                        '16:9' => '16:9 (YouTube)',
                        '9:16' => '9:16 (Shorts / Reels)',
                    ],
                    'default' => '16:9',
                ],
                'commentary' => [
                    'type' => 'select',
                    'label' => 'AI Commentary Voiceover',
                    'options' => [
                        'on' => 'On (AI narrates each moment)',
                        'off' => 'Off (no voiceover)',
                    ],
                    'default' => 'on',
                ],
                'original_audio' => [
                    'type' => 'select',
                    'label' => 'Original Clip Audio',
                    'options' => [
                        'background_only' => 'Background only (remove voices, keep music/SFX)',
                        'keep' => 'Keep original (ducked under commentary)',
                        'mute' => 'Mute original (commentary only)',
                    ],
                    'default' => 'background_only',
                ],
                'tts_voice' => [
                    'type' => 'select',
                    'label' => 'Commentary Voice',
                    'options' => [
                        'am_michael' => 'Michael (US Male)',
                        'af_sarah' => 'Sarah (US Female)',
                        'bm_george' => 'George (UK Male)',
                        'bf_emma' => 'Emma (UK Female)',
                    ],
                    'default' => 'am_michael',
                ],
                'commentary_style' => [
                    'type' => 'select',
                    'label' => 'Commentary Style',
                    'options' => [
                        'energetic' => 'Energetic',
                        'sarcastic' => 'Sarcastic',
                        'dramatic' => 'Dramatic',
                        'minimal' => 'Minimal',
                    ],
                    'default' => 'energetic',
                ],
                'show_captions' => [
                    'type' => 'select',
                    'label' => 'Show Captions',
                    'options' => [
                        'yes' => 'Show karaoke captions',
                        'no' => 'Hide captions',
                    ],
                    'default' => 'yes',
                ],
                'caption_template' => [
                    'type' => 'select',
                    'label' => 'Caption Style',
                    'options' => [
                        'modern_karaoke' => 'Modern Karaoke',
                        'classic_block' => 'Classic Block',
                        'minimal_clean' => 'Minimal Clean',
                        'single_word_pop' => 'One Word Pop',
                    ],
                    'default' => 'modern_karaoke',
                ],
                'show_counter' => [
                    'type' => 'select',
                    'label' => 'Countdown Counter Overlay',
                    'options' => [
                        'yes' => 'Show countdown (#30 ... #1)',
                        'no' => 'Hide',
                    ],
                    'default' => 'yes',
                ],
            ],
            'processing_steps' => [
                'validate_input' => 4,
                'download_videos' => 18,
                'analyze_scenes' => 10,
                'transcribe_sources' => 13,
                'rank_moments' => 7,
                'select_clips' => 3,
                'generate_commentary' => 5,
                'generate_tts' => 8,
                'render_clips' => 12,
                'assemble_video' => 8,
                'generate_thumbnail' => 2,
                'upload_to_r2' => 6,
                'cleanup' => 2,
            ],
        ];
    }
}
