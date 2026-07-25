<?php

namespace Modules\Project\Processors;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\PusherService;
use Modules\Project\Services\VideoTranscriptionService;
use Modules\Project\Services\SRTGenerationService;
use Modules\Project\Services\ScriptRewriteService;
use Modules\Project\Services\TTSGenerationService;
use Modules\Project\Services\FaceDetectionService;
use Modules\Project\Services\VideoEditingService;
use Modules\Project\Services\R2UploadService;
use Modules\Project\Services\WordLevelCaptionService;

class AIVideoProcessor extends AbstractVideoProcessor
{
    private VideoTranscriptionService $transcriptionService;
    private SRTGenerationService $srtService;
    private ScriptRewriteService $scriptRewriteService;
    private TTSGenerationService $ttsService;
    private FaceDetectionService $faceDetectionService;
    private VideoEditingService $videoEditingService;
    private R2UploadService $r2UploadService;
    private WordLevelCaptionService $captionService;
    protected PusherService $pusherService;

    public function __construct(\Modules\Project\Models\Project $project)
    {
        parent::__construct($project);
        
        $this->transcriptionService = new VideoTranscriptionService();
        $this->srtService = new SRTGenerationService();
        $this->scriptRewriteService = new ScriptRewriteService();
        $this->ttsService = new TTSGenerationService();
        $this->faceDetectionService = new FaceDetectionService();
        $this->videoEditingService = new VideoEditingService();
        $this->r2UploadService = new R2UploadService();
        $this->captionService = new WordLevelCaptionService();
        $this->pusherService = new PusherService();
    }

    /**
     * Process AI Video Template using internal services.
     */
    public function process(): bool
    {
        try {
            Log::info('AI video processor starting', [
                'project_id' => $this->project->id,
                'failed_step' => $this->project->failed_step,
                'output_path' => $this->project->output_path,
                'thumbnail_path' => $this->project->thumbnail_path,
            ]);

            $this->logActivity('ai_processing_started', 'AI Video processing started');
            $this->pusherService->sendStatus($this->project->id, 'processing', 'Starting AI video processing');

            $validation = $this->runProcessingStep(
                'validate',
                function () {
                    $validation = $this->validate();
                    if (!empty($validation)) {
                        Log::error('AIVideoProcessor validation failed', ['errors' => $validation]);
                        return false;
                    }
                    return true;
                },
                10,
                'Validating input',
                'Input validation completed',
                'Failed validation'
            );

            if ($validation === false) {
                return false;
            }

            $transcription = $this->runProcessingStep(
                'transcribe_video',
                fn () => $this->transcribeVideo(),
                20,
                'Transcribing video',
                'Video transcription completed',
                'Failed to transcribe video'
            );

            if ($transcription === false) {
                $this->handleFailure('Failed to transcribe video', 'transcribe_video');
                return false;
            }

            $rewrittenScript = $this->runProcessingStep(
                'rewrite_script',
                fn () => $this->rewriteScript($transcription),
                35,
                'Rewriting script',
                'Script rewrite completed',
                'Failed to rewrite script'
            );

            if ($rewrittenScript === false) {
                $this->handleFailure('Failed to rewrite script', 'rewrite_script');
                return false;
            }

            $ttsResult = $this->runProcessingStep(
                'generate_tts',
                fn () => $this->generateTTSWithTiming($rewrittenScript),
                55,
                'Generating TTS',
                'TTS generation completed',
                'Failed to generate TTS'
            );

            if ($ttsResult === false || !isset($ttsResult['audioPath'])) {
                $this->handleFailure('Failed to generate TTS', 'generate_tts');
                return false;
            }

            $newAudioPath = $ttsResult['audioPath'];
            $wordTimings = $ttsResult['wordTimings'] ?? [];

            $captionPath = $this->runProcessingStep(
                'generate_karaoke_captions',
                fn () => $this->generateKaraokeCaptions($wordTimings, $this->settings['caption_template'] ?? 'modern_karaoke'),
                65,
                'Generating karaoke captions',
                'Karaoke captions generated',
                'Failed to generate karaoke captions'
            );

            if ($captionPath === false) {
                $this->handleFailure('Failed to generate karaoke captions', 'generate_karaoke_captions');
                return false;
            }

            $faceData = $this->runProcessingStep(
                'detect_faces',
                fn () => $this->detectFaces(),
                75,
                'Detecting faces',
                'Face detection completed',
                'Failed to detect faces'
            );

            if ($faceData === false) {
                $this->handleFailure('Failed to detect faces', 'detect_faces');
                return false;
            }

            $editedVideoPath = $this->runProcessingStep(
                'edit_visuals',
                fn () => $this->editVisuals($faceData, $newAudioPath),
                85,
                'Editing visuals',
                'Visual editing completed',
                'Failed to edit visuals'
            );

            if ($editedVideoPath === false) {
                $this->handleFailure('Failed to edit visuals', 'edit_visuals');
                return false;
            }

            $finalVideoPath = $this->runProcessingStep(
                'assemble_final_video',
                fn () => $this->assembleFinalVideo($editedVideoPath, $newAudioPath),
                90,
                'Assembling video',
                'Video assembly completed',
                'Failed to assemble final video'
            );

            if ($finalVideoPath === false) {
                $this->handleFailure('Failed to assemble final video', 'assemble_final_video');
                return false;
            }

            $captionedVideoPath = $this->runProcessingStep(
                'burn_karaoke_captions',
                fn () => $this->burnKaraokeCaptions($finalVideoPath, $captionPath),
                96,
                'Burning captions',
                'Captions burned into video',
                'Failed to burn captions into video'
            );

            if ($captionedVideoPath === false) {
                $this->handleFailure('Failed to burn captions into video', 'burn_karaoke_captions');
                return false;
            }

            // Optional Pixabay music bed under the narration (default: none).
            $this->applyBackgroundMusic(Storage::disk('public')->path($captionedVideoPath));

            $thumbnailResult = $this->runProcessingStep(
                'generate_thumbnail',
                fn () => $this->generateThumbnailFromPath($captionedVideoPath),
                98,
                'Generating thumbnail',
                'Thumbnail generated',
                'Failed to generate thumbnail'
            );

            if ($thumbnailResult === false) {
                Log::error('AI video thumbnail generation step failed', [
                    'project_id' => $this->project->id,
                    'captioned_video_path' => $captionedVideoPath,
                    'resolved_video_path' => Storage::disk('public')->path($captionedVideoPath),
                    'thumbnail_path' => $this->thumbnailPath,
                ]);

                $this->handleFailure('Failed to generate thumbnail', 'generate_thumbnail');
                return false;
            }

            $this->runProcessingStep(
                'complete',
                function () {
                    $this->updateProgress(100);
                    return true;
                },
                100,
                'Completing AI processing',
                'Processing completed',
                'Processing completion failed'
            );

            $this->handleSuccess();
            $this->pusherService->sendCompletion($this->project->id, [
                'output_path' => $captionedVideoPath,
                'thumbnail_path' => $this->project->thumbnail_path,
                'status' => 'completed'
            ]);
            return true;

        } catch (\Exception $e) {
            $this->handleFailure('AI Video processing failed: ' . $e->getMessage());
            $this->pusherService->sendError($this->project->id, 'AI Video processing failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate video meets AI template requirements.
     */
    public function validate(): array
    {
        $errors = [];
        $config = $this->getTemplateConfig();

        // Check video duration
        $duration = $this->getVideoDuration();
        if ($duration < $config['min_duration'] || $duration > $config['max_duration']) {
            $errors[] = "Video duration must be between {$config['min_duration']} and {$config['max_duration']} seconds";
        }

        // Check file size
        $fileSize = $this->project->file_size;
        $maxSizeBytes = $config['max_file_size_mb'] * 1024 * 1024;
        if ($fileSize > $maxSizeBytes) {
            $errors[] = "Video file size must be less than {$config['max_file_size_mb']}MB";
        }

        // Validate TTS settings
        $ttsErrors = $this->ttsService->validateSettings($this->settings);
        $errors = array_merge($errors, $ttsErrors);

        return $errors;
    }

    /**
     * Get AI Video template configuration.
     */
    protected function getTemplateConfig(): array
    {
        return [
            'name' => 'YT Automation Short',
            'description' => 'Advanced AI-driven video processing with transcription, script rewriting, TTS, face detection, and automated editing',
            'min_duration' => 10,
            'max_duration' => 600,
            'max_file_size_mb' => 500,
            'supported_formats' => ['mp4', 'mov', 'avi'],
            'output_formats' => ['mp4'],
            'settings_schema' => [
                'rewrite_style' => [
                    'type' => 'select',
                    'label' => 'Script Rewrite Style',
                    'options' => ['professional', 'casual', 'energetic', 'educational', 'entertaining'],
                    'default' => 'professional'
                ],
                'target_length' => [
                    'type' => 'select',
                    'label' => 'Target Script Length',
                    'options' => ['shorter', 'similar', 'longer'],
                    'default' => 'similar'
                ],
                'tone' => [
                    'type' => 'select',
                    'label' => 'Script Tone',
                    'options' => ['neutral', 'positive', 'serious', 'humorous'],
                    'default' => 'neutral'
                ],
                'purpose' => [
                    'type' => 'select',
                    'label' => 'Script Purpose',
                    'options' => ['general', 'marketing', 'educational', 'storytelling', 'persuasive'],
                    'default' => 'general'
                ],
                'tts_voice' => [
                    'type' => 'select',
                    'label' => 'TTS Voice',
                    'options' => ['am_michael', 'af_sarah', 'bm_george', 'bf_emma'],
                    'default' => 'am_michael'
                ],
                'caption_template' => [
                    'type' => 'select',
                    'label' => 'Caption Template',
                    'options' => ['modern_karaoke', 'classic_block', 'minimal_clean'],
                    'default' => 'modern_karaoke'
                ],
                'crop_to_faces' => [
                    'type' => 'checkbox',
                    'label' => 'Crop to Faces',
                    'default' => true
                ],
                'smooth_transitions' => [
                    'type' => 'checkbox',
                    'label' => 'Add Smooth Transitions',
                    'default' => true
                ],
                'focus_main_speaker' => [
                    'type' => 'checkbox',
                    'label' => 'Focus on Main Speaker',
                    'default' => true
                ]
            ],
            'processing_steps' => [
                'transcribe' => 10,
                'write_srt' => 5,
                'rewrite_script' => 10,
                'generate_tts' => 15,
                'detect_faces' => 15,
                'edit_visuals' => 20,
                'assemble_video' => 15,
                'upload_r2' => 10
            ]
        ];
    }

    /**
     * Get processing steps for AI Video.
     */
    protected function getProcessingSteps(): array
    {
        return [
            'validate' => 10,
            'transcribe_video' => 10,
            'rewrite_script' => 10,
            'generate_tts' => 15,
            'generate_karaoke_captions' => 10,
            'detect_faces' => 15,
            'edit_visuals' => 15,
            'assemble_final_video' => 10,
            'burn_karaoke_captions' => 10,
            'generate_thumbnail' => 5,
            'complete' => 10
        ];
    }

    /**
     * Step 1: Transcribe video using internal service.
     */
    private function transcribeVideo(): ?array
    {
        try {
            $this->logActivity('transcription_started', 'Starting video transcription');

            $transcription = $this->transcriptionService->transcribe($this->inputPath);
            
            if (!$transcription) {
                throw new \Exception('Transcription service returned null');
            }

            $this->logActivity('transcription_completed', 'Video transcription completed', [
                'segments_count' => count($transcription['segments'] ?? [])
            ]);

            return $transcription;
        } catch (\Exception $e) {
            Log::error('Transcription failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Step 3b: Generate TTS with word-level timing for karaoke captions.
     */
    private function generateTTSWithTiming(string $script): ?array
    {
        try {
            $this->logActivity('tts_generation_started', 'Starting TTS generation with word timing');

            $audioPath = str_replace('.mp4', '_narration.wav', $this->outputPath);
            $voice = $this->settings['tts_voice'] ?? $this->settings['voice'] ?? 'am_michael';
            if (is_array($voice)) {
                $voice = $voice['id']
                    ?? $voice['value']
                    ?? $voice['voice']
                    ?? (isset($voice[0]) ? $voice[0] : 'am_michael');
            }

            $outputFullPath = Storage::disk('public')->path($audioPath);

            // Ensure the output directory exists on the shared volume before the
            // Python AI container tries to write the WAV file into it.
            $outputDir = dirname($outputFullPath);
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            Log::info('Calling TTSGenerationService with word timing enabled', [
                'project_id' => $this->project?->id,
                'voice' => $voice,
                'script_length' => strlen($script),
            ]);

            $result = $this->ttsService->generateTTS($script, (string)$voice, $outputFullPath, true, $this->ttsContext());
            
            if (!$result || !($result['success'] ?? true)) {
                throw new \Exception($result['error'] ?? 'TTS generation failed');
            }

            $wordTimings = $result['word_timings'] ?? [];
            
            // Fallback: Generate synthetic word timings if not provided
            if (empty($wordTimings)) {
                Log::warning('No word timings from TTS service, generating synthetic timings');
                $wordTimings = $this->generateSyntheticWordTimings($script, 60); // Assume 60 seconds
            }

            Log::info('TTS generation completed with word timings', [
                'project_id' => $this->project?->id,
                'word_timings_count' => count($wordTimings),
            ]);

            $this->logActivity('tts_generation_completed', 'TTS generation completed with word timing', [
                'audio_path' => $outputFullPath,
                'word_count' => count($wordTimings),
                'synthetic_timings' => empty($result['word_timings'] ?? [])
            ]);

            return [
                'audioPath' => $outputFullPath,
                'wordTimings' => $wordTimings
            ];
        } catch (\Exception $e) {
            Log::error('TTS generation with timing failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate synthetic word timings when TTS service doesn't provide them.
     */
    private function generateSyntheticWordTimings(string $script, float $estimatedDuration = 60): array
    {
        $words = preg_split('/\s+/', trim($script), -1, PREG_SPLIT_NO_EMPTY);
        $wordCount = count($words);
        
        if ($wordCount === 0) {
            return [];
        }

        $timePerWord = $estimatedDuration / $wordCount;
        $timings = [];

        foreach ($words as $index => $word) {
            $timings[] = [
                'word' => $word,
                'start' => $index * $timePerWord,
                'end' => ($index + 1) * $timePerWord
            ];
        }

        return $timings;
    }

    /**
     * Generate karaoke-style captions from word timings.
     */
    private function generateKaraokeCaptions(array $wordTimings, string $captionTemplate = 'modern_karaoke'): ?string
    {
        try {
            if (empty($wordTimings)) {
                throw new \Exception('No word timings available for caption generation');
            }

            $this->logActivity('karaoke_caption_generation_started', 'Starting karaoke caption generation', [
                'caption_template' => $captionTemplate,
            ]);

            $captionPath = str_replace('.mp4', '.ass', $this->outputPath);
            $success = $this->captionService->generateKaraokeCaptions($wordTimings, $captionPath, $captionTemplate);

            if (!$success) {
                throw new \Exception('Karaoke caption generation failed');
            }

            $this->logActivity('karaoke_caption_generation_completed', 'Karaoke captions generated', [
                'caption_path' => $captionPath,
                'caption_template' => $captionTemplate,
                'word_count' => count($wordTimings)
            ]);

            return $captionPath;
        } catch (\Exception $e) {
            Log::error('Karaoke caption generation failed: ' . $e->getMessage());
            return null;
        }
    }


    private function rewriteScript(array $transcription): ?string
    {
        try {
            $this->logActivity('script_rewrite_started', 'Starting script rewrite with AI');

            $rewrittenScript = $this->scriptRewriteService->rewriteScript($transcription, $this->settings);
            
            if (!$rewrittenScript) {
                throw new \Exception('Script rewrite service returned null');
            }

            $this->logActivity('script_rewrite_completed', 'Script rewrite completed', [
                'original_length' => strlen(implode(' ', array_column($transcription['segments'] ?? [], 'text'))),
                'rewritten_length' => strlen($rewrittenScript)
            ]);

            return $rewrittenScript;
        } catch (\Exception $e) {
            Log::error('Script rewrite failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Step 5: Detect faces using internal service.
     */
    private function detectFaces(): ?array
    {
        try {
            $this->logActivity('face_detection_started', 'Starting face detection');

            $faceData = $this->faceDetectionService->detectFaces($this->inputPath, $this->settings);
            
            if (!$faceData) {
                throw new \Exception('Face detection failed');
            }

            $this->logActivity('face_detection_completed', 'Face detection completed', [
                'faces_count' => count($faceData['faces'] ?? [])
            ]);

            return $faceData;
        } catch (\Exception $e) {
            Log::error('Face detection failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Step 6: Edit visuals using internal service.
     */
    private function editVisuals(array $faceData, string $audioPath): ?string
    {
        try {
            $this->logActivity('visual_editing_started', 'Starting visual editing');

            $editedVideoPath = str_replace('.mp4', '_edited.mp4', $this->outputPath);
            
            $settings = array_merge($this->settings, ['output_path' => Storage::disk('public')->path($editedVideoPath)]);
            $result = $this->videoEditingService->cropToFaces($this->inputPath, $faceData, $settings);
            
            if (!$result || !$result['success']) {
                throw new \Exception($result['error'] ?? 'Visual editing failed');
            }

            // Use the actual output path returned by the Python service, not the assumed PHP path
            $actualOutputPath = $result['output_path'] ?? null;
            if ($actualOutputPath) {
                Log::info('Visual editing returned output path: ' . $actualOutputPath);
                // The result contains the AI container path, but we need to pass it along so the next step can access it
                $editedVideoPath = $actualOutputPath;
            }

            $this->logActivity('visual_editing_completed', 'Visual editing completed', [
                'edited_video_path' => $editedVideoPath
            ]);

            return $editedVideoPath;
        } catch (\Exception $e) {
            Log::error('Visual editing failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Step 7: Assemble final video.
     */
    private function assembleFinalVideo(string $editedVideoPath, string $audioPath): ?string
    {
        try {
            $this->logActivity('video_assembly_started', 'Starting final video assembly');

            $finalVideoPath = str_replace('.mp4', '_final.mp4', $this->outputPath);
            
            $settings = ['output_path' => Storage::disk('public')->path($finalVideoPath)];
            $result = $this->videoEditingService->combineWithAudio($editedVideoPath, $audioPath, $settings);
            
            if (!$result) {
                throw new \Exception('Video assembly failed');
            }

            // Update project with final video path
            $this->project->update(['output_path' => $finalVideoPath]);

            $this->logActivity('video_assembly_completed', 'Final video assembly completed', [
                'final_video_path' => $finalVideoPath
            ]);

            return $finalVideoPath;
        } catch (\Exception $e) {
            Log::error('Video assembly failed: ' . $e->getMessage());
            return null;
        }
    }



    /**
     * Step 8: Upload to Cloudflare R2.
     */
    private function uploadToR2(string $videoPath): ?string
    {
        try {
            $this->logActivity('r2_upload_started', 'Starting R2 upload');

            $localPath = Storage::disk('public')->path($videoPath);
            $remotePath = 'ai_videos/' . $this->project->id . '/' . basename($videoPath);
            
            $options = [
                'content_type' => 'video/mp4',
                'metadata' => [
                    'project_id' => $this->project->id,
                    'template' => 'ai_video',
                    'processed_at' => now()->toISOString()
                ]
            ];
            
            $uploadUrl = $this->r2UploadService->upload($localPath, $remotePath, $options);
            
            if (!$uploadUrl) {
                throw new \Exception('R2 upload failed');
            }

            $this->project->update([
                'r2_url' => $uploadUrl,
                'r2_key' => $remotePath,
            ]);

            $this->logActivity('r2_upload_completed', 'R2 upload completed', [
                'upload_url' => $uploadUrl,
                'remote_path' => $remotePath
            ]);

            return $uploadUrl;
        } catch (\Exception $e) {
            Log::error('R2 upload failed: ' . $e->getMessage());
            return null;
        }
    }
}
