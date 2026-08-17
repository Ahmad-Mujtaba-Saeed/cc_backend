<?php

namespace Modules\Project\Processors;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Project\Services\CharacterDetectionService;
use Modules\Project\Services\CharacterVibrateService;
use Modules\Project\Services\ImageGenerationService;
use Modules\Project\Services\R2UploadService;
use Modules\Project\Services\SceneAssemblyService;
use Modules\Project\Services\ScriptGenerationService;
use Modules\Project\Services\TTSGenerationService;
use Modules\Project\Services\WordLevelCaptionService;

class AIImageShortsProcessor extends AbstractVideoProcessor
{
    private ScriptGenerationService $scriptGenerationService;
    private ImageGenerationService $imageGenerationService;
    private CharacterDetectionService $characterDetectionService;
    private CharacterVibrateService $characterVibrateService;
    private SceneAssemblyService $sceneAssemblyService;
    private TTSGenerationService $ttsService;
    private WordLevelCaptionService $captionService;
    private R2UploadService $r2UploadService;

    public function __construct(\Modules\Project\Models\Project $project)
    {
        parent::__construct($project);

        $this->scriptGenerationService = new ScriptGenerationService();
        $this->imageGenerationService = new ImageGenerationService();
        $this->characterDetectionService = new CharacterDetectionService();
        $this->characterVibrateService = new CharacterVibrateService();
        $this->sceneAssemblyService = new SceneAssemblyService();
        $this->ttsService = new TTSGenerationService();
        $this->captionService = new WordLevelCaptionService();
        $this->r2UploadService = new R2UploadService();
    }

    public function process(): bool
    {
        try {
            Log::info('AI image shorts processor starting', [
                'project_id' => $this->project->id,
                'template_type' => $this->project->template_type,
            ]);

            $this->logActivity('ai_image_shorts_processing_started', 'AI image-based shorts processing started');
            $this->pusherService->sendStatus($this->project->id, 'processing', 'Starting AI image-based shorts processing');

            $validation = $this->runProcessingStep(
                'validate',
                function () {
                    $validation = $this->validate();
                    if (!empty($validation)) {
                        Log::error('AIImageShortsProcessor validation failed', ['errors' => $validation]);
                        return false;
                    }
                    return true;
                },
                5,
                'Validating input',
                'Input validation completed',
                'Failed validation'
            );

            if ($validation === false) {
                return false;
            }

            $generatedScript = $this->runProcessingStep(
                'generate_script',
                fn () => $this->generateScript(),
                15,
                'Generating script',
                'AI script generated',
                'Failed to generate AI script'
            );

            if ($generatedScript === false) {
                return false;
            }

            $generatedImages = $this->runProcessingStep(
                'generate_images',
                fn () => $this->generateImages($generatedScript),
                30,
                'Generating images',
                'AI images generated',
                'Failed to generate AI images'
            );

            if ($generatedImages === false) {
                return false;
            }

            $characterData = $this->runProcessingStep(
                'detect_characters',
                fn () => $this->detectCharacters($generatedImages),
                40,
                'Detecting characters',
                'Characters detected',
                'Failed to detect characters'
            );

            if ($characterData === false) {
                return false;
            }

            $animatedSegments = $this->runProcessingStep(
                'apply_vibrate_effect',
                fn () => $this->applyVibrateEffect($generatedImages, $characterData),
                50,
                'Applying character vibrate effect',
                'Vibrate effect applied',
                'Failed to apply vibrate effect'
            );

            if ($animatedSegments === false) {
                return false;
            }

            $ttsResult = $this->runProcessingStep(
                'generate_tts',
                fn () => $this->generateTTSWithTiming($generatedScript),
                65,
                'Generating voice-over',
                'Voice-over generated',
                'Failed to generate voice-over'
            );

            if ($ttsResult === false || !isset($ttsResult['audioPath'])) {
                return false;
            }

            $captionPath = $this->runProcessingStep(
                'generate_captions',
                fn () => $this->generateCaptions($ttsResult['wordTimings'] ?? [], $this->settings['caption_template'] ?? 'modern_karaoke'),
                75,
                'Generating captions',
                'Captions generated',
                'Failed to generate captions'
            );

            if ($captionPath === false) {
                return false;
            }

            $assembledVideoPath = $this->runProcessingStep(
                'assemble_scenes',
                fn () => $this->assembleScenes($animatedSegments, $ttsResult['audioPath']),
                90,
                'Assembling scenes',
                'Scene assembly completed',
                'Failed to assemble scenes'
            );

            if ($assembledVideoPath === false) {
                return false;
            }

            $captionedVideoPath = $this->runProcessingStep(
                'burn_captions',
                fn () => $this->burnKaraokeCaptions($assembledVideoPath, $captionPath),
                94,
                'Burning captions',
                'Captions burned into video',
                'Failed to burn captions into video'
            );

            if ($captionedVideoPath === false) {
                return false;
            }

            $thumbnailResult = $this->runProcessingStep(
                'generate_thumbnail',
                fn () => $this->generateThumbnailFromPath($captionedVideoPath),
                97,
                'Generating thumbnail',
                'Thumbnail generated',
                'Failed to generate thumbnail'
            );

            if ($thumbnailResult === false) {
                return false;
            }

            $r2Url = $this->runProcessingStep(
                'upload_r2',
                fn () => $this->uploadToR2($captionedVideoPath),
                100,
                'Uploading to cloud storage',
                'Upload to cloud storage completed',
                'Failed to upload to cloud storage'
            );

            if ($r2Url === false) {
                return false;
            }

            $this->handleSuccess();
            $this->pusherService->sendCompletion($this->project->id, [
                'output_path' => $captionedVideoPath,
                'thumbnail_path' => $this->project->thumbnail_path,
                'r2_url' => $this->project->r2_url ?? null,
                'status' => 'completed'
            ]);

            return true;
        } catch (\Exception $e) {
            $this->handleFailure('AI image-based shorts processing failed: ' . $e->getMessage());
            $this->pusherService->sendError($this->project->id, 'AI image-based shorts processing failed: ' . $e->getMessage());
            return false;
        }
    }

    public function validate(): array
    {
        $errors = [];

        $prompt = trim($this->settings['prompt'] ?? $this->project->description ?? $this->project->title ?? '');
        if (empty($prompt)) {
            $errors[] = 'A prompt, title, or description is required for AI image-based shorts.';
        }

        $sceneCount = isset($this->settings['scene_count']) ? (int) $this->settings['scene_count'] : 5;
        if ($sceneCount < 1 || $sceneCount > 12) {
            $errors[] = 'Scene count must be between 1 and 12.';
        }

        $validStyles = ['cinematic', 'storyboard', 'animated', 'photo_realistic', 'cartoon'];
        if (isset($this->settings['visual_style']) && !in_array($this->settings['visual_style'], $validStyles, true)) {
            $errors[] = 'Invalid visual style for AI image-based shorts.';
        }

        $ttsErrors = $this->ttsService->validateSettings($this->settings);
        $errors = array_merge($errors, $ttsErrors);

        return $errors;
    }

    protected function getTemplateConfig(): array
    {
        return [
            'name' => 'AI Image-Based Shorts',
            'description' => 'Generate a vertical social short from AI script, image generation, character animation and captions.',
            'requires_upload' => false,
            'min_duration' => 10,
            'max_duration' => 300,
            'max_file_size_mb' => 250,
            'supported_formats' => ['mp4', 'mov', 'avi'],
            'output_formats' => ['mp4'],
            'settings_schema' => [
                'prompt' => [
                    'type' => 'textarea',
                    'label' => 'AI Prompt',
                    'default' => '',
                    'required' => true
                ],
                'scene_count' => [
                    'type' => 'select',
                    'label' => 'Scene Count',
                    'options' => [3, 5, 7, 9],
                    'default' => 5
                ],
                'visual_style' => [
                    'type' => 'select',
                    'label' => 'Visual Style',
                    'options' => ['cinematic', 'storyboard', 'animated', 'photo_realistic', 'cartoon'],
                    'default' => 'cinematic'
                ],
                'tone' => [
                    'type' => 'select',
                    'label' => 'Tone',
                    'options' => ['neutral', 'positive', 'serious', 'humorous'],
                    'default' => 'neutral'
                ],
                'purpose' => [
                    'type' => 'select',
                    'label' => 'Purpose',
                    'options' => ['general', 'marketing', 'educational', 'storytelling', 'persuasive'],
                    'default' => 'general'
                ],
                'target_length' => [
                    'type' => 'select',
                    'label' => 'Target Length',
                    'options' => ['short', 'medium', 'long'],
                    'default' => 'short'
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
            ],
            'processing_steps' => [
                'generate_script' => 10,
                'generate_images' => 20,
                'detect_characters' => 10,
                'apply_vibrate_effect' => 10,
                'generate_tts' => 15,
                'generate_captions' => 10,
                'assemble_scenes' => 15,
                'burn_captions' => 5,
                'upload_r2' => 5,
            ]
        ];
    }

    protected function getProcessingSteps(): array
    {
        return [
            'validate' => 5,
            'generate_script' => 10,
            'generate_images' => 15,
            'detect_characters' => 10,
            'apply_vibrate_effect' => 10,
            'generate_tts' => 15,
            'generate_captions' => 10,
            'assemble_scenes' => 15,
            'burn_captions' => 5,
            'generate_thumbnail' => 3,
            'upload_r2' => 2,
            'complete' => 5,
        ];
    }

    private function generateScript(): ?string
    {
        $sourcePrompt = trim($this->settings['prompt'] ?? $this->project->description ?? $this->project->title ?? '');
        return $this->scriptGenerationService->generateScript($sourcePrompt, $this->settings);
    }

    /**
     * Parse the generated OpenAI scene outline script into structured scenes.
     */
    private function parseScript(string $scriptText): array
    {
        $scenes = [];
        
        // Split by "Scene" keyword or numbers (e.g. "Scene 1", "1. Scene", "Scene [1]", etc.)
        $parts = preg_split('/(?=(?:Scene\s+\d+|Scene:\s+\d+|\b\d+\b\.?\s+Scene|\b\d+\b\s*:\s*(?:Image|Visual)))/i', $scriptText);
        
        $sceneIndex = 1;
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }
            
            $imagePrompt = '';
            $narration = '';
            
            // Look for patterns like "Image: ...", "Visual: ...", "Prompt: ..."
            if (preg_match('/(?:Image|Visual|Prompt)\s*:\s*(.*?)(?=(?:Narration|Voiceover|VO|Text|Scene|$))/is', $part, $matches)) {
                $imagePrompt = trim($matches[1]);
            }
            
            // Look for patterns like "Narration: ...", "Voiceover: ...", "VO: ...", "Text: ..."
            if (preg_match('/(?:Narration|Voiceover|VO|Text)\s*:\s*(.*?)(?=(?:Scene|Image|Visual|Prompt|$))/is', $part, $matches)) {
                $narration = trim($matches[1]);
            }
            
            // Fallback for simple splits of paragraphs or lines
            if (empty($imagePrompt) || empty($narration)) {
                $lines = explode("\n", $part);
                $nonEmptyLines = array_values(array_filter(array_map('trim', $lines)));
                
                if (count($nonEmptyLines) >= 2) {
                    foreach ($nonEmptyLines as $line) {
                        if (empty($imagePrompt) && (stripos($line, 'image') !== false || stripos($line, 'visual') !== false)) {
                            $imagePrompt = preg_replace('/^(?:image|visual)\s*:\s*/i', '', $line);
                        } elseif (empty($narration) && (stripos($line, 'narration') !== false || stripos($line, 'voice') !== false || stripos($line, 'text') !== false)) {
                            $narration = preg_replace('/^(?:narration|voiceover|vo|text)\s*:\s*/i', '', $line);
                        }
                    }
                    
                    if (empty($imagePrompt)) {
                        $imagePrompt = preg_replace('/^(?:Scene\s+\d+|Scene:\s+\d+|\d+\.)\s*/i', '', $nonEmptyLines[0]);
                    }
                    if (empty($narration) && count($nonEmptyLines) > 1) {
                        $narration = $nonEmptyLines[1];
                    }
                }
            }
            
            $imagePrompt = trim(preg_replace('/^(?:image|visual|prompt|description)\s*:\s*/i', '', $imagePrompt));
            $imagePrompt = trim($imagePrompt, " \t\n\r\0\x0B\"'");
            
            $narration = trim(preg_replace('/^(?:narration|voiceover|vo|text|script)\s*:\s*/i', '', $narration));
            $narration = trim($narration, " \t\n\r\0\x0B\"'");
            
            if (!empty($imagePrompt) || !empty($narration)) {
                $scenes[] = [
                    'scene_number' => $sceneIndex++,
                    'image_prompt' => $imagePrompt,
                    'narration' => $narration
                ];
            }
        }
        
        // Ultimate fallback
        if (empty($scenes)) {
            $paragraphs = array_values(array_filter(array_map('trim', explode("\n\n", $scriptText))));
            foreach ($paragraphs as $index => $para) {
                $scenes[] = [
                    'scene_number' => $index + 1,
                    'image_prompt' => $para,
                    'narration' => $para
                ];
            }
        }
        
        return $scenes;
    }

    private function generateImages(string $script): ?array
    {
        $sceneCount = isset($this->settings['scene_count']) ? (int) $this->settings['scene_count'] : 5;
        $visualStyle = $this->settings['visual_style'] ?? 'cinematic';

        $scenes = $this->parseScript($script);
        $prompts = array_column($scenes, 'image_prompt');

        if (empty($prompts)) {
            Log::warning("Parser returned empty prompts inside AIImageShortsProcessor, falling back to full script");
            $prompts = [$script];
        }

        return $this->imageGenerationService->generateImages($prompts, $sceneCount, $visualStyle, [
            'project_id' => $this->project->id,
            'template' => 'ai_image_based_shorts',
            'aspect_ratio' => $this->project->aspect_ratio ?? '9:16',
            // The look comes from the user's chosen visual_style alone — this
            // template previously inherited the horror grading by accident.
            'character_consistency' => true,
        ]);
    }

    private function detectCharacters(array $imagePaths): ?array
    {
        return $this->characterDetectionService->detectCharacters($imagePaths);
    }

    private function applyVibrateEffect(array $imagePaths, array $characterData): ?array
    {
        return $this->characterVibrateService->applyVibrateEffect($imagePaths, $characterData, [
            'project_id' => $this->project->id,
            'vibrate_strength' => $this->settings['vibrate_strength'] ?? 0.08,
        ]);
    }

    private function generateTTSWithTiming(string $script): ?array
    {
        try {
            $audioPath = str_replace('.mp4', '_narration.wav', $this->outputPath);
            $voice = $this->settings['tts_voice'] ?? 'am_michael';
            $outputFullPath = Storage::disk('public')->path($audioPath);

            // Ensure the output directory exists on the shared volume before the
            // Python AI container tries to write the WAV file into it.
            $outputDir = dirname($outputFullPath);
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $scenes = $this->parseScript($script);
            $narrations = array_column($scenes, 'narration');
            $narrationText = implode(' ', $narrations);

            if (empty($narrationText)) {
                Log::warning("Parser returned empty narration inside AIImageShortsProcessor, falling back to full script for TTS");
                $narrationText = $script;
            }

            Log::info("Generating TTS narration audio for AI Image based shorts", [
                'project_id' => $this->project->id,
                'narration_length' => strlen($narrationText)
            ]);

            $result = $this->ttsService->generateTTS($narrationText, (string) $voice, $outputFullPath, true, $this->ttsContext());

            if (!($result['success'] ?? false)) {
                throw new \Exception($result['error'] ?? 'TTS generation failed');
            }

            $wordTimings = $result['word_timings'] ?? [];
            if (empty($wordTimings)) {
                Log::warning('No word timings returned from TTS service for AI image shorts, generating synthetic timings');
                $wordTimings = $this->generateSyntheticWordTimings($narrationText, 30);
            }

            return [
                'audioPath' => $outputFullPath,
                'wordTimings' => $wordTimings,
            ];
        } catch (\Exception $e) {
            Log::error('AIImageShortsProcessor TTS generation failed: ' . $e->getMessage());
            return null;
        }
    }

    private function generateSyntheticWordTimings(string $script, float $estimatedDuration): array
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
                'end' => ($index + 1) * $timePerWord,
            ];
        }

        return $timings;
    }

    private function generateCaptions(array $wordTimings, string $captionTemplate = 'modern_karaoke'): ?string
    {
        if (empty($wordTimings)) {
            return null;
        }

        $captionPath = str_replace('.mp4', '.ass', $this->outputPath);
        $success = $this->captionService->generateKaraokeCaptions($wordTimings, $captionPath, $captionTemplate);

        return $success ? $captionPath : null;
    }

    private function assembleScenes(array $animatedSegments, string $audioPath): ?string
    {
        try {
            if (empty($animatedSegments)) {
                throw new \Exception('No animated segments available for scene assembly');
            }

            $finalVideoPath = str_replace('.mp4', '_final.mp4', $this->outputPath);
            $fullOutputPath = Storage::disk('public')->path($finalVideoPath);

            $sceneDefinitions = [];
            foreach ($animatedSegments as $index => $segmentPath) {
                $sceneDefinitions[] = [
                    'segment_path' => $segmentPath,
                    'order' => $index + 1,
                ];
            }

            $assemblySettings = [
                'visual_style' => $this->settings['visual_style'] ?? 'cinematic',
                'aspect_ratio' => $this->project->aspect_ratio ?? '9:16',
                'project_id' => $this->project->id,
            ];

            // Optional Pixabay music bed mixed UNDER the narration by the
            // assembly service (same hook the horror template uses). Off by
            // default — this template historically had no background music.
            $musicCategory = strtolower(trim((string) ($this->settings['music_category'] ?? 'none')));
            if ($musicCategory !== '' && $musicCategory !== 'none') {
                $chosenTrack = isset($this->settings['music_track_id']) && $this->settings['music_track_id'] !== ''
                    ? (string) $this->settings['music_track_id']
                    : null;
                // "My music" (the user's own upload) is resolved first and
                // never falls through to the catalogue — see UserMusicLibrary.
                $pixabayTrack = \Modules\Project\Services\UserMusicLibrary::isCustom($musicCategory)
                    ? \Modules\Project\Services\UserMusicLibrary::resolveForProject($this->project, $musicCategory, $chosenTrack)
                    : \Modules\Project\Services\MusicProviderFactory::make()
                        ->pickTrack($musicCategory, (int) $this->project->id, $chosenTrack);
                if ($pixabayTrack) {
                    $musicVolume = (float) ($this->settings['music_volume'] ?? 0.15);
                    $assemblySettings['background_music_path'] = Storage::disk('public')->path($pixabayTrack);
                    $assemblySettings['music_volume'] = max(0.0, min(1.0, $musicVolume));
                }
            }

            $resultPath = $this->sceneAssemblyService->assembleScenes($sceneDefinitions, $audioPath, $fullOutputPath, $assemblySettings);

            if (empty($resultPath)) {
                throw new \Exception('Scene assembly returned no output path');
            }

            $relativePath = $this->normalizeStoragePath($resultPath);
            $this->project->update(['output_path' => $relativePath]);

            return $relativePath;
        } catch (\Exception $e) {
            Log::error('AIImageShortsProcessor scene assembly failed: ' . $e->getMessage());
            return null;
        }
    }

    private function normalizeStoragePath(string $path): string
    {
        $diskRoot = Storage::disk('public')->path('');
        $normalized = str_replace('\\', '/', $path);

        if (str_starts_with($normalized, str_replace('\\', '/', $diskRoot))) {
            return ltrim(substr($normalized, strlen(str_replace('\\', '/', $diskRoot))), '/');
        }

        return $path;
    }

    private function uploadToR2(string $videoPath): ?string
    {
        try {
            $localPath = Storage::disk('public')->path($videoPath);
            $remotePath = 'ai_image_shorts/' . $this->project->id . '/' . basename($videoPath);

            $options = [
                'content_type' => 'video/mp4',
                'metadata' => [
                    'project_id' => $this->project->id,
                    'template' => 'ai_image_based_shorts',
                    'processed_at' => now()->toISOString(),
                ],
            ];

            $uploadUrl = $this->r2UploadService->upload($localPath, $remotePath, $options);

            if (!$uploadUrl) {
                throw new \Exception('R2 upload failed');
            }

            $this->project->update([
                'r2_url' => $uploadUrl,
                'r2_key' => $remotePath,
            ]);

            return $uploadUrl;
        } catch (\Exception $e) {
            Log::error('AIImageShortsProcessor R2 upload failed: ' . $e->getMessage());
            return null;
        }
    }
}
