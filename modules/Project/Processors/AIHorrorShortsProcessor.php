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

class AIHorrorShortsProcessor extends AbstractVideoProcessor
{
    /**
     * The art direction for horror shorts. This used to be the fallback default
     * inside PythonAIService::generateImages(), where it silently graded the
     * images of EVERY template that didn't pass its own tone (AI image shorts,
     * explainer illustrations, explainer props). It belongs here, with the only
     * template that wants it.
     */
    private const VISUAL_TONES = [
        // The dark-documentary look of the viral storytelling shorts: painterly
        // realism, muted and desaturated, light always motivated and scarce.
        'cinematic' => 'cinematic digital painting, realistic proportions, muted desaturated palette, deep shadows with a single motivated light source, soft rim light, volumetric fog, atmospheric haze, fine film grain, dark documentary mood, dramatic low-key lighting, rich surface texture',
        'cartoon_horror' => '2D cel-shaded, bold black outlines, flat shading, exaggerated expressions, limited palette inspired by edgy adult sci-fi cartoons, muted palette with greenish tints and subtle desaturated reds, low saturation, eerie fog, cinematic low-key lighting, high contrast, dark and moody, minimal bright colors, slightly underexposed, moody color grading',
        'gothic' => 'gothic oil painting, chiaroscuro lighting, deep blacks, cold desaturated palette, ornate decaying detail, painterly brushwork, oppressive dread-laden atmosphere, candlelit accents',
        'photo_realistic' => 'ultra realistic cinematic photograph, 35mm film still, natural low-key lighting, deep shadows, muted color grade, shallow depth of field, fine film grain, documentary photography',
    ];

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
            Log::info('AI horror shorts processor starting', [
                'project_id' => $this->project->id,
                'template_type' => $this->project->template_type,
            ]);

            $this->logActivity('ai_horror_shorts_processing_started', 'AI horror shorts processing started');
            $this->pusherService->sendStatus($this->project->id, 'processing', 'Starting AI horror shorts processing');

            $validation = $this->runProcessingStep(
                'validate',
                function () {
                    $validation = $this->validate();
                    if (!empty($validation)) {
                        Log::error('AIHorrorShortsProcessor validation failed', ['errors' => $validation]);
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
                fn() => $this->generateScript(),
                15,
                'Generating horror script',
                'Horror script generated',
                'Failed to generate horror script'
            );

            if ($generatedScript === false) {
                return false;
            }

            $generatedImages = $this->runProcessingStep(
                'generate_images',
                fn() => $this->generateImages($generatedScript),
                30,
                'Generating horror images',
                'Horror images generated',
                'Failed to generate horror images'
            );

            if ($generatedImages === false) {
                return false;
            }

            $characterData = $this->runProcessingStep(
                'detect_characters',
                fn() => $this->detectCharacters($generatedImages),
                40,
                'Detecting characters',
                'Characters detected',
                'Failed to detect characters'
            );

            if ($characterData === false) {
                return false;
            }

            // TTS runs BEFORE the image animation so every scene's segment is
            // rendered at its REAL narration duration — scene cuts then follow
            // the story instead of a fixed 3-second flip.
            $ttsResult = $this->runProcessingStep(
                'generate_tts',
                fn() => $this->generateTTSWithTiming($generatedScript),
                55,
                'Generating voice-over',
                'Voice-over generated',
                'Failed to generate voice-over'
            );

            if ($ttsResult === false || !isset($ttsResult['audioPath'])) {
                return false;
            }

            $animatedSegments = $this->runProcessingStep(
                'apply_effects',
                fn() => $this->applyHorrorEffects($generatedImages, $characterData, $generatedScript, $ttsResult['sceneDurations'] ?? []),
                70,
                'Applying horror effects',
                'Horror effects applied',
                'Failed to apply horror effects'
            );

            if ($animatedSegments === false) {
                return false;
            }

            $captionPath = $this->runProcessingStep(
                'generate_captions',
                fn() => $this->generateCaptions($ttsResult['wordTimings'] ?? [], $this->settings['caption_template'] ?? 'minimal_clean'),
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
                fn() => $this->assembleScenes($animatedSegments, $ttsResult['audioPath']),
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
                fn() => $this->burnKaraokeCaptions($assembledVideoPath, $captionPath),
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
                fn() => $this->generateThumbnailFromPath($captionedVideoPath),
                97,
                'Generating thumbnail',
                'Thumbnail generated',
                'Failed to generate thumbnail'
            );

            if ($thumbnailResult === false) {
                return false;
            }

            // $r2Url = $this->runProcessingStep(
            //     'upload_r2',
            //     fn () => $this->uploadToR2($captionedVideoPath),
            //     100,
            //     'Uploading to cloud storage',
            //     'Upload to cloud storage completed',
            //     'Failed to upload to cloud storage'
            // );

            // if ($r2Url === false) {
            //     return false;
            // }

            $this->handleSuccess();
            $this->pusherService->sendCompletion($this->project->id, [
                'output_path' => $captionedVideoPath,
                'thumbnail_path' => $this->project->thumbnail_path,
                'r2_url' => $this->project->r2_url ?? null,
                'status' => 'completed'
            ]);

            return true;
        } catch (\Exception $e) {
            $this->handleFailure('AI horror shorts processing failed: ' . $e->getMessage());
            $this->pusherService->sendError($this->project->id, 'AI horror shorts processing failed: ' . $e->getMessage());
            return false;
        }
    }

    public function validate(): array
    {
        $errors = [];

        $prompt = trim($this->settings['prompt'] ?? $this->project->description ?? $this->project->title ?? '');
        if (empty($prompt)) {
            $errors[] = 'A prompt, title, or description is required for AI horror shorts.';
        }

        $duration = isset($this->settings['duration_seconds']) ? (int) $this->settings['duration_seconds'] : 20;
        if (!in_array($duration, [15, 20, 25, 30, 35])) {
            $errors[] = 'Video duration must be one of: 15, 20, 25, 30, or 35 seconds.';
        }

        $validStyles = ['cartoon_horror', 'gothic', 'cinematic', 'photo_realistic'];
        if (isset($this->settings['visual_style']) && !in_array($this->settings['visual_style'], $validStyles, true)) {
            $errors[] = 'Invalid visual style for AI horror shorts.';
        }

        $ttsErrors = $this->ttsService->validateSettings($this->settings);
        $errors = array_merge($errors, $ttsErrors);

        return $errors;
    }

    protected function getTemplateConfig(): array
    {
        return [
            'name' => 'AI Horror Shorts',
            'description' => 'Generate a vertical horror short with cartoon/horror images, effects and captions.',
            'requires_upload' => false,
            'min_duration' => 15,
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
                'duration_seconds' => [
                    'type' => 'select',
                    'label' => 'Video Duration',
                    'options' => [15, 20, 25, 30, 35],
                    'default' => 20,
                    'suffix' => 'seconds'
                ],
                'visual_style' => [
                    'type' => 'select',
                    'label' => 'Visual Style',
                    'options' => ['cinematic', 'cartoon_horror', 'gothic', 'photo_realistic'],
                    'default' => 'cinematic'
                ],
                'tone' => [
                    'type' => 'select',
                    'label' => 'Tone',
                    'options' => ['creepy', 'tense', 'jump_scare'],
                    'default' => 'creepy'
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
                    'default' => 'minimal_clean'
                ],
                'music_volume' => [
                    'type' => 'number',
                    'label' => 'Music Volume',
                    'min' => 0,
                    'max' => 1,
                    'step' => 0.05,
                    'default' => 0.15
                ],
            ],
            'processing_steps' => [
                'generate_script' => 10,
                'generate_images' => 20,
                'detect_characters' => 10,
                'apply_effects' => 15,
                'generate_tts' => 15,
                'generate_captions' => 10,
                'assemble_scenes' => 15,
                'burn_captions' => 5,
                'upload_r2' => 5,
            ]
        ];
    }

    public static function getStaticTemplateConfig(): array
    {
        return [
            'name' => 'AI Horror Shorts',
            'description' => 'Generate a vertical horror short with cartoon/horror images, effects and captions.',
            'requires_upload' => false,
            'min_duration' => 15,
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
                'duration_seconds' => [
                    'type' => 'select',
                    'label' => 'Video Duration',
                    'options' => [15, 20, 25, 30, 35],
                    'default' => 20,
                    'suffix' => 'seconds'
                ],
                'visual_style' => [
                    'type' => 'select',
                    'label' => 'Visual Style',
                    'options' => ['cinematic', 'cartoon_horror', 'gothic', 'photo_realistic'],
                    'default' => 'cinematic'
                ],
                'tone' => [
                    'type' => 'select',
                    'label' => 'Tone',
                    'options' => ['creepy', 'tense', 'jump_scare'],
                    'default' => 'creepy'
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
                    'default' => 'minimal_clean'
                ],
                'music_volume' => [
                    'type' => 'number',
                    'label' => 'Music Volume',
                    'min' => 0,
                    'max' => 1,
                    'step' => 0.05,
                    'default' => 0.15
                ],
            ],
            'processing_steps' => [
                'generate_script' => 10,
                'generate_images' => 20,
                'detect_characters' => 10,
                'apply_effects' => 15,
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
            'apply_effects' => 15,
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
        // Ask script generator to produce horror-oriented scene outline
        $settings = $this->settings;
        $settings['template'] = 'horror_stories';
        $settings['tone'] = $this->settings['tone'] ?? 'creepy';
        $settings['purpose'] = 'horror short';

        // Set target length based on video duration
        $duration = isset($this->settings['duration_seconds']) ? (int) $this->settings['duration_seconds'] : 20;
        $settings['target_length'] = $duration . ' seconds';
        $settings['duration_seconds'] = $duration;
        // Exactly as many scenes as images will be generated — a mismatch used
        // to pad the tail with duplicates of the last image.
        $settings['scene_count'] = $this->getImageCountForDuration($duration);

        return $this->scriptGenerationService->generateScript($sourcePrompt, $settings);
    }

    private function parseScript(string $scriptText): array
    {
        $scenes = [];

        // First try: JSON parsing (new format)
        $jsonData = json_decode($scriptText, true);
        if (is_array($jsonData) && isset($jsonData['scenes']) && is_array($jsonData['scenes'])) {
            Log::info('Script parsing - JSON approach', [
                'json_scenes_count' => count($jsonData['scenes'])
            ]);

            foreach ($jsonData['scenes'] as $idx => $scene) {
                $imagePrompt = $scene['image'] ?? '';
                $narration = $scene['narration'] ?? '';

                if (!empty($imagePrompt) || !empty($narration)) {
                    $scenes[] = [
                        'scene_number' => count($scenes) + 1,
                        'image_prompt' => trim($imagePrompt),
                        'narration' => trim($narration),
                        // Camera direction from the documentary-horror format;
                        // absent in old scripts, callers fall back to heuristics.
                        'shot' => is_string($scene['shot'] ?? null) ? trim($scene['shot']) : null,
                        'motion' => is_string($scene['motion'] ?? null) ? trim($scene['motion']) : null,
                        'scare' => (bool) ($scene['scare'] ?? false),
                    ];
                }
            }

            Log::info('Script parsing - JSON completed', [
                'final_scene_count' => count($scenes),
                'scenes_summary' => array_map(fn($s) => [
                    'number' => $s['scene_number'],
                    'image_length' => strlen($s['image_prompt']),
                    'narration_preview' => substr($s['narration'], 0, 60)
                ], $scenes)
            ]);

            return $scenes;
        }

        // Fallback: regex-based splitting on scene markers
        $parts = preg_split('/(?=(?:\d+\.\s*\*?[^\n]*Scene|Scene\s+\d+|Scene:\s+\d+|\b\d+\b\s*:\s*(?:Image|Visual)))/i', $scriptText);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            $lines = preg_split('/\r?\n/', $part);
            $lines = array_map('trim', $lines);

            $imagePrompt = '';
            $narration = '';

            // Skip scene header (first line with scene number/title)
            $contentStartIdx = 0;
            foreach ($lines as $idx => $line) {
                if (empty($line))
                    continue;
                if (preg_match('/(?:^\d+\.|Scene\s+\d+|Scene:\s+\d+|^\*\*\d+)/i', $line)) {
                    $contentStartIdx = $idx + 1;
                    break;
                }
            }

            // Extract image description and narration from remaining lines
            $inNarration = false;
            for ($i = $contentStartIdx; $i < count($lines); $i++) {
                $line = $lines[$i];
                if (empty($line))
                    continue;

                // Check for Image Description label
                if (preg_match('/^\*?(?:Image Description|Image|Visual|Prompt)\s*:\s*(.*)$/i', $line, $match)) {
                    $imagePrompt = trim($match[1]);
                    // Continue looking for more content on next lines until we hit Narration
                    for ($j = $i + 1; $j < count($lines); $j++) {
                        if (preg_match('/^\*?(?:Narration|VO|Text)/i', $lines[$j])) {
                            break;
                        }
                        if (!empty($lines[$j])) {
                            $imagePrompt .= ' ' . trim(preg_replace('/^\*/', '', $lines[$j]));
                        }
                    }
                    $inNarration = true;
                    continue;
                }

                // Check for Narration label
                if (preg_match('/^\*?(?:Narration Text|Narration|Voiceover|VO|Text)\s*:\s*(.*)$/i', $line, $match)) {
                    $narration = trim($match[1]);
                    // Continue collecting narration from subsequent lines
                    for ($j = $i + 1; $j < count($lines); $j++) {
                        if (preg_match('/^\*?(?:Image|Visual|Prompt|Scene)/i', $lines[$j])) {
                            break;
                        }
                        if (!empty($lines[$j]) && !preg_match('/^\*\*/', $lines[$j])) {
                            $narration .= ' ' . trim(preg_replace('/^\*/', '', $lines[$j]));
                        }
                    }
                    break;
                }

                // If no explicit labels found, use first substantial line as image, rest as narration
                if (empty($imagePrompt) && strlen($line) > 5 && !preg_match('/^\*\*/', $line)) {
                    $imagePrompt = $line;
                } elseif (!empty($imagePrompt) && empty($narration) && strlen($line) > 5) {
                    $narration = $line;
                }
            }

            // Clean up markdown
            $imagePrompt = trim(preg_replace('/[\*_]+/', '', $imagePrompt));
            $narration = trim(preg_replace('/[\*_]+/', '', $narration));
            $imagePrompt = trim(preg_replace('/^(?:image|visual|prompt|description)\s*:\s*/i', '', $imagePrompt));
            $narration = trim(preg_replace('/^(?:narration|voiceover|vo|text|script)\s*:\s*/i', '', $narration));

            if (!empty($imagePrompt) || !empty($narration)) {
                $scenes[] = [
                    'scene_number' => count($scenes) + 1,
                    'image_prompt' => $imagePrompt,
                    'narration' => $narration
                ];
            }
        }

        // DEBUG: Log parsed vs expected
        Log::info('Script parsing - regex approach', [
            'regex_scenes_count' => count($scenes),
            'regex_scenes' => $scenes
        ]);

        // Fallback: if we got fewer scenes than expected, try splitting by quoted narrations
        // This handles cases where all narrations exist but parsing failed
        if (count($scenes) < 3) {
            $quotedMatches = [];
            if (preg_match_all('/(["\'])(.+?)\1/s', $scriptText, $matches)) {
                foreach ($matches[2] as $match) {
                    $match = trim($match);
                    if (strlen($match) > 10) {
                        $quotedMatches[] = $match;
                    }
                }
            }

            Log::info('Script parsing - fallback quoted narrations', [
                'quoted_narrations_count' => count($quotedMatches),
                'quoted_narrations' => array_slice($quotedMatches, 0, 5)
            ]);

            // If we found more narrations than scenes, recreate scenes from narrations
            if (count($quotedMatches) > count($scenes)) {
                $scenes = [];
                foreach ($quotedMatches as $idx => $narration) {
                    $scenes[] = [
                        'scene_number' => count($scenes) + 1,
                        'image_prompt' => substr($narration, 0, 100), // Use first 100 chars as image prompt
                        'narration' => $narration
                    ];
                }
            }
        }

        // Final fallback: split by paragraph
        if (empty($scenes)) {
            Log::warning('Script parsing: both regex and narration extraction failed, falling back to paragraph split');
            $paras = array_values(array_filter(array_map('trim', explode("\n\n", $scriptText))));
            foreach ($paras as $i => $p) {
                $scenes[] = [
                    'scene_number' => $i + 1,
                    'image_prompt' => substr($p, 0, 100),
                    'narration' => $p
                ];
            }
        }

        Log::info('Script parsing completed', [
            'final_scene_count' => count($scenes),
            'scenes_summary' => array_map(fn($s) => [
                'number' => $s['scene_number'],
                'image_length' => strlen($s['image_prompt']),
                'narration_preview' => substr($s['narration'], 0, 60)
            ], $scenes)
        ]);

        return $scenes;
    }

    /**
     * Map video duration to the number of images to generate.
     * Longer videos need more images for better pacing.
     */
    private function getImageCountForDuration(int $duration): int
    {
        $durationMap = [
            15 => 8,
            20 => 10,
            25 => 13,
            30 => 15,
            35 => 17,
        ];
        return $durationMap[$duration] ?? 10;
    }

    private function generateImages(string $script): ?array
    {
        $duration = isset($this->settings['duration_seconds']) ? (int) $this->settings['duration_seconds'] : 20;
        $sceneCount = $this->getImageCountForDuration($duration);
        $visualStyle = $this->settings['visual_style'] ?? 'cinematic';
        $scenes = $this->parseScript($script);

        // Character/setting sheets from the documentary-horror script: appended
        // to EVERY image prompt so the model can't drift the protagonist's look
        // or the location between scenes.
        $sheets = $this->extractSheets($script);

        $prompts = array_map(function (array $scene) use ($sheets) {
            $parts = [];
            if (!empty($scene['shot'])) {
                $parts[] = str_replace('_', ' ', $scene['shot']) . ' shot';
            }
            $parts[] = $scene['image_prompt'];
            if ($sheets['character'] !== '') {
                $parts[] = 'The protagonist: ' . $sheets['character'];
            }
            if ($sheets['setting'] !== '') {
                $parts[] = 'Location: ' . $sheets['setting'];
            }
            return implode('. ', array_filter($parts));
        }, $scenes);

        if (empty($prompts)) {
            Log::warning("Parser returned empty prompts inside AIHorrorShortsProcessor, falling back to full script");
            $prompts = [$script];
        }

        Log::info("AIHorrorShortsProcessor: Prepared image prompts", [
            'scene_count' => count($prompts),
            'request_count' => $sceneCount,
            'prompts_sample' => array_slice($prompts, 0, 3)
        ]);

        // Horror shorts are always 9:16 vertical — force this regardless of project setting
        $aspect = '9:16';

        // Add consistency hint for visual synchronization across all images
        $tone = $this->settings['tone'] ?? 'creepy';

        $images = $this->imageGenerationService->generateImages($prompts, $sceneCount, $visualStyle, [
            'project_id' => $this->project->id,
            'template' => 'horror_stories',
            'aspect_ratio' => $aspect,
            'image_size' => ['width' => 768, 'height' => 1344],
            'visual_tone' => self::VISUAL_TONES[$visualStyle] ?? self::VISUAL_TONES['cinematic'],
            'character_consistency' => true,
            'prompt_modifiers' => "Coherent narrative progression, consistent characters and environment, {$tone} atmosphere throughout",
            'tone' => $tone,
        ]);

        if (empty($images) || !is_array($images)) {
            Log::error('AIHorrorShortsProcessor: no images returned from image generation', ['project_id' => $this->project->id]);
            return null;
        }

        // Reindex and ensure we have exactly $sceneCount entries
        $images = array_values($images);
        if (count($images) < $sceneCount) {
            $last = end($images);
            while (count($images) < $sceneCount)
                $images[] = $last;
            Log::warning('AIHorrorShortsProcessor: padded images to match scene count', ['requested' => $sceneCount, 'final_count' => count($images)]);
        } elseif (count($images) > $sceneCount) {
            $images = array_slice($images, 0, $sceneCount);
        }

        Log::info('AIHorrorShortsProcessor: images prepared for processing', ['project_id' => $this->project->id, 'count' => count($images), 'examples' => array_slice($images, 0, 3)]);

        return $images;
    }

    private function detectCharacters(array $imagePaths): ?array
    {
        return $this->characterDetectionService->detectCharacters($imagePaths);
    }

    /**
     * Top-level character/setting sheets from the documentary-horror script
     * JSON. Empty strings for legacy scripts — prompts then ship unchanged.
     *
     * @return array{character: string, setting: string}
     */
    private function extractSheets(string $script): array
    {
        $json = json_decode($script, true);

        return [
            'character' => is_array($json) && is_string($json['character_sheet'] ?? null)
                ? trim($json['character_sheet'])
                : '',
            'setting' => is_array($json) && is_string($json['setting_sheet'] ?? null)
                ? trim($json['setting_sheet'])
                : '',
        ];
    }

    /**
     * Camera move per scene. The script's own direction (motion/scare from the
     * documentary-horror format) wins; legacy scripts fall back to a fixed
     * alternation that still reads intentional. Shake is reserved for the
     * scripted scare beat — it is an impact hit, never a constant vibration.
     */
    private function decideEffectsForScene(array $scene, ?array $characterInfo): array
    {
        $validMotions = ['push_in', 'pull_back', 'drift_left', 'drift_right'];
        $motion = in_array($scene['motion'] ?? '', $validMotions, true) ? $scene['motion'] : null;

        if ($motion === null) {
            // Deterministic alternation (no rand(): re-renders look identical):
            // mostly slow push-ins, a pull-back reveal or lateral drift for air.
            $n = (int) ($scene['scene_number'] ?? 1);
            $motion = ['push_in', 'push_in', 'drift_left', 'push_in', 'pull_back', 'drift_right'][$n % 6];
        }

        $effect = [
            'motion' => $motion,
            'scare' => (bool) ($scene['scare'] ?? false),
            'strength' => (float) ($this->settings['effect_strength'] ?? 0.08),
            // Legacy keys so an older (not yet rebuilt) python service still
            // produces sensible motion from this config.
            'zoom' => $motion === 'pull_back' ? 'zoom_out' : 'zoom_in',
            'vibrate' => (bool) ($scene['scare'] ?? false),
        ];

        return $effect;
    }

    private function applyHorrorEffects(array $imagePaths, array $characterData, string $script, array $sceneDurations = []): ?array
    {
        // Build scenes and ask GPT heuristics via prompt parsing (we use parseScript heuristics here)
        $scenes = $this->parseScript($script);
        $effectsPerScene = [];
        $animatedSegments = [];

        Log::info('AIHorrorShortsProcessor::applyHorrorEffects starting', [
            'image_count' => count($imagePaths),
            'character_data_count' => count($characterData),
            'scenes_count' => count($scenes),
            'scene_durations' => $sceneDurations,
            'image_paths' => $imagePaths
        ]);

        foreach ($imagePaths as $index => $imagePath) {
            $scene = $scenes[$index] ?? ['scene_number' => $index + 1, 'image_prompt' => '', 'narration' => ''];
            $charInfo = $characterData[$index] ?? [];
            $effect = $this->decideEffectsForScene($scene, $charInfo);
            $effectsPerScene[] = $effect;

            // Real narration duration for this scene — the camera move spans
            // the whole beat and the cut lands where the story moves on.
            $duration = (float) ($sceneDurations[$index] ?? 3.0);

            Log::debug("Processing image segment $index", [
                'input_path' => $imagePath,
                'scene_number' => $scene['scene_number'] ?? 'N/A',
                'duration' => $duration,
                'effect' => $effect
            ]);

            // Apply character vibration and/or zoom effects by passing effect config to service
            // The service should handle creating a video segment with the specified effects
            $vibrated = $this->characterVibrateService->applyVibrateEffect(
                [$imagePath],
                $charInfo ? [$charInfo] : [],
                [
                    'project_id' => $this->project->id,
                    'segment_index' => $index,
                    'duration' => $duration,
                    'motion' => $effect['motion'] ?? null,
                    'scare' => !empty($effect['scare']),
                    'vibrate_strength' => !empty($effect['vibrate']) ? $effect['strength'] : 0,
                    'zoom_effect' => $effect['zoom'] ?? null,
                    'zoom_strength' => $effect['strength'] ?? 0.08,
                    'aspect_ratio' => '9:16',
                    'output_width' => 1080,
                    'output_height' => 1920,
                ]
            );

            $segmentPath = is_array($vibrated) && !empty($vibrated[0]) ? $vibrated[0] : $imagePath;

            Log::info("Segment created for image $index", [
                'input' => $imagePath,
                'output' => $segmentPath,
                'order' => $index + 1,
                'duration' => $duration,
                'effect_motion' => $effect['motion'] ?? null,
                'effect_scare' => $effect['scare'] ?? false
            ]);

            // Store segment and metadata for assembly
            $animatedSegments[] = [
                'segment_path' => $segmentPath,
                'order' => $index + 1,
                'duration' => $duration,
                'effect' => $effect,
            ];
        }

        Log::info('AIHorrorShortsProcessor::applyHorrorEffects completed', [
            'total_segments' => count($animatedSegments),
            'segments' => array_map(fn($s) => ['order' => $s['order'], 'path' => $s['segment_path']], $animatedSegments)
        ]);

        return $animatedSegments;
    }

    private function generateTTSWithTiming(string $script): ?array
    {
        // Reuse AIImageShortsProcessor approach
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

            Log::info('AIHorrorShortsProcessor: Parsed scenes for TTS', [
                'scene_count' => count($scenes),
                'narration_count' => count($narrations),
                'narration_text_length' => strlen($narrationText),
                'first_narration' => isset($narrations[0]) ? substr($narrations[0], 0, 80) : 'N/A',
                'full_narration' => $narrationText
            ]);

            if (empty($narrationText)) {
                Log::warning("Parser returned empty narration inside AIHorrorShortsProcessor, falling back to full script for TTS");
                $narrationText = $script;
            }

            $result = $this->ttsService->generateTTS($narrationText, (string) $voice, $outputFullPath, true, $this->ttsContext());

            if (!($result['success'] ?? false)) {
                throw new \Exception($result['error'] ?? 'TTS generation failed');
            }

            $wordTimings = $result['word_timings'] ?? [];
            if (empty($wordTimings)) {
                Log::warning('No word timings returned from TTS service for AI horror shorts, generating synthetic timings');
                $wordTimings = $this->generateSyntheticWordTimings($narrationText, 30);
            }

            // Calculate scene durations proportional to each scene's narration
            $sceneDurations = $this->calculateSceneDurations($wordTimings, $scenes);

            Log::info('AIHorrorShortsProcessor: TTS timing calculated', [
                'scene_count' => count($scenes),
                'word_timing_count' => count($wordTimings),
                'scene_durations' => $sceneDurations,
                'total_duration' => array_sum($sceneDurations)
            ]);

            return [
                'audioPath' => $outputFullPath,
                'wordTimings' => $wordTimings,
                'sceneDurations' => $sceneDurations,
            ];
        } catch (\Exception $e) {
            Log::error('AIHorrorShortsProcessor TTS generation failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Per-scene durations PROPORTIONAL to each scene's narration length (word
     * share of the total), so the image on screen is the one the narrator is
     * talking about. The last scene absorbs rounding drift so the video ends
     * exactly with the narration.
     *
     * @param array $scenes parseScript() scenes (their narrations drive the split)
     */
    private function calculateSceneDurations(array $wordTimings, array $scenes): array
    {
        $sceneCount = count($scenes);
        if ($sceneCount <= 0) {
            return [];
        }
        if (empty($wordTimings)) {
            return array_fill(0, $sceneCount, 3.0);
        }

        $totalDuration = (float) (end($wordTimings)['end'] ?? 30);

        $wordCounts = array_map(
            fn ($scene) => max(1, count(preg_split('/\s+/', trim((string) ($scene['narration'] ?? '')), -1, PREG_SPLIT_NO_EMPTY))),
            $scenes
        );
        $totalWords = max(1, array_sum($wordCounts));

        $durations = [];
        foreach ($wordCounts as $i => $count) {
            $durations[$i] = max(1.6, $totalDuration * $count / $totalWords);
        }

        // Re-anchor the sum to the narration length (min-clamps can inflate it).
        $drift = $totalDuration - array_sum($durations);
        $last = $sceneCount - 1;
        $durations[$last] = max(1.6, $durations[$last] + $drift);

        return array_map(fn ($d) => round($d, 3), $durations);
    }

    private function generateSyntheticWordTimings(string $script, float $estimatedDuration): array
    {
        $words = preg_split('/\\s+/', trim($script), -1, PREG_SPLIT_NO_EMPTY);
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

    private function generateCaptions(array $wordTimings, string $captionTemplate = 'minimal_clean'): ?string
    {
        if (empty($wordTimings)) {
            return null;
        }
        $captionPath = str_replace('.mp4', '.ass', $this->outputPath);
        $success = $this->captionService->generateKaraokeCaptions($wordTimings, $captionPath, $captionTemplate);
        return $success ? $captionPath : null;
    }

    /**
     * Pick a random background-music track from storage/app/public/audio/horror/.
     * Returns the absolute path, or null when the folder is empty/missing so the
     * pipeline can proceed silently without background music.
     */
    private function getRandomHorrorMusic(): ?string
    {
        // Check dedicated horror subfolder first, then fall back to parent audio/ directory
        $candidates = [
            storage_path('app/public/audio/horror'),
            storage_path('app/public/audio'),
        ];

        $files = [];
        $musicDir = null;
        foreach ($candidates as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $found = glob($dir . '/*.{mp3,wav,ogg,m4a,aac}', GLOB_BRACE) ?: [];
            $found = array_values(array_filter($found, 'is_file'));
            if (!empty($found)) {
                $files = $found;
                $musicDir = $dir;
                break;
            }
        }

        if (empty($files)) {
            Log::info('AIHorrorShortsProcessor: no horror music tracks found, skipping background music', [
                'checked_dirs' => $candidates
            ]);
            return null;
        }

        $selected = $files[array_rand($files)];
        Log::info('AIHorrorShortsProcessor: selected background music track', [
            'track' => basename($selected),
            'available' => count($files)
        ]);

        return $selected;
    }

    private function assembleScenes(array $animatedSegments, string $audioPath): ?string
    {
        try {
            if (empty($animatedSegments)) {
                throw new \Exception('No animated segments available for scene assembly');
            }

            $finalVideoPath = str_replace('.mp4', '_final.mp4', $this->outputPath);
            $fullOutputPath = Storage::disk('public')->path($finalVideoPath);

            // Pass effect and duration metadata through to scene assembly service
            $sceneDefinitions = [];
            foreach ($animatedSegments as $index => $segment) {
                $def = [
                    'segment_path' => $segment['segment_path'],
                    'order' => $segment['order'],
                    'effect' => $segment['effect'] ?? null,
                    'scene_index' => $index,
                    'total_scenes' => count($animatedSegments),
                ];

                // Include calculated duration if available
                if (isset($segment['duration'])) {
                    $def['duration'] = $segment['duration'];
                }

                $sceneDefinitions[] = $def;
            }

            Log::info('AIHorrorShortsProcessor::assembleScenes - Prepared scene definitions', [
                'total_scenes' => count($sceneDefinitions),
                'audio_path' => $audioPath,
                'output_path' => $fullOutputPath,
                'definitions' => array_map(fn($d) => [
                    'order' => $d['order'],
                    'path' => $d['segment_path'],
                    'duration' => $d['duration'] ?? 'N/A'
                ], $sceneDefinitions)
            ]);

            $assemblySettings = [
                'visual_style' => $this->settings['visual_style'] ?? 'cartoon_horror',
                'aspect_ratio' => '9:16',
                'output_width' => 1080,
                'output_height' => 1920,
                'project_id' => $this->project->id,
                'apply_effects' => true,
                'effects_enabled' => true,
                'min_scene_duration' => 2.0,
            ];

            // Optional background music mixed UNDER the narration. Pixabay is
            // the primary source (admin-managed key, category per project);
            // the legacy storage/app/public/audio/horror library is the
            // fallback so existing installs keep working without a key.
            $backgroundMusic = $this->settings['background_music'] ?? 'random';
            $musicCategory = strtolower(trim((string) ($this->settings['music_category'] ?? 'horror')));
            if ($backgroundMusic !== 'none' && $musicCategory !== 'none') {
                $musicPath = null;
                $chosenTrack = isset($this->settings['music_track_id']) && $this->settings['music_track_id'] !== ''
                    ? (string) $this->settings['music_track_id']
                    : null;
                $pixabayTrack = (new \Modules\Project\Services\PixabayMusicService())
                    ->pickTrack($musicCategory, (int) $this->project->id, $chosenTrack);
                if ($pixabayTrack) {
                    $musicPath = Storage::disk('public')->path($pixabayTrack);
                }
                $musicPath = $musicPath ?: $this->getRandomHorrorMusic();
                if ($musicPath) {
                    $musicVolume = (float) ($this->settings['music_volume'] ?? 0.15);
                    $musicVolume = max(0.0, min(1.0, $musicVolume));
                    $assemblySettings['background_music_path'] = $musicPath;
                    $assemblySettings['music_volume'] = $musicVolume;
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
            Log::error('AIHorrorShortsProcessor scene assembly failed: ' . $e->getMessage());
            return null;
        }
    }

    private function normalizeStoragePath(string $path): string
    {
        $diskRoot = Storage::disk('public')->path('');
        $normalized = str_replace('\\\\', '/', $path);
        if (str_starts_with($normalized, str_replace('\\\\', '/', $diskRoot))) {
            return ltrim(substr($normalized, strlen(str_replace('\\\\', '/', $diskRoot))), '/');
        }
        return $path;
    }

    private function uploadToR2(string $videoPath): ?string
    {
        try {
            $localPath = Storage::disk('public')->path($videoPath);
            $remotePath = 'ai_horror_shorts/' . $this->project->id . '/' . basename($videoPath);

            $options = [
                'content_type' => 'video/mp4',
                'metadata' => [
                    'project_id' => $this->project->id,
                    'template' => 'horror_stories',
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
            Log::error('AIHorrorShortsProcessor R2 upload failed: ' . $e->getMessage());
            return null;
        }
    }
}
