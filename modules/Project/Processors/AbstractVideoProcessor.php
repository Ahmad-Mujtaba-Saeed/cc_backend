<?php

namespace Modules\Project\Processors;

use Modules\Project\Models\Project;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Services\PusherService;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use FFMpeg\Coordinate\Dimension;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Format\Video\X264;
use FFMpeg\Format\Audio\Mp3;

abstract class AbstractVideoProcessor
{
    protected Project $project;
    protected FFMpeg $ffmpeg;
    protected FFProbe $ffprobe;
    protected PusherService $pusherService;
    protected array $settings;
    protected string $inputPath;
    protected string $outputPath;
    protected string $thumbnailPath;
    protected array $processingState = [];

    public function __construct(Project $project)
    {
        $this->project = $project;
        $this->ffmpeg = FFMpeg::create();
        $this->ffprobe = FFProbe::create();
        $this->pusherService = new PusherService();
        $this->settings = $project->settings ?? [];
        $this->inputPath = $project->video_path ? Storage::disk('public')->path($project->video_path) : '';
        $this->outputPath = $this->generateOutputPath();
        $this->thumbnailPath = $this->generateThumbnailPath();
        $this->loadProcessingState();

        // Attribute every paid AI call made during this render to the project.
        \Modules\Project\Services\CostTracker::setContext($project);
    }

    /**
     * Load processing state from the project record.
     */
    protected function loadProcessingState(): void
    {
        $this->processingState = $this->project->processing_state ?? [];
    }

    /**
     * Template context handed to TTSGenerationService so the OpenAI engine can
     * pick per-template delivery instructions and resolve voices.
     */
    protected function ttsContext(): array
    {
        return [
            'template_type' => $this->project->template_type,
            'settings' => $this->settings,
        ];
    }

    /**
     * Best-effort: mix a Pixabay background-music bed under the finished
     * video's existing audio, in place. Driven by the template settings
     * `music_category` ('none' skips — the default for templates that never
     * had music) and `music_volume`. The track is picked deterministically
     * per project so re-renders sound identical, and any failure (no key, no
     * tracks, ffmpeg error) leaves the original file untouched.
     */
    protected function applyBackgroundMusic(string $videoAbsPath): void
    {
        try {
            $category = strtolower(trim((string) ($this->settings['music_category'] ?? 'none')));
            if ($category === '' || $category === 'none' || !file_exists($videoAbsPath)) {
                return;
            }

            // Idempotency across job resumes: a marker at least as new as the
            // video means THIS file already has its bed — never layer twice.
            // A regenerated video is newer than the marker and mixes again.
            $marker = $videoAbsPath . '.musicmixed';
            // The worker is long-running — don't trust PHP's per-process stat cache.
            clearstatcache(true, $videoAbsPath);
            clearstatcache(true, $marker);
            if (file_exists($marker) && filemtime($marker) >= filemtime($videoAbsPath)) {
                return;
            }

            $chosenTrack = isset($this->settings['music_track_id']) && $this->settings['music_track_id'] !== ''
                ? (string) $this->settings['music_track_id']
                : null;
            $trackRelative = (new \Modules\Project\Services\PixabayMusicService())
                ->pickTrack($category, (int) $this->project->id, $chosenTrack);
            if (!$trackRelative) {
                Log::info('applyBackgroundMusic: no track available — skipping', [
                    'project_id' => $this->project->id,
                    'category' => $category,
                ]);
                return;
            }
            $trackAbs = Storage::disk('public')->path($trackRelative);

            $volume = (float) ($this->settings['music_volume'] ?? 0.12);
            $volume = max(0.0, min(1.0, $volume));
            if ($volume <= 0.0) {
                return;
            }

            $duration = 0.0;
            try {
                $duration = (float) $this->ffprobe->format($videoAbsPath)->get('duration');
            } catch (\Throwable $e) {
                // Fade-out is cosmetic; mix proceeds without it.
            }
            $fade = $duration > 3.0 ? sprintf(',afade=t=out:st=%.2f:d=1.5', $duration - 1.5) : '';

            $hasAudio = true;
            try {
                $hasAudio = count($this->ffprobe->streams($videoAbsPath)->audios()) > 0;
            } catch (\Throwable $e) {
                // Assume audio and let the fallback branch handle a mapping error.
            }

            $mixedPath = preg_replace('/\.mp4$/', '_music.mp4', $videoAbsPath) ?: $videoAbsPath . '_music.mp4';

            if ($hasAudio) {
                $filter = "[1:a]aresample=44100,volume={$volume}{$fade}[bg];"
                    . '[0:a][bg]amix=inputs=2:duration=first:dropout_transition=0:normalize=0[a]';
                $args = [
                    'ffmpeg', '-y', '-i', $videoAbsPath, '-stream_loop', '-1', '-i', $trackAbs,
                    '-filter_complex', $filter, '-map', '0:v', '-map', '[a]',
                    '-c:v', 'copy', '-c:a', 'aac', '-b:a', '192k', '-shortest', $mixedPath,
                ];
            } else {
                $args = [
                    'ffmpeg', '-y', '-i', $videoAbsPath, '-stream_loop', '-1', '-i', $trackAbs,
                    '-filter_complex', "[1:a]aresample=44100,volume={$volume}{$fade}[a]",
                    '-map', '0:v', '-map', '[a]',
                    '-c:v', 'copy', '-c:a', 'aac', '-b:a', '192k', '-shortest', $mixedPath,
                ];
            }

            $process = new \Symfony\Component\Process\Process($args);
            $process->setTimeout(600);
            $process->run();

            if (!$process->isSuccessful() || !file_exists($mixedPath) || filesize($mixedPath) === 0) {
                Log::warning('applyBackgroundMusic: ffmpeg mix failed — keeping original audio', [
                    'project_id' => $this->project->id,
                    'error' => substr($process->getErrorOutput(), -400),
                ]);
                @unlink($mixedPath);
                return;
            }

            if (!@rename($mixedPath, $videoAbsPath)) {
                @copy($mixedPath, $videoAbsPath);
                @unlink($mixedPath);
            }
            @touch($marker);

            Log::info('applyBackgroundMusic: mixed background music', [
                'project_id' => $this->project->id,
                'category' => $category,
                'track' => $trackRelative,
                'volume' => $volume,
            ]);
        } catch (\Throwable $e) {
            Log::warning('applyBackgroundMusic: skipped after error', [
                'project_id' => $this->project->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Save processing state back to the project record.
     *
     * Individual step methods (e.g. prepareGameplay) write ad-hoc keys such as
     * `gameplay_source_path` directly onto the project's processing_state. This
     * abstract keeps its own in-memory copy ($this->processingState) that is
     * only loaded once at construction, so a blind overwrite here would wipe
     * those keys. Merge the freshest persisted state underneath our step
     * markers so both survive.
     */
    protected function saveProcessingState(): void
    {
        $current = $this->project->processing_state ?? [];
        $this->processingState = array_replace($current, $this->processingState);
        $this->project->update(['processing_state' => $this->processingState]);
    }

    /**
     * Get saved step data.
     */
    protected function getStepData(string $step): mixed
    {
        return $this->processingState[$step]['output'] ?? null;
    }

    /**
     * Has the step already completed?
     */
    protected function hasStepCompleted(string $step): bool
    {
        return !empty($this->processingState[$step]['completed']);
    }

    /**
     * Returns the numeric position for a named step.
     */
    protected function getStepIndex(string $step): ?int
    {
        $steps = array_keys($this->getProcessingSteps());
        return array_search($step, $steps, true) !== false ? array_search($step, $steps, true) : null;
    }

    /**
     * Mark a processing step as completed with its output.
     */
    protected function markStepComplete(string $step, mixed $output = null): void
    {
        $this->processingState[$step] = [
            'completed' => true,
            'completed_at' => now()->toISOString(),
            'output' => $output,
        ];

        $this->saveProcessingState();
    }

    /**
     * Determine whether a step should be skipped when resuming.
     */
    protected function shouldSkipStep(string $step): bool
    {
        if (!$this->hasStepCompleted($step)) {
            return false;
        }

        if (!$this->project->failed_step) {
            return false;
        }

        $failedIndex = $this->getStepIndex($this->project->failed_step);
        $stepIndex = $this->getStepIndex($step);

        return $failedIndex !== null && $stepIndex !== null && $stepIndex < $failedIndex;
    }

    /**
     * Run a logical processing step with resume support.
     */
    protected function runProcessingStep(string $step, callable $callback, int $progress, string $stepLabel, string $successMessage, string $failureMessage, bool $persistOutput = true): mixed
    {
        $steps = $this->getProcessingSteps();
        $stepIndex = $this->getStepIndex($step);
        $totalSteps = count($steps);
        $currentProgress = $this->project->progress;

        if ($this->shouldSkipStep($step)) {
            Log::info('Skipping already completed step for resume', [
                'project_id' => $this->project->id,
                'step' => $step,
                'failed_step' => $this->project->failed_step,
                'step_output' => $this->getStepData($step),
            ]);

            $this->updateProgress($progress);
            $this->pusherService->sendProgress($this->project->id, $progress, "Resumed: {$successMessage}");
            return $this->getStepData($step);
        }

        if ($stepLabel && $stepIndex !== null) {
            $this->pusherService->sendStepProgress($this->project->id, $stepLabel, $stepIndex + 1, $totalSteps, $currentProgress);
        }

        $result = $callback();

        if ($result === false || $result === null) {
            $this->handleFailure($failureMessage, $step);
            return false;
        }

        // For heavy results (e.g. a 3000+ segment transcript) the caller can
        // opt out of caching the output in processing_state to avoid bloating
        // the row; the full result is still returned to the caller in-memory.
        $this->markStepComplete($step, $persistOutput ? $result : null);
        $this->updateProgress($progress);
        $this->pusherService->sendProgress($this->project->id, $progress, $successMessage);

        return $result;
    }
    abstract public function process(): bool;

    /**
     * Validate that the video meets template requirements.
     */
    abstract public function validate(): array;

    /**
     * Get template-specific configuration.
     */
    abstract protected function getTemplateConfig(): array;

    /**
     * Get a static template config without requiring a populated project instance.
     */
    public static function getStaticTemplateConfig(): array
    {
        return (new static(new Project()))->getTemplateConfig();
    }

    /**
     * Get processing steps for this template.
     */
    abstract protected function getProcessingSteps(): array;

    /**
     * Update project progress.
     */
    protected function updateProgress(int $progress, string $status = null): void
    {
        $updateData = ['progress' => $progress];
        
        if ($status) {
            $updateData['status'] = $status;
        }

        $this->project->update($updateData);
    }

    /**
     * Log processing activity.
     */
    protected function logActivity(string $action, string $description, array $metadata = []): void
    {
        $this->project->activities()->create([
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Generate output file path.
     */
    protected function generateOutputPath(): string
    {
        $fileName = 'output_' . $this->project->id . '_' . time() . '.mp4';
        return 'projects/outputs/' . $fileName;
    }

    /**
     * Generate thumbnail path.
     */
    protected function generateThumbnailPath(): string
    {
        $fileName = 'thumb_' . $this->project->id . '_' . time() . '.jpg';
        return 'projects/thumbnails/' . $fileName;
    }

    /**
     * Get video duration.
     */
    protected function getVideoDuration(): float
    {
        return $this->ffprobe->format($this->inputPath)->get('duration');
    }

    /**
     * Get video dimensions.
     */
    protected function getVideoDimensions(): array
    {
        $video = $this->ffprobe->streams($this->inputPath)->videos()->first();
        return [
            'width' => $video->get('width'),
            'height' => $video->get('height'),
        ];
    }

    /**
     * Resolve a physical file path for a video, using storage if needed.
     */
    protected function resolvePhysicalPath(string $path): string
    {
        // Already absolute path
        if (file_exists($path)) {
            return $path;
        }

        // Try as relative to storage disk
        $storagePath = Storage::disk('public')->path($path);
        if (file_exists($storagePath)) {
            return $storagePath;
        }

        // Fall back to storage path even if it doesn't exist yet
        return $storagePath;
    }

    /**
     * Generate thumbnail from video.
     */
    protected function generateThumbnail(string $time = '00:00:01', ?string $videoPath = null): bool
    {
        try {
            $sourcePath = $videoPath ? $this->resolvePhysicalPath($videoPath) : $this->inputPath;

            Log::info('Thumbnail generation: Opening video', [
                'source_path' => $sourcePath,
                'exists' => file_exists($sourcePath),
                'thumbnail_path' => $this->thumbnailPath,
            ]);

            if (!file_exists($sourcePath)) {
                throw new \Exception("Video file not found at path: {$sourcePath}");
            }

            $duration = $this->ffprobe->format($sourcePath)->get('duration');
            $requestedTime = 1.0;

            if (!empty($time) && preg_match('/^(\d{2}):(\d{2}):(\d{2})$/', $time, $matches)) {
                $requestedTime = (int) $matches[1] * 3600 + (int) $matches[2] * 60 + (int) $matches[3];
            }

            if ($duration <= 0) {
                $frameSeconds = 0.0;
            } else {
                $frameSeconds = min($requestedTime, max(0.0, $duration - 0.1));
                if ($frameSeconds <= 0) {
                    $frameSeconds = min(0.1, $duration);
                }
            }

            $thumbnailFullPath = Storage::disk('public')->path($this->thumbnailPath);
            $thumbnailDir = dirname($thumbnailFullPath);
            if (!is_dir($thumbnailDir)) {
                mkdir($thumbnailDir, 0755, true);
            }

            $video = $this->ffmpeg->open($sourcePath);
            $video->frame(TimeCode::fromSeconds($frameSeconds))
                  ->save($thumbnailFullPath);

            $this->project->update([
                'thumbnail_path' => $this->thumbnailPath,
                'failed_step' => null,
            ]);

            $this->logActivity('thumbnail_generated', 'Thumbnail generated successfully', [
                'thumbnail_path' => $this->thumbnailPath,
                'frame_seconds' => $frameSeconds,
                'source_path' => $sourcePath,
                'video_duration' => $duration,
            ]);

            return true;
        } catch (\Exception $e) {
            // Persist attempted thumbnail path so it appears in the project record
            try {
                $this->project->update(['thumbnail_path' => $this->thumbnailPath]);
            } catch (\Exception $inner) {
                Log::error('Failed to persist attempted thumbnail_path to project: ' . $inner->getMessage(), [
                    'project_id' => $this->project->id,
                    'thumbnail_path' => $this->thumbnailPath,
                ]);
            }

            // Gather diagnostics
            $sourceExists = isset($sourcePath) ? file_exists($sourcePath) : false;
            $sourceReadable = $sourceExists ? is_readable($sourcePath) : false;
            $thumbnailDirWritable = isset($thumbnailDir) ? is_writable($thumbnailDir) : false;
            $thumbnailCreated = isset($thumbnailFullPath) ? file_exists($thumbnailFullPath) : false;
            $sourceSize = $sourceExists ? filesize($sourcePath) : null;
            $sourcePerms = $sourceExists ? substr(sprintf('%o', fileperms($sourcePath)), -4) : null;

            Log::error('Thumbnail generation failed: ' . $e->getMessage(), [
                'exception' => $e,
                'source_path' => $sourcePath ?? null,
                'thumbnail_path' => $this->thumbnailPath,
                'thumbnail_full_path_exists' => $thumbnailCreated,
                'thumbnail_dir_writable' => $thumbnailDirWritable,
                'source_exists' => $sourceExists,
                'source_readable' => $sourceReadable,
                'source_size' => $sourceSize,
                'source_perms' => $sourcePerms,
                'video_path_argument' => $videoPath,
            ]);

            // Try CLI ffmpeg fallback to extract a frame (gives better error output)
            try {
                $ffmpegCmd = [
                    'ffmpeg',
                    '-y',
                    '-ss', (string) $frameSeconds,
                    '-i', $this->resolvePhysicalPath($sourcePath),
                    '-frames:v', '1',
                    '-q:v', '2',
                    $thumbnailFullPath
                ];

                $proc = new \Symfony\Component\Process\Process($ffmpegCmd);
                $proc->setTimeout(300);
                $proc->run();

                if ($proc->isSuccessful() && file_exists($thumbnailFullPath)) {
                    $this->project->update([
                        'thumbnail_path' => $this->thumbnailPath,
                        'failed_step' => null,
                    ]);

                    $this->logActivity('thumbnail_generated_cli', 'Thumbnail generated via ffmpeg CLI fallback', [
                        'thumbnail_path' => $this->thumbnailPath,
                        'frame_seconds' => $frameSeconds,
                        'ffmpeg_stdout' => $proc->getOutput(),
                        'ffmpeg_stderr' => $proc->getErrorOutput(),
                    ]);

                    return true;
                }

                Log::error('ffmpeg CLI fallback failed', [
                    'cmd' => $ffmpegCmd,
                    'stdout' => $proc->getOutput(),
                    'stderr' => $proc->getErrorOutput(),
                    'thumbnail_exists' => file_exists($thumbnailFullPath),
                ]);
            } catch (\Exception $cliEx) {
                Log::error('ffmpeg CLI fallback raised exception: ' . $cliEx->getMessage(), [
                    'exception' => $cliEx
                ]);
            }

            return false;
        }
    }

    /**
     * Generate thumbnail from a specific video path.
     */
    protected function generateThumbnailFromPath(string $videoPath, string $time = '00:00:01'): bool
    {
        return $this->generateThumbnail($time, $videoPath);
    }

    /**
     * Convert video to standard format.
     */
    protected function convertVideo(array $options = []): bool
    {
        try {
            $config = $this->getTemplateConfig();
            $format = new X264();
            
            // Apply template-specific settings
            if (isset($config['video_codec'])) {
                $format->setVideoCodec($config['video_codec']);
            }
            
            if (isset($config['audio_codec'])) {
                $format->setAudioCodec($config['audio_codec']);
            }
            
            if (isset($config['video_bitrate'])) {
                $format->setKiloBitrate($config['video_bitrate']);
            }
            
            if (isset($config['audio_bitrate'])) {
                $format->setAudioKiloBitrate($config['audio_bitrate']);
            }

            $video = $this->ffmpeg->open($this->inputPath);
            
            // Apply dimensions if specified
            if (isset($config['dimensions'])) {
                $dimensions = new Dimension($config['dimensions']['width'], $config['dimensions']['height']);
                $video->resize($dimensions);
            }

            $video->save($format, Storage::disk('public')->path($this->outputPath));

            $this->project->update(['output_path' => $this->outputPath]);
            
            $this->logActivity('video_converted', 'Video converted successfully', [
                'output_path' => $this->outputPath,
                'format' => $config,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Video conversion failed: ' . $e->getMessage());
            $this->project->update(['error_message' => 'Video conversion failed: ' . $e->getMessage()]);
            return false;
        }
    }

    /**
     * Extract audio from video.
     */
    protected function extractAudio(string $outputFormat = 'mp3'): bool
    {
        try {
            $format = $outputFormat === 'mp3' ? new Mp3() : new X264();
            $audioPath = str_replace('.mp4', '.' . $outputFormat, $this->outputPath);
            
            $video = $this->ffmpeg->open($this->inputPath);
            $video->save($format, Storage::disk('public')->path($audioPath));

            $this->logActivity('audio_extracted', 'Audio extracted successfully', [
                'audio_path' => $audioPath,
                'format' => $outputFormat,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Audio extraction failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Trim video to specific duration.
     */
    protected function trimVideo(float $start, float $duration): bool
    {
        try {
            $video = $this->ffmpeg->open($this->inputPath);
            $video->clip(TimeCode::fromSeconds($start), TimeCode::fromSeconds($duration))
                  ->save(new X264(), Storage::disk('public')->path($this->outputPath));

            $this->logActivity('video_trimmed', 'Video trimmed successfully', [
                'start_time' => $start,
                'duration' => $duration,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Video trimming failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Merge multiple videos.
     */
    protected function mergeVideos(array $videoPaths): bool
    {
        try {
            $video = $this->ffmpeg->open($this->inputPath);
            
            foreach ($videoPaths as $path) {
                $video->concat(Storage::disk('public')->path($path));
            }
            
            $video->save(new X264(), Storage::disk('public')->path($this->outputPath));

            $this->logActivity('videos_merged', 'Videos merged successfully', [
                'video_paths' => $videoPaths,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Video merging failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Add watermark to video.
     */
    protected function addWatermark(string $watermarkPath, array $position = ['x' => 10, 'y' => 10]): bool
    {
        try {
            $video = $this->ffmpeg->open($this->inputPath);
            $watermark = Storage::disk('public')->path($watermarkPath);
            
            $video->filters()->watermark($watermark, [
                'position' => 'relative',
                'x' => $position['x'],
                'y' => $position['y'],
            ]);
            
            $video->save(new X264(), Storage::disk('public')->path($this->outputPath));

            $this->logActivity('watermark_added', 'Watermark added successfully', [
                'watermark_path' => $watermarkPath,
                'position' => $position,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Watermark addition failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Handle processing failure with optional failed step tracking.
     */
    protected function handleFailure(string $error, ?string $failedStep = null): void
    {
        $updateData = [
            'status' => 'failed',
            'error_message' => $error,
        ];
        
        if ($failedStep) {
            $updateData['failed_step'] = $failedStep;
        }
        
        $this->project->update($updateData);

        $this->logActivity('processing_failed', 'Video processing failed', [
            'error' => $error,
            'failed_step' => $failedStep,
        ]);

        Log::error('Video processing failed for project ' . $this->project->id . ': ' . $error, [
            'failed_step' => $failedStep,
        ]);
    }

    /**
     * Burn karaoke-style ASS captions into the final video.
     */
    protected function burnKaraokeCaptions(string $videoPath, string $captionPath): ?string
    {
        try {
            $this->logActivity('karaoke_caption_burn_started', 'Starting karaoke caption burn-in');

            $captionedVideoPath = str_replace('.mp4', '_captioned.mp4', $this->outputPath);
            $inputFullPath = Storage::disk('public')->path($videoPath);
            $captionFullPath = Storage::disk('public')->path($captionPath);
            $outputFullPath = Storage::disk('public')->path($captionedVideoPath);

            $escapedCaptionPath = str_replace('\\', '/', $captionFullPath);
            $escapedCaptionPath = str_replace("'", "\\'", $escapedCaptionPath);

            // Use ASS subtitle filter for proper karaoke rendering
            $process = new \Symfony\Component\Process\Process([
                'ffmpeg',
                '-y',
                '-i',
                $inputFullPath,
                '-vf',
                "ass='{$escapedCaptionPath}'",
                '-c:a',
                'copy',
                $outputFullPath
            ]);

            $process->setTimeout(3600);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \Exception($process->getErrorOutput() ?: $process->getOutput());
            }

            $this->project->update(['output_path' => $captionedVideoPath]);

            $this->logActivity('karaoke_caption_burn_completed', 'Karaoke caption burn-in completed', [
                'captioned_video_path' => $captionedVideoPath,
                'caption_path' => $captionPath
            ]);

            return $captionedVideoPath;
        } catch (\Exception $e) {
            Log::error('Karaoke caption burn-in failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Handle processing success.
     */
    protected function handleSuccess(): void
    {
        $this->project->update([
            'status' => 'completed',
            'progress' => 100,
            'completed_at' => now(),
            'failed_step' => null,
            'processing_state' => null,
        ]);

        $this->logActivity('processing_completed', 'Video processing completed successfully', [
            'output_path' => $this->outputPath,
        ]);
    }
}
